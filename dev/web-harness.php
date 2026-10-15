<?php
/**
 * Dev-only: выполняет веб-запрос к Joomla в консоли — админке или сайту — от имени Суперпользователя из БД.
 * Паролей нет, сессия в памяти. Нужен, чтобы проверять контроллеры, формы и страницы без входа в браузере.
 *
 *   php dev/web-harness.php admin "option=com_dharma_mcp&view=tokens"
 *   php dev/web-harness.php admin "option=com_dharma_mcp&task=token.save" POST '{"jform":{"title":"x","user_id":"42"}}'
 *   php dev/web-harness.php site  "option=com_dharma_mcp&view=authorize&client_id=..."
 *
 * Окружение: DHARMA_SITE (каталог сайта, по умолчанию /Volumes/Work/Server/www/test), DHARMA_HOST (по умолчанию test.test).
 * Для POST действительный токен формы (CSRF) подставляется сам. Выводятся заголовки Status/Location и тело ответа.
 */
$mode   = $argv[1] ?? 'admin';
$query  = $argv[2] ?? '';
$method = strtoupper($argv[3] ?? 'GET');
$post   = isset($argv[4]) ? (json_decode($argv[4], true) ?: []) : [];
if (!in_array($mode, ['admin', 'site'], true)) {
    fwrite(STDERR, "Первый аргумент: admin или site\n");
    exit(1);
}

parse_str($query, $get);
$site = rtrim(getenv('DHARMA_SITE') ?: '/Volumes/Work/Server/www/test', '/');
$host = getenv('DHARMA_HOST') ?: 'test.test';
$base = $mode === 'admin' ? '/administrator/index.php' : '/index.php';

define('_JEXEC', 1);
$_SERVER['HTTP_HOST'] = $host;
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = ($mode === 'admin' ? $base : '/') . '?' . $query;
$_SERVER['QUERY_STRING'] = $query;
$_SERVER['SCRIPT_NAME'] = $base;
$_SERVER['PHP_SELF'] = $base;
$_SERVER['HTTP_USER_AGENT'] = 'web-harness';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = $get;
$_POST = $post;
$_REQUEST = array_merge($get, $post);

define('JPATH_BASE', $mode === 'admin' ? $site . '/administrator' : $site);
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session.web', 'session.cli')->alias('session', 'session.cli')->alias('JSession', 'session.cli')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

$app = $container->get($mode === 'admin' ? \Joomla\CMS\Application\AdministratorApplication::class : \Joomla\CMS\Application\SiteApplication::class);
\Joomla\CMS\Factory::$application = $app;

$db = $container->get('DatabaseDriver');
$db->setQuery('SELECT u.id FROM #__users u JOIN #__user_usergroup_map m ON m.user_id = u.id WHERE m.group_id = 8 AND u.block = 0 ORDER BY u.id LIMIT 1');
$userId = (int) $db->loadResult();

$app->getDispatcher()->addListener('onAfterInitialise', function () use ($app, $container, $userId) {
    $app->loadIdentity($container->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($userId));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = \Joomla\CMS\Session\Session::getFormToken();
        $_POST[$token] = 1;
        $_REQUEST[$token] = 1;
        $app->getInput()->post->set($token, 1);
    }
});

register_shutdown_function(function () use ($app) {
    $out = '';
    while (ob_get_level() > 0) {
        $out = ob_get_clean() . $out;
    }
    foreach ($app->getHeaders() as $h) {
        if (in_array(strtolower($h['name']), ['location', 'status'], true)) {
            echo '[HEADER] ' . $h['name'] . ': ' . $h['value'] . "\n";
        }
    }
    echo $out;
});

ob_start();
$app->execute();
