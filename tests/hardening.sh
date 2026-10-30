# Sourced by tests/e2e.sh (needs $WP $WORK $PORT $STATUS). Registration limits, consent page, proxy transport.
B="http://localhost:$PORT"
reg() { curl -s -o "$WORK/reg.body" -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d "$1" "$B/wpmcp-oauth/register" ; }
ENDPOINT_BODY='{"client_name":"x","redirect_uris":["https://a.example/1","https://a.example/2","https://a.example/3","https://a.example/4","https://a.example/5","https://a.example/6"]}'
C1=$(reg "$ENDPOINT_BODY")
LONG=$(printf 'a%.0s' $(seq 1 600))
C2=$(reg "{\"client_name\":\"x\",\"redirect_uris\":[\"https://a.example/$LONG\"]}")
C3=$(reg '{"client_name":"x","redirect_uris":["https://a.example/cb"]}')
[ "$C1" = "400" ] && echo "PASS hardening: more than 5 redirect URIs refused" || { echo "FAIL hardening: 6 redirect URIs gave $C1"; STATUS=1; }
[ "$C2" = "400" ] && echo "PASS hardening: overlong redirect URI refused" || { echo "FAIL hardening: long redirect URI gave $C2"; STATUS=1; }
case "$C3" in 200|201) echo "PASS hardening: a normal registration still works";; *) echo "FAIL hardening: normal registration gave $C3"; STATUS=1;; esac
if node -e "const p=require('$PLUGIN_DIR/bin/wpmcp-proxy.cjs');const e={WP_USERNAME:'u',WP_APP_PASSWORD:'p'};let r=[];try{p.configure({...e,WP_URL:'http://example.com'});r.push('bad')}catch(x){r.push('ok')}try{p.configure({...e,WP_URL:'http://localhost:8080'});r.push('ok')}catch(x){r.push('bad')}try{p.configure({...e,WP_URL:'https://example.com'});r.push('ok')}catch(x){r.push('bad')}process.exit(r.join()==='ok,ok,ok'?0:1)"; then echo "PASS hardening: proxy refuses http:// to a remote host"; else echo "FAIL hardening: proxy transport check"; STATUS=1; fi
