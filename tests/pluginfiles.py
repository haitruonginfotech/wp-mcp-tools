#!/usr/bin/env python3
"""v0.8.0 plugin-file tools.

Usage: python3 tests/pluginfiles.py BASE_URL USER APP_PASSWORD [--on]
Default: both plugin groups are OFF, so none of the tools may be listed.
--on:    tests/pluginfiles.sh switched the groups on and allowed "wpmcp-edit" (active, loads lib.php) and "wpmcp-dummy";
         "wpmcp-fatal" is installed but NOT allowed. Exercises write / edit / lint / rollback / traversal / self-protection.
"""
import base64, json, sys, urllib.request

base, user, pw = sys.argv[1].rstrip('/'), sys.argv[2], sys.argv[3]
on = '--on' in sys.argv
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


_, d = rpc({'jsonrpc': '2.0', 'id': 2, 'method': 'tools/list', 'params': {}}, sid)
names = [t['name'] for t in d['result']['tools']]
TOOLS = ['wpmcp-' + n for n in ('list-editable-plugins', 'list-plugin-files', 'read-plugin-file', 'search-plugin-files',
         'write-plugin-file', 'edit-plugin-file', 'delete-plugin-file', 'list-plugin-backups', 'restore-plugin-backup')]

if not on:
    check('plugin groups OFF by default: none listed', not any(t in names for t in TOOLS))
