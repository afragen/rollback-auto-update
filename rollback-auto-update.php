<?php
/**
 * Rollback an auto-update containing an activation error.
 *
 * @package Rollback_Auto_Update
 *
 * Plugin Name:       Rollback Auto-Update
 * Plugin URI:        https://github.com/afragen/rollback-auto-update
 * Description:       Rollback an auto-update containing an activation error.
 * Version:           0.5.7.1
 * Author:            WP Core Contributors
 * License:           MIT
 * Requires at least: 5.9
 * Requires PHP:      5.6
 * GitHub Plugin URI: https://github.com/afragen/rollback-auto-update
 * Primary Branch:    main
 */

namespace Fragen;

add_filter(
	'upgrader_install_package_result',
	[ new Rollback_Auto_Update(), 'auto_update_check' ],
	15,
	2
);

/**
 * Class Auto_Update_Failure_Check
 */
class Rollback_Auto_Update {

	/**
	 * Has plugin errored on update already?
	 *
	 * @var bool
	 */
	private $errored = false;

	/**
	 * Check validity of updated plugin.
	 *
	 * @param array|WP_Error $result     Result from WP_Upgrader::install_package().
	 * @param array          $hook_extra Extra arguments passed to hooked filters.
	 *
	 * @return array|WP_Error
	 */
	public function auto_update_check( $result, $hook_extra ) {
		if ( ! is_wp_error( $result ) && wp_doing_cron() || true ) {

			if ( ! defined( 'QM_ERROR_FATALS' ) ) {
				define( 'QM_ERROR_FATALS', E_ERROR | E_PARSE | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR );
			}

			if ( ! isset( $hook_extra['plugin'] ) ) {
				return $result;
			}

			$plugin = $hook_extra['plugin'];

			// Register exception and shutdown handlers.
			$handler_args = [
				'error'      => 'Shutdown Caught',
				'result'     => $result,
				'hook_extra' => $hook_extra,
			];
			$lambda_error = function( $error ) use ( $handler_args ) {
				$handler_args['error'] = 'Error Caught';
				$this->handler( $handler_args );
			};
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			set_error_handler( $lambda_error, ( E_ALL ^ QM_ERROR_FATALS ) );
			$lambda_exception = function( $exception ) use ( $handler_args ) {
				$handler_args['error'] = 'Exception Caught';
				$this->handler( $handler_args );
			};
			set_exception_handler( $lambda_exception );
			register_shutdown_function( [ $this, 'shutdown_handler' ], $handler_args );

			// working parts of `plugin_sandbox_scrape()`.
			wp_register_plugin_realpath( WP_PLUGIN_DIR . '/' . $plugin );
			include WP_PLUGIN_DIR . '/' . $plugin;
		}

		return $result;
	}

	/**
	 * Rollback during cron.
	 *
	 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem subclass.
	 *
	 * @param array|WP_Error $result     Result from WP_Upgrader::install_package().
	 * @param array          $hook_extra Extra arguments passed to hooked filters.
	 *
	 * @return array|WP_Error
	 */
	public function cron_rollback( $result, $hook_extra ) {
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) ) {
			return $result;
		}
		$result      = new \WP_Error( 'unexpected_output', __( 'The plugin generated unexpected output.' ) );
		$plugin      = $hook_extra['plugin'];
		$temp_backup = [
			'temp_backup' => [
				'dir'  => 'plugins',
				'slug' => dirname( $plugin ),
				'src'  => $wp_filesystem->wp_plugins_dir(),
			],
		];
		$hook_extra  = array_merge( $hook_extra, $temp_backup );
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

		$this->send_fatal_error_email( $hook_extra );

		return $result;
	}

	/**
	 * Handle errors by running Rollback.
	 *
	 * @param array $args Array of data.
	 *
	 * @return array
	 */
	public function handler( $args ) {
		//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'Rollback Auto-Update - ' . $args['error'] . ' in ' . $args['hook_extra']['plugin'] );
		$args['result']['error'] = $args['error'];
		$this->errored           = true;
		return $this->cron_rollback( $args['result'], $args['hook_extra'] );
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

		if ( $this->errored ) {
			return $handler_args['result'];
		}

		if ( $e['type'] & E_RECOVERABLE_ERROR ) {
			$error = 'Catchable fatal error';
		} else {
			$error = 'Fatal error';
		}
		$handler_args['error'] = $error;

		return $this->handler( $handler_args );
	}

	/**
	 * Sends an email to the site administrator when a plugin
	 * new version contains a fatal error.
	 *
	 * @param array $hook_extra Array of data from hook.
	 */
	private function send_fatal_error_email( $hook_extra ) {
		$name = \get_plugin_data( $hook_extra['temp_backup']['src'] . $hook_extra['plugin'] )['Name'];
		$body = sprintf(
		/* translators: 1: The name of the plugin or theme. 2: Home URL. */
			__( 'Howdy! Due to a fatal error, %1$s, failed to automatically update to the latest versions on your site at %2$s. If a new version is released without fatal errors, it will be installed automatically.' ) . "\n" .
			__( 'Please be aware that some additional auto-updates may not have been performed due the nature of the error seen.' ),
			$name,
			home_url()
		);

		$body .= "\n\n" . __( 'The WordPress Team' ) . "\n";

		wp_mail( get_bloginfo( 'admin_email' ), __( 'Plugin auto-update failed due to a fatal error' ), $body );
	}
}
