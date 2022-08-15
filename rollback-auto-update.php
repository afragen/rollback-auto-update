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
 * Version:           0.6.3
 * Author:            WP Core Contributors
 * License:           MIT
 * Requires at least: 5.9
 * Requires PHP:      5.6
 * GitHub Plugin URI: https://github.com/afragen/rollback-auto-update
 * Primary Branch:    main
 */

namespace Fragen;

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

		$this->handler_args = [
			'handler_error' => '',
			'result'        => $result,
			'hook_extra'    => $hook_extra,
		];
		$processed          = (array) get_site_transient( 'processed_auto_updates' );

		// Register exception and shutdown handlers.
		$this->initialize_handlers();

		// Working parts of `plugin_sandbox_scrape()`.
		wp_register_plugin_realpath( WP_PLUGIN_DIR . '/' . $hook_extra['plugin'] );
		if ( 'rollback-auto-update/rollback-auto-update.php' !== $hook_extra['plugin'] ) {
			include_once WP_PLUGIN_DIR . '/' . $hook_extra['plugin'];
		}

		$processed[] = $hook_extra['plugin'];
		set_site_transient( 'processed_auto_updates', $processed, 60 );

		\error_log( $hook_extra['plugin'] . ' auto updated ' );

		return $result;
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
		$this->handler();
	}

	/**
	 * Handles Exceptions.
	 */
	public function exception_handler() {
		$this->handler_args['handler_error'] = 'Exception Caught';
		$this->handler();
	}

	/**
	 * Handles errors by running Rollback.
	 */
	private function handler() {
		// Exit for non-fatal errors.
		$e = error_get_last();
		if ( ! empty( $e ) && $this->error_types !== $e['type'] ) {
			// error_log( 'return on non-fatal error: ' . $this->handler_args['hook_extra']['plugin'] );
			// error_log( 'non-fatal error: ' . \var_export( $e, true ) );
			return;
		}
		set_site_transient( 'rollback_fatal_plugin', [ $this->handler_args['hook_extra']['plugin'] ], 60 );

		$this->cron_rollback();
		$this->log_error_msg( $e );
		$this->restart_updates();
		$this->send_update_result_email();
		return;
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

		$this->send_fatal_error_email();
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
	 * @param array $e Error code.
	 */
	private function log_error_msg( $e ) {
		$error_msg = sprintf(
			'Rollback Auto-Update: %1$s in %2$s, error type %3$s',
			$this->handler_args['handler_error'],
			$this->handler_args['hook_extra']['plugin'],
			empty( $e ) ? 'fatal' : $e['type']
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
			// Time to notify the site administrator.
			// $this->send_update_result_email();
			return;
		}

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		\error_log( 'Plugin_Upgrader::bulk_upgrade' . "\n" . var_export( $remaining_auto_updates, true ) );
		$upgrader->bulk_upgrade( $remaining_auto_updates );
	}

	/**
	 * Sends an email noting successful and failed updates.
	 */
	private function send_update_result_email() {
		$processed      = (array) get_site_transient( 'processed_auto_updates' );
		$fatals         = (array) get_site_transient( 'rollback_fatal_plugin' );
		$update_plugins = get_site_transient( 'update_plugins' );

		$successful = $failed = [];

		\error_log( 'send_update_result_email: ' . \var_export( $processed, true ) );
		/*
		 * Using `get_plugin_data()` instead has produced warnings/errors
		 * as the files may not be in place at this time.
		 */
		$plugins = get_plugins();

		foreach ( $update_plugins->response as $k => $update ) {
			$item = $update_plugins->response[ $k ];
			$name = $plugins[ $update->plugin ]['Name'];

			/*
			 * This appears to be the only way to get a plugin's older version
			 * at this stage of an auto-update when not implementing this
			 * feature directly in Core.
			 */
			$current_version = $update_plugins->checked[ $update->plugin ];

			/*
			 * The `current_version` property does not exist yet. Add it.
			 *
			 * `$update_plugins->response[ $k ]` is an instance of `stdClass`,
			 * so this should not fall victim to PHP 8.2's deprecation of
			 * dynamic properties.
			 */
			$item->current_version = $current_version;

			$plugin_result = (object) [
				'name' => $name,
				'item' => $item,
			];

			if ( in_array( $update->plugin, $processed, true ) ) {
				$successful['plugin'][] = $plugin_result;
				continue;
			}

			if ( in_array( $update->plugin, $fatals, true ) ) {
				$failed['plugin'][] = $plugin_result;
			}
		}

		$automatic_upgrader      = new \WP_Automatic_Updater();
		$send_plugin_theme_email = new \ReflectionMethod( $automatic_upgrader, 'send_plugin_theme_email' );
		$send_plugin_theme_email->setAccessible( true );
		$send_plugin_theme_email->invoke( $automatic_upgrader, 'success', $successful, $failed );
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
		$processed = (array) get_site_transient( 'processed_auto_updates' );
		$fatals    = (array) get_site_transient( 'rollback_fatal_plugin' );

		// Get array of plugins set for auto-updating.
		$auto_updates    = (array) get_site_option( 'auto_update_plugins', [] );
		$current         = get_site_transient( 'update_plugins' );
		$current_plugins = array_keys( $current->response );

		// Get all auto-updating plugins that have updates available.
		$current_auto_updates = array_intersect( $auto_updates, $current_plugins );
		error_log( 'current_auto_updates ' . var_export( $current_auto_updates, true ) );
		error_log( 'fatals ' . var_export( $fatals, true ) );

		// Get array of non-fatal auto-updates remaining.
		$remaining_auto_updates = array_diff( $current_auto_updates, $processed, $fatals );

		$processed = array_unique( array_merge( $processed, $remaining_auto_updates ) );
		\set_site_transient( 'processed_auto_updates', $processed, 60 );
		error_log( 'remaining_auto_updates ' . var_export( $remaining_auto_updates, true ) );

		return $remaining_auto_updates;
	}
}

new Rollback_Auto_Update();
