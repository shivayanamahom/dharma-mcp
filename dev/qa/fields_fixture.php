<?php
/** Временные токены и точная уборка записей QA-MCP-FIELDS, только на локальном стенде. */
require '/Volumes/Work/Joomla/dharma_shop/dev/boot.php';
use Joomla\CMS\Factory;
if (JPATH_ROOT !== '/Volumes/Work/Server/www/test') { throw new RuntimeException('Local test.test only.'); }
$app = Factory::getApplication();
$db = Factory::getContainer()->get('DatabaseDriver');
$db->setQuery('SELECT u.id FROM #__users u JOIN #__user_usergroup_map m ON m.user_id=u.id WHERE m.group_id=8 AND u.block=0 LIMIT 1');
$uid = (int) $db->loadResult();
$app->loadIdentity(Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($uid));
$file = '/tmp/dharma-mcp-fields-qa.json';
$mvc = $app->bootComponent('com_dharma_mcp')->getMVCFactory();
$action = $argv[1] ?? 'seed';
if ($action === 'seed') {
    if (file_exists($file)) { throw new RuntimeException('Clean previous fixture first.'); }
    $d = ['records' => [], 'tokens' => []];
    foreach (['full'=>['content:read','content:write'], 'read'=>['content:read']] as $name=>$scopes) {
        $raw='dhmcp_'.bin2hex(random_bytes(24));
        $t=$mvc->createTable('Token','Administrator');
        if (!$t->save(['title'=>'QA-MCP-FIELDS '.$name,'user_id'=>$uid,'state'=>1,'scopes'=>$scopes,'token_hash'=>hash('sha256',$raw),'token_prefix'=>substr($raw,0,14),'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)])) { throw new RuntimeException((string)$t->getError()); }
        $d[$name]=$raw; $d['tokens'][]=(int)$t->id;
        file_put_contents($file,json_encode($d)); chmod($file,0600);
    }
    $db->setQuery('SELECT id FROM #__users WHERE block=0 LIMIT 50');
    foreach ($db->loadColumn() as $candidate) {
        $user=Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById((int)$candidate);
        if ($user->authorise('core.manage','com_content')) { continue; }
        $raw='dhmcp_'.bin2hex(random_bytes(24));$t=$mvc->createTable('Token','Administrator');
        $t->save(['title'=>'QA-MCP-FIELDS restricted','user_id'=>(int)$candidate,'state'=>1,'scopes'=>['content:read','content:write'],'token_hash'=>hash('sha256',$raw),'token_prefix'=>substr($raw,0,14),'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)]);
        $d['restricted']=$raw;$d['tokens'][]=(int)$t->id;file_put_contents($file,json_encode($d));break;
    }
    echo "Fixtures ready; tokens not printed.\n";
} else {
    $d=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    $table=\Joomla\CMS\Table\Table::getInstance('Extension');
    $table->load(['type'=>'component','element'=>'com_dharma_mcp']);
    $params=json_decode($table->params,true);
    if ($action==='mode') {
        if (!isset($d['original_mode'])) { $d['original_mode']=$params['server_mode']??'drafts'; }
        $params['server_mode']=$argv[2];
        file_put_contents($file,json_encode($d));
    } else {
        foreach (array_reverse($d['records']) as [$component,$model,$id]) {
            $m=$app->bootComponent($component)->getMVCFactory()->createModel($model,'Administrator',['ignore_request'=>true]);
            $app->getInput()->set('extension','com_content'); if ($component==='com_categories') { $m->setState('category.extension','com_content'); }
            $row=$m->getItem($id);
            if (!str_starts_with($row->title??'','QA-MCP-FIELDS')) { if (empty($row->id)) { continue; } throw new RuntimeException('Fixture title guard: ' . $component . '/' . $id . '/' . ($row->title ?? 'NULL'));  }
            $ids=[$id]; $m->publish($ids,-2); $ids=[$id];
            if (!$m->delete($ids)) { throw new RuntimeException((string)$m->getError()); }
        }
        foreach($d['tokens'] as $id) { $t=$mvc->createTable('Token','Administrator'); $t->delete($id); }
        $params['server_mode']=$d['original_mode']??$params['server_mode'];
        unlink($file);
    }
    $table->params=json_encode($params);$table->store();
    Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)->createCacheController('callback',['defaultgroup'=>'_system'])->clean();
    echo "Fixture action complete.\n";
}
