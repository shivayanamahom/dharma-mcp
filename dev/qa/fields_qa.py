#!/usr/bin/env python3
"""Поля материалов: HTTP MCP, native формы, ACL/режимы и точная уборка fixtures на test.test."""
import json, pathlib, subprocess, sys
from mcp_qa import Client
PHP='/Applications/ServBay/package/php/8.4/current/bin/php'
FIX=pathlib.Path('/tmp/dharma-mcp-fields-qa.json')
f=json.loads(FIX.read_text())
c=Client('https://test.test/mcp', f['full'], True)
ro=Client('https://test.test/mcp', f['read'], True)
passed=0

def native(*args):
    return subprocess.run([PHP,'-d','mysqli.default_socket=/Users/mksim/.kettle/run/mysql.sock',str(pathlib.Path(__file__).with_name('fields_fixture.php')),*args],check=True,capture_output=True,text=True).stdout

def check(v,msg):
    global passed
    if not v: raise AssertionError(msg)
    passed+=1; print('OK',msg)

def call(name,args=None,client=c,error=False):
    bad,data=client.call(name,args or {})
    if bad!=error: raise AssertionError(f'{name} error={bad}: {data}')
    return data

def confirmed(name,args):
    d=call(name,args)
    check(d.get('status')=='confirmation_required',name+' confirmation')
    return call(name,args|{'confirm_code':d['confirm_code']})

def create(name,args,component,model):
    d=call(name,args)
    if not d.get('id'): raise AssertionError(str(d))
    x=json.loads(FIX.read_text());x['records'].append([component,model,d['id']]);FIX.write_text(json.dumps(x))
    return d['id']

def raw(a,name): return a['custom_fields'][name]['raw_value']['user_content']

