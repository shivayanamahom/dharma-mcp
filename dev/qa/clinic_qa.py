#!/usr/bin/env python3
"""Сквозной автотест набора «Клиника» (Dharma MCP) на тестовом сайте с Dharma Clinic.

Подготовка и уборка (из корня репозитория):
    export DHARMA_SITE=/Volumes/Work/Server/www/clinic
    php dev/qa/clinic_fixture.php seed && php dev/qa/clinic_fixture.php rows
    php dev/qa/clinic_fixture.php mode full_write        # или drafts / read_only
    python3 dev/qa/clinic_qa.py --url https://clinic.test/mcp [--mode full_write|drafts]
    php dev/qa/clinic_fixture.php cleanup

Токены читаются из файла фикстуры (0600), а не из командной строки. Записи создаются с пометкой «QA-MCP-CL» и убираются.
Код возврата 0 — всё прошло, 1 — есть ошибки.
"""
import argparse, importlib.util, json, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location('mcp_qa', os.path.join(HERE, 'mcp_qa.py'))
q = importlib.util.module_from_spec(spec); spec.loader.exec_module(q)
check = q.check


def j(x):
    return json.dumps(x, ensure_ascii=False)


def run(a):
    fx = json.load(open(os.environ.get('MCP_QA_FILE', '/tmp/dharma-mcp-clinic-qa.json')))
    full, ro, nopii, rs = (q.Client(a.url, fx[k], True) for k in ('full', 'read', 'nopii', 'restricted'))
    P = 'QA-MCP-CL '

    q.section('Протокол и набор')
    code, d, _ = full.rpc('tools/list')
    names = [t['name'] for t in d['result']['tools']]
    mine = [n for n in names if n.startswith(('clinic.', 'leads.', 'reviews.'))]
    check('набор виден: 18 инструментов клиники' if a.mode != 'read_only' else 'набор виден (чтение)', len(mine) >= (18 if a.mode != 'read_only' else 8), str(len(mine)))
    check('у инструментов записи есть confirm_code', all('confirm_code' in t['inputSchema']['properties'] for t in d['result']['tools'] if t['name'] in mine and not t.get('annotations', {}).get('readOnlyHint')))
    err, t = full.call('clinic.info')
    check('clinic.info: версия, счётчики и очередь', not err and t.get('counts', {}).get('doctor') and 'queue' in t, j(t)[:150])
    check('clinic.info: адрес филиала не закрыт маской', '[REDACTED]' not in j(t.get('main_branch')), j(t.get('main_branch'))[:100])
    err, t = full.call('clinic.entities')
    keys = [e['entity'] for e in t.get('entities', [])]
    check('clinic.entities: 10 сущностей', len(keys) == 10, str(keys))
    for k in keys:
        err, d1 = full.call('clinic.entities', {'entity': k})
        check(f'  поля формы «{k}»', not err and len(d1.get('fields', [])) > 3, j(d1)[:120])
        err, d2 = full.call('clinic.entity_list', {'entity': k, 'limit': 2})
        check(f'  список «{k}»', not err and d2.get('total', 0) > 0, j(d2)[:120])

    q.section('Защита')
    check('токен без прав на компонент: запрещено', rs.call('clinic.entity_list', {'entity': 'doctor'})[1].get('error') == 'forbidden')
    check('токен без прав на компонент: запись запрещена', a.mode == 'read_only' or rs.call('clinic.entity_update', {'entity': 'doctor', 'id': 5, 'data': {'intro': 'x'}})[1].get('error') in ('forbidden',))
    ro_names = [t['name'] for t in ro.rpc('tools/list')[1]['result']['tools']]
    check('токен только на чтение не видит инструментов записи', not any(n in ro_names for n in ('clinic.entity_create', 'clinic.entity_update', 'clinic.settings_set', 'leads.set_status', 'reviews.moderate')))
    check('секреты настроек скрыты', full.call('clinic.settings', {'search': 'token'})[1].get('settings', {}).get('notify_telegram_token') in ('[hidden]', None))
    check('секрет нельзя записать', a.mode == 'read_only' or full.call('clinic.settings_set', {'settings': {'notify_telegram_token': 'x'}})[1].get('error') == 'forbidden')
    check('page_check отказывает чужому адресу', full.call('clinic.page_check', {'path': '//evil.example/x'})[1].get('error') == 'validation')
    err, t = nopii.call('leads.list', {'search': 'QA-MCP-CL'})
    check('заявки: без области персональных данных всё скрыто', t.get('items') and all(v == '[REDACTED]' for v in (t['items'][0]['phone'], t['items'][0]['email'], t['items'][0]['patient_name'])) and 'hidden' in str(t['items'][0]['message']), j(t)[:200])

    if a.mode == 'read_only':
        check('режим read_only: инструментов записи нет', not any(n in names for n in ('clinic.entity_create', 'clinic.entity_update', 'leads.set_status')))
        return

    q.section('Сущности: создание, правка, публикация, удаление')
    cases = {
        'direction': {'title': P + 'Направление', 'parent_id': 2, 'age_group': 3},
        'service': {'title': P + 'Услуга', 'primary_direction_id': 3, 'directions': [3], 'age_group': 3, 'schema_type': 'Service'},
        'doctor': {'title': P + 'Врач', 'position': 'Тест', 'age_group': 3, 'directions': [5], 'intro': 'Тест'},
        'disease': {'title': P + 'Болезнь', 'icd_code': 'Z00', 'intro': 'Тест'},
        'branch': {'title': P + 'Филиал', 'address': 'ул. Тестовая, 1', 'city': 'Пермь'},
        'price_group': {'title': P + 'Группа'},
        'price_item': {'title': P + 'Позиция', 'price': 100, 'group_id': 1},
        'question': {'title': P + 'Вопрос', 'answer': '<p>Ответ</p>'},
    }
    made = {}
    for e, data in cases.items():
        t, err, r = full.confirmed('clinic.entity_create', {'entity': e, 'data': data})
        ok = check(f'создание «{e}» (черновик)', r.get('created') and r.get('published') is False, j(r)[:160])
        if not ok:
            continue
        i = made[e] = r['id']
        t, err, r2 = full.confirmed('clinic.entity_update', {'entity': e, 'id': i, 'data': {'state': True}})
        check(f'  публикация «{e}»', r2.get('published') is True, j(r2)[:140])
        full.confirmed('clinic.entity_update', {'entity': e, 'id': i, 'data': {'state': False}})
    t, err, r = full.confirmed('clinic.entity_update', {'entity': 'doctor', 'id': made.get('doctor', 0), 'data': {'intro': 'Правка'}})
    err, g = full.call('clinic.entity_get', {'entity': 'doctor', 'id': made.get('doctor', 0)})
    check('правка врача не сбросила связи', g.get('record', {}).get('intro') == 'Правка' and g['record'].get('directions') == [5], j(g.get('record', {}).get('directions')))
    check('форму сайта нельзя удалить', full.confirmed('clinic.entity_delete', {'entity': 'site_form', 'id': 1})[2].get('error') == 'validation')
    for e, i in made.items():
        t, err, r = full.confirmed('clinic.entity_delete', {'entity': e, 'id': i, 'permanent': True})
        check(f'удаление «{e}»', r.get('deleted') is True, j(r)[:140])

    q.section('Заявки и отзывы')
    lid, rid = fx.get('lead_id'), fx.get('review_id')
    err, g0 = full.call('leads.get', {'id': lid})
    target = 3 if g0['lead']['status_id'] == 2 else 2
    t, err, r = full.confirmed('leads.set_status', {'lead_ids': [lid], 'status_id': target, 'comment': 'QA'})
    check('leads.set_status', r.get('changed') == 1, j(r))
    err, g = full.call('leads.get', {'id': lid})
    check('leads.get: история статуса', len(g.get('history', [])) >= 1, j(g)[:160])
    t, err, r = full.confirmed('leads.create_question', {'lead_id': lid})
    check('leads.create_question: черновик (или уже был)', bool(r.get('question_id')) and r.get('published') in (False, None), j(r))
    t, err, r = full.confirmed('leads.create_question', {'lead_id': lid})
    check('leads.create_question: повтор без дубля', r.get('created') is False, j(r))
    t, err, r = full.confirmed('reviews.moderate', {'review_ids': [rid], 'action': 'approve'})
    check('reviews.moderate: одобрение', r.get('done') is True, j(r))
    t, err, r = full.confirmed('reviews.reply', {'review_id': rid, 'reply': 'Спасибо за отзыв! Свяжитесь с администратором.'})
    check('reviews.reply', r.get('updated') is True, j(r))
    err, g = full.call('reviews.list', {'status': 'published', 'limit': 100})
    check('reviews.list: ответ виден, текст в обёртке', any(it['id'] == rid and it['reply'] and 'user_content' in it['text'] for it in g.get('items', [])), j(g)[:160])
    full.confirmed('reviews.moderate', {'review_ids': [rid], 'action': 'trash'})

    q.section('Настройки и цены')
    err, s0 = full.call('clinic.settings', {'search': 'reviews_limit'})
    old = s0['settings']['reviews_limit']
    t, err, r = full.confirmed('clinic.settings_set', {'settings': {'reviews_limit': int(old) + 1}})
    check('clinic.settings_set', r.get('updated') is True, j(r))
    check('число принимается только числом', full.call('clinic.settings_set', {'settings': {'reviews_limit': 'abc'}})[1].get('error') == 'validation')
    full.confirmed('clinic.settings_set', {'settings': {'reviews_limit': int(old)}})
    check('настройка возвращена', full.call('clinic.settings', {'search': 'reviews_limit'})[1]['settings']['reviews_limit'] == old)
    ids = []
    for n, pr in ((1, 1000), (2, 2000)):
        t, err, r = full.confirmed('clinic.entity_create', {'entity': 'price_item', 'data': {'title': f'{P}Позиция {n}', 'price': pr, 'group_id': 1}})
        ids.append(r.get('id'))
    t, err, r = full.confirmed('clinic.prices_batch', {'items': [{'id': ids[0], 'price': 1100}, {'id': ids[1], 'price': 2000}]})
    check('clinic.prices_batch: изменена одна из двух', r.get('updated') == 1 and r.get('unchanged') == 1, j(r))
    check('prices_batch: нулевой процент отклонён', full.call('clinic.prices_batch', {'group_id': 1, 'percent': 0})[1].get('error') == 'validation')
    for i in ids:
        full.confirmed('clinic.entity_delete', {'entity': 'price_item', 'id': i, 'permanent': True})
    err, t = full.call('clinic.page_check', {'path': '/vrachi'})
    check('clinic.page_check: страница врачей', t.get('status') == 200 and t.get('title_length', 99) <= 65 and len(t.get('h1', [])) == 1, j(t)[:200])

    if a.mode == 'drafts':
        q.section('Режим «черновики»: подтверждение')
        for n, args in (('clinic.entity_update', {'entity': 'doctor', 'id': 5, 'data': {'intro': 'x'}}), ('clinic.settings_set', {'settings': {'reviews_limit': 10}}), ('leads.set_status', {'lead_ids': [lid], 'status_id': 2}), ('reviews.moderate', {'review_ids': [rid], 'action': 'spam'}), ('clinic.entity_delete', {'entity': 'doctor', 'id': 5})):
            err, t = full.call(n, args)
            check(f'{n}: требует подтверждения', isinstance(t, dict) and t.get('status') == 'confirmation_required', j(t)[:100])


fx = {}
if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('--url', required=True)
    ap.add_argument('--mode', default='full_write', choices=['full_write', 'drafts', 'read_only'])
    args = ap.parse_args()
    fx = json.load(open(os.environ.get('MCP_QA_FILE', '/tmp/dharma-mcp-clinic-qa.json')))
    run(args)
    print(f"\nИтого: {q.PASS} прошло, {q.FAIL} не прошло" + (' — ' + '; '.join(q.FAILED) if q.FAILED else ''))
    sys.exit(1 if q.FAIL else 0)
