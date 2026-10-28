#!/usr/bin/env python3
"""Theme file tools (write / edit / delete / backups) against a running test site.

Usage: python3 tests/themefiles.py BASE_URL USER APP_PASSWORD
Guards the code that theme and plugin file tools share.
"""
import base64, json, sys, urllib.request

base, user, pw = sys.argv[1].rstrip('/'), sys.argv[2], sys.argv[3]
url = base + '/wp-json/wp-mcp-tools/mcp'
auth = 'Basic ' + base64.b64encode(f'{user}:{pw}'.encode()).decode()
fails = []
H = {'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream',
     'MCP-Protocol-Version': '2025-06-18', 'Authorization': auth}


def rpc(payload, session=None):
    h = dict(H)
    if session:
        h['Mcp-Session-Id'] = session
    req = urllib.request.Request(url, data=json.dumps(payload).encode(), headers=h, method='POST')
    with urllib.request.urlopen(req, timeout=120) as r:
        body = r.read().decode()
        return dict(r.headers), (json.loads(body) if body.strip() else None)


def check(name, ok, info=''):
    print(('PASS ' if ok else 'FAIL ') + name + (f' — {info}' if info else ''))
    if not ok:
        fails.append(name)


hd, _ = rpc({'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {
    'protocolVersion': '2025-06-18', 'capabilities': {}, 'clientInfo': {'name': 't', 'version': '1'}}})
sid = {k.lower(): v for k, v in hd.items()}.get('mcp-session-id')
rpc({'jsonrpc': '2.0', 'method': 'notifications/initialized'}, sid)
_id = [10]


def tool(name, args=None):
    _id[0] += 1
    _, d = rpc({'jsonrpc': '2.0', 'id': _id[0], 'method': 'tools/call',
                'params': {'name': name, 'arguments': args or {}}}, sid)
    if 'error' in (d or {}):
        return True, d['error'].get('message', '')
    res = (d or {}).get('result', {})
    text = ''.join(c.get('text', '') for c in res.get('content', []))
    try:
        data = json.loads(text)
    except ValueError:
        data = text
    return bool(res.get('isError')), data


def err_has(r, *words):
    return r[0] and all(w.lower() in json.dumps(r[1]).lower() for w in words)


P = 'wpmcp-test/lib.php'
GOOD = "<?php\nfunction wpmcp_theme_hello() { return 'hello'; }\n"

r = tool('wpmcp-list-theme-files')
check('list-theme-files', not r[0] and r[1]['count'] > 0 and r[1]['writable'] is True)
r = tool('wpmcp-write-theme-file', {'path': P, 'content': GOOD})
check('write creates file', not r[0] and r[1].get('created') is True, str(r[1])[:120])
h1 = r[1].get('hash') if not r[0] else None
r = tool('wpmcp-read-theme-file', {'path': P, 'start_line': 2, 'end_line': 2})
check('read line range', not r[0] and r[1]['content'].startswith('function wpmcp_theme_hello') and r[1]['lines'] == 3, str(r[1])[:120])
r = tool('wpmcp-read-theme-file', {'path': P})
check('read full + hash', not r[0] and r[1]['hash'] == h1 and r[1]['content'] == GOOD)
r = tool('wpmcp-edit-theme-file', {'path': P, 'old_string': "'hello'", 'new_string': "'hi'"})
check('edit ok with backup', not r[0] and r[1].get('backup') and r[1].get('replacements') == 1, str(r[1])[:120])
bk = r[1].get('backup') if not r[0] else ''
r = tool('wpmcp-edit-theme-file', {'path': P, 'old_string': 'function', 'new_string': 'function', 'all': False})
check('edit ambiguity / success path', not r[0] or err_has(r, 'found'))
r = tool('wpmcp-edit-theme-file', {'path': P, 'old_string': '$$$nothing$$$', 'new_string': 'x'})
check('edit missing string refused', err_has(r, 'not found'))
r = tool('wpmcp-write-theme-file', {'path': P, 'content': GOOD, 'expected_hash': 'deadbeef'})
check('stale expected_hash refused', err_has(r, 'changed'))

cur = tool('wpmcp-read-theme-file', {'path': P})[1]
r = tool('wpmcp-write-theme-file', {'path': P, 'content': "<?php\nif ( \n"})
check('PHP syntax error refused', err_has(r, 'syntax'))
r = tool('wpmcp-write-theme-file', {'path': 'wpmcp-test/data.json', 'content': '{bad json'})
check('JSON syntax error refused', err_has(r, 'json'))
check('file unchanged after lint failure', tool('wpmcp-read-theme-file', {'path': P})[1]['hash'] == cur['hash'])

# fatal in functions.php (loaded on every request) must be rolled back
fn = tool('wpmcp-read-theme-file', {'path': 'functions.php'})
orig = fn[1]['hash'] if not fn[0] else None
r = tool('wpmcp-write-theme-file', {'path': 'functions.php', 'content': "<?php\nwpmcp_undefined_function();\n"})
check('fatal in functions.php rolled back', err_has(r, 'rolled back'), str(r[1])[:160])
now = tool('wpmcp-read-theme-file', {'path': 'functions.php'})
check('functions.php restored', (orig is None and now[0]) or (not now[0] and now[1]['hash'] == orig))

for label, args in (
    ('traversal', {'path': '../../wp-config.php', 'content': '<?php\n'}),
    ('absolute-looking path', {'path': '/etc/passwd', 'content': 'x'}),
    ('disallowed extension', {'path': 'evil.phtml', 'content': 'x'}),
    ('theme not allowed', {'theme': 'not-active-theme', 'path': 'a.php', 'content': '<?php\n'}),
):
    r = tool('wpmcp-write-theme-file', args)
    check('refused: ' + label, r[0], json.dumps(r[1])[:100])
check('read traversal refused', tool('wpmcp-read-theme-file', {'path': '../../wp-config.php'})[0])
check('style.css cannot be deleted', err_has(tool('wpmcp-delete-theme-file', {'path': 'style.css'}), 'cannot be deleted'))

r = tool('wpmcp-search-theme-files', {'query': 'wpmcp_theme_hello'})
check('search finds code', not r[0] and r[1]['count'] >= 1)
r = tool('wpmcp-search-theme-files', {'query': 'wpmcp_theme_h[a-z]+', 'regex': True})
check('regex search', not r[0] and r[1]['count'] >= 1)
check('invalid regex refused', err_has(tool('wpmcp-search-theme-files', {'query': '(', 'regex': True}), 'regular'))

r = tool('wpmcp-delete-theme-file', {'path': P})
check('delete ok (backup kept)', not r[0] and r[1].get('deleted') and r[1].get('backup'))
r = tool('wpmcp-list-theme-backups', {'path': P})
check('list-theme-backups', not r[0] and len(r[1]['backups']) >= 2)
r = tool('wpmcp-restore-theme-backup', {'backup': bk})
check('restore backup', not r[0] and r[1].get('restored_from') == bk, str(r[1])[:120])
check('restore traversal refused', tool('wpmcp-restore-theme-backup', {'backup': '../../x.20200101-000000-abcd.bak'})[0])

print('\n%s' % ('ALL OK' if not fails else 'FAILED: ' + ', '.join(fails)))
sys.exit(1 if fails else 0)
