<?php
/**
 * Rollback Auto Update
 *
 * @author  Andy Fragen, Colin Stewart
 * @license MIT
 * @link    https://github.com/afragen/rollback-auto-update
 * @package rollback-auto-update
 */

/**
 * Rollback an auto-update containing an activation error.
 *
 * @package Rollback_Auto_Update
 *
 * Plugin Name:       Rollback Auto-Update
 * Plugin URI:        https://github.com/afragen/rollback-auto-update
 * Description:       Rollback an auto-update containing an activation error.
 * Version:           0.6.1
 * Author:            WP Core Contributors
 * License:           MIT
 * Requires at least: 5.9
 * Requires PHP:      5.6
 * GitHub Plugin URI: https://github.com/afragen/rollback-auto-update
 * Primary Branch:    main
 */

namespace Fragen;

use WP_Automatic_Updater;

/*
 * Exit if called directly.
 * PHP version check and exit.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Auto_Update_Failure_Check
 */
class Rollback_Auto_Update {

	/**
	 * Stores handler parameters.
	 *
	 * @var array
	 */
	private $handler_args = [];

	/**
	 * Stores error codes.
	 *
	 * @var int
	 */
	public $error_types = E_ERROR | E_PARSE | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	/**
	 * Constructor, let's get going.
	 */
	public function __construct() {
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

		$plugin = $hook_extra['plugin'];

		// Register exception and shutdown handlers.
		$this->handler_args = [
			'handler_error' => 'Shutdown Caught',
			'result'        => $result,
			'hook_extra'    => $hook_extra,
		];
		$this->initialize_handlers();

		// Working parts of `plugin_sandbox_scrape()`.
		wp_register_plugin_realpath( WP_PLUGIN_DIR . '/' . $plugin );
		if ( 'rollback-auto-update/rollback-auto-update.php' !== $plugin ) {
			include_once WP_PLUGIN_DIR . '/' . $plugin;
		}
	}

	/**
	 * Initializes handlers.
	 */
	private function initialize_handlers() {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		set_error_handler( [ $this, 'error_handler' ], ( E_ALL ^ $this->error_types ) );
		set_exception_handler( [ $this, 'exception_handler' ] );
		register_shutdown_function( [ $this, 'shutdown_handler' ] );
	}

	/**
	 * Handles Errors.
	 */
	public function error_handler() {
		$this->handler_args['handler_error'] = 'Error Caught';
		$this->handler( $this->handler_args );
	}

	/**
	 * Handles Exceptions.
	 */
	public function exception_handler() {
		$this->handler_args['handler_error'] = 'Exception Caught';
		$this->handler( $this->handler_args );
	}

	/**
	 * Displays fatal error output for sites running PHP < 7.
	 * Liberally borrowed from John Blackbourn's Query Monitor.
	 */
	public function shutdown_handler() {
		$e = error_get_last();

		if ( empty( $e ) || ! ( $e['type'] & $this->error_types ) ) {
			return;
		}

		if ( ! empty( $this->handler_args['handler_error'] ) || 'Shutdown Caught' !== $this->handler_args['handler_error'] ) {
			return;
		}

		$this->handler_args['handler_error'] = $e['type'] & E_RECOVERABLE_ERROR ? 'Recoverable fatal error' : 'Fatal error';

		$this->handler();
	}

	/**
	 * Handles errors by running Rollback.
	 */
	private function handler() {
		$this->cron_rollback( $this->handler_args );
		$this->log_error_msg( $this->handler_args );
		$this->send_fatal_error_email( $this->handler_args );
		$this->restart_updates( $this->handler_args );
	}

	/**
	 * Rolls back during cron.
	 *
	 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem subclass.
	 */
	private function cron_rollback() {
		global $wp_filesystem;

		$plugin      = $this->handler_args['hook_extra']['plugin'];
		$temp_backup = [
			'temp_backup' => [
				'dir'  => 'plugins',
				'slug' => dirname( $plugin ),
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
	}

	/**
	 * Sends an email to the site administrator when a plugin
	 * new version contains a fatal error.
	 *
	 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem subclass.
	 */
	private function send_fatal_error_email() {
		global $wp_filesystem;

		$plugin_path = $wp_filesystem->wp_plugins_dir() . $this->handler_args['hook_extra']['plugin'];
		$name        = \get_plugin_data( $plugin_path )['Name'];
		$body        = sprintf(
			__( 'Howdy!' ) . "\n\n" .
			/* translators: 1: The name of the plugin or theme. 2: Home URL. */
			__( '%1$s was successfully updated on your site at %2$s.' ) . "\n\n" .
			/* translators: 1: The name of the plugin or theme. */
			__( 'However, due to a fatal error, %1$s, was reverted to the previously installed version. If a new version is released without fatal errors, it will be installed automatically.' ) . "\n\n" .
			__( 'Please be aware that some additional auto-updates may not have been performed due the nature of the error seen.' ),
			$name,
			home_url()
		);

		$body .= "\n\n" . __( 'The WordPress Rollback Team' ) . "\n";

		wp_mail( get_bloginfo( 'admin_email' ), __( 'Plugin auto-update failed due to a fatal error' ), $body );
	}

	/**
	 * Outputs the handler error to the log file.
	 */
	private function log_error_msg() {
		$error_msg = sprintf(
			'Rollback Auto-Update: %1$s in %2$s',
			$this->handler_args['handler_error'],
			$this->handler_args['hook_extra']['plugin']
		);
		//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $error_msg );
	}

	/**
	 * Restart update process for plugins that remain after a fatal.
	 *
	 * @param array $handler_args {
	 *    An array of error data.
	 *
	 *    @type string   $error      The error message.
	 *    @type WP_Error $result     Generic WP_Error reporting unexpected output.
	 *    @type array    $hook_extra Extra arguments that were passed to hooked filters.
	 * }
	 *
	 * @return void
	 */
	private function restart_updates( $handler_args ) {
		// Get array of plugins set for auto-updating.
		$auto_updates = (array) get_site_option( 'auto_update_plugins', [] );
		$current      = \get_site_transient( 'update_plugins' );
		$plugins      = array_keys( $current->response );
		// Get all auto-updating plugins that have updates available.
		$current_auto_updates = array_intersect( $auto_updates, $plugins );
		unset( $current_auto_updates[ $handler_args['hook_extra']['plugin'] ] );

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$upgrader->bulk_upgrade( $current_auto_updates );
	}
}

new Rollback_Auto_Update();
