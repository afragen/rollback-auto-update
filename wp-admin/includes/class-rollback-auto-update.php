<?php
/**
 * WordPress Plugin Administration API: WP_Rollback_Auto_Update class
 *
 * @package WordPress
 * @subpackage Administration
 * @since 6.2.0
 */

/**
 * Core class for rolling back auto-update plugin failures.
 */
class WP_Rollback_Auto_Update {

	/**
	 * Stores handler parameters.
	 *
	 * @var array
	 */
	private $handler_args = [];

	/**
	 * Stores successfully updated plugins.
	 *
	 * @var array
	 */
	private $processed = [];

	/**
	 * Stores fataling plugins.
	 *
	 * @var array
	 */
	private $fatals = [];

	/**
	 * Stores `update_plugins` transient.
	 *
	 * @var \stdClass
	 */
	private static $current;

	/**
	 * Stores boolean for no error from check_plugin_for_errors().
	 *
	 * @var bool
	 */
	private $no_error = false;

	/**
	 * Stores error codes.
	 *
	 * @var int
	 */
	public $error_types = E_ERROR | E_PARSE | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	/**
	 * Static function to get started from hook.
	 *
	 * @return void
	 */
	public static function init() {
		( new WP_Rollback_Auto_Update() )->load_hooks();
	}

	/**
	 * Load hook to start.
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_filter( 'upgrader_install_package_result', [ $this, 'auto_update_check' ], 15, 2 );
	}

	/**
	 * Checks the validity of the updated plugin.
	 *
	 * @param array|WP_Error $result     Result from WP_Upgrader::install_package().
	 * @param array          $hook_extra Extra arguments passed to hooked filters.
	 *
	 * @return array|WP_Error
	 */
	public function auto_update_check( $result, $hook_extra ) {
		if ( is_wp_error( $result ) || ! wp_doing_cron() || ! isset( $hook_extra['plugin'] ) ) {
			return $result;
		}

		$this->no_error     = false;
		static::$current    = get_site_transient( 'update_plugins' );
		$this->handler_args = [
			'handler_error' => '',
			'result'        => $result,
			'hook_extra'    => $hook_extra,
		];
		$this->is_active    = is_plugin_active( $hook_extra['plugin'] );

		// Register exception and shutdown handlers.
		$this->initialize_handlers();

		error_log( $hook_extra['plugin'] . ' processing...' );
		$this->processed[] = $hook_extra['plugin'];

		// This needed for inactive plugins that fatal.
		// Working parts of plugin_sandbox_scrape().
		wp_register_plugin_realpath( WP_PLUGIN_DIR . '/' . $hook_extra['plugin'] );
		include WP_PLUGIN_DIR . '/' . $hook_extra['plugin'];

		$this->check_plugin_for_errors( $hook_extra['plugin'] );
		error_log( $hook_extra['plugin'] . ' auto updated.' );

		return $result;
	}

