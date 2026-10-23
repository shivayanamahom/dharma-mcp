#!/usr/bin/env python3
"""Проверка нативных форм токена/OAuth через web-harness; без вывода секретов.
Запуск после radicalmart_fixture.php seed, до radicalmart_qa.py (который убирает fixtures).
"""
import json
import os
import re
import subprocess
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode, urlsplit
import argparse
from mcp_qa import Client

p=argparse.ArgumentParser()
p.add_argument('--url',required=True)
p.add_argument('--insecure',action='store_true')
a=p.parse_args()
root=Path(__file__).resolve().parents[2]
path=Path(os.getenv('MCP_QA_FILE','/tmp/dharma-mcp-rm-qa.json'))
f=json.loads(path.read_text())
host=urlsplit(a.url).netloc
env={**os.environ,'DHARMA_SITE':f['site'],'DHARMA_HOST':host,'MCP_QA_FILE':str(path)}
c=Client(a.url,f['read'],a.insecure)
checks=0

def check(name,result):
 global checks
 assert result,name
 checks+=1
 print('OK',name,flush=True)

def page(mode,query,post=None):
 args=['php',str(root/'dev/web-harness.php'),mode,query]
 if post is not None:args+=['POST',json.dumps(post)]
 r=subprocess.run(args,env=env,capture_output=True,text=True,check=True)
 assert not re.search(r'(Fatal error|Warning:|Parse error:)',r.stdout),r.stdout[:200]
 return r.stdout

class Form(HTMLParser):
 def __init__(self):super().__init__();self.inputs=[];self.labels=[]
 def handle_starttag(self,tag,attrs):
  d=dict(attrs)
  if tag=='input':self.inputs.append(d)
  if tag=='label':self.labels.append(d.get('for'))

ident=f['token_ids'][1]
s=page('admin',f'option=com_dharma_mcp&view=token&layout=edit&id={ident}')
form=Form();form.feed(s)
scopes=[x for x in form.inputs if x.get('name')=='jform[scopes][]']
check('six admin scope groups',re.findall(r'data-scope-group="([^"]+)"',s)==['shop','radicalmart','content','template','system','personal'])
check('all eleven admin checkboxes',len(scopes)==11)
check('unique checkbox ids and matching labels',len({x['id'] for x in scopes})==11 and all(x['id'] in form.labels for x in scopes))
check('existing selection preserved',[x['value'] for x in scopes if 'checked' in x]==['radicalmart:read'])
user=next(x['value'] for x in form.inputs if x.get('name')=='jform[user_id]')
for selected,expected in [(['radicalmart:read','content:read'],['radicalmart:read','content:read']),(['radicalmart:read'],['radicalmart:read']),([],['radicalmart:read']),(['unknown:scope'],['radicalmart:read'])]:
 page('admin',f'option=com_dharma_mcp&task=token.save&id={ident}',{'jform':{'id':ident,'title':'QA-MCP-RM read','user_id':user,'state':1,'scopes':selected,'expires_at':''}})
 bad,d=c.call('ping')
 check('scope save or rejection: '+str(selected),not bad and d['scopes']==expected)

subprocess.run(['php',str(root/'dev/qa/radicalmart_fixture.php'),'oauth'],env=env,capture_output=True,text=True,check=True)
f=json.loads(path.read_text())
q=urlencode({'option':'com_dharma_mcp','view':'authorize','client_id':f['oauth_client_id'],'redirect_uri':'https://example.invalid/callback','response_type':'code','scope':' '.join(x['value'] for x in scopes),'code_challenge':'A'*43,'code_challenge_method':'S256'})
s=page('site',q)
form=Form();form.feed(s)
options=[x for x in form.inputs if x.get('name')=='scopes[]']
check('six OAuth scope groups',len(re.findall(r'data-scope-group="([^"]+)"',s))==6)
check('all requested OAuth scopes preserved',len(options)==11 and {x['value'] for x in options}=={x['value'] for x in scopes})
check('PII checkbox disabled while server PII is off',any(x['value']=='customers:pii' and 'disabled' in x for x in options))
_,metadata,_=c.http(method='GET',url=urlsplit(a.url).scheme+'://'+host+'/.well-known/oauth-authorization-server')
d=json.loads(metadata)
check('OAuth metadata advertises separate scopes',all(x in d['scopes_supported'] for x in ['shop:read','shop:write','radicalmart:read','radicalmart:write']))
print(f'PASS: {checks} UI/metadata checks',flush=True)