else:
    check('plugin groups ON: 9 tools listed', all(t in names for t in TOOLS))

    r = tool('wpmcp-list-editable-plugins')
    ed = {p['plugin']: p for p in r[1]['plugins']} if not r[0] else {}
    check('list: wpmcp-edit editable', ed.get('wpmcp-edit', {}).get('editable') is True)
    check('list: wpmcp-fatal not editable', ed.get('wpmcp-fatal', {}).get('editable') is False)
    check('list: wp-mcp-tools never editable', ed.get('wp-mcp-tools', {}).get('editable') is False and ed['wp-mcp-tools']['self'] is True)

    P = 'wpmcp-edit'
    r = tool('wpmcp-list-plugin-files', {'plugin': P})
    check('list-plugin-files', not r[0] and any(f['path'] == 'wpmcp-edit.php' for f in r[1]['files']))

    # create
    GOOD = "<?php\nfunction wpmcp_edit_hello() { return 'hello'; }\n"
    r = tool('wpmcp-write-plugin-file', {'plugin': P, 'path': 'lib.php', 'content': GOOD})
    check('write creates file', not r[0] and r[1].get('created') is True, str(r[1])[:150])
    h1 = r[1].get('hash') if not r[0] else None
    r = tool('wpmcp-read-plugin-file', {'plugin': P, 'path': 'lib.php'})
    check('read returns content + hash', not r[0] and r[1]['content'] == GOOD and r[1]['hash'] == h1)

    # edit + backup
    r = tool('wpmcp-edit-plugin-file', {'plugin': P, 'path': 'lib.php', 'old_string': "'hello'", 'new_string': "'hi'"})
    check('edit ok with backup', not r[0] and r[1].get('backup'), str(r[1])[:150])
    bk = r[1].get('backup') if not r[0] else ''
    r = tool('wpmcp-edit-plugin-file', {'plugin': P, 'path': 'lib.php', 'old_string': 'nope-not-there', 'new_string': 'x'})
    check('edit missing string refused', err_has(r, 'not found'))

    # conflict
    r = tool('wpmcp-write-plugin-file', {'plugin': P, 'path': 'lib.php', 'content': GOOD, 'expected_hash': 'deadbeef'})
    check('stale expected_hash refused', err_has(r, 'changed'))

    # lint
    cur = tool('wpmcp-read-plugin-file', {'plugin': P, 'path': 'lib.php'})[1]
    r = tool('wpmcp-write-plugin-file', {'plugin': P, 'path': 'lib.php', 'content': "<?php\nif ( \n"})
    check('PHP syntax error refused', err_has(r, 'syntax'))
    after = tool('wpmcp-read-plugin-file', {'plugin': P, 'path': 'lib.php'})[1]
    check('file unchanged after lint failure', after['hash'] == cur['hash'])

    # fatal -> rollback (lib.php is required by the active plugin)
    FATAL = "<?php\nadd_action( 'init', function () { wpmcp_undefined_function(); } );\n"
    r = tool('wpmcp-write-plugin-file', {'plugin': P, 'path': 'lib.php', 'content': FATAL})
    check('fatal error rolled back automatically', err_has(r, 'rolled back'), str(r[1])[:200])
    after = tool('wpmcp-read-plugin-file', {'plugin': P, 'path': 'lib.php'})[1]
    check('previous version restored', after['hash'] == cur['hash'])

    # path / extension / location policy
    for label, args in (
        ('traversal to sibling plugin', {'plugin': P, 'path': '../wpmcp-dummy/x.php', 'content': '<?php\n'}),
        ('traversal to wp-config', {'plugin': P, 'path': '../../../wp-config.php', 'content': '<?php\n'}),
        ('backslash traversal', {'plugin': P, 'path': '..\\..\\x.php', 'content': '<?php\n'}),
        ('plugin param with slash', {'plugin': '../wpmcp-dummy', 'path': 'x.php', 'content': '<?php\n'}),
        ('disallowed extension', {'plugin': P, 'path': 'evil.phtml', 'content': 'x'}),
        ('hidden file', {'plugin': P, 'path': '.htaccess', 'content': 'x'}),
        ('vendor dir read-only', {'plugin': P, 'path': 'vendor/x.php', 'content': '<?php\n'}),
    ):
        r = tool('wpmcp-write-plugin-file', args)
        check('refused: ' + label, r[0], json.dumps(r[1])[:120])

    # not on the editable list / self protection
    r = tool('wpmcp-write-plugin-file', {'plugin': 'wpmcp-fatal', 'path': 'x.php', 'content': '<?php\n'})
    check('plugin not on editable list refused', err_has(r, 'not on the editable list'))
    for slug in ('wp-mcp-tools', 'WP-MCP-Tools'):
        r = tool('wpmcp-write-plugin-file', {'plugin': slug, 'path': 'wp-mcp-tools.php', 'content': '<?php\n'})
        check(f'self write refused ({slug})', r[0], json.dumps(r[1])[:120])
    r = tool('wpmcp-delete-plugin-file', {'plugin': 'wp-mcp-tools', 'path': 'wp-mcp-tools.php'})
    check('self delete refused', r[0])
    r = tool('wpmcp-edit-plugin-file', {'plugin': 'wp-mcp-tools', 'path': 'includes/class-plugin.php', 'old_string': 'MODULES', 'new_string': 'X', 'all': True})
    check('self edit refused', r[0])

    # reading is allowed for non-editable plugins, but only inside the plugin
    r = tool('wpmcp-read-plugin-file', {'plugin': 'wpmcp-fatal', 'path': 'wpmcp-fatal.php'})
    check('read of non-editable plugin works', not r[0] and 'Plugin Name' in r[1]['content'])
    r = tool('wpmcp-read-plugin-file', {'plugin': P, 'path': '../../../wp-config.php'})
    check('read traversal refused', r[0])
    r = tool('wpmcp-read-plugin-file', {'plugin': P, 'path': 'link.php'})
    check('read through symlink to wp-config refused', r[0], json.dumps(r[1])[:120])
    r = tool('wpmcp-write-plugin-file', {'plugin': P, 'path': 'link.php', 'content': '<?php\n'})
    check('write through symlink refused', r[0])
    r = tool('wpmcp-search-plugin-files', {'plugin': P, 'query': 'wpmcp_edit_hello'})
    check('search finds code', not r[0] and r[1]['count'] >= 1)

    # delete / backups / restore
    r = tool('wpmcp-delete-plugin-file', {'plugin': P, 'path': 'wpmcp-edit.php'})
    check('main plugin file cannot be deleted', err_has(r, 'cannot be deleted'))
    r = tool('wpmcp-delete-plugin-file', {'plugin': P, 'path': 'lib.php'})
    check('delete other file ok (backup kept)', not r[0] and r[1].get('deleted') and r[1].get('backup'), str(r[1])[:150])
    r = tool('wpmcp-list-plugin-backups', {'plugin': P, 'path': 'lib.php'})
    check('list-plugin-backups', not r[0] and len(r[1]['backups']) >= 2, str(len(r[1].get('backups', []))) if not r[0] else str(r[1]))
    r = tool('wpmcp-restore-plugin-backup', {'backup': bk})
    check('restore backup', not r[0] and r[1].get('restored_from') == bk, str(r[1])[:150])
    r = tool('wpmcp-restore-plugin-backup', {'backup': 'wpmcp-fatal/x.php.20200101-000000-abcd.bak'})
    check('restore for non-editable plugin refused', r[0])
    r = tool('wpmcp-restore-plugin-backup', {'backup': '../../x.20200101-000000-abcd.bak'})
    check('restore traversal refused', r[0])

print('\n%s' % ('ALL OK' if not fails else 'FAILED: ' + ', '.join(fails)))
sys.exit(1 if fails else 0)
