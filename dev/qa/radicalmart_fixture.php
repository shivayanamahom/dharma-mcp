<?php
/** Dev-only: временные токены и точная уборка QA-MCP-RM. Секреты остаются в файле с правами 0600. */
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
if (!$uid) { throw new RuntimeException('No test-site Super User.'); }
$app->loadIdentity(Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($uid));
$file = getenv('MCP_QA_FILE') ?: '/tmp/dharma-mcp-rm-qa.json';
$action = $argv[1] ?? 'seed';
if ($action !== 'seed') {
    $fixture = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (($fixture['site'] ?? '') !== JPATH_ROOT) { throw new RuntimeException('Fixture belongs to another site.'); }
}
$mvc = $app->bootComponent('com_dharma_mcp')->getMVCFactory();
if ($action === 'seed') {
    if (file_exists($file)) { throw new RuntimeException('Clean up the previous fixture first.'); }
    $data = ['token_ids' => [], 'records' => [], 'site' => JPATH_ROOT, 'user_id' => $uid];
    foreach (['full' => ['radicalmart:read','radicalmart:write'], 'read' => ['radicalmart:read'], 'shop' => ['shop:read','shop:write']] as $key => $scopes) {
        $raw = 'dhmcp_' . bin2hex(random_bytes(24));
        $table = $mvc->createTable('Token', 'Administrator');
        if (!$table->save(['title' => 'QA-MCP-RM ' . $key, 'user_id' => $uid, 'state' => 1, 'scopes' => $scopes,
            'token_hash' => hash('sha256', $raw), 'token_prefix' => substr($raw, 0, 14) . '...', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)])) {
            throw new RuntimeException((string) $table->getError());
        }
        $data[$key] = $raw; $data['token_ids'][] = (int) $table->id;
        file_put_contents($file, json_encode($data)); chmod($file, 0600);
    }
    // Проверяем ACL с уже существующим непривилегированным пользователем; аккаунт не изменяем.
    $db->setQuery('SELECT id FROM #__users WHERE block=0 LIMIT 30');
    foreach ($db->loadColumn() as $candidate) {
        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $candidate);
        if ($user->authorise('core.manage', 'com_radicalmart')) { continue; }
        $raw = 'dhmcp_' . bin2hex(random_bytes(24));
        $table = $mvc->createTable('Token', 'Administrator');
        if (!$table->save(['title' => 'QA-MCP-RM restricted', 'user_id' => (int) $candidate, 'state' => 1,
            'scopes' => ['radicalmart:read','radicalmart:write'], 'token_hash' => hash('sha256', $raw),
            'token_prefix' => substr($raw, 0, 14) . '...', 'created_by' => $uid,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)])) { throw new RuntimeException((string) $table->getError()); }
        $data['restricted'] = $raw; $data['token_ids'][] = (int) $table->id;
        file_put_contents($file, json_encode($data));
        break;
    }
    echo "QA token fixtures created; values not printed.\n";
} elseif ($action === 'mode') {
    $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $mode = $argv[2] ?? 'drafts';
    if (!in_array($mode, ['drafts','read_only','full_write'], true)) { throw new RuntimeException('Invalid test mode.'); }
    $table = \Joomla\CMS\Table\Table::getInstance('Extension'); $table->load(['element' => 'com_dharma_mcp', 'type' => 'component']);
    $params = json_decode($table->params, true) ?: [];
    if (!array_key_exists('original_mode', $data)) { $data['original_mode'] = $params['server_mode'] ?? null; }
    $params['server_mode'] = $mode; $table->params = json_encode($params); $table->store();
    file_put_contents($file, json_encode($data));
    Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)->createCacheController('callback', ['defaultgroup' => '_system'])->clean();
    echo "QA server mode: $mode\n";
} elseif ($action === 'oauth') {
    $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $server = new \Dharma\Component\DharmaMcp\Site\Oauth\OauthServer($db);
    $client = $server->register(['client_name' => 'QA-MCP-RM', 'redirect_uris' => ['https://example.invalid/callback']]);
    $data['oauth_client_id'] = $client['client_id'];
    $data['oauth_client_ids'][] = $client['client_id'];
    file_put_contents($file, json_encode($data));
    echo "QA OAuth client created.\n";
} elseif ($action === 'order') {
    $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $pid = (int) ($argv[2] ?? 0);
    if (!array_filter($data['records'], fn ($r) => $r['model'] === 'Product' && $r['id'] === $pid)) { throw new RuntimeException('Not a fixture product.'); }
    $table = $app->bootComponent('com_radicalmart')->getMVCFactory()->createTable('Order', 'Administrator');
    $now = gmdate('Y-m-d H:i:s');
    $row = ['number' => 'QA-MCP-RM-' . time(), 'currency' => 'RUB', 'status' => 1, 'state' => 1,
        'created_by' => $uid, 'modified_by' => $uid, 'created' => $now, 'modified' => $now,
        'secret' => bin2hex(random_bytes(16)), 'contacts' => json_encode(['name' => 'QA-MCP-RM customer', 'email' => 'qa-mcp-rm@example.invalid']),
        'products' => json_encode(['p' . $pid => ['id' => $pid, 'base' => 1234, 'final' => 1234, 'discount' => 0, 'quantity' => 1, 'sum_base' => 1234, 'sum_final' => 1234]]),
        'total' => json_encode(['base' => 1234,'final' => 1234,'fee' => 0,'discount' => 0,'products' => 1,'quantity' => 1]),
        'shipping' => '{}', 'payment' => '{}', 'plugins' => '{}', 'logs' => '{}', 'note' => 'QA-MCP-RM private note'];
    if (!$table->save($row)) { throw new RuntimeException((string) $table->getError()); }
    $data['records'][] = ['model' => 'Order', 'id' => (int) $table->id]; $data['order_id'] = (int) $table->id;
    $data['order_secret'] = $row['secret'];
    file_put_contents($file, json_encode($data));
    echo "QA order fixture created without notifications.\n";
} elseif ($action === 'verify') {
    $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $rm = $app->bootComponent('com_radicalmart')->getMVCFactory();
    $out = [];
    foreach ($data['records'] as $record) {
        $table = $rm->createTable($record['model'], 'Administrator');
        if (!$table->load($record['id'])) { continue; }
        $out[] = ['model' => $record['model'], 'id' => $record['id'], 'state' => $table->state ?? null,
            'status' => $table->status ?? null, 'native_log_entries' => count(json_decode($table->logs ?? '[]', true) ?: [])];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
} elseif ($action === 'cleanup') {
    $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if ($data['site'] !== JPATH_ROOT) { throw new RuntimeException('Fixture belongs to another site.'); }
    $rm = !empty($data['records']) ? $app->bootComponent('com_radicalmart')->getMVCFactory() : null;
    foreach (array_reverse($data['records'] ?? []) as $record) {
        $model = $rm->createModel($record['model'], 'Administrator', ['ignore_request' => true]);
        $table = $model->getTable();
        if (!$table->load($record['id'])) { continue; }
        $marker = $table->title ?? $table->number ?? '';
        if (!str_contains($marker, 'QA-MCP-RM')) { throw new RuntimeException('Refusing to remove an unmarked record.'); }
        $ids = [(int) $record['id']];
        if (isset($table->state) && (int) $table->state !== -2) {
            $publishIds = $ids;
            if (!$model->publish($publishIds, -2)) { throw new RuntimeException((string) $model->getError()); }
        }
        if (!$model->delete($ids)) { throw new RuntimeException((string) $model->getError()); }
    }
    foreach ($data['token_ids'] as $id) {
        $table = $mvc->createTable('Token', 'Administrator');
        if ($table->load($id) && str_starts_with($table->title, 'QA-MCP-RM ')) { $table->delete($id); }
    }
    foreach (array_unique(array_merge($data['oauth_client_ids'] ?? [], isset($data['oauth_client_id']) ? [$data['oauth_client_id']] : [])) as $clientId) {
        $clientTable = new class ($db) extends \Joomla\CMS\Table\Table {
            public function __construct($db) { parent::__construct('#__dharma_mcp_oauth_clients', 'client_id', $db); }
        };
        if ($clientTable->load($clientId) && $clientTable->client_name === 'QA-MCP-RM') { $clientTable->delete($clientId); }
    }
    if (array_key_exists('original_mode', $data)) {
        $table = \Joomla\CMS\Table\Table::getInstance('Extension'); $table->load(['element' => 'com_dharma_mcp', 'type' => 'component']);
        $params = json_decode($table->params, true) ?: [];
        if ($data['original_mode'] === null) { unset($params['server_mode']); } else { $params['server_mode'] = $data['original_mode']; }
        $table->params = json_encode($params); $table->store();
        Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)->createCacheController('callback', ['defaultgroup' => '_system'])->clean();
    }
    unlink($file);
    echo "QA records and temporary tokens removed.\n";
}
