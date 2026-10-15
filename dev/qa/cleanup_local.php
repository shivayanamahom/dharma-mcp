<?php
/**
 * Dev-only: удаляет записи, оставленные автотестом (dev/qa/mcp_qa.py --write), на локальном стенде.
 * Только записи с пометкой «QA-MCP» в названии и файлы mcp-qa-* в шаблоне; всё остальное не трогается.
 *
 *   DHARMA_BOOT=/путь/к/dev/boot.php php dev/qa/cleanup_local.php
 *
 * DHARMA_BOOT — консольная загрузка Joomla (по умолчанию из репозитория магазина), DHARMA_SITE — каталог сайта.
 */
require getenv('DHARMA_BOOT') ?: '/Volumes/Work/Joomla/dharma_shop/dev/boot.php';

use Joomla\CMS\Factory;

$app = Factory::getApplication();
$db = Factory::getContainer()->get('DatabaseDriver');
$db->setQuery('SELECT u.id FROM #__users u JOIN #__user_usergroup_map m ON m.user_id = u.id WHERE m.group_id = 8 AND u.block = 0 LIMIT 1');
$app->loadIdentity(Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById((int) $db->loadResult()));
foreach (['com_content', 'com_categories', 'com_menus', 'com_modules'] as $c) {
    $app->getLanguage()->load($c, JPATH_ADMINISTRATOR);
}
$like = '%QA-MCP%';
$report = [];

$delete = function (string $component, string $model, string $table, string $titleColumn, string $extra = '') use ($app, $db, $like, &$report): void {
    $db->setQuery("SELECT id FROM $table WHERE $titleColumn LIKE " . $db->quote($like) . ' ' . $extra);
    $ids = array_map('intval', $db->loadColumn());
    if (!$ids) {
        $report[$table] = 0;

        return;
    }
    $m = $app->bootComponent($component)->getMVCFactory()->createModel($model, 'Administrator', ['ignore_request' => true]);
    $app->getInput()->set('extension', 'com_content');
    if ($component === 'com_categories') {
        $m->setState('category.extension', 'com_content');
    }
    $pks = $ids;
    $m->publish($pks, -2);
    $pks = $ids;
    $m->delete($pks);
    $db->setQuery("SELECT COUNT(*) FROM $table WHERE id IN (" . implode(',', $ids) . ')');
    $report[$table] = count($ids) - (int) $db->loadResult();
};

$delete('com_content', 'Article', '#__content', 'title');
$delete('com_menus', 'Item', '#__menu', 'title', "AND client_id = 0 AND home = 0");
$delete('com_modules', 'Module', '#__modules', 'title', 'AND client_id = 0');
$delete('com_categories', 'Category', '#__categories', 'title', "AND extension = 'com_content'");

// Статусы заказов и покупатели через сервер не удаляются, поэтому тест их и не создаёт; остатки ручных проверок убираем напрямую.
$db->setQuery('DELETE FROM #__dharmashop_statuses WHERE title LIKE ' . $db->quote($like))->execute();
$report['statuses'] = $db->getAffectedRows();

// Файлы шаблона и копии, созданные тестом.
$site = rtrim(getenv('DHARMA_SITE') ?: '/Volumes/Work/Server/www/test', '/');
$files = 0;
foreach (glob($site . '/templates/*/mcp-qa-*') ?: [] as $f) {
    if (is_file($f)) {
        unlink($f);
        $files++;
    }
}
foreach (glob($site . '/tmp/dharma_mcp_backups/*/mcp-qa-*') ?: [] as $dir) {
    foreach (glob($dir . '/*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($dir);
    $files++;
}
$report['files'] = $files;

echo 'удалено: ' . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";
