<?php
use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Class for integrating with WooCommerce Blocks - Cart
 * 
 */
class WC_Affirm_Blocks_Cart implements IntegrationInterface {
	/**
	 * The name of the integration.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'wc-affirm-block-cart';
	}

	/**
     * The gateway instance.
     * 
     * @var WC_Gateway_Affirm
     */
    private $gateway_cartpage;


	/**
	 * When called invokes  initialization/setup for the integration.
     * 
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_affirm_settings', [] );
		$this->gateway_cartpage = new WC_Gateway_Affirm();

		$script_url = plugins_url('woocommerce-gateway-affirm/assets/blocks/js/frontend/cart.js');
		$script_asset_path = plugins_url('woocommerce-gateway-affirm/assets/blocks/js/frontend/cart.asset.php');
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => WC_GATEWAY_AFFIRM_VERSION,
			);

		wp_register_script(
			'wc-affirm-block-cart',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations('wc-affirm-block-cart');
		}
	}

	
	/**
	 * Returns an array of script handles to enqueue in the frontend context.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( 'wc-affirm-block-cart' );
	}

	/**
	 * Returns an array of script handles to enqueue in the editor context.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array( 'wc-affirm-block-cart' );
	}

	/**
	 * An array of key, value pairs of data made available to the cart-block on the client side.
	 *
	 * @return array
	 */
	public function get_script_data() {
		
		if ( $this->gateway_cartpage->testmode ) {
			$affirm_script_url = 'https://sandbox.affirm.com/js/v2/affirm.js';
		} else {
			$affirm_script_url = 'https://wwww.affirm.com/js/v2/affirm.js';
		}
		
		$site_locale = get_locale();
		
	    return [
			'affirmColor' => $this->gateway_cartpage->affirm_color,
			'public_key' => $this->gateway_cartpage->public_key,
			'public_key_ca' => $this->gateway_cartpage->public_key_ca,
			'script_url' => $affirm_script_url,
			'learnmore' => $this->gateway_cartpage->show_learnmore,
			'enabled' => ( $this->settings['enabled'] === 'yes') ? true : false,
			'valid_use' => $this->gateway_cartpage->isValidForUse(),
			'cart_ala' => $this->gateway_cartpage->cart_ala,
			'language_selector' => $this->gateway_cartpage->use_site_language,
			'site_locale' => $site_locale,
        ];
	}
}
?>
