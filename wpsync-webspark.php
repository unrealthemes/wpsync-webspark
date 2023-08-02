<?php

/**
 *
 * Plugin Name:       WPsync Webspark
 * Description:       Synchronization of the products via API 
 * Version:           1.0.0
 * Author:            Roman Bondarenko
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       webspark
 * Domain Path:       /languages
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'WEBSPARK_BASE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WEBSPARK_BASE_NAME', plugin_basename( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class.activator.php
 */
function webspark_activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class.activator.php';
	$webspark_activator = new Webspark_Activator();
	$webspark_activator->activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class.deactivator.php
 */
function webspark_deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class.deactivator.php';
	Webspark_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'webspark_activate' );
register_deactivation_hook( __FILE__, 'webspark_deactivate' );



require plugin_dir_path( __FILE__ ) . 'includes/class.product-synchronization.php';

$wps = new Webspark_Product_Sync();