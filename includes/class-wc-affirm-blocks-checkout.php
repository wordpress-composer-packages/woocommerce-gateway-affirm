<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
/**
 * WC_Affirm_Blocks_Support class. 
 * 
 * @extends AbstractPaymentMethodType
 */

final class WC_Affirm_Blocks_Checkout extends AbstractPaymentMethodType {
    
    /**
     * The gateway instance.
     * 
     * @var WC_Gateway_Affirm
     */
    private $gateway;


    /**
     * Payment method name/id.
     */
    protected $name = 'affirm';


    /**
     * This function initializes a gateway.
     * 
     * It will get called during the server side initialization process
     * (on every request). 
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_affirm_settings', [] );
        $this->gateway = new WC_Gateway_Affirm();
    }

    /**
     * Returns whether the payment method is enabled or not,
     * and checks if the currency is not supported or if
	 * setup is incomplete.
	 *
     * @return boolean Returns true if gateway is valid for use and enabled
     */

    public function is_active() {
        if ($this->get_setting( 'enabled' ) !== 'yes') {
            return false;
        };
        if (!$this->gateway->isValidForUse()) {
            return false;
        }
        return true;
	}


    /**
    * Registers the payment method scripts (using wp_register_script).
    * Returns an array of script handles to enqueue for this payment method in
    * the frontend context
    *
    * @return array
    */

      public function get_payment_method_script_handles() {
        $script_url = plugins_url('woocommerce-gateway-affirm/assets/blocks/js/frontend/checkout.js');
        $script_asset_path =  plugins_url('woocommerce-gateway-affirm/assets/blocks/js/frontend/checkout.asset.php');
        $script_asset = file_exists( $script_asset_path )
        ? require( $script_asset_path )
        : array(
            'dependencies' => array(),
            'version'      => WC_GATEWAY_AFFIRM_VERSION
        );
        
        wp_register_script(
            'wc-affirm-blocks-integration',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );  

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'wc-affirm-blocks-integration');
		}
        return ['wc-affirm-blocks-integration'];
      }

      /**
       * Returns an associative array of data we want to be exposed for the payment method client side script
       * 
       * @return array
       */

       public function get_payment_method_data() {
            return [
                'title'       => $this->gateway->title,
                'description' => $this->gateway->description,
                'min'         => $this->gateway->min,
                'max'         => $this->gateway->max,
                'icon'        => $this->gateway->icon,
                'countries'   => $this->gateway::AVAILABLE_COUNTRIES
            ];
       }
}
?>
