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
 * Version:           0.6.5
 * Author:            WP Core Contributors
 * License:           MIT
 * Requires at least: 5.9
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
		require_once __DIR__ . '/wp-admin/includes/class-rollback-auto-update.php';
		add_action( 'init', [ 'WP_Rollback_Auto_Update', 'init' ] );
	}
}

new Init();
