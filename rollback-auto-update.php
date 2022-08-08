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
 * Version:           0.6.0.6
 * Author:            WP Core Contributors
 * License:           MIT
 * Requires at least: 5.9
 * Requires PHP:      5.6
 * GitHub Plugin URI: https://github.com/afragen/rollback-auto-update
 * Primary Branch:    main
 */

namespace Fragen;

use Fragen\Singleton;

/*
 * Exit if called directly.
 * PHP version check and exit.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Load the Composer autoloader.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require __DIR__ . '/vendor/autoload.php';
}

add_filter(
	'upgrader_install_package_result',
	[ Singleton::get_instance( 'Fragen\Rollback_Auto_Update', new \stdClass() ), 'auto_update_check' ],
	15,
	2
);

/**
 * Class Auto_Update_Failure_Check
 */
class Rollback_Auto_Update {

	/**
	 * Variable to store handler parameters.
	 *
	 * @var array
	 */
	private $handler_args = [];

	/**
	 * Check validity of updated plugin.
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

		$result = new \WP_Error( 'unexpected_output', __( 'The plugin generated unexpected output.' ) );
		$plugin = $hook_extra['plugin'];

		// Register exception and shutdown handlers.
		$this->handler_args = [
			'handler_error' => 'Shutdown Caught',
			'result'        => $result,
			'hook_extra'    => $hook_extra,
		];
		$this->initialize_handlers();

		// working parts of `plugin_sandbox_scrape()`.
		wp_register_plugin_realpath( WP_PLUGIN_DIR . '/' . $plugin );
		include WP_PLUGIN_DIR . '/' . $plugin;

		return $result;
	}

	/**
	 * Initialize handlers.
	 *
	 * @return void
	 */
	private function initialize_handlers() {
		if ( ! defined( 'QM_ERROR_FATALS' ) ) {
			define( 'QM_ERROR_FATALS', E_ERROR | E_PARSE | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		set_error_handler( [ $this, 'error_handler' ], ( E_ALL ^ QM_ERROR_FATALS ) );
		set_exception_handler( [ $this, 'exception_handler' ] );
		register_shutdown_function( [ $this, 'shutdown_handler' ], $this->handler_args );
	}

	/**
	 * Error handler function.
	 *
	 * @param \Error $error Error object.
	 *
	 * @return void
	 */
	public function error_handler( $error ) {
		$this->handler_args['handler_error'] = 'Error Caught';
		$this->handler( $this->handler_args );
	}

	/**
	 * Exception handler function.
	 *
	 * @param \Exception $exception Exception object.
	 *
	 * @return void
	 */
	public function exception_handler( $exception ) {
		$this->handler_args['handler_error'] = 'Exception Caught';
		$this->handler( $this->handler_args );
	}

	/**
	 * Displays fatal error output for sites running PHP < 7.
	 * Liberally borrowed from John Blackbourn's Query Monitor.
	 *
	 * @param array $handler_args Array of data.
	 *
	 * @return array
	 */
	public function shutdown_handler( $handler_args ) {
		$e = error_get_last();

		if ( empty( $e ) || ! ( $e['type'] & QM_ERROR_FATALS ) ) {
			return;
		}

		if ( 'Shutdown Caught' !== $this->handler_args['handler_error'] ) {
			return $handler_args['result'];
		}

		$handler_args['handler_error'] = $e['type'] & E_RECOVERABLE_ERROR ? 'Recoverable fatal error' : 'Fatal error';

		$this->handler( $handler_args );

		return $handler_args['result'];
	}

	/**
	 * Handle errors by running Rollback.
	 *
	 * @param array $args {
	 *    An array of error data.
	 *
	 *    @type string   $error      The error message.
	 *    @type WP_Error $result     Generic WP_Error reporting unexpected output.
	 *    @type array    $hook_extra Extra arguments that were passed to hooked filters.
	 * }
	 *
	 * @return void
	 */
	private function handler( $args ) {
		$this->cron_rollback( $args );
		$this->log_error_msg( $args );
		$this->send_fatal_error_email( $args );
	}

	/**
	 * Rollback during cron.
	 *
	 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem subclass.
	 *
	 * @param array $args {
	 *    An array of error data.
	 *
	 *    @type string   $error      The error message.
	 *    @type WP_Error $result     Generic WP_Error reporting unexpected output.
	 *    @type array    $hook_extra Extra arguments that were passed to hooked filters.
	 * }
	 *
	 * @return void
	 */
	private function cron_rollback( $args ) {
		global $wp_filesystem;

		$plugin      = $args['hook_extra']['plugin'];
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
	 *
	 * @param array $args {
	 *    An array of error data.
	 *
	 *    @type string   $error      The error message.
	 *    @type WP_Error $result     Generic WP_Error reporting unexpected output.
	 *    @type array    $hook_extra Extra arguments that were passed to hooked filters.
	 * }
	 */
	private function send_fatal_error_email( $args ) {
		global $wp_filesystem;

		$plugin_path = $wp_filesystem->wp_plugins_dir() . $args['hook_extra']['plugin'];
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
	 * Undocumented function
	 *
	 * @param array $args {
	 *    An array of error data.
	 *
	 *    @type string   $error      The error message.
	 *    @type WP_Error $result     Generic WP_Error reporting unexpected output.
	 *    @type array    $hook_extra Extra arguments that were passed to hooked filters.
	 * }
	 *
	 * @return void
	 */
	private function log_error_msg( $args ) {
		$error_msg = sprintf(
			'Rollback Auto-Update: %1$s in %2$s',
			$args['handler_error'],
			$args['hook_extra']['plugin']
		);
		//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $error_msg );
	}
}
