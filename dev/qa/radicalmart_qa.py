#!/usr/bin/env python3
"""Сквозная проверка RadicalMart по MCP на локальном стенде.

DHARMA_SITE=/путь/к/сайту php dev/qa/radicalmart_fixture.php seed
python3 dev/qa/radicalmart_qa.py --url https://сайт/mcp --insecure

Создаёт только QA-MCP-RM записи. Не отправляет уведомления покупателям.
В finally убирает точные QA ids/токены и восстанавливает режим сервера.
--keep-fixture оставляет их только для диагностики; потом обязательно fixture.php cleanup.
"""
import argparse
import json
import os
import subprocess
import time
from pathlib import Path
from mcp_qa import Client

ROOT = Path(__file__).resolve().parents[2]
p = argparse.ArgumentParser()
p.add_argument('--url', required=True)
p.add_argument('--insecure', action='store_true')
p.add_argument('--keep-fixture', action='store_true')
p.add_argument('--fixture', default=os.getenv('MCP_QA_FILE', '/tmp/dharma-mcp-rm-qa.json'))
a = p.parse_args()
fixture = Path(a.fixture)
f = json.loads(fixture.read_text())
env = {**os.environ, 'DHARMA_SITE': f['site'], 'MCP_QA_FILE': str(fixture)}
c = Client(a.url, f['full'], a.insecure)
ro = Client(a.url, f['read'], a.insecure)
shop = Client(a.url, f['shop'], a.insecure)
passed = 0

def check(name, value):
    global passed
    if not value:
        raise AssertionError(name)
    passed += 1
    print('OK', name, flush=True)

def native(action, *args):
    return subprocess.run(['php', str(ROOT/'dev/qa/radicalmart_fixture.php'), action, *map(str, args)], env=env, check=True, capture_output=True, text=True).stdout.strip()

def call(name, args=None, client=c, error=False):
    bad, data = client.call(name, args or {})
    if bad != error:
        raise AssertionError(f'{name}: expected error={error}: {data}')
    return data

def tools(client):
    status, data, _ = client.rpc('tools/list')
    check('tools/list HTTP + JSON', status == 200 and 'result' in data)
    return {x['name'] for x in data['result']['tools'] if x['name'].startswith('radicalmart.')}

def track(model, ident):
    current = json.loads(fixture.read_text())
    current['records'].append({'model': model, 'id': ident})
    fixture.write_text(json.dumps(current))

def create(entity, data):
    row = call('radicalmart.entity_create', {'entity': entity, 'data': data})
    check('create '+entity+' returns id', row.get('id', 0) > 0)
    track(entity.title(), row['id'])
    check(entity+' forced draft', row['state'] == 0)
    return row['id']

def get(entity, ident):
    return call('radicalmart.entity_get', {'entity': entity, 'id': ident})

def update(entity, ident, data, confirm=True):
    args = {'entity': entity, 'id': ident, 'data': data}
    result = call('radicalmart.entity_update', args)
    if confirm:
        check('update requires confirmation', result.get('status') == 'confirmation_required')
        args['confirm_code'] = result['confirm_code']
        result = call('radicalmart.entity_update', args)
    check('update persisted '+entity, result.get('id') == ident)
    return result

