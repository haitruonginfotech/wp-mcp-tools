#!/usr/bin/env python3
"""OAuth pages (approval + error pages) and the upload-link page.

Usage: python3 tests/oauth_pages.py BASE_URL WP_USER WP_LOGIN_PASSWORD APP_PASSWORD
Checks the HTML of: Unknown application, Invalid redirect address, the approval page (logged in), Session expired,
that Approve still ends in a redirect with code + state, and that the upload link page keeps its form ids.
"""
import base64, http.cookiejar, json, re, sys, urllib.error, urllib.parse, urllib.request

base, user, login_pw, app_pw = sys.argv[1].rstrip('/'), sys.argv[2], sys.argv[3], sys.argv[4]
fails = []

def check(name, ok, info=''):
    print(('PASS ' if ok else 'FAIL ') + name + (f' — {info}' if info else ''))
    if not ok:
        fails.append(name)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None

jar = http.cookiejar.CookieJar()
op = urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(jar))

def req(url, data=None, headers=None, method=None):
    r = urllib.request.Request(url, data=data, headers=headers or {}, method=method)
    try:
        with op.open(r, timeout=60) as resp:
            return resp.status, dict(resp.headers), resp.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode('utf-8', 'replace')

REDIRECT = 'http://localhost:9999/callback'
CH = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'

# Dynamic client registration.
st, _, body = req(base + '/wpmcp-oauth/register', json.dumps({
    'client_name': 'Pages Test App', 'redirect_uris': [REDIRECT], 'token_endpoint_auth_method': 'none'}).encode(),
    {'Content-Type': 'application/json'}, 'POST')
cid = json.loads(body).get('client_id') if st in (200, 201) else None
check('register client', bool(cid), f'HTTP {st}')

def auth_url(client_id, redirect=REDIRECT):
    q = {'response_type': 'code', 'client_id': client_id, 'redirect_uri': redirect, 'code_challenge': CH,
         'code_challenge_method': 'S256', 'state': 'xyz'}
    return base + '/wpmcp-oauth/authorize?' + urllib.parse.urlencode(q)

st, _, html = req(auth_url('does-not-exist'))
check('Unknown application page', st == 400 and 'Unknown application' in html and 'What to do' in html and 'role="alert"' in html, f'HTTP {st}')
check('error page is self-contained', 'https://fonts.' not in html and '<script' not in html)

st, _, html = req(auth_url(cid, 'http://evil.example/cb'))
check('Invalid redirect page', st == 400 and 'Invalid redirect address' in html and 'What to do' in html, f'HTTP {st}')

st, hd, _ = req(auth_url(cid))
check('logged out -> redirect to wp-login', st == 302 and 'wp-login.php' in {k.lower(): v for k, v in hd.items()}.get('location', ''), f'HTTP {st}')

# Log in (cookie jar).
req(base + '/wp-login.php')
data = urllib.parse.urlencode({'log': user, 'pwd': login_pw, 'wp-submit': 'Log In', 'redirect_to': base + '/wp-admin/', 'testcookie': '1'}).encode()
st, _, _ = req(base + '/wp-login.php', data, {'Content-Type': 'application/x-www-form-urlencoded'}, 'POST')
check('WordPress login', st in (302, 200) and any(c.name.startswith('wordpress_logged_in') for c in jar), f'HTTP {st}')

st, _, html = req(auth_url(cid))
check('approval page renders', st == 200 and 'Connect Pages Test App to' in html and 'name="wpmcp_decision" value="approve"' in html and 'name="wpmcp_decision" value="deny"' in html, f'HTTP {st}')
check('approval page: signed-in user + permissions', 'Signed in' in html and user in html and 'class="perms"' in html and 'Changes files or plugins' in html)
check('approval page: redirect host shown', 'localhost' in html and 'Only approve apps you started connecting yourself' in html)
check('approval page: dark mode + mobile', 'prefers-color-scheme:dark' in html and 'max-width:560px' in html and 'name="viewport"' in html)
check('approval page: no external resources', not re.search(r'(src|href)="https?://(?!localhost)', html.replace(base, '')) and '<script' not in html)

