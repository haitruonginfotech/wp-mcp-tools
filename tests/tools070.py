#!/usr/bin/env python3
"""v0.7.0 tools: get-update-status, diagnose, updater summary in get-site-info, and (with --manage) the manage group.

Usage: python3 tests/tools070.py BASE_URL USER APP_PASSWORD [--manage]
Default mode: the manage group is OFF, so its tools must not be listed.
--manage: the group was switched on (tests/e2e.sh does that); exercises activate / deactivate / delete / rollback on a
dummy plugin and checks that WP MCP Tools protects itself. No internet is needed.
"""
import base64, json, sys, urllib.request, urllib.error

base, user, pw = sys.argv[1].rstrip('/'), sys.argv[2], sys.argv[3]
manage = '--manage' in sys.argv
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
    res = (d or {}).get('result', {})
    if 'error' in (d or {}):
        return True, d['error'].get('message', '')
    text = ''.join(c.get('text', '') for c in res.get('content', []))
    try:
        data = json.loads(text)
    except ValueError:
        data = text
    return bool(res.get('isError')), data

_, d = rpc({'jsonrpc': '2.0', 'id': 2, 'method': 'tools/list', 'params': {}}, sid)
names = [t['name'] for t in d['result']['tools']]
MANAGE = ['wpmcp-activate-plugin', 'wpmcp-deactivate-plugin', 'wpmcp-install-plugin', 'wpmcp-update-plugin',
          'wpmcp-delete-plugin', 'wpmcp-switch-theme', 'wpmcp-install-theme', 'wpmcp-update-theme']
check('new tools listed', 'wpmcp-diagnose' in names and 'wpmcp-get-update-status' in names, f'{len(names)} tools')
if manage:
    check('manage group ON: 8 tools listed', all(t in names for t in MANAGE))
else:
    check('manage group OFF by default: none listed', not any(t in names for t in MANAGE))

err, st = tool('wpmcp-get-update-status')
ok = not err and isinstance(st, dict) and 'auto_update' in st and 'history' in st and st.get('installed_version')
check('get-update-status', ok, json.dumps(st)[:200] if not ok else f"v{st['installed_version']} auto={st['auto_update']['enabled']} channel={st['update_channel']}")

err, info = tool('wpmcp-get-site-info')
check('get-site-info has updater summary', not err and isinstance(info, dict) and 'updater' in info and 'auto_update' in info['updater'])

err, dg = tool('wpmcp-diagnose')
ok = not err and isinstance(dg, dict) and dg.get('checks')
check('diagnose runs', ok, str(dg)[:200] if not ok else f"{dg['summary']} {dg['counts']}")
if ok:
    by = {c['check']: c for c in dg['checks']}
    for k in ('mcp_route', 'oauth_resource_metadata', 'oauth_server_metadata', 'oauth_registration'):
        check('diagnose ' + k, by.get(k, {}).get('status') == 'ok', json.dumps(by.get(k))[:200])
    check('diagnose gives outside curl commands', bool((dg.get('test_from_outside') or {}).get('commands')))

if manage:
    # Dummy plugins created by tests/e2e.sh.
    err, r = tool('wpmcp-activate-plugin', {'plugin': 'wpmcp-dummy'})
    check('activate dummy plugin', not err and r.get('active') is True and r.get('changed') is True, str(r)[:160])
    err, r = tool('wpmcp-activate-plugin', {'plugin': 'wpmcp-dummy'})
    check('activate again is a no-op', not err and r.get('changed') is False)
    err, r = tool('wpmcp-delete-plugin', {'plugin': 'wpmcp-dummy'})
    check('delete refuses active plugin', err and 'Deactivate' in str(r), str(r)[:120])
    err, r = tool('wpmcp-deactivate-plugin', {'plugin': 'wpmcp-dummy'})
    check('deactivate dummy plugin', not err and r.get('active') is False and r.get('changed') is True, str(r)[:160])
    err, r = tool('wpmcp-activate-plugin', {'plugin': 'wpmcp-fatal'})
    check('fatal plugin is rolled back', err and 'deactivated again' in str(r), str(r)[:200])
    _, pl = tool('wpmcp-list-plugins')
    fatal = [p for p in pl['plugins'] if p['file'].startswith('wpmcp-fatal/')]
    check('fatal plugin is inactive afterwards', fatal and fatal[0]['active'] is False)
    err, r = tool('wpmcp-delete-plugin', {'plugin': 'wpmcp-fatal'})
    check('delete inactive plugin', not err and r.get('deleted') is True, str(r)[:160])
    err, r = tool('wpmcp-deactivate-plugin', {'plugin': 'wp-mcp-tools'})
    check('cannot deactivate itself', err and 'itself' in str(r), str(r)[:120])
    err, r = tool('wpmcp-delete-plugin', {'plugin': 'wp-mcp-tools'})
    check('cannot delete itself', err and 'itself' in str(r), str(r)[:120])
    err, r = tool('wpmcp-update-plugin', {'plugin': 'wp-mcp-tools'})
    check('cannot update itself through MCP', err and 'Dashboard' in str(r), str(r)[:120])
    err, r = tool('wpmcp-deactivate-plugin', {'plugin': 'no-such-plugin'})
    check('unknown plugin -> error', err)
    err, r = tool('wpmcp-install-plugin', {'slug': 'classic-editor'})
    check('install without internet fails cleanly', err, str(r)[:120])
    err, r = tool('wpmcp-switch-theme', {'theme': 'no-such-theme'})
    check('switch to unknown theme -> error', err)

print('RESULT:', 'OK' if not fails else 'FAILED: ' + ', '.join(fails))
sys.exit(1 if fails else 0)
