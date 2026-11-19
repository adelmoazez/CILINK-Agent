<?php
/**
 * Plugin Name: cilink Agent
 * Description: Exposes this WordPress site as an MCP server (JSON-RPC over HTTP) so AI agents can run PHP, read/write files, query the database and run WP-CLI. FOR DEV/STAGING SITES ONLY.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: cilink-agent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CiLink_Agent {

	const OPT_ENABLED  = 'cla_enabled';
	const OPT_DB_WRITE = 'cla_allow_db_write';
	const OPT_LOG      = 'cla_audit_log';
	const NS           = 'cilink-agent/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/mcp',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_request' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	public static function check_permission() {
		if ( ! get_option( self::OPT_ENABLED ) ) {
			return new WP_Error( 'cla_disabled', 'AI abilities are disabled in Settings > cilink Agent.', array( 'status' => 403 ) );
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'cla_forbidden', 'Administrator credentials (Application Password) required.', array( 'status' => 401 ) );
		}
		return true;
	}

	public static function handle_request( WP_REST_Request $request ) {
		$msg = json_decode( $request->get_body(), true );
		if ( ! is_array( $msg ) || ( $msg['jsonrpc'] ?? '' ) !== '2.0' ) {
			return self::rpc_error( null, -32600, 'Invalid JSON-RPC request.' );
		}

		$id     = $msg['id'] ?? null;
		$method = $msg['method'] ?? '';
		$params = $msg['params'] ?? array();

		if ( null === $id ) {
			return new WP_REST_Response( null, 202 );
		}

		switch ( $method ) {
			case 'initialize':
				return self::rpc_result(
					$id,
					array(
						'protocolVersion' => $params['protocolVersion'] ?? '2025-03-26',
						'capabilities'    => array( 'tools' => new stdClass() ),
						'serverInfo'      => array(
							'name'    => 'cilink-agent',
							'version' => '0.1.0',
						),
					)
				);
			case 'ping':
				return self::rpc_result( $id, new stdClass() );
			case 'tools/list':
				return self::rpc_result( $id, array( 'tools' => self::tool_definitions() ) );
			case 'tools/call':
				$name = $params['name'] ?? '';
				$args = $params['arguments'] ?? array();
				return self::rpc_result( $id, self::call_tool( $name, is_array( $args ) ? $args : array() ) );
		}

		return self::rpc_error( $id, -32601, 'Method not found: ' . $method );
	}

	private static function rpc_result( $id, $result ) {
		return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ), 200 );
	}

	private static function rpc_error( $id, $code, $message ) {
		return new WP_REST_Response(
			array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) ),
			200
		);
	}

	private static function tool_definitions() {
		$str = function ( $desc ) {
			return array( 'type' => 'string', 'description' => $desc );
		};
		$def = function ( $name, $desc, $props, $required ) {
			return array(
				'name'        => $name,
				'description' => $desc,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => empty( $props ) ? new stdClass() : $props,
					'required'   => $required,
				),
			);
		};

		return array(
			$def( 'get_site_info', 'Return WordPress/PHP versions, active theme, active plugins and paths.', array(), array() ),
			$def( 'execute_php', 'Run PHP inside the loaded WordPress environment (no <?php tag). Use "return" to return a value; echo output is captured.', array( 'code' => $str( 'PHP code to run.' ) ), array( 'code' ) ),
			$def( 'list_files', 'List files and folders. Path is relative to the WordPress root.', array( 'path' => $str( 'Directory, e.g. wp-content/plugins' ) ), array() ),
			$def( 'read_file', 'Read a text file. Path is relative to the WordPress root.', array( 'path' => $str( 'File path.' ) ), array( 'path' ) ),
			$def( 'write_file', 'Create or overwrite a file (parent folders are created). Path is relative to the WordPress root.', array( 'path' => $str( 'File path.' ), 'content' => $str( 'Full file content.' ) ), array( 'path', 'content' ) ),
			$def( 'db_query', 'Run SQL. SELECT/SHOW/DESCRIBE always allowed; writes only if enabled in settings. Use {prefix} for the table prefix.', array( 'sql' => $str( 'SQL statement.' ) ), array( 'sql' ) ),
			$def( 'wp_cli', 'Run a WP-CLI command without the leading "wp", e.g. "plugin list --format=json". Requires WP-CLI and exec() on the server.', array( 'command' => $str( 'WP-CLI arguments.' ) ), array( 'command' ) ),
		);
	}

	private static function call_tool( $name, array $args ) {
		try {
			switch ( $name ) {
				case 'get_site_info':
					$out = self::tool_site_info();
					break;
				case 'execute_php':
					$out = self::tool_execute_php( (string) ( $args['code'] ?? '' ) );
					break;
				case 'list_files':
					$out = self::tool_list_files( (string) ( $args['path'] ?? '' ) );
					break;
				case 'read_file':
					$out = self::tool_read_file( (string) ( $args['path'] ?? '' ) );
					break;
				case 'write_file':
					$out = self::tool_write_file( (string) ( $args['path'] ?? '' ), (string) ( $args['content'] ?? '' ) );
					break;
				case 'db_query':
					$out = self::tool_db_query( (string) ( $args['sql'] ?? '' ) );
					break;
				case 'wp_cli':
					$out = self::tool_wp_cli( (string) ( $args['command'] ?? '' ) );
					break;
				default:
					throw new Exception( 'Unknown tool: ' . $name );
			}
			self::audit( $name, $args, true );
			return array(
				'content' => array( array( 'type' => 'text', 'text' => is_string( $out ) ? $out : wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ),
				'isError' => false,
			);
		} catch ( Throwable $e ) {
			self::audit( $name, $args, false );
			return array(
				'content' => array( array( 'type' => 'text', 'text' => 'Error: ' . $e->getMessage() ) ),
				'isError' => true,
			);
		}
	}

	private static function tool_site_info() {
		global $wp_version;
		$theme = wp_get_theme();
		return array(
			'wordpress'      => $wp_version,
			'php'            => PHP_VERSION,
			'site_url'       => site_url(),
			'abspath'        => ABSPATH,
			'environment'    => wp_get_environment_type(),
			'multisite'      => is_multisite(),
			'theme'          => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'active_plugins' => (array) get_option( 'active_plugins', array() ),
		);
	}

	private static function tool_execute_php( $code ) {
		if ( '' === trim( $code ) ) {
			throw new Exception( 'Empty code.' );
		}
		$code = preg_replace( '/^\s*<\?php/i', '', $code );
		ob_start();
		try {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- intentionally executes administrator-supplied code.
			$return = eval( $code );
		} catch ( Throwable $e ) {
			$out = ob_get_clean();
			throw new Exception( $e->getMessage() . ' (line ' . $e->getLine() . ')' . ( $out ? "\nOutput so far:\n" . $out : '' ) );
		}
		$out = ob_get_clean();
		return array( 'output' => $out, 'return' => $return );
	}

	private static function resolve_path( $rel, $must_exist = true ) {
		$root = realpath( ABSPATH );
		$rel  = ltrim( str_replace( '\\', '/', $rel ), '/' );
		$full = $root . '/' . $rel;

		if ( $must_exist ) {
			$real = realpath( $full );
			if ( false === $real ) {
				throw new Exception( 'Path not found: ' . $rel );
			}
		} else {
			$parent = $full;
			while ( ! file_exists( $parent ) && dirname( $parent ) !== $parent ) {
				$parent = dirname( $parent );
			}
			$real_parent = realpath( $parent );
			if ( false === $real_parent || false !== strpos( $rel, '..' ) ) {
				throw new Exception( 'Invalid or traversing path.' );
			}
			$real = $real_parent . substr( $full, strlen( $parent ) );
		}

		if ( $real !== $root && 0 !== strpos( $real, $root . '/' ) ) {
			throw new Exception( 'Path is outside the WordPress directory.' );
		}
		return $real;
	}

	private static function tool_list_files( $path ) {
		$dir = self::resolve_path( $path );
		if ( ! is_dir( $dir ) ) {
			throw new Exception( 'Not a directory.' );
		}
		$items = array();
		foreach ( scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $dir . '/' . $f;
			$items[] = array( 'name' => $f, 'type' => is_dir( $p ) ? 'dir' : 'file', 'size' => is_file( $p ) ? filesize( $p ) : null );
		}
		return $items;
	}

	private static function tool_read_file( $path ) {
		$file = self::resolve_path( $path );
		if ( ! is_file( $file ) ) {
			throw new Exception( 'Not a file.' );
		}
		if ( filesize( $file ) > 1024 * 1024 ) {
			throw new Exception( 'File is larger than 1 MB.' );
		}
		return (string) file_get_contents( $file );
	}

	private static function tool_write_file( $path, $content ) {
		if ( '' === trim( $path ) ) {
			throw new Exception( 'Path required.' );
		}
		$file = self::resolve_path( $path, false );
		$dir  = dirname( $file );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new Exception( 'Could not create directory.' );
		}
		$bytes = file_put_contents( $file, $content );
		if ( false === $bytes ) {
			throw new Exception( 'Write failed.' );
		}
		return 'Wrote ' . $bytes . ' bytes to ' . ltrim( str_replace( realpath( ABSPATH ), '', $file ), '/' );
	}

	private static function tool_db_query( $sql ) {
		global $wpdb;
		$sql = trim( str_replace( '{prefix}', $wpdb->prefix, $sql ) );
		if ( '' === $sql ) {
			throw new Exception( 'Empty SQL.' );
		}
		$is_read = (bool) preg_match( '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql );
		if ( ! $is_read ) {
			if ( ! get_option( self::OPT_DB_WRITE ) ) {
				throw new Exception( 'Write queries are disabled. Enable them in Settings > cilink Agent.' );
			}
			$wpdb->suppress_errors( true );
			$res = $wpdb->query( $sql );
			if ( false === $res ) {
				throw new Exception( $wpdb->last_error );
			}
			return array( 'rows_affected' => $res, 'insert_id' => $wpdb->insert_id );
		}
		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( $wpdb->last_error ) {
			throw new Exception( $wpdb->last_error );
		}
		return array( 'count' => count( $rows ), 'rows' => array_slice( $rows, 0, 500 ) );
	}

	private static function tool_wp_cli( $command ) {
		if ( '' === trim( $command ) ) {
			throw new Exception( 'Empty command.' );
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( ! function_exists( 'exec' ) || in_array( 'exec', $disabled, true ) ) {
			throw new Exception( 'exec() is disabled on this server, so WP-CLI cannot run.' );
		}
		$bin = trim( (string) shell_exec( 'command -v wp 2>/dev/null' ) );
		if ( '' === $bin ) {
			throw new Exception( 'WP-CLI binary ("wp") was not found on this server.' );
		}
		$cmd = escapeshellarg( $bin ) . ' ' . $command . ' --path=' . escapeshellarg( ABSPATH ) . ' --allow-root 2>&1';
		exec( $cmd, $lines, $code );
		return array( 'exit_code' => $code, 'output' => implode( "\n", $lines ) );
	}

	private static function audit( $tool, array $args, $ok ) {
		$log = get_option( self::OPT_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$summary = wp_json_encode( $args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		array_unshift(
			$log,
			array(
				'time' => current_time( 'mysql' ),
				'user' => get_current_user_id(),
				'tool' => $tool,
				'ok'   => $ok,
				'args' => mb_substr( (string) $summary, 0, 300 ),
			)
		);
		update_option( self::OPT_LOG, array_slice( $log, 0, 50 ), false );
	}

	public static function register_settings() {
		register_setting( 'cla_settings', self::OPT_ENABLED, array( 'type' => 'boolean', 'sanitize_callback' => 'absint', 'default' => 0 ) );
		register_setting( 'cla_settings', self::OPT_DB_WRITE, array( 'type' => 'boolean', 'sanitize_callback' => 'absint', 'default' => 0 ) );
	}

	public static function admin_menu() {
		add_options_page( 'cilink Agent', 'cilink Agent', 'manage_options', 'cilink-agent', array( __CLASS__, 'render_page' ) );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$endpoint = rest_url( self::NS . '/mcp' );
		$log      = get_option( self::OPT_LOG, array() );
		?>
		<div class="wrap">
			<h1>cilink Agent</h1>
			<div class="notice notice-warning inline"><p><strong>Warning:</strong> when enabled, an AI agent with an administrator Application Password can run arbitrary PHP, edit files and change the database. Use on development/staging sites only, and keep backups.</p></div>
			<?php if ( 'production' === wp_get_environment_type() ) : ?>
				<div class="notice notice-error inline"><p>This site reports a <code>production</code> environment. Do not enable AI abilities here.</p></div>
			<?php endif; ?>
			<?php if ( ! is_ssl() ) : ?>
				<div class="notice notice-error inline"><p>HTTPS is not active. Credentials would travel unencrypted.</p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'cla_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">AI abilities</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT_ENABLED ); ?>" value="1" <?php checked( get_option( self::OPT_ENABLED ) ); ?>> Enable the MCP endpoint</label></td></tr>
					<tr><th scope="row">Database writes</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT_DB_WRITE ); ?>" value="1" <?php checked( get_option( self::OPT_DB_WRITE ) ); ?>> Allow INSERT/UPDATE/DELETE/DDL in <code>db_query</code></label></td></tr>
					<tr><th scope="row">MCP endpoint</th><td><code><?php echo esc_html( $endpoint ); ?></code></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<h2>Connect an AI client</h2>
			<ol>
				<li>Go to Users &rarr; Profile and create an <strong>Application Password</strong> for an administrator.</li>
				<li>Add the endpoint above as a remote MCP server in your client, with HTTP Basic auth (<code>username:application-password</code>).</li>
			</ol>
			<h2>Recent activity</h2>
			<table class="widefat striped">
				<thead><tr><th>Time</th><th>User</th><th>Tool</th><th>OK</th><th>Arguments</th></tr></thead>
				<tbody>
				<?php if ( empty( $log ) ) : ?>
					<tr><td colspan="5">No activity yet.</td></tr>
				<?php else : foreach ( $log as $row ) : ?>
					<tr><td><?php echo esc_html( $row['time'] ); ?></td><td><?php echo esc_html( $row['user'] ); ?></td><td><?php echo esc_html( $row['tool'] ); ?></td><td><?php echo $row['ok'] ? '&#10003;' : '&#10007;'; ?></td><td><code><?php echo esc_html( $row['args'] ); ?></code></td></tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

CiLink_Agent::init();