try:
    native('mode', 'drafts')
    check('drafts mode active', call('ping')['server_mode'] == 'drafts')
    all_tools = tools(c)
    check('11 RadicalMart tools registered', len(all_tools) == 11)
    read_tools = tools(ro)
    check('read token hides mutation tools', len(read_tools) == 6 and 'radicalmart.entity_create' not in read_tools)
    check('Dharma Shop token grants no RadicalMart access', not tools(shop))
    call('radicalmart.info', client=shop, error=True)
    call('radicalmart.entity_create', {'entity':'product','data':{'title':'must not create'}}, client=ro, error=True)
    if f.get('restricted'):
        restricted = Client(a.url, f['restricted'], a.insecure)
        for name,args in [('radicalmart.info',{}),('radicalmart.entity_create',{'entity':'product','data':{'title':'must not create'}})]:
            result = call(name,args,client=restricted,error=True)
            check('Joomla ACL denies '+name,result.get('error')=='forbidden')
    info = call('radicalmart.info')
    check('version and currency discovery', info['version'] and info['currencies'])
    for entity in info['entities']:
        result = call('radicalmart.entity_list', {'entity':entity,'limit':1})
        check('paged native list '+entity, len(result['items']) <= 1 and 'total' in result)
        if result['items']:
            ident = result['items'][0]['id']
            check('native read '+entity, get(entity, ident)['id'] == ident)
    check('native price form present', any(x['name']=='prices.rub.base' for x in call('radicalmart.entities', {'entity':'product'})['fields']))
    call('radicalmart.entity_update', {'entity':'category','id':1,'data':{'title':'must not change'}}, error=True)
    call('radicalmart.entity_trash', {'entity':'category','id':1}, error=True)
    call('radicalmart.entity_create', {'entity':'meta','data':{'title':'must not create'}}, error=True)
    call('radicalmart.entity_create', {'entity':'product','data':'invalid'}, error=True)
    check('root and unsupported writes rejected', True)

    stamp = str(int(time.time()))
    cat = create('category', {'title':'QA-MCP-RM category '+stamp,'type':'category','parent_id':1,'state':1})
    check('category draft persisted', get('category',cat)['state'] == 0)
    manufacturer = create('category', {'title':'QA-MCP-RM manufacturer '+stamp,'type':'manufacturer','parent_id':1})
    badge = create('category', {'title':'QA-MCP-RM badge '+stamp,'type':'badge','parent_id':1})
    check('category special types preserved', get('category',manufacturer)['type']=='manufacturer' and get('category',badge)['type']=='badge')
    pid = create('product', {'title':'QA-MCP-RM product '+stamp,'category':cat,'state':1,
        'code':'QA-MCP-RM-'+stamp,'prices':{'rub':{'base':1234}},'stock':{'all':7},
        'media':{'gallery':[{'type':'image','src':'images/category/beton-gravii.webp','main':1,'alt':'QA-MCP-RM'}]},
        'categories_additional_manufacturers':[manufacturer],'categories_additional_badges':[badge]})
    row = get('product',pid)
    check('price, stock, category and draft persisted', row['state']==0 and row['category']==cat and row['stock']['all']==7 and row['prices']['rub']['base']==1234)
    check('native gallery persisted', row['media']['gallery'][0]['src']=='images/category/beton-gravii.webp')
    created = row['created']
    preview = call('radicalmart.entity_update', {'entity':'product','id':pid,'data':{'title':'QA-MCP-RM changed '+stamp}})
    check('preview leaves data unchanged', get('product',pid)['title']==row['title'])
    args = {'entity':'product','id':pid,'data':{'title':'QA-MCP-RM wrong '+stamp},'confirm_code':preview['confirm_code']}
    changed_preview = call('radicalmart.entity_update',args)
    check('confirmation bound to arguments', changed_preview.get('status')=='confirmation_required' and get('product',pid)['title']==row['title'])
    update('product',pid,{'title':'QA-MCP-RM changed '+stamp})
    row = get('product',pid)
    check('partial update preserves price, stock, gallery and creation date', row['prices']['rub']['base']==1234 and row['stock']['all']==7 and row['media']['gallery'] and row['created']==created)
    update('product',pid,{'prices':{'rub':{'base':2000,'discount_enable':1,'discount':'10%'}},'stock':{'all':11}})
    row = get('product',pid)
    check('native price calculation and stock update', row['prices']['rub']['base']==2000 and row['prices']['rub']['final']==1800 and row['stock']['all']==11)
    call('radicalmart.entity_update',{'entity':'product','id':pid,'data':{'prices':{'unknown':{'base':1}}}},error=True)
    call('radicalmart.entity_update',{'entity':'product','id':pid,'data':{'created_by':999}},error=True)
    check('unknown currency and author fields rejected', get('product',pid)['prices']['rub']['base']==2000)

    alias = 'qa-mcp-rm-choice-'+stamp
    fid = create('field', {'title':'QA-MCP-RM choice '+stamp,'alias':alias,'area':'products','plugin':'standard',
        'params':{'type':'list'},'options':[{'text':'QA A','value':'a'},{'text':'QA B','value':'b'}],
        'all_categories':0,'categories':[cat]})
    update('field',fid,{'state':1})
    update('product',pid,{'fields':{alias:'a'}})
    check('dynamic characteristic value persisted', get('product',pid)['fields']['user_content'][alias]=='a')
    call('radicalmart.entity_update',{'entity':'field','id':fid,'data':{'alias':'must-not-change'}},error=True)
    check('native readonly alias protected', get('field',fid)['alias']==alias)

    native('order',pid)
    current = json.loads(fixture.read_text())
    oid = current['order_id']
    order = call('radicalmart.orders_get',{'order_id':oid})
    serialized = json.dumps(order)
    check('order line item has correct product title', order['products'][0]['title']==get('product',pid)['title'])
    check('PII and private order secret hidden', order['customer_contacts']=='[REDACTED]' and current['order_secret'] not in serialized and 'qa-mcp-rm@example.invalid' not in serialized)
    statuses = call('radicalmart.entity_list',{'entity':'status','limit':100})['items']
    target = next(x['id'] for x in statuses if x['id'] != order['status'])
    args = {'order_id':oid,'status_id':target,'expected_status':order['status'],'notify':False}
    preview = call('radicalmart.orders_set_status',args)
    check('order status preview without mutation', preview.get('status')=='confirmation_required' and call('radicalmart.orders_get',{'order_id':oid})['status']==order['status'])
    call('radicalmart.orders_set_status',{**args,'notify':'false'},error=True)
    call('radicalmart.orders_set_status',{**args,'confirm_code':preview['confirm_code']})
    check('order status persisted', call('radicalmart.orders_get',{'order_id':oid})['status']==target)
    call('radicalmart.orders_set_status',args,error=True)
    check('stale expected status rejected', True)

    native('mode','read_only')
    check('read-only mode active',call('ping')['server_mode']=='read_only')
    check('read-only mode hides every mutation',tools(c)==read_tools)
    call('radicalmart.entity_create',{'entity':'product','data':{'title':'must not create'}},error=True)
    native('mode','full_write')
    check('full-write mode active',call('ping')['server_mode']=='full_write')
    update('product',pid,{'stock':{'all':12}},confirm=False)
    check('full-write mutation persisted',get('product',pid)['stock']['all']==12)
    args = {'order_id':oid,'status_id':order['status'],'expected_status':target,'notify':False}
    check('orders still require confirmation in full-write',call('radicalmart.orders_set_status',args).get('status')=='confirmation_required')
    args = {'entity':'category','id':badge}
    preview = call('radicalmart.entity_trash',args)
    check('trash still requires confirmation in full-write',preview.get('status')=='confirmation_required')
    call('radicalmart.entity_trash',{**args,'confirm_code':preview['confirm_code']})
    check('trash is soft delete',get('category',badge)['state']==-2)
    repeat = call('radicalmart.entity_trash',args)
    call('radicalmart.entity_trash',{**args,'confirm_code':repeat['confirm_code']})
    check('repeated trash is harmless',get('category',badge)['state']==-2)
    verify = json.loads(native('verify'))
    check('native order history written',next(x['native_log_entries'] for x in verify if x['id']==oid and x['model']=='Order')>0)
    print(f'PASS: {passed} checks',flush=True)
finally:
    if not a.keep_fixture:
        print(native('cleanup'),flush=True)
