<?php
/**
 * Dev-only: временные токены и уборка записей «QA-MCP-CL» для набора «Клиника».
 *
 *   DHARMA_SITE=/Volumes/Work/Server/www/clinic php dev/qa/clinic_fixture.php seed      # токены (значения не печатаются, файл 0600)
 *   DHARMA_SITE=... php dev/qa/clinic_fixture.php mode drafts|read_only|full_write       # режим сервера на время проверки
 *   DHARMA_SITE=... php dev/qa/clinic_fixture.php cleanup                                # удаляет токены, возвращает режим, чистит QA-записи
 */
require getenv('DHARMA_BOOT') ?: '/Volumes/Work/Joomla/dharma_shop/dev/boot.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;

set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, 'QA fixture: ' . $e->getMessage() . "\n");
    exit(1);
});

$app = Factory::getApplication();
$db = Factory::getContainer()->get('DatabaseDriver');
$db->setQuery('SELECT u.id FROM #__users u JOIN #__user_usergroup_map m ON m.user_id = u.id WHERE m.group_id = 8 AND u.block = 0 ORDER BY u.id LIMIT 1');
$uid = (int) $db->loadResult();
if (!$uid) { throw new RuntimeException('No Super User on the site.'); }
$app->loadIdentity(Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($uid));

$file = getenv('MCP_QA_FILE') ?: '/tmp/dharma-mcp-clinic-qa.json';
$action = $argv[1] ?? 'seed';
$mvc = $app->bootComponent('com_dharma_mcp')->getMVCFactory();

if ($action === 'seed') {
    if (file_exists($file)) { throw new RuntimeException('Clean up the previous fixture first.'); }
    $data = ['token_ids' => [], 'site' => JPATH_ROOT, 'user_id' => $uid];
    $make = static function (string $key, array $scopes, int $user) use ($mvc, &$data, $file, $uid): void {
        $raw = 'dhmcp_' . bin2hex(random_bytes(24));
        $table = $mvc->createTable('Token', 'Administrator');
        if (!$table->save(['title' => 'QA-MCP-CL ' . $key, 'user_id' => $user, 'state' => 1, 'scopes' => $scopes, 'token_hash' => hash('sha256', $raw),
            'token_prefix' => substr($raw, 0, 14) . '...', 'created_by' => $uid, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 7200)])) {
            throw new RuntimeException((string) $table->getError());
        }
        $data[$key] = $raw;
        $data['token_ids'][] = (int) $table->id;
        file_put_contents($file, json_encode($data));
        chmod($file, 0600);
    };
    $make('full', ['clinic:read', 'clinic:write', 'customers:pii'], $uid);
    $make('read', ['clinic:read'], $uid);
    $make('nopii', ['clinic:read', 'clinic:write'], $uid);
    // Пользователь без прав на компонент клиники (существующий непривилегированный, аккаунт не меняем).
    $db->setQuery('SELECT id FROM #__users WHERE block = 0 AND id <> ' . $uid . ' LIMIT 30');
    foreach ($db->loadColumn() as $candidate) {
        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $candidate);
        if ($user->authorise('core.manage', 'com_dharmaclinic')) { continue; }
        $make('restricted', ['clinic:read', 'clinic:write'], (int) $candidate);
        break;
    }
    echo "QA token fixtures created; values not printed.\n";
    exit(0);
}

$data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
if (($data['site'] ?? '') !== JPATH_ROOT) { throw new RuntimeException('Fixture belongs to another site.'); }