	/**
	 * Checks a new plugin version for errors.
	 *
	 * If an error is found, the previously installed version will be reinstalled
	 * and an email will be sent to the site administrator.
	 *
	 * @param string $plugin The plugin to check.
	 *
	 * @throws Exception If errors are present.
	 *
	 * @return void
	 */
	private function check_plugin_for_errors( $plugin ) {
		global $wp_filesystem;

		if ( file_exists( ABSPATH . '.maintenance' ) ) {
			$wp_filesystem->delete( ABSPATH . '.maintenance' );
		}

		$nonce    = wp_create_nonce( 'plugin-activation-error_' . $plugin );
		$response = wp_remote_get(
			add_query_arg(
				[
					'action'   => 'error_scrape',
					'plugin'   => $plugin,
					'_wpnonce' => $nonce,
				],
				admin_url( 'plugins.php' )
			),
			[ 'timeout' => 60 ]
		);

		if ( is_wp_error( $response ) ) {
			// If it isn't possible to run the check, assume an error.
			throw new \Exception( $response->get_error_message() );
		}

		$code           = wp_remote_retrieve_response_code( $response );
		$body           = wp_remote_retrieve_body( $response );
		$this->no_error = 200 === $code;

		// error_log( 'check_for_plugin_errors code: ' . var_export( $code, true ) );
		if ( str_contains( $body, 'wp-die-message' ) || 200 !== $code ) {
			$error = new \WP_Error(
				'new_version_error',
				sprintf(
					/* translators: %s: The name of the plugin. */
					__( 'The new version of %s contains an error' ),
					\get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin )['Name']
				)
			);
			throw new \Exception( $error->get_error_message() );
		}
	}

	/**
	 * Initializes handlers.
	 */
	private function initialize_handlers() {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		set_error_handler( [ $this, 'error_handler' ], ( E_ALL ^ $this->error_types ) );
		set_exception_handler( [ $this, 'exception_handler' ] );
	}

	/**
	 * Handles Errors.
	 */
	public function error_handler() {
		$this->handler_args['handler_error'] = 'Error Caught';
		// error_log( 'error handler: ' . var_export( $this->handler_args['hook_extra']['plugin'], true ) );

		$this->handler( $this->non_fatal_errors() );
	}

	/**
	 * Handles Exceptions.
	 */
	public function exception_handler() {
		$this->handler_args['handler_error'] = 'Exception Caught';
		// error_log( 'exception handler: ' . var_export( $this->handler_args['hook_extra']['plugin'], true ) );
		$this->handler( false );
	}

	/**
	 * Handles errors by running Rollback.
	 *
	 * @param bool If false, assume fatal and process, default false.
	 */
	private function handler( $skip = false ) {
		if ( $skip ) {
			// error_log( 'exit handler skip: ' . var_export( $skip, true ) );
			return;
		}
		$this->fatals[] = $this->handler_args['hook_extra']['plugin'];

		$this->cron_rollback();
		$this->log_error_msg( error_get_last() );
		$this->restart_updates();
	}

	/**
	 * Skip (exit handler() early) for non-fatal errors or non-errors.
	 *
	 * @return bool
	 */
	private function non_fatal_errors() {
		$e                = error_get_last();
		$non_fatal_errors = ( ! empty( $e ) && $this->error_types !== $e['type'] );
		$skip             = is_plugin_active( $this->handler_args['hook_extra']['plugin'] ) || $this->no_error;
		$skip             = $skip ? $skip : $non_fatal_errors;

		return $skip;
	}

	/**
	 * Rolls back during cron.
	 *
	 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem subclass.
	 */
	private function cron_rollback() {
		global $wp_filesystem;

		$temp_backup = [
			'temp_backup' => [
				'dir'  => 'plugins',
				'slug' => dirname( $this->handler_args['hook_extra']['plugin'] ),
				'src'  => $wp_filesystem->wp_plugins_dir(),
			],
		];

		include_once $wp_filesystem->wp_plugins_dir() . 'rollback-update-failure/wp-admin/includes/class-wp-upgrader.php';
		$rollback_updater = new \Rollback_Update_Failure\WP_Upgrader();

		// Set private $temp_restores variable.
		$ref_temp_restores = new \ReflectionProperty( $rollback_updater, 'temp_restores' );
		$ref_temp_restores->setAccessible( true );
		$ref_temp_restores->setValue( $rollback_updater, $temp_backup );

		// Set private $temp_backups variable.
		$ref_temp_backups = new \ReflectionProperty( $rollback_updater, 'temp_backups' );
		$ref_temp_backups->setAccessible( true );
		$ref_temp_backups->setValue( $rollback_updater, $temp_backup );

		// Call Rollback's restore_temp_backup().
		$restore_temp_backup = new \ReflectionMethod( $rollback_updater, 'restore_temp_backup' );
		$restore_temp_backup->invoke( $rollback_updater );

		// Call Rollback's delete_temp_backup().
		$delete_temp_backup = new \ReflectionMethod( $rollback_updater, 'delete_temp_backup' );
		$delete_temp_backup->invoke( $rollback_updater );

		error_log( $this->handler_args['hook_extra']['plugin'] . ' rolled back' );
		// $this->send_fatal_error_email();
	}

	/**
	 * Sends an email to the site administrator when a plugin
	 * new version contains a fatal error.
	 *
	 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem subclass.
	 */
	private function send_fatal_error_email() {
		global $wp_filesystem;

		if ( ! isset( $this->handler_args['hook_extra']['plugin'] ) ) {
			return;
		}

		$plugin_path = $wp_filesystem->wp_plugins_dir() . $this->handler_args['hook_extra']['plugin'];
		$name        = \get_plugin_data( $plugin_path )['Name'];
		$subject     = __( 'A plugin was rolled back to the previously installed version' );
		$body        = sprintf(
			__( 'Howdy!' ) . "\n\n" .
			/* translators: 1: The name of the plugin or theme. 2: Home URL. */
			__( '%1$s was successfully updated on your site, [%2$s], at %3$s.' ) . "\n\n" .
			__( 'However, due to a fatal error, it was reverted to the previously installed version to keep your site running.' ) . ' ' .
			__( 'If a new version is released without fatal errors, it will be installed automatically.' ) . "\n\n",
			$name,
			get_bloginfo( 'name' ),
			home_url()
		);

		$body .= __( 'The WordPress Rollback Team' ) . "\n";

		wp_mail( get_bloginfo( 'admin_email' ), $subject, $body );
	}

	/**
	 * Outputs the handler error to the log file.
	 *
	 * @param array $e Last error.
	 */
	private function log_error_msg( $e ) {
		$error_msg = sprintf(
			'Rollback Auto-Update: %1$s in %2$s, error %3$s',
			$this->handler_args['handler_error'],
			$this->handler_args['hook_extra']['plugin'],
			empty( $e ) ? '?ParseError?' : var_export( $e, true )
		);
		//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $error_msg );
	}

	/**
	 * Restart update process for plugins that remain after a fatal.
	 */
	private function restart_updates() {
		$remaining_auto_updates = $this->get_remaining_auto_updates();

		if ( empty( $remaining_auto_updates ) ) {
			return;
		}

		error_log( 'restart Plugin_Upgrader::bulk_upgrade ' . var_export( $remaining_auto_updates, true ) );
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$upgrader->bulk_upgrade( $remaining_auto_updates );
		$this->send_update_result_email();
	}

	/**
	 * Sends an email noting successful and failed updates.
	 */
	private function send_update_result_email() {
		$successful = [];
		$failed     = [];

		/*
		 * Using `get_plugin_data()` instead has produced warnings/errors
		 * as the files may not be in place at this time.
		 */
		$plugins = get_plugins();

		foreach ( static::$current->response as $k => $update ) {
			$item = static::$current->response[ $k ];
			$name = $plugins[ $update->plugin ]['Name'];

			/*
			 * This appears to be the only way to get a plugin's older version
			 * at this stage of an auto-update when not implementing this
			 * feature directly in Core.
			 */
			$current_version = static::$current->checked[ $update->plugin ];

			/*
			 * The `current_version` property does not exist yet. Add it.
			 *
			 * `static::$current->response[ $k ]` is an instance of `stdClass`,
			 * so this should not fall victim to PHP 8.2's deprecation of
			 * dynamic properties.
			 */
			$item->current_version = $current_version;

			$plugin_result = (object) [
				'name' => $name,
				'item' => $item,
			];

			$success = array_diff( $this->processed, $this->fatals );

			if ( in_array( $update->plugin, $success, true ) ) {
				$successful['plugin'][] = $plugin_result;
				continue;
			}

			if ( in_array( $update->plugin, $this->fatals, true ) ) {
				$failed['plugin'][] = $plugin_result;
			}
		}

		$automatic_upgrader      = new \WP_Automatic_Updater();
		$send_plugin_theme_email = new \ReflectionMethod( $automatic_upgrader, 'send_plugin_theme_email' );
		$send_plugin_theme_email->setAccessible( true );
		$send_plugin_theme_email->invoke( $automatic_upgrader, 'mixed', $successful, $failed );
	}

	/**
	 * Get array of non-fataling auto-updates remaining.
	 *
	 * @return array
	 */
	private function get_remaining_auto_updates() {
		if ( empty( $this->handler_args ) ) {
			return [];
		}

		// error_log( 'fatals ' . var_export( array_unique( $this->fatals ), true ) );
		// Get array of plugins set for auto-updating.
		$auto_updates    = (array) get_site_option( 'auto_update_plugins', [] );
		$current_plugins = array_keys( static::$current->response );

		// Get all auto-updating plugins that have updates available.
		$current_auto_updates = array_intersect( $auto_updates, $current_plugins );
		// error_log( 'current auto updates: ' . var_export( $current_auto_updates, true ) );

		// Get array of non-fatal auto-updates remaining.
		$remaining_auto_updates = array_diff( $current_auto_updates, $this->processed, $this->fatals );
		// error_log( 'remaining auto updates ' . var_export( $remaining_auto_updates, true ) );

		$this->processed = array_unique( array_merge( $this->processed, $remaining_auto_updates ) );

		// error_log( 'processed ' . var_export( $this->processed, true ) );
		return $remaining_auto_updates;
	}
}
