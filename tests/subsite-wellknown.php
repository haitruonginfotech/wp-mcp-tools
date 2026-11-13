<?php
/**
 * Sub-folder installs: which /.well-known/oauth-* requests are answered on behalf of a WordPress in /demo.
 * Stubs only, no WordPress needed:  php tests/subsite-wellknown.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WPMCP_ROUTE_NAMESPACE', 'wp-mcp-tools' );
define( 'WPMCP_ROUTE', 'mcp' );
$docroot = sys_get_temp_dir() . '/wpmcp-docroot-' . getmypid();
mkdir( $docroot . '/demo', 0777, true );
touch( $docroot . '/demo/wp-load.php' );
mkdir( $docroot . '/plain', 0777, true ); // A folder that is not a WordPress.
$_SERVER['DOCUMENT_ROOT'] = $docroot;
$HOME_URL = 'https://saap.org.sg';
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function wp_unslash( $v ) { return $v; }
function home_url( $p = '' ) { global $HOME_URL; return $HOME_URL . $p; }
function rest_url( $p = '' ) { global $HOME_URL; return $HOME_URL . '/wp-json/' . $p; }
function rest_get_url_prefix() { return 'wp-json'; }
function apply_filters( $n, $v ) { return $v; }
class WPMCP_Settings { static function get( $k ) { return ''; } static function mcp_enabled() { return true; } }
require __DIR__ . '/../includes/oauth/class-oauth-server.php';

$fail = 0;
function ok( $c, $n ) { global $fail; echo ( $c ? 'ok   ' : 'FAIL ' ) . $n . "\n"; if ( ! $c ) { ++$fail; } }
function at( $uri ) { $_SERVER['REQUEST_URI'] = $uri; return WPMCP_OAuth_Server::endpoint_from_request(); }

// Root site (home https://saap.org.sg): requests naming /demo
ok( 'subsite-resource-metadata' === at( '/.well-known/oauth-protected-resource/demo/wp-json/wp-mcp-tools/mcp' ), 'root: protected-resource for /demo is answered on behalf' );
ok( 'subsite-ignore' === at( '/.well-known/oauth-authorization-server/demo/wpmcp-oauth' ), 'root: AS metadata for /demo/wpmcp-oauth is NOT answered (404)' );
ok( 'subsite-ignore' === at( '/.well-known/openid-configuration/demo/wpmcp-oauth' ), 'root: openid metadata for /demo/wpmcp-oauth is NOT answered (404)' );
ok( 'subsite-ignore' === at( '/.well-known/oauth-authorization-server/demo' ), 'root: AS metadata for issuer /demo is NOT answered (404)' );
ok( 'resource-metadata' === at( '/.well-known/oauth-protected-resource/wp-json/wp-mcp-tools/mcp' ), 'root: its own suffixed URL is still ours' );
ok( 'resource-metadata' === at( '/.well-known/oauth-protected-resource' ), 'root: plain protected-resource still ours' );
ok( 'server-metadata' === at( '/.well-known/oauth-authorization-server' ), 'root: plain AS metadata still ours' );
ok( 'server-metadata' === at( '/.well-known/oauth-authorization-server/wpmcp-oauth' ), 'root: its own path issuer still ours' );
ok( 'server-metadata' === at( '/wpmcp-oauth/.well-known/openid-configuration' ), 'root: its own openid under issuer path still ours' );
ok( 'resource-metadata' === at( '/.well-known/oauth-protected-resource/plain/wp-json/wp-mcp-tools/mcp' ), 'folder without WordPress: legacy behaviour' );
ok( 'resource-metadata' === at( '/.well-known/oauth-protected-resource/nothere/wp-json/wp-mcp-tools/mcp' ), 'missing folder: legacy behaviour' );
ok( '' === at( '/demo/' ), 'ordinary URL: not ours' );

$_SERVER['REQUEST_URI'] = '/.well-known/oauth-protected-resource/demo/wp-json/wp-mcp-tools/mcp';
$m = WPMCP_OAuth_Server::subsite_resource_metadata( '/demo' );
ok( 'https://saap.org.sg/demo/wp-json/wp-mcp-tools/mcp' === $m['resource'], 'metadata: resource is the /demo MCP URL' );
ok( array( 'https://saap.org.sg/demo/wpmcp-oauth' ) === $m['authorization_servers'], 'metadata: issuer is /demo/wpmcp-oauth' );

// The /demo site itself (home https://saap.org.sg/demo): its own routes
$HOME_URL = 'https://saap.org.sg/demo';
ok( 'server-metadata' === at( '/demo/wpmcp-oauth/.well-known/openid-configuration' ), '/demo: path-issuer openid metadata is its own' );
ok( 'resource-metadata' === at( '/demo/wpmcp-oauth/resource-metadata' ) || 'resource-metadata' === at( '/demo/wpmcp-oauth/resource-metadata' ), '/demo: resource-metadata endpoint is its own' );

system( 'rm -rf ' . escapeshellarg( $docroot ) );
echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