try:
    native('mode','drafts')
    names={t['name'] for t in c.rpc('tools/list')[1]['result']['tools']}
    check('content.fields.create' in names,'field tools registered')
    ro_names={t['name'] for t in ro.rpc('tools/list')[1]['result']['tools']}
    check('content.fields.get' in ro_names and 'content.fields.create' not in ro_names,'read scopes')
    call('content.fields.create',{},client=ro,error=True)
    if f.get('restricted'):
        restricted=Client('https://test.test/mcp',f['restricted'],True)
        d=call('content.fields.list',client=restricted,error=True)
        check(d.get('error')=='forbidden','Joomla ACL denies field management')
    cat=create('categories.create',{'title':'QA-MCP-FIELDS cat'},'com_categories','Category')
    other=create('categories.create',{'title':'QA-MCP-FIELDS other'},'com_categories','Category')
    ids={}
    for typ in ['text','url','list']:
        args={'title':'QA-MCP-FIELDS '+typ,'name':'qa-mcp-fields-'+typ,'type':typ,'category_ids':[cat]}
        if typ=='list':args['options']=['Joomla 6','Joomla 5 и 6']
        ident=create('content.fields.create',args,'com_fields','Field');ids[typ]=ident
        d=call('content.fields.get',{'id':ident})
        check(d['state']==0,'field draft '+typ)
        check(d['category_ids']==[cat] and d['params']['display']=='0','category and hidden display '+typ)
        call('content.fields.create',args,error=True)
        confirmed('content.fields.set_state',{'id':ident,'state':'published'})
    page=call('content.fields.list',{'limit':1})
    check(len(page['items'])==1 and page['total']>=3,'field definition pagination')
    for args in [
        {'type':'editor','category_ids':[cat]}, {'type':'text','category_ids':[1]},
        {'type':'text','category_ids':[]}, {'type':'text','category_ids':[999999]},
        {'type':'list','category_ids':[cat],'options':['x','x']}]:
        call('content.fields.create',{'title':'QA-MCP-FIELDS bad','name':'qa-mcp-fields-bad'}|args,error=True)
        passed+=1
    a=create('articles.create',{'title':'QA-MCP-FIELDS article','category_id':cat,'text':'<p>Preserve body</p>','custom_fields':{'qa-mcp-fields-text':'6','qa-mcp-fields-url':'https://github.com/shivayanamahom/dharma-mcp','qa-mcp-fields-list':'Joomla 6'}},'com_content','Article')
    d=call('articles.get',{'id':a})
    check(raw(d,'qa-mcp-fields-text')=='6','create saves custom fields')
    check(raw(d,'qa-mcp-fields-list')=='Joomla 6','list value readback')
    harness=str(pathlib.Path(__file__).resolve().parents[1]/'web-harness.php')
    native_form=subprocess.run([PHP,'-d','mysqli.default_socket=/Users/mksim/.kettle/run/mysql.sock',harness,'admin',f'option=com_content&view=article&layout=edit&id={a}'],capture_output=True,text=True,check=True).stdout
    check('jform[com_fields][qa-mcp-fields-text]' in native_form and 'Joomla 6' in native_form,'native Joomla article editor includes the fields')
    confirmed('articles.update',{'id':a,'custom_fields':{'qa-mcp-fields-text':'5 и 6'}})
    d=call('articles.get',{'id':a})
    check(raw(d,'qa-mcp-fields-text')=='5 и 6','patch value')
    check(raw(d,'qa-mcp-fields-list')=='Joomla 6' and d['intro_text']['user_content']=='<p>Preserve body</p>','omitted fields and text preserved')
    for values in [{'missing':'x'},{'qa-mcp-fields-url':'javascript:alert(1)'},{'qa-mcp-fields-list':'wrong'},{'qa-mcp-fields-text':[]},None]:
        call('articles.update',{'id':a,'custom_fields':values},error=True);passed+=1
    call('articles.create',{'title':'QA-MCP-FIELDS invalid','category_id':other,'custom_fields':{'qa-mcp-fields-text':'x'}},error=True)
    check(raw(call('articles.get',{'id':a}),'qa-mcp-fields-text')=='5 и 6','rejections leave values unchanged')
    call('articles.update',{'id':a,'category_id':other,'custom_fields':{'qa-mcp-fields-text':'wrong category'}},error=True)
    check(call('articles.get',{'id':a})['category_id']==cat,'invalid target category leaves article unchanged')
    confirmed('articles.update',{'id':a,'category_id':other})
    check(call('articles.get',{'id':a})['category_id']==other,'article category patch applies')
    confirmed('articles.update',{'id':a,'category_id':cat,'custom_fields':{'qa-mcp-fields-text':'6'}})
    check(raw(call('articles.get',{'id':a}),'qa-mcp-fields-text')=='6','category move validates target fields')
    confirmed('articles.update',{'id':a,'custom_fields':{'qa-mcp-fields-text':''}})
    check(raw(call('articles.get',{'id':a}),'qa-mcp-fields-text') in ('',None),'clear value')
    confirmed('content.fields.update',{'id':ids['text'],'title':'QA-MCP-FIELDS renamed','description':'Version'})
    check(call('content.fields.get',{'id':ids['text']})['name']=='qa-mcp-fields-text','rename preserves machine name')
    if f.get('restricted'):
        call('content.fields.get',{'id':ids['text']},client=restricted,error=True)
        call('articles.update',{'id':a,'custom_fields':{'qa-mcp-fields-text':'ACL must reject'}},client=restricted,error=True)
        check(raw(call('articles.get',{'id':a}),'qa-mcp-fields-text') in ('',None),'ACL rejection preserves values')
    before=call('content.fields.get',{'id':ids['list']})
    confirmed('content.fields.update',{'id':ids['list'],'title':'QA-MCP-FIELDS list renamed'})
    after=call('content.fields.get',{'id':ids['list']})
    check(before['fieldparams']==after['fieldparams'] and before['category_ids']==after['category_ids'],'definition patch preserves options and assignments')
    confirmed('content.fields.set_state',{'id':ids['text'],'state':'unpublished'})
    call('articles.update',{'id':a,'custom_fields':{'qa-mcp-fields-text':'x'}},error=True)
    native('mode','full_write')
    confirmed('content.fields.update',{'id':ids['url'],'description':'Link'})
    native('mode','read_only')
    call('content.fields.create',{'title':'QA-MCP-FIELDS no','name':'qa-no','type':'text','category_ids':[cat]},error=True)
    call('articles.update',{'id':a,'custom_fields':{'qa-mcp-fields-list':'Joomla 5 и 6'}},error=True)
    check('content.fields.create' not in {t['name'] for t in c.rpc('tools/list')[1]['result']['tools']},'read-only hides writes')
    native('mode','drafts')
    import http.cookiejar, urllib.request, ssl
    jar=http.cookiejar.CookieJar()
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar),urllib.request.HTTPSHandler(context=ssl._create_unverified_context()))
    payload=json.dumps({'jsonrpc':'2.0','id':1,'method':'tools/call','params':{'name':'articles.get','arguments':{'id':a}}}).encode()
    req=urllib.request.Request('https://test.test/mcp',data=payload,headers={'Authorization':'Bearer '+f['full'],'Content-Type':'application/json'})
    with opener.open(req) as r: json.load(r)
    with opener.open('https://test.test/index.php?option=com_users&view=profile') as r: html=r.read().decode()
    check('name="password"' in html and 'name="username"' in html,'MCP token does not authenticate browser session')
    print('PASS',passed)
finally:
    native('cleanup')
