<?php
/**
 * Auto-update fatal error Rollback.
 *
 * @package AutoUpdateFatalErrorRollback
 *
 * Plugin Name:       Auto-update Fatal Error Rollback
 * Plugin URI:        https://github.com/afragen/auto-update-fatal-error-rollback
 * Description:       Check for a PHP error on plugin auto-update and Rollback plugin if one exists.
 * Version:           0.5.4
 * Author:            WP Core Contributors
 * License:           MIT
 * Requires at least: 5.9
 * Requires PHP:      5.6
 * GitHub Plugin URI: https://github.com/afragen/auto-update-fatal-error-rollback
 * Primary Branch:    main
 */

namespace Fragen;

add_filter(
	'upgrader_install_package_result',
	[ new Auto_Update_Failure_Rollback(), 'auto_update_failure_check' ],
	10,
	2
);

/**
 * Class Auto_Update_Failure_Check
 */
class Auto_Update_Failure_Rollback {

	/**
	 * Check validity of updated plugin.
	 *
	 * @param array|WP_Error $result     Result from WP_Upgrader::install_package().
	 * @param array          $hook_extra Extra arguments passed to hooked filters.
	 *
	 * @return array|WP_Error
	 */
	public function auto_update_failure_check( $result, $hook_extra ) {
		if ( ! is_wp_error( $result ) && wp_doing_cron() ) {

			// Register exception and shutdown handlers.
			$handler_args = [
				'error'      => 'Shutdown Caught',
				'result'     => $result,
				'hook_extra' => $hook_extra,
			];
			register_shutdown_function( [ $this, 'handler' ], $handler_args );
			$lambda = function( $error ) use ( $handler_args ) {
				$handler_args['error'] = 'Error Caught';
				$this->handler( $handler_args );
			};
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			set_error_handler( $lambda );
			$lambda2 = function( $exception ) use ( $handler_args ) {
				$handler_args['error'] = 'Exception Caught';
				$this->handler( $handler_args );
			};
			set_exception_handler( $lambda2 );

			$plugin = $hook_extra['plugin'];
			ob_start();

			// working parts of `plugin_sandbox_scrape()`.
			wp_register_plugin_realpath( WP_PLUGIN_DIR . '/' . $plugin );
			try {
				include WP_PLUGIN_DIR . '/' . $plugin;
			} catch ( \Exception $e ) {
				echo esc_attr( $e->getMessage() );
			}

			if ( ob_get_length() > 0 ) {
				ob_end_clean();
				$this->cron_rollback( $result, $hook_extra );
			}
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
		$options     = [ 'hook_extra' => $hook_extra ];
		include_once $wp_filesystem->wp_plugins_dir() . 'rollback-update-failure/wp-admin/includes/class-wp-upgrader.php';
		$rollback_updater = new \Rollback_Update_Failure\WP_Upgrader();

		// Set private $options variable.
		$ref_options = new \ReflectionProperty( $rollback_updater, 'options' );
		$ref_options->setAccessible( true );
		$ref_options->setValue( $rollback_updater, $options );

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

		return $result;
	}

	/**
	 * Handle errors by running Rollback.
	 *
	 * @param array $args Array of args.
	 *
	 * @return void
	 */
	public function handler( $args ) {
		\error_log( $args['error'] );
		$this->cron_rollback( $args['result'], $args['hook_extra'] );
	}
}