import html as _h
fields = {}
for tag in re.findall(r'<input[^>]*type="hidden"[^>]*>', html):  # attribute order differs (wp_nonce_field puts id first)
    n, v = re.search(r'name="([^"]*)"', tag), re.search(r'value="([^"]*)"', tag)
    if n:
        fields[n.group(1)] = _h.unescape(v.group(1)) if v else ''
check('approval form has nonce + redirect_uri', '_wpnonce' in fields and fields.get('redirect_uri') == REDIRECT, ','.join(fields))

bad = dict(fields, _wpnonce='nope', wpmcp_decision='approve')
st, _, html2 = req(base + '/wpmcp-oauth/authorize', urllib.parse.urlencode(bad).encode(), {'Content-Type': 'application/x-www-form-urlencoded'}, 'POST')
check('bad nonce -> Session expired page', st == 400 and 'Session expired' in html2 and 'What to do' in html2, f'HTTP {st}')

deny = dict(fields, wpmcp_decision='deny')
st, hd, _ = req(base + '/wpmcp-oauth/authorize', urllib.parse.urlencode(deny).encode(), {'Content-Type': 'application/x-www-form-urlencoded'}, 'POST')
loc = {k.lower(): v for k, v in hd.items()}.get('location', '')
check('Deny redirects with access_denied + state', st == 302 and loc.startswith(REDIRECT) and 'error=access_denied' in loc and 'state=xyz' in loc, loc[:120])

ok = dict(fields, wpmcp_decision='approve')
st, hd, _ = req(base + '/wpmcp-oauth/authorize', urllib.parse.urlencode(ok).encode(), {'Content-Type': 'application/x-www-form-urlencoded'}, 'POST')
loc = {k.lower(): v for k, v in hd.items()}.get('location', '')
check('Approve redirects with code + state', st == 302 and loc.startswith(REDIRECT) and 'code=' in loc and 'state=xyz' in loc, loc[:120])

# Upload link page (shares the page layout).
auth = 'Basic ' + base64.b64encode(f'{user}:{app_pw}'.encode()).decode()
H = {'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream', 'MCP-Protocol-Version': '2025-06-18', 'Authorization': auth}
def rpc(payload, sid=None):
    h = dict(H)
    if sid:
        h['Mcp-Session-Id'] = sid
    r = urllib.request.Request(base + '/wp-json/wp-mcp-tools/mcp', data=json.dumps(payload).encode(), headers=h, method='POST')
    with urllib.request.urlopen(r, timeout=60) as resp:
        t = resp.read().decode()
        return dict(resp.headers), (json.loads(t) if t.strip() else None)
hd, _ = rpc({'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {'protocolVersion': '2025-06-18', 'capabilities': {}, 'clientInfo': {'name': 't', 'version': '1'}}})
sid = {k.lower(): v for k, v in hd.items()}.get('mcp-session-id')
rpc({'jsonrpc': '2.0', 'method': 'notifications/initialized'}, sid)
_, d = rpc({'jsonrpc': '2.0', 'id': 2, 'method': 'tools/call', 'params': {'name': 'wpmcp-create-upload-session', 'arguments': {'max_files': 2}}}, sid)
txt = ''.join(c.get('text', '') for c in d['result']['content'])
url = json.loads(txt).get('upload_url') if not d['result'].get('isError') else None
check('create upload link', bool(url), txt[:100])
if url:
    anon = urllib.request.build_opener(NoRedirect)
    try:
        with anon.open(url, timeout=30) as r:
            st, html = r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        st, html = e.code, e.read().decode()
    check('upload page keeps form ids', st == 200 and 'id="wpmcp-files"' in html and 'id="wpmcp-submit"' in html and 'Upload files' in html, f'HTTP {st}')

print('RESULT:', 'OK' if not fails else 'FAILED: ' + ', '.join(fails))
sys.exit(1 if fails else 0)
