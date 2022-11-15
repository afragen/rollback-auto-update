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
 * Version:           0.9.2.1
 * Author:            WP Core Contributors
 * License:           MIT
 * Requires at least: 6.0
 * Requires PHP:      5.6
 * GitHub Plugin URI: https://github.com/afragen/rollback-auto-update
 * Primary Branch:    main
 */

namespace WP_Rollback_Auto_Update;

/*
 * Exit if called directly.
 * PHP version check and exit.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Init
 */
class Init {

	/**
	 * Let's get started.
	 */
	public function __construct() {
		// Add to wp-admin/includes/admin.php.
		require_once __DIR__ . '/wp-admin/includes/class-rollback-auto-update.php';

		// Add to wp-admin/includes/admin-filters.php.
		add_action( 'init', array( 'WP_Rollback_Auto_Update', 'init' ) );
	}
}

new Init();
