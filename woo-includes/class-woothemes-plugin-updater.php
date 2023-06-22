<?php
/**
 * Functions used by plugins
 *
 * PHP version 7.2
 *
 * @class    class-woothemes-plugin-updater
 * @package  WooCommerce
 * @link     https://www.affirm.com/
 */

if ( ! class_exists( 'WC_Dependencies' ) ) {
	include_once 'class-wc-dependencies.php';
}

/**
 * WC Detection
 */
if ( ! function_exists( 'is_woocommerce_active' ) ) {
	/**
	 * Checks if woocommece is active.
	 */
	function is_woocommerce_active() {
		return WC_Dependencies::woocommerce_active_check();
	}
}

/**
 * Queue updates for the WooUpdater
 */
if ( ! function_exists( 'woothemes_queue_update' ) ) {
	/**
	 * Queue updates for the WooUpdater
	 *
	 * @param string $file       file.
	 * @param int    $file_id    file id.
	 * @param int    $product_id product id.
	 *
	 * @return void
	 */
	function woothemes_queue_update( $file, $file_id, $product_id ) {
		global $woothemes_queued_updates;

		if ( ! isset( $woothemes_queued_updates ) ) {
			$woothemes_queued_updates = array();
		}

		$plugin             = new stdClass();
		$plugin->file       = $file;
		$plugin->file_id    = $file_id;
		$plugin->product_id = $product_id;

		$woothemes_queued_updates[] = $plugin;
	}
}
