<?php
/**
 * Admin screen: MCP Tools → Connection / Tools.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

class WPMCP_Admin {

	const SLUG = 'wp-mcp-tools';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_wpmcp_oauth_signout', array( __CLASS__, 'handle_signout' ) );
		add_action( 'admin_post_wpmcp_oauth_remove', array( __CLASS__, 'handle_remove' ) );
		add_action( 'admin_post_wpmcp_app_password', array( __CLASS__, 'handle_app_password' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPMCP_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'update_option_' . WPMCP_Settings::OPTION, array( __CLASS__, 'flag_saved' ) );
		add_action( 'add_option_' . WPMCP_Settings::OPTION, array( __CLASS__, 'flag_saved' ) );
	}

	/** Remember a successful save so the next page load can say so (options.php does not on custom top-level pages). */
	public static function flag_saved(): void {
		if ( is_admin() && isset( $_POST['option_page'] ) && 'wpmcp' === $_POST['option_page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php already verified the nonce.
			set_transient( 'wpmcp_saved_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );
		}
	}

	/** Admin page hook suffix (top-level menu). */
	const HOOK = 'toplevel_page_wp-mcp-tools';

	/** Plug + spark glyph (20×20). Used for the admin menu, the page header and the admin bar. */
	const ICON_PATH = 'M6.2 2.5a1 1 0 0 1 2 0V6h-2Zm5.6 0a1 1 0 0 1 2 0V6h-2ZM4.5 7.2c0-.66.54-1.2 1.2-1.2h8.6c.66 0 1.2.54 1.2 1.2v2.3a5.5 5.5 0 0 1-4.4 5.39V16.5a2 2 0 0 1-2 2H8.5a.75.75 0 0 1 0-1.5h.6a.5.5 0 0 0 .5-.5v-1.61A5.5 5.5 0 0 1 4.5 9.5ZM10 7.6l.85 1.95 1.95.85-1.95.85L10 13.2l-.85-1.95-1.95-.85 1.95-.85Z';

	/** Inline SVG (currentColor). */
	public static function icon( string $class = '' ): string {
		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" fill-rule="evenodd" d="' . self::ICON_PATH . '"/></svg>';
	}

	/** Data-URI icon for add_menu_page (WP's svg-painter recolours the fill to match the admin scheme). */
	public static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="' . self::ICON_PATH . '"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function assets( string $hook ): void {
		if ( self::HOOK !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wpmcp-admin', WPMCP_URL . 'assets/admin.css', array(), WPMCP_VERSION );
		wp_enqueue_script( 'wpmcp-admin', WPMCP_URL . 'assets/admin.js', array(), WPMCP_VERSION, true );
	}

	public static function url( string $tab = 'connection', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'wp-mcp-tools' ) . '</a>' );
		return $links;
	}

	public static function menu(): void {
		add_menu_page( 'WP MCP Tools', 'MCP Tools', 'manage_options', self::SLUG, array( __CLASS__, 'render' ), self::menu_icon(), 81 );
	}

	public static function register(): void {
		register_setting(
			'wpmcp',
			WPMCP_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WPMCP_Settings', 'sanitize' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	private static function guard( string $action ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( $action );
		return isset( $_REQUEST['client_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client_id'] ) ) : '';
	}

	public static function handle_signout(): void {
		$client = self::guard( 'wpmcp_oauth_signout' );
		WPMCP_OAuth_Store::revoke_client_tokens( $client );
		wp_safe_redirect( self::url( 'connection', array( 'wpmcp_msg' => 'signedout' ) ) . '#connected-apps' );
		exit;
	}

	public static function handle_remove(): void {
		$client = self::guard( 'wpmcp_oauth_remove' );
		WPMCP_OAuth_Store::delete_client( $client );
		wp_safe_redirect( self::url( 'connection', array( 'wpmcp_msg' => 'removed' ) ) . '#connected-apps' );
		exit;
	}

	public static function handle_app_password(): void {
		self::guard( 'wpmcp_app_password' );
		$user = wp_get_current_user();
		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			wp_safe_redirect( self::url( 'connection', array( 'wpmcp_msg' => 'noapppw' ) ) );
			exit;
		}
		$created = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => 'MCP client (WP MCP Tools) ' . wp_date( 'Y-m-d H:i' ) )
		);
		if ( is_wp_error( $created ) ) {
			wp_safe_redirect( self::url( 'connection', array( 'wpmcp_msg' => 'noapppw' ) ) );
			exit;
		}
		// Shown once on the next page load.
		set_transient( 'wpmcp_new_app_pw_' . $user->ID, $created[0], 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( 'connection', array( 'wpmcp_msg' => 'apppw', 'wpmcp_auth' => 'app_password' ) ) . '#connect-client' );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Client instructions                                                 */
	/* ------------------------------------------------------------------ */

	private static function json( $data ): string {
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Setup steps per client and auth method.
	 * Step: [ 'title' => string, 'text' => string(html), 'code' => string, 'label' => string ].
	 *
	 * @return array<string, array{label:string, oauth:array, app_password:array}>
	 */
	private static function clients( string $name, string $url, string $basic ): array {
		$is_http   = 0 === strpos( $url, 'http://' );
		$http_flag = $is_http ? array( '--allow-http' ) : array();
		$public    = ! $is_http && ! preg_match( '~://(localhost|127\.|[^/]+\.(local|test|localhost)(:|/))~', $url );
		$local_tip = $public ? '' : '<p class="wpmcp-warn">This URL is not reachable from the internet, so cloud clients (Claude.ai, Claude Desktop connectors, ChatGPT) cannot use it. Use Claude Code, Cursor, VS Code or <strong>npx mcp-remote</strong>, or put the site behind a public HTTPS tunnel and set <em>Server URL</em>.</p>';
		$auth_hdr  = 'Authorization: Basic ' . $basic;

		$remote_oauth = array(
			'mcpServers' => array(
				$name => array(
					'command' => 'npx',
					'args'    => array_merge( array( '-y', 'mcp-remote', $url ), $http_flag ),
				),
			),
		);
		$remote_basic = array(
			'mcpServers' => array(
				$name => array(
					'command' => 'npx',
					'args'    => array_merge( array( '-y', 'mcp-remote', $url, '--header', 'Authorization:${WP_AUTH}' ), $http_flag ),
					'env'     => array( 'WP_AUTH' => 'Basic ' . $basic ),
				),
			),
		);
		$wp_remote = array(
			'mcpServers' => array(
				$name => array(
					'command' => 'npx',
					'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
					'env'     => array(
						'WP_API_URL'      => $url,
						'WP_API_USERNAME' => wp_get_current_user()->user_login,
						'WP_API_PASSWORD' => '<application-password>',
					),
				),
			),
		);
		$no_app_pw = array(
			array(
				'title' => 'Use OAuth for this client',
				'text'  => '<p>This client only supports OAuth sign-in for remote servers. Switch the authentication method above to <strong>OAuth</strong>.</p>',
			),
		);

		return array(
			'claude-desktop' => array(
				'label'        => 'Claude Desktop',
				'oauth'        => array(
					array(
						'title' => 'Option A: Custom connector (public HTTPS sites)',
						'text'  => $local_tip . '<p>Open <em>Settings → Connectors → Add custom connector</em>, enter a name and the URL below, click <em>Add</em>, then <em>Connect</em> and approve on your site.</p>',
						'label' => 'URL',
						'code'  => $url,
					),
					array(
						'title' => 'Option B: Local bridge (works for local sites)',
						'text'  => '<p><em>Settings → Developer → Edit Config</em>, add this server to <code>claude_desktop_config.json</code> and restart Claude. A browser window opens to sign in and approve. Requires Node.js.</p>',
						'label' => 'claude_desktop_config.json',
						'code'  => self::json( $remote_oauth ),
					),
				),
				'app_password' => array(
					array(
						'title' => 'Add to claude_desktop_config.json',
						'text'  => '<p><em>Settings → Developer → Edit Config</em>, add the server and restart Claude. Requires Node.js.</p>',
						'label' => 'claude_desktop_config.json (mcp-remote)',
						'code'  => self::json( $remote_basic ),
					),
					array(
						'title' => 'Alternative: WordPress remote proxy',
						'label' => 'claude_desktop_config.json (@automattic/mcp-wordpress-remote)',
						'code'  => self::json( $wp_remote ),
					),
				),
			),
			'claude-ai'      => array(
				'label'        => 'Claude.ai',
				'oauth'        => array(
					array(
						'title' => 'Add a custom connector',
						'text'  => $local_tip . '<p>On claude.ai open <em>Settings → Connectors → Add custom connector</em>. Enter a name and this URL, click <em>Add</em>, then <em>Connect</em>. You are sent to this site to log in and click <strong>Approve</strong>.</p>',
						'label' => 'Remote MCP server URL',
						'code'  => $url,
					),
				),
				'app_password' => $no_app_pw,
			),
			'claude-code'    => array(
				'label'        => 'Claude Code',
				'oauth'        => array(
					array(
						'title' => 'Add the server',
						'label' => 'Terminal',
						'code'  => 'claude mcp add --transport http ' . $name . ' ' . $url,
					),
					array(
						'title' => 'Authenticate',
						'text'  => '<p>Start <code>claude</code>, run <code>/mcp</code>, select <strong>' . esc_html( $name ) . '</strong> → <em>Authenticate</em>, then approve in the browser.</p>',
					),
				),
				'app_password' => array(
					array(
						'title' => 'Add the server with the Authorization header',
						'label' => 'Terminal',
						'code'  => 'claude mcp add --transport http ' . $name . ' ' . $url . ' --header "' . $auth_hdr . '"',
					),
				),
			),
			'cursor'         => array(
				'label'        => 'Cursor',
				'oauth'        => array(
					array(
						'title' => 'Add to ~/.cursor/mcp.json',
						'text'  => '<p>Then open <em>Settings → MCP</em> and click <em>Login</em> / <em>Needs authentication</em> next to the server.</p>',
						'label' => 'mcp.json',
						'code'  => self::json( array( 'mcpServers' => array( $name => array( 'url' => $url ) ) ) ),
					),
				),
				'app_password' => array(
					array(
						'title' => 'Add to ~/.cursor/mcp.json',
						'label' => 'mcp.json',
						'code'  => self::json( array( 'mcpServers' => array( $name => array( 'url' => $url, 'headers' => array( 'Authorization' => 'Basic ' . $basic ) ) ) ) ),
					),
				),
			),
			'vscode'         => array(
				'label'        => 'VS Code',
				'oauth'        => array(
					array(
						'title' => 'Add to .vscode/mcp.json',
						'text'  => '<p>Start the server from the file (or <em>MCP: List Servers</em>) and allow the sign-in prompt.</p>',
						'label' => 'mcp.json',
						'code'  => self::json( array( 'servers' => array( $name => array( 'type' => 'http', 'url' => $url ) ) ) ),
					),
				),
				'app_password' => array(
					array(
						'title' => 'Add to .vscode/mcp.json',
						'label' => 'mcp.json',
						'code'  => self::json( array( 'servers' => array( $name => array( 'type' => 'http', 'url' => $url, 'headers' => array( 'Authorization' => 'Basic ' . $basic ) ) ) ) ),
					),
				),
			),
			'chatgpt'        => array(
				'label'        => 'ChatGPT',
				'oauth'        => array(
					array(
						'title' => 'Create a connector',
						'text'  => $local_tip . '<p>In ChatGPT enable <em>Settings → Apps &amp; Connectors → Advanced → Developer mode</em>, then <em>Create</em>. Enter a name, this URL, authentication <strong>OAuth</strong>, and save. Approve on your site when asked.</p>',
						'label' => 'MCP server URL',
						'code'  => $url,
					),
				),
				'app_password' => $no_app_pw,
			),
			'mcp-remote'     => array(
				'label'        => 'npx mcp-remote',
				'oauth'        => array(
					array(
						'title' => 'Any STDIO client (bridge with OAuth)',
						'text'  => '<p>Runs a local bridge that opens the browser for sign-in (callback on localhost). Works for local sites. Requires Node.js.</p>',
						'label' => 'Command',
						'code'  => 'npx -y mcp-remote ' . $url . ( $is_http ? ' --allow-http' : '' ),
					),
				),
				'app_password' => array(
					array(
						'title' => 'Any STDIO client (bridge with header)',
						'label' => 'Command',
						'code'  => 'npx -y mcp-remote ' . $url . ' --header "' . $auth_hdr . '"' . ( $is_http ? ' --allow-http' : '' ),
					),
				),
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                              */
	/* ------------------------------------------------------------------ */

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab     = isset( $_GET['tab'] ) && 'tools' === $_GET['tab'] ? 'tools' : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mcp_on  = WPMCP_Settings::mcp_enabled();
		$adapter = class_exists( '\WP\MCP\Core\McpAdapter' );
		$ok      = $mcp_on && $adapter && function_exists( 'wp_register_ability' );
		$state   = $ok ? 'on' : ( $mcp_on ? 'warn' : 'off' );
		$label   = $ok ? 'Online' : ( $mcp_on ? 'Needs attention' : 'Off' );
		$active  = count( WPMCP_Registry::active_tool_slugs() );
		?>
		<div class="wrap wpmcp">
			<h1 class="screen-reader-text">WP MCP Tools</h1>
			<header class="wpmcp-hero">
				<div class="wpmcp-hero__brand">
					<span class="wpmcp-hero__logo"><?php echo self::icon( 'wpmcp-hero__glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<div>
						<p class="wpmcp-hero__title">WP MCP Tools <span class="wpmcp-ver">v<?php echo esc_html( WPMCP_VERSION ); ?></span></p>
						<p class="wpmcp-hero__sub">Connect AI agents to this WordPress site over MCP · by Hai Truong IT</p>
					</div>
				</div>
				<div class="wpmcp-hero__side">
					<span class="wpmcp-state is-<?php echo esc_attr( $state ); ?>"><i></i><?php echo esc_html( $label ); ?></span>
					<button type="submit" form="wpmcp-settings" class="wpmcp-btn wpmcp-btn--primary">Save changes</button>
				</div>
			</header>

			<nav class="wpmcp-tabs" aria-label="WP MCP Tools sections">
				<a class="wpmcp-tab <?php echo 'connection' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::url( 'connection' ) ); ?>" <?php echo 'connection' === $tab ? 'aria-current="page"' : ''; ?>><span class="dashicons dashicons-admin-links" aria-hidden="true"></span>Connection</a>
				<a class="wpmcp-tab <?php echo 'tools' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::url( 'tools' ) ); ?>" <?php echo 'tools' === $tab ? 'aria-current="page"' : ''; ?>><span class="dashicons dashicons-screenoptions" aria-hidden="true"></span>Tools <span class="wpmcp-count"><?php echo (int) $active; ?></span></a>
			</nav>

			<?php self::notices(); ?>
			<?php 'tools' === $tab ? self::render_tools() : self::render_connection(); ?>
		</div>
		<?php
	}

	private static function notices(): void {
		$msg  = isset( $_GET['wpmcp_msg'] ) ? sanitize_key( $_GET['wpmcp_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list = array(
			'signedout' => array( 'success', 'The app was signed out. It can connect again after approval.' ),
			'removed'   => array( 'success', 'The app registration and its tokens were removed.' ),
			'apppw'     => array( 'success', 'Application password created. It is filled into the configs below and will not be shown again.' ),
			'noapppw'   => array( 'error', 'Application Passwords are not available for your account (they need HTTPS or WP_ENVIRONMENT_TYPE=local).' ),
		);
		if ( isset( $list[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $list[ $msg ][0] ), esc_html( $list[ $msg ][1] ) );
		}
		$flag = 'wpmcp_saved_' . get_current_user_id();
		if ( get_transient( $flag ) ) {
			delete_transient( $flag );
			echo '<div class="notice notice-success is-dismissible wpmcp-saved"><p>Settings saved.</p></div>';
		}
	}

	private static function copy_block( string $label, string $code, bool $inline = false ): void {
		?>
		<div class="wpmcp-code<?php echo $inline ? ' wpmcp-code--inline' : ''; ?>">
			<div class="wpmcp-code__head"><span><?php echo esc_html( $label ); ?></span><button type="button" class="wpmcp-copy"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="wpmcp-copy__txt">Copy</span></button></div>
			<pre><code><?php echo esc_html( $code ); ?></code></pre>
		</div>
		<?php
	}

	private static function switch_input( string $name, bool $checked, string $label ): void {
		?>
		<label class="wpmcp-switch">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?>>
			<span class="wpmcp-switch__track" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}

	private static function stat( string $icon, string $label, string $value, string $state ): void {
		?>
		<div class="wpmcp-stat">
			<span class="wpmcp-stat__ico dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<span class="wpmcp-stat__body">
				<span class="wpmcp-stat__label"><?php echo esc_html( $label ); ?></span>
				<strong><i class="wpmcp-dot is-<?php echo esc_attr( $state ); ?>"></i><?php echo esc_html( $value ); ?></strong>
			</span>
		</div>
		<?php
	}

	private static function render_connection(): void {
		$s        = WPMCP_Settings::all();
		$opt      = WPMCP_Settings::OPTION;
		$url      = WPMCP_Settings::endpoint_url();
		$adapter  = class_exists( '\WP\MCP\Core\McpAdapter' );
		$enabled  = count( WPMCP_Registry::active_tool_slugs() );
		$total    = count( WPMCP_Registry::tools() );
		$host     = (string) wp_parse_url( $url, PHP_URL_HOST );
		$name     = sanitize_title( str_replace( '.', '-', $host ) ) ?: 'wordpress';
		$method   = isset( $_GET['wpmcp_auth'] ) ? sanitize_key( $_GET['wpmcp_auth'] ) : $s['auth_method']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$method   = in_array( $method, array( 'oauth', 'app_password' ), true ) ? $method : 'oauth';
		$user     = wp_get_current_user();
		$new_pw   = get_transient( 'wpmcp_new_app_pw_' . $user->ID );
		if ( $new_pw ) {
			delete_transient( 'wpmcp_new_app_pw_' . $user->ID );
		}
		$basic    = $new_pw ? base64_encode( $user->user_login . ':' . $new_pw ) : '<base64 of username:application-password>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$clients  = self::clients( $name, $url, $basic );
		$oauth_on = ! empty( $s['oauth_enabled'] );
		$mcp_on   = ! empty( $s['enabled'] );
		?>
		<div class="wpmcp-stats">
			<?php
			self::stat( 'dashicons-networking', 'MCP server', $mcp_on ? 'Enabled' : 'Off', $mcp_on ? 'ok' : 'off' );
			self::stat( 'dashicons-admin-plugins', 'MCP Adapter', WPMCP_Plugin::adapter_label(), $adapter ? 'ok' : 'bad' );
			self::stat( 'dashicons-lock', 'OAuth sign-in', $oauth_on ? 'Enabled' : 'Disabled', $oauth_on ? 'ok' : 'off' );
			self::stat( 'dashicons-screenoptions', 'Tools enabled', $enabled . ' / ' . $total, $enabled ? 'ok' : 'off' );
			?>
		</div>

		<?php if ( ! $mcp_on ) : ?>
			<p class="wpmcp-warn"><strong>MCP is turned off.</strong> No tools, MCP endpoint or OAuth endpoints are available until you turn it on.</p>
		<?php endif; ?>

		<section class="wpmcp-panel wpmcp-endpoint">
			<div class="wpmcp-endpoint__text">
				<h2>MCP server URL</h2>
				<p>Paste this into your AI client. Setup steps for each client are below.</p>
			</div>
			<?php self::copy_block( 'Endpoint', $url, true ); ?>
		</section>

		<form method="post" action="options.php" id="wpmcp-settings">
			<?php settings_fields( 'wpmcp' ); ?>
			<?php self::hidden_groups( $s ); ?>

			<section class="wpmcp-panel">
				<div class="wpmcp-panel__head">
					<h2>Settings</h2>
					<p>Who can connect, and how.</p>
				</div>

				<div class="wpmcp-row">
					<div class="wpmcp-row__text">
						<h3>MCP server</h3>
						<p>Expose WP MCP Tools to AI agents on this site. Also available from the <strong>MCP</strong> menu in the admin bar.</p>
						<p class="wpmcp-note"><span class="dashicons dashicons-warning" aria-hidden="true"></span>Connected agents can create, edit and delete content and theme files, with the permissions of the connected user.</p>
					</div>
					<div class="wpmcp-row__ctl"><?php self::switch_input( $opt . '[enabled]', $mcp_on, 'Expose WP MCP Tools to AI agents on this site' ); ?></div>
				</div>

				<div class="wpmcp-row">
					<div class="wpmcp-row__text">
						<h3>OAuth sign-in</h3>
						<p>Claude, ChatGPT, Cursor and other clients register themselves, open a page on this site where you log in and approve, and receive tokens bound to this server. Application Passwords keep working alongside.</p>
						<?php if ( ! WPMCP_OAuth_Server::pretty() ) : ?>
							<p class="wpmcp-warn">Pretty permalinks are off, so <code>/.well-known/</code> discovery is unavailable. Enable any permalink structure in <em>Settings → Permalinks</em>.</p>
						<?php endif; ?>
					</div>
					<div class="wpmcp-row__ctl"><?php self::switch_input( $opt . '[oauth_enabled]', $oauth_on, 'Let clients connect by signing in (OAuth)' ); ?></div>
				</div>

				<div class="wpmcp-row">
					<div class="wpmcp-row__text">
						<h3>Automatic updates</h3>
						<p>Let WordPress install new WP MCP Tools releases by itself (this plugin only). It runs with WordPress' twice-daily check, so it needs WP-Cron or a real cron. Turn off to update by clicking <em>Update now</em> on the Plugins screen.</p>
					</div>
					<div class="wpmcp-row__ctl"><?php self::switch_input( $opt . '[auto_update]', ! empty( $s['auto_update'] ), 'Update WP MCP Tools automatically' ); ?></div>
				</div>

				<div class="wpmcp-row wpmcp-row--stack">
					<div class="wpmcp-row__text">
						<h3><label for="wpmcp-public-url">Server URL</label></h3>
						<p>The base URL AI clients use to reach this site. Leave blank to use <code><?php echo esc_html( untrailingslashit( home_url() ) ); ?></code>. Set it only behind a tunnel, proxy or staging domain: it is used for the MCP URL, the OAuth endpoints and the metadata.</p>
					</div>
					<div class="wpmcp-row__ctl">
						<input type="url" id="wpmcp-public-url" class="wpmcp-input code" name="<?php echo esc_attr( $opt ); ?>[public_url]" value="<?php echo esc_attr( $s['public_url'] ); ?>" placeholder="<?php echo esc_attr( untrailingslashit( home_url() ) ); ?>">
					</div>
				</div>

				<div class="wpmcp-row wpmcp-row--select">
					<div class="wpmcp-row__text">
						<h3><label for="wpmcp-cap">Minimum capability</label></h3>
						<p>Needed to use the endpoint and to approve OAuth sign-ins. Each tool still checks its own capability (theme writes need <code>edit_themes</code>).</p>
						<label class="wpmcp-check"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[expose_default_server]" value="1" <?php checked( ! empty( $s['expose_default_server'] ) ); ?>> Also expose the tools on the MCP Adapter default server</label>
					</div>
					<div class="wpmcp-row__ctl">
						<select id="wpmcp-cap" class="wpmcp-input" name="<?php echo esc_attr( $opt ); ?>[endpoint_capability]">
							<?php foreach ( WPMCP_Settings::CAPABILITIES as $cap ) : ?>
								<option value="<?php echo esc_attr( $cap ); ?>" <?php selected( $s['endpoint_capability'], $cap ); ?>><?php echo esc_html( $cap ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="wpmcp-row wpmcp-row--stack">
					<div class="wpmcp-row__text">
						<h3>Authentication method</h3>
						<p>Used for the setup steps below.</p>
					</div>
					<div class="wpmcp-methods">
						<label class="wpmcp-method <?php echo 'oauth' === $method ? 'is-active' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( $opt ); ?>[auth_method]" value="oauth" <?php checked( 'oauth', $method ); ?>>
							<span class="wpmcp-method__ico dashicons dashicons-unlock" aria-hidden="true"></span>
							<span class="wpmcp-method__body"><strong>OAuth <span class="wpmcp-badge">Recommended</span></strong><span>Sign in through the browser, no password to copy.</span></span>
						</label>
						<label class="wpmcp-method <?php echo 'app_password' === $method ? 'is-active' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( $opt ); ?>[auth_method]" value="app_password" <?php checked( 'app_password', $method ); ?>>
							<span class="wpmcp-method__ico dashicons dashicons-admin-network" aria-hidden="true"></span>
							<span class="wpmcp-method__body"><strong>Application password</strong><span>Generate a password and paste it into the client config.</span></span>
						</label>
					</div>
				</div>

				<div class="wpmcp-panel__foot">
					<span class="wpmcp-dirty-msg">You have unsaved changes.</span>
					<button type="submit" class="wpmcp-btn wpmcp-btn--primary">Save changes</button>
				</div>
			</section>
		</form>

		<section class="wpmcp-panel wpmcp-auth-only" data-auth="app_password">
			<div class="wpmcp-panel__head">
				<h2>Application password</h2>
				<?php if ( $new_pw ) : ?>
					<p>New password for <strong><?php echo esc_html( $user->user_login ); ?></strong>. Copy it now, it is not shown again.</p>
				<?php else : ?>
					<p>Create an Application Password for <strong><?php echo esc_html( $user->user_login ); ?></strong>. It is inserted into the configs below. Manage or revoke passwords in <a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>">your profile</a>.</p>
				<?php endif; ?>
			</div>
			<div class="wpmcp-panel__body">
				<?php if ( $new_pw ) : ?>
					<?php self::copy_block( 'Application password', $new_pw, true ); ?>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wpmcp_app_password">
					<?php wp_nonce_field( 'wpmcp_app_password' ); ?>
					<button class="wpmcp-btn"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php echo $new_pw ? 'Generate another password' : 'Generate application password'; ?></button>
				</form>
			</div>
		</section>

		<section class="wpmcp-panel wpmcp-auth-only" data-auth="oauth" id="connected-apps">
			<div class="wpmcp-panel__head">
				<h2>Connected apps</h2>
				<p>Every app that registered with this site. <em>Sign out</em> ends its sessions but lets it sign in again; <em>Remove</em> deletes the registration.</p>
			</div>
			<div class="wpmcp-panel__body"><?php self::render_connected_apps(); ?></div>
		</section>

		<section class="wpmcp-panel" id="connect-client">
			<div class="wpmcp-panel__head">
				<h2>Connect your AI client</h2>
				<p>Pick the app you will connect from. The steps follow the authentication method chosen above.</p>
			</div>
			<div class="wpmcp-panel__body">
				<div class="wpmcp-clients" role="tablist" aria-label="AI clients">
					<?php $first = true; foreach ( $clients as $key => $client ) : ?>
						<button type="button" role="tab" aria-selected="<?php echo $first ? 'true' : 'false'; ?>" class="wpmcp-client <?php echo $first ? 'is-active' : ''; ?>" data-client="<?php echo esc_attr( $key ); ?>"><span class="wpmcp-client__mono" aria-hidden="true"><?php echo esc_html( self::initials( $client['label'] ) ); ?></span><?php echo esc_html( $client['label'] ); ?></button>
					<?php $first = false; endforeach; ?>
				</div>
				<?php $first = true; foreach ( $clients as $key => $client ) : ?>
					<div class="wpmcp-client-panel" role="tabpanel" data-client="<?php echo esc_attr( $key ); ?>" <?php echo $first ? '' : 'hidden'; ?>>
						<?php foreach ( array( 'oauth', 'app_password' ) as $m ) : ?>
							<div class="wpmcp-auth-only" data-auth="<?php echo esc_attr( $m ); ?>">
								<?php if ( 'oauth' === $m && ! $oauth_on ) : ?>
									<p class="wpmcp-warn">OAuth is disabled. Turn on “OAuth sign-in” above and save.</p>
								<?php endif; ?>
								<ol class="wpmcp-steps">
									<?php foreach ( $client[ $m ] as $step ) : ?>
										<li>
											<h4><?php echo esc_html( $step['title'] ); ?></h4>
											<?php echo wp_kses_post( $step['text'] ?? '' ); ?>
											<?php if ( isset( $step['code'] ) ) : ?>
												<?php self::copy_block( $step['label'] ?? '', $step['code'] ); ?>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ol>
							</div>
						<?php endforeach; ?>
					</div>
				<?php $first = false; endforeach; ?>
			</div>
		</section>

		<?php /* Optional add-on (v0.10.0): remove this line, class-connection-check.php and its require to drop it. */ ?>
		<?php WPMCP_Connection_Check::render(); ?>

		<?php /* Optional add-on (v0.9.0). To drop it: remove this line, render_desktop() and includes/class-desktop.php. */ ?>
		<?php self::render_desktop( $url, $user->user_login, $new_pw ? (string) $new_pw : '' ); ?>

		<details class="wpmcp-panel wpmcp-details">
			<summary><span class="dashicons dashicons-code-standards" aria-hidden="true"></span>OAuth endpoints (for debugging)</summary>
			<div class="wpmcp-panel__body">
				<table class="wpmcp-table">
					<tr><th>Protected resource metadata</th><td><code><?php echo esc_html( WPMCP_OAuth_Server::resource_metadata_url() ); ?></code></td></tr>
					<tr><th>Authorization server metadata</th><td><code><?php echo esc_html( self::as_metadata_url() ); ?></code></td></tr>
					<tr><th>Authorize</th><td><code><?php echo esc_html( WPMCP_OAuth_Server::endpoint( 'authorize' ) ); ?></code></td></tr>
					<tr><th>Token</th><td><code><?php echo esc_html( WPMCP_OAuth_Server::endpoint( 'token' ) ); ?></code></td></tr>
					<tr><th>Register</th><td><code><?php echo esc_html( WPMCP_OAuth_Server::endpoint( 'register' ) ); ?></code></td></tr>
					<tr><th>STDIO (WP-CLI)</th><td><code>wp mcp-adapter serve --server=<?php echo esc_html( WPMCP_SERVER_ID ); ?> --user=<?php echo esc_html( $user->user_login ); ?></code></td></tr>
				</table>
				<p class="wpmcp-muted">“Couldn't register” / 401 without a login prompt usually means the <code>Authorization</code> header is stripped by the web server, a firewall (Cloudflare, security plugin) blocks <code>/.well-known/</code> or <code>/wpmcp-oauth/</code>, or the site is not reachable from the client.</p>
			</div>
		</details>
		<?php
	}

	/** Two-letter monogram for a client chip ("Claude Desktop" → "CD", "npx mcp-remote" → "NM"). */
	private static function initials( string $label ): string {
		$words = preg_split( '/[\s.\-]+/', trim( $label ) );
		$out   = '';
		foreach ( array_slice( array_filter( $words ), 0, 2 ) as $w ) {
			$out .= strtoupper( substr( $w, 0, 1 ) );
		}
		return $out;
	}

	private static function as_metadata_url(): string {
		$p    = wp_parse_url( WPMCP_OAuth_Server::base() );
		$root = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		return $root . '/.well-known/oauth-authorization-server' . ( $p['path'] ?? '' );
	}

	private static function render_connected_apps(): void {
		$clients = WPMCP_OAuth_Store::clients();
		if ( ! $clients ) {
			echo '<div class="wpmcp-empty"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><p><strong>No apps registered yet.</strong><br>Apps appear here after they connect with OAuth.</p></div>';
			return;
		}
		uasort(
			$clients,
			static function ( $a, $b ) {
				return $b['created_at'] <=> $a['created_at'];
			}
		);
		?>
		<div class="wpmcp-table-wrap">
			<table class="wpmcp-table wpmcp-apps">
				<thead><tr><th>Client</th><th>Status</th><th>Connected as</th><th>Registered</th><th><span class="screen-reader-text">Actions</span></th></tr></thead>
				<tbody>
				<?php foreach ( $clients as $id => $c ) : ?>
					<?php
					$tokens = WPMCP_OAuth_Store::count_tokens( $id );
					$users  = array_filter( array_map( 'get_userdata', WPMCP_OAuth_Store::client_users( $id ) ) );
					?>
					<tr>
						<td><strong><?php echo esc_html( $c['client_name'] ); ?></strong><code class="wpmcp-small"><?php echo esc_html( implode( ', ', (array) $c['redirect_uris'] ) ); ?></code></td>
						<td><?php if ( $tokens ) : ?><span class="wpmcp-tag is-ok">Connected</span><small><?php echo (int) $tokens; ?> active token(s)</small><?php else : ?><span class="wpmcp-tag">Not signed in</span><?php endif; ?></td>
						<td><?php echo esc_html( implode( ', ', wp_list_pluck( $users, 'user_login' ) ) ?: '-' ); ?></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $c['created_at'] ) ); ?></td>
						<td class="wpmcp-actions">
							<?php if ( $tokens ) : ?>
								<a class="wpmcp-btn wpmcp-btn--sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpmcp_oauth_signout&client_id=' . rawurlencode( $id ) ), 'wpmcp_oauth_signout' ) ); ?>">Sign out</a>
							<?php endif; ?>
							<a class="wpmcp-btn wpmcp-btn--sm wpmcp-btn--danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpmcp_oauth_remove&client_id=' . rawurlencode( $id ) ), 'wpmcp_oauth_remove' ) ); ?>" data-wpmcp-confirm="Remove this app registration?">Remove</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Claude Desktop / local sites: proxy file + one-click .mcpb bundle + config snippet (class-desktop.php). */
	private static function render_desktop( string $url, string $username, string $new_pw ): void {
		$local = WPMCP_Desktop::is_local_site( $url );
		?>
		<section class="wpmcp-panel" id="claude-desktop">
			<div class="wpmcp-panel__head">
				<h2>Claude Desktop<?php echo $local ? ' · local site' : ''; ?></h2>
				<p>
					<?php if ( $local ) : ?>
						This site (<code><?php echo esc_html( (string) wp_parse_url( $url, PHP_URL_HOST ) ); ?></code>) cannot be reached from the internet, so a cloud connector will not work. Claude Desktop can run a small local helper instead; it talks to this site directly from your computer (self-signed HTTPS certificates of .local/.test sites are accepted).
					<?php else : ?>
						Optional: connect Claude Desktop through a local helper instead of a cloud connector. Needed for sites behind a firewall or on localhost.
					<?php endif; ?>
				</p>
			</div>
			<div class="wpmcp-row">
				<div class="wpmcp-row__text">
					<h3>Option A: one-click extension (.mcpb)</h3>
					<p>Download, then double-click the file (or drag it into Claude Desktop → Settings → Extensions). Claude Desktop asks for the site address, your username and an <strong>Application Password</strong>; the password is stored by Claude Desktop and is not inside the file.</p>
				</div>
				<div class="wpmcp-row__ctl">
					<a class="wpmcp-btn wpmcp-btn--primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpmcp_download_mcpb' ), 'wpmcp_download_mcpb' ) ); ?>">Download .mcpb</a>
				</div>
			</div>
			<div class="wpmcp-row">
				<div class="wpmcp-row__text">
					<h3>Option B: config file</h3>
					<p>Save the helper somewhere on your computer, then add this to <code>claude_desktop_config.json</code> (Windows: <code>%APPDATA%\Claude</code>) and restart Claude Desktop. Needs Node.js 18+. Change the path in <code>args</code>.</p>
					<?php if ( $new_pw ) : ?>
						<p class="wpmcp-note"><span class="dashicons dashicons-warning" aria-hidden="true"></span>The new Application Password is filled in below. It is shown only once.</p>
					<?php else : ?>
						<p>Create an Application Password in the section below ("Application Password" client tab) or under Users → Profile.</p>
					<?php endif; ?>
				</div>
				<div class="wpmcp-row__ctl">
					<a class="wpmcp-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpmcp_download_proxy' ), 'wpmcp_download_proxy' ) ); ?>">Download wpmcp-proxy.cjs</a>
				</div>
			</div>
			<div class="wpmcp-panel__body">
				<?php self::copy_block( 'claude_desktop_config.json', WPMCP_Desktop::config_snippet( home_url(), $username, $new_pw ) ); ?>
			</div>
		</section>
		<?php
	}

	/** "Editable plugins" picker: only ticked plugins can be written through MCP (needs the Plugin files (write) group). */
	private static function render_editable_plugins( array $s ): void {
		$opt      = WPMCP_Settings::OPTION;
		$selected = (array) ( $s['editable_plugins'] ?? array() );
		$self     = strtolower( WPMCP_Plugin_Files::self_slug() );
		?>
		<div class="wpmcp-editable">
			<h3>Editable plugins</h3>
			<p>With <strong>Plugin files (write)</strong> on, MCP clients can change files only in the plugins ticked here. Every change is backed up first, PHP is syntax-checked and the site is rolled back automatically if it breaks. WP MCP Tools itself can never be edited this way.</p>
			<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[editable_plugins_present]" value="1">
			<div class="wpmcp-checks">
				<?php foreach ( WPMCP_Plugin_Files::installed_slugs() as $slug ) : ?>
					<?php
					if ( strtolower( $slug ) === $self || 'wp-mcp-tools' === strtolower( $slug ) ) {
						continue;
					}
					?>
					<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[editable_plugins][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected, true ) ); ?>> <code><?php echo esc_html( $slug ); ?></code></label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/** Keep group toggles when the Connection form is saved (they live on the Tools tab). */
	private static function hidden_groups( array $s ): void {
		foreach ( $s['groups'] as $g => $on ) {
			if ( $on ) {
				printf( '<input type="hidden" name="%s[groups][%s]" value="1">', esc_attr( WPMCP_Settings::OPTION ), esc_attr( $g ) );
			}
		}
		foreach ( (array) ( $s['editable_plugins'] ?? array() ) as $slug ) {
			printf( '<input type="hidden" name="%s[editable_plugins][]" value="%s">', esc_attr( WPMCP_Settings::OPTION ), esc_attr( (string) $slug ) );
		}
	}

	private static function hidden_connection( array $s ): void {
		$opt = WPMCP_Settings::OPTION;
		foreach ( array( 'endpoint_capability', 'public_url', 'auth_method' ) as $k ) {
			printf( '<input type="hidden" name="%s[%s]" value="%s">', esc_attr( $opt ), esc_attr( $k ), esc_attr( (string) $s[ $k ] ) );
		}
		foreach ( array( 'enabled', 'oauth_enabled', 'expose_default_server', 'auto_update' ) as $k ) {
			if ( ! empty( $s[ $k ] ) ) {
				printf( '<input type="hidden" name="%s[%s]" value="1">', esc_attr( $opt ), esc_attr( $k ) );
			}
		}
	}

	/** Dashicon per tool group (unknown groups fall back to a generic icon). */
	private static function group_icon( string $slug ): string {
		$map = array(
			'site'        => 'dashicons-admin-site-alt3',
			'content'     => 'dashicons-admin-post',
			'taxonomies'  => 'dashicons-tag',
			'fields'      => 'dashicons-forms',
			'media'       => 'dashicons-admin-media',
			'menus'       => 'dashicons-menu-alt3',
			'theme'       => 'dashicons-admin-appearance',
			'theme-write' => 'dashicons-edit-page',
			'elementor'   => 'dashicons-layout',
			'manage'      => 'dashicons-admin-plugins',
			'plugin-read' => 'dashicons-media-code',
			'plugin-write' => 'dashicons-edit',
		);
		return $map[ $slug ] ?? 'dashicons-admin-generic';
	}

	private static function render_tools(): void {
		$s      = WPMCP_Settings::all();
		$groups = WPMCP_Registry::groups();
		$tools  = WPMCP_Registry::tools();
		$counts = array();
		foreach ( $tools as $tool ) {
			$counts[ $tool['group'] ] = ( $counts[ $tool['group'] ] ?? 0 ) + 1;
		}
		// Elementor's own abilities (served through the bridge), listed after ours.
		$elementor = WPMCP_Elementor::is_available() ? WPMCP_Elementor::catalog() : array();
		$counts[ WPMCP_Elementor::GROUP ] = count( $elementor );
		?>
		<form method="post" action="options.php" id="wpmcp-settings">
			<?php settings_fields( 'wpmcp' ); ?>
			<?php self::hidden_connection( $s ); ?>
			<section class="wpmcp-panel">
				<div class="wpmcp-panel__head">
					<h2>Tool groups</h2>
					<p>Disabled groups are not registered at all, so clients never see them.</p>
				</div>
				<div class="wpmcp-groups">
					<?php foreach ( $groups as $slug => $group ) : ?>
						<?php
						$available = isset( $group['available'] ) && is_callable( $group['available'] ) ? (bool) call_user_func( $group['available'] ) : true;
						$on        = ! empty( $s['groups'][ $slug ] );
						?>
						<div class="wpmcp-group<?php echo $available ? '' : ' is-unavailable'; ?>">
							<span class="wpmcp-group__ico dashicons <?php echo esc_attr( self::group_icon( $slug ) ); ?>" aria-hidden="true"></span>
							<div class="wpmcp-group__body">
								<h3><?php echo esc_html( $group['label'] ); ?> <span class="wpmcp-count"><?php echo (int) ( $counts[ $slug ] ?? 0 ); ?></span></h3>
								<p><?php echo esc_html( $group['description'] ); ?></p>
								<?php if ( ! $available ) : ?>
									<p class="wpmcp-bad">Unavailable: dependency not active<?php echo in_array( $slug, array( 'theme-write', 'plugin-write' ), true ) ? ' or DISALLOW_FILE_EDIT is set' : ''; ?>.</p>
								<?php endif; ?>
							</div>
							<?php self::switch_input( WPMCP_Settings::OPTION . '[groups][' . $slug . ']', $on, $group['label'] ); ?>
						</div>
					<?php endforeach; ?>
				</div>
				<?php self::render_editable_plugins( $s ); ?>
				<div class="wpmcp-panel__foot">
					<span class="wpmcp-dirty-msg">You have unsaved changes.</span>
					<button type="submit" class="wpmcp-btn wpmcp-btn--primary">Save changes</button>
				</div>
			</section>
		</form>

		<section class="wpmcp-panel">
			<div class="wpmcp-panel__head wpmcp-panel__head--row">
				<div>
					<h2>Tools <span class="wpmcp-count"><?php echo count( $tools ) + count( $elementor ); ?></span></h2>
					<p>Every tool this plugin provides. MCP clients see them as <code><?php echo esc_html( WPMCP_NS ); ?>-&lt;tool&gt;</code>.</p>
				</div>
				<div class="wpmcp-search">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<input type="search" class="wpmcp-input" id="wpmcp-tool-search" placeholder="Search tools…" aria-label="Search tools">
				</div>
			</div>
			<div class="wpmcp-filter" role="group" aria-label="Filter by group">
				<button type="button" class="wpmcp-chip is-active" data-group="">All</button>
				<?php foreach ( $groups as $slug => $group ) : ?>
					<button type="button" class="wpmcp-chip" data-group="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $group['label'] ); ?></button>
				<?php endforeach; ?>
			</div>
			<div class="wpmcp-table-wrap">
				<table class="wpmcp-table wpmcp-tools">
					<thead><tr><th>MCP tool name</th><th>Group</th><th>Description</th></tr></thead>
					<tbody>
					<?php foreach ( $tools as $slug => $tool ) : ?>
						<?php $live = WPMCP_Registry::group_active( $tool['group'] ) && WPMCP_Settings::mcp_enabled(); ?>
						<tr data-group="<?php echo esc_attr( $tool['group'] ); ?>" class="<?php echo $live ? '' : 'is-off'; ?>">
							<td><code><?php echo esc_html( WPMCP_NS . '-' . $slug ); ?></code></td>
							<td><span class="wpmcp-tag"><?php echo esc_html( $groups[ $tool['group'] ]['label'] ?? $tool['group'] ); ?></span></td>
							<td><?php echo esc_html( $tool['description'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php $el_live = WPMCP_Elementor::active(); ?>
					<?php foreach ( $elementor as $row ) : ?>
						<tr data-group="<?php echo esc_attr( WPMCP_Elementor::GROUP ); ?>" class="<?php echo $el_live ? '' : 'is-off'; ?>">
							<td><code><?php echo esc_html( $row['name'] ); ?></code><?php echo 'resource' === $row['kind'] ? ' <span class="wpmcp-tag">resource</span>' : ''; ?></td>
							<td><span class="wpmcp-tag">Elementor</span></td>
							<td><?php echo esc_html( wp_trim_words( $row['description'], 40 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="wpmcp-empty wpmcp-tools-empty" hidden>No tools match your search.</p>
			</div>
		</section>
		<?php
	}
}
