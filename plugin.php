<?php
/**
 * Rollback Auto Updates
 *
 * @author  Andy Fragen, Colin Stewart
 * @license MIT
 * @link    https://github.com/afragen/rollback-auto-update
 * @package rollback-auto-updaters
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

namespace Rollback_Auto_Update;

/*
 * Exit if called directly.
 * PHP version check and exit.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

class Init {
	public function __construct() {
		require_once __DIR__ . '/rollback-auto-update.php';
		add_filter(
			'upgrader_install_package_result',
			[ __NAMESPACE__ . '\\Rollback_Auto_Update', 'init' ],
			15,
			2
		);
	}
}

new Init();