if ($action === 'mode') {
    $mode = $argv[2] ?? 'drafts';
    if (!in_array($mode, ['drafts', 'read_only', 'full_write'], true)) { throw new RuntimeException('Invalid mode.'); }
    $table = \Joomla\CMS\Table\Table::getInstance('Extension');
    $table->load(['element' => 'com_dharma_mcp', 'type' => 'component']);
    $params = json_decode($table->params, true) ?: [];
    if (!array_key_exists('original_mode', $data)) { $data['original_mode'] = $params['server_mode'] ?? null; }
    $params['server_mode'] = $mode;
    $table->params = json_encode($params);
    $table->store();
    file_put_contents($file, json_encode($data));
    echo "Mode: $mode\n";
} elseif ($action === 'rows') {
    // Две QA-записи для сценариев заявок и отзывов; номера кладутся в файл фикстуры.
    $db->setQuery("INSERT INTO #__dharmaclinic_leads (form_id,kind,status_id,name,phone,email,message,page_url,ip_hash,external_id,state,created) VALUES (NULL,'question',(SELECT id FROM #__dharmaclinic_lead_statuses WHERE state = 1 ORDER BY ordering, id LIMIT 1),'QA-MCP-CL Пациент','+7 900 111-22-33','qa@example.test','QA-MCP-CL вопрос про подготовку','https://example.test/',REPEAT('0',64),'',1,UTC_TIMESTAMP())")->execute();
    $data['lead_id'] = (int) $db->insertid();
    $db->setQuery("INSERT INTO #__dharmaclinic_reviews (object_type,object_id,rating,body,author_name,status,source,created) VALUES (1,(SELECT id FROM #__dharmaclinic_doctors ORDER BY id LIMIT 1),5,'QA-MCP-CL отзыв','QA-MCP-CL Автор',0,1,UTC_TIMESTAMP())")->execute();
    $data['review_id'] = (int) $db->insertid();
    file_put_contents($file, json_encode($data));
    echo "QA rows: lead {$data['lead_id']}, review {$data['review_id']}\n";
} elseif ($action === 'pii') {
    // Временно включает/выключает «персональные данные» на сервере MCP (по умолчанию выключено); cleanup возвращает прежнее значение.
    $on = ($argv[2] ?? 'on') === 'on';
    $table = \Joomla\CMS\Table\Table::getInstance('Extension');
    $table->load(['element' => 'com_dharma_mcp', 'type' => 'component']);
    $params = json_decode($table->params, true) ?: [];
    if (!array_key_exists('original_pii', $data)) { $data['original_pii'] = $params['pii_enabled'] ?? null; }
    $params['pii_enabled'] = $on ? 1 : 0;
    $table->params = json_encode($params);
    $table->store();
    file_put_contents($file, json_encode($data));
    echo 'PII: ' . ($on ? 'on' : 'off') . "\n";
} elseif ($action === 'cleanup') {
    foreach ($data['token_ids'] ?? [] as $id) {
        $table = $mvc->createTable('Token', 'Administrator');
        if ($table->load((int) $id)) { $table->delete((int) $id); }
    }
    if (array_key_exists('original_mode', $data)) {
        $table = \Joomla\CMS\Table\Table::getInstance('Extension');
        $table->load(['element' => 'com_dharma_mcp', 'type' => 'component']);
        $params = json_decode($table->params, true) ?: [];
        if ($data['original_mode'] === null) { unset($params['server_mode']); } else { $params['server_mode'] = $data['original_mode']; }
        $table->params = json_encode($params);
        $table->store();
    }
    if (array_key_exists('original_pii', $data)) {
        $table = \Joomla\CMS\Table\Table::getInstance('Extension');
        $table->load(['element' => 'com_dharma_mcp', 'type' => 'component']);
        $params = json_decode($table->params, true) ?: [];
        if ($data['original_pii'] === null) { unset($params['pii_enabled']); } else { $params['pii_enabled'] = $data['original_pii']; }
        $table->params = json_encode($params);
        $table->store();
    }
    // QA-заявки, отзывы и вопросы-из-заявок (точно по пометке QA-MCP-CL).
    $db->setQuery("DELETE q FROM #__dharmaclinic_questions q JOIN #__dharmaclinic_leads l ON l.id = q.lead_id WHERE l.name LIKE 'QA-MCP-CL%'")->execute();
    $db->setQuery("DELETE g FROM #__dharmaclinic_lead_log g JOIN #__dharmaclinic_leads l ON l.id = g.lead_id WHERE l.name LIKE 'QA-MCP-CL%'")->execute();
    $db->setQuery("DELETE FROM #__dharmaclinic_leads WHERE name LIKE 'QA-MCP-CL%'")->execute();
    $db->setQuery("DELETE g FROM #__dharmaclinic_review_log g JOIN #__dharmaclinic_reviews r ON r.id = g.review_id WHERE r.author_name LIKE 'QA-MCP-CL%'")->execute();
    $db->setQuery("DELETE FROM #__dharmaclinic_reviews WHERE author_name LIKE 'QA-MCP-CL%'")->execute();
    // QA-записи клиники: точные id по названию с пометкой QA-MCP-CL, через модели компонента (корзина → удаление).
    $clinic = $app->bootComponent('com_dharmaclinic');
    $app->getLanguage()->load('com_dharmaclinic', JPATH_ADMINISTRATOR);
    \Joomla\CMS\Form\Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_dharmaclinic/forms');
    $removed = 0;
    foreach ([['Doctor', '#__dharmaclinic_doctors'], ['Service', '#__dharmaclinic_services'], ['Disease', '#__dharmaclinic_diseases'], ['Direction', '#__dharmaclinic_directions'], ['Branch', '#__dharmaclinic_branches'],
              ['Pricegroup', '#__dharmaclinic_price_groups'], ['Priceitem', '#__dharmaclinic_price_items'], ['Question', '#__dharmaclinic_questions']] as [$model, $table]) {
        $db->setQuery('SELECT id FROM ' . $table . " WHERE title LIKE 'QA-MCP-CL%'");
        $ids = array_map('intval', $db->loadColumn());
        foreach ($ids as $id) {
            $m = $clinic->getMVCFactory()->createModel($model, 'Administrator', ['ignore_request' => true]);
            $pks = [$id];
            $m->publish($pks, -2);
            $pks = [$id];
            if ($m->delete($pks)) { $removed++; }
        }
    }
    @unlink($file);
    echo "Cleanup done, QA records removed: $removed.\n";
}
