<?php
/**
 * Plugin Name: WooCommerce Affirm Gateway
 * Plugin URI: https://woocommerce.com/products/woocommerce-gateway-affirm/
 * Description: Receive payments using the Affirm payments provider.
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 * Version: 2.1.0
 * WC tested up to: 4.9
 * WC requires at least: 3.2
 * Woo: 1474706:b271ae89b8b86c34020f58af2f4cbc81
 * Text Domain: woocommerce-gateway-affirm
 * Domain Path: /languages/
 *
 * Copyright (c) 2020 WooCommerce
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 * php version 7.2
 *
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @link     https://www.affirm.com/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Required functions and classes.
 */
if ( ! function_exists( 'woothemes_queue_update' ) ) {
	include_once 'woo-includes/class-woothemes-plugin-updater.php';
}

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Plugin updates.
 */
woothemes_queue_update(
	plugin_basename( __FILE__ ),
	'b271ae89b8b86c34020f58af2f4cbc81',
	'1474706'
);


// Include the main WooCommerce class.
if ( ! class_exists( 'WooCommerce_Gateway_Affirm', false ) ) {
	include_once dirname( __FILE__ ) . '/class-woocommerce-gateway-affirm.php';
}

/**
 * Returns Affirm.
 */
function affirm() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid.
	return WooCommerce_Gateway_Affirm::get_instance();
}

/**
 * Loads Affirm.
 */
$GLOBALS['wc_affirm_loader'] = affirm();
