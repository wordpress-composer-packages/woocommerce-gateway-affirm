<?php
/**
 * Affirm Payment Gateway
 *
 * Provides a form based Affirm Payment Gateway.
 *
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @link     https://www.affirm.com/
 */

define( 'WC_GATEWAY_AFFIRM_VERSION', '2.1.0' ); // WRCS: DEFINED_VERSION.
define( 'WC_GATEWAY_AFFIRM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
use Automattic\WooCommerce\Utilities\OrderUtil;
/**
 * Class WooCommerce_Gateway_Affirm
 * Load Affirm
 *
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @link     https://www.affirm.com/
 */
class WooCommerce_Gateway_Affirm {


	/**
	 * The reference the *Singleton* instance of this class.
	 *
	 * @var WooCommerce_Gateway_Affirm
	 */
	private static $_instance;

	/**
	 * Whether or not we've already embedded the affirm script loader.
	 *
	 * @deprecated Since 1.1.0
	 *
	 * @var bool
	 */
	private $_loader_has_been_embedded = false;

	/**
	 * Instance of WC_Gateway_Affirm.
	 *
	 * @var WC_Gateway_Affirm
	 */
	private $gateway = false;

	/**
	 * Returns the *Singleton* instance of this class.
	 *
	 * @return Singleton The *Singleton* instance.
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Public clone method to prevent cloning of the instance of the
	 * *Singleton* instance.
	 *
	 * @return void
	 */
	public function __clone() {
	}

	/**
	 * Public unserialize method to prevent unserializing of the *Singleton*
	 * instance.
	 *
	 * @return void
	 */
	public function __wakeup() {
	}

	/**
	 * Protected constructor to prevent creating a new instance of the
	 * *Singleton* via the `new` operator from outside of this class.
	 */
	protected function __construct() {

		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			array( $this, 'plugin_action_links' )
		);
		add_action(
			'plugins_loaded',
			array( $this, 'init_gateway' ),
			0
		);

		// WooCommerce Blocks integration
		add_action( 
			'woocommerce_blocks_loaded', 
			array( $this,'woocommerce_gateway_affirm_blocks_support' )
		);

		// Order actions.
		add_filter(
			'woocommerce_order_actions',
			array( $this, 'possibly_add_capture_to_order_actions' )
		);
		add_action(
			'woocommerce_order_action_wc_affirm_capture_charge',
			array( $this, 'possibly_capture_charge' )
		);
		add_action(
			'woocommerce_order_status_pending_to_cancelled',
			array( $this, 'possibly_void_charge' )
		);
		add_action(
			'woocommerce_order_status_processing_to_cancelled',
			array( $this, 'possibly_refund_captured_charge' )
		);
		add_action(
			'woocommerce_order_status_completed_to_cancelled',
			array( $this, 'possibly_refund_captured_charge' )
		);

		// Bulk capture.
		add_action(
			'admin_footer-edit.php',
			array( $this, 'possibly_add_capture_charge_bulk_order_action' )
		);
		add_action(
			'load-edit.php',
			array( $this, 'possibly_capture_charge_bulk_order_action' )
		);
		add_action(
			'admin_notices',
			array( $this, 'custom_bulk_admin_notices' )
		);

		// As low as.
		add_action(
			'wp_head',
			array( $this, 'affirm_js_runtime_script' )
		);
		add_action(
			'wp_enqueue_scripts',
			array( $this, 'possibly_enqueue_scripts' )
		);
		add_action(
			'woocommerce_after_shop_loop_item',
			array( $this, 'woocommerce_after_shop_loop_item' )
		);
		// Uses priority 15 to get the as-low-as to appear after the product price.
		add_action(
			'woocommerce_single_product_summary',
			array( $this, 'promo_message_after_product_price' ),
			15
		);
		add_action(
			'woocommerce_composite_add_to_cart_button',
			array( $this, 'promo_message_composite_products' ),
			1
		);
		add_action(
			'woocommerce_after_add_to_cart_form',
			array( $this, 'promo_message_after_add_to_cart' )
		);
		add_action(
			'woocommerce_cart_totals_after_order_total',
			array( $this, 'woocommerce_cart_totals_after_order_total' )
		);
		add_action(
			'woocommerce_thankyou',
			array( $this, 'wc_affirm_checkout_analytics' )
		);

		// Checkout Button -  Changes Place Order Button to Continue with Affirm.
		add_action(
			'woocommerce_before_checkout_form',
			array( $this, 'woocommerce_order_button_text' )
		);

		// Display merchant order fee.
		add_action(
			'woocommerce_admin_order_totals_after_total',
			array( $this, 'display_order_fee' )
		);

		// Affirm Inline Checkout.
		add_action(
			'woocommerce_checkout_after_order_review',
			array( $this, 'inline_checkout' )
		);
		add_action(
			'wc_ajax_wc_affirm_inline_checkout',
			array( $this, 'ajax_inline_checkout' )
		);

		// Partial capture.
		add_action(
			'woocommerce_order_item_add_action_buttons',
			array( $this, 'add_partial_capture_toggle' )
		);
		add_action(
			'woocommerce_admin_order_totals_after_total',
			array( $this, 'add_partial_capture_order_totals' ),
			1
		);
		add_action(
			'admin_enqueue_scripts',
			array( $this, 'admin_enqueue_scripts_order' )
		);
		add_action(
			'wp_ajax_wc_affirm_admin_order_capture',
			array( $this, 'ajax_capture_handler' )
		);

	}


	/**
	 * Initialize the gateway.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public function init_gateway() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		include_once dirname( __FILE__ ) . '/includes/class-wc-affirm-privacy.php';
		include_once plugin_basename( 'includes/class-wc-gateway-affirm.php' );
		load_plugin_textdomain(
			'woocommerce-gateway-affirm',
			false,
			trailingslashit(
				dirname(
					plugin_basename( __FILE__ )
				)
			)
		);
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
		$this->load_plugin_textdomain();
	}


	/**
	 * Adds plugin action links.
	 *
	 * @param array $links Plugin action links.
	 *
	 * @return array Plugin action links.
	 */
	public function plugin_action_links( $links ) {

		$settings_url = add_query_arg(
			array(
				'page'    => 'wc-settings',
				'tab'     => 'checkout',
				'section' => 'wc_gateway_affirm',
			),
			admin_url( 'admin.php' )
		);

		$plugin_links = array(
			'<a href="' . $settings_url .
			'">' .
			__( 'Settings', 'woocommerce-gateway-affirm' ) . '</a>',
			'<a href="http://docs.woothemes.com/document/woocommerce-gateway-affirm/">' .
			__( 'Docs', 'woocommerce-gateway-affirm' ) . '</a>',
			'<a href="http://support.woothemes.com/">' .
			__( 'Support', 'woocommerce-gateway-affirm' ) .
			'</a>',
		);
		return array_merge( $plugin_links, $links );
	}

	/**
	 * Return an instance of the gateway for those loader functions that need it
	 * so we don't keep creating it over and over again.
	 *
	 * @return object
	 * @since  1.0.0
	 */
	public function get_gateway() {
		if ( ! $this->gateway ) {
			$this->gateway = new WC_Gateway_Affirm();
		}
		return $this->gateway;
	}

	/**
	 * Helper method to check the payment method and authentication.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @since  1.0.7
	 * @return bool Returns true if the payment method is affirm
	 * and the auth flag is set. False otherwise.
	 */
	private function check_payment_method_and_auth_flag( $order ) {
		return $this->get_gateway()->check_payment_method( $order )
		&& ( $this->get_gateway()->issetOrderAuthOnlyFlag( $order )
			|| $this->get_gateway()->getPartiallyCapturedFlag( $order )
		);
	}

	/**
	 * Possibly add the means to capture an order with Affirm to the order actions
	 * This was added here and not in WC_Gateway_Affirm because that class' construct
	 * is not called until after this filter is fired.
	 *
	 * @param array $actions Order actions.
	 *
	 * @return array Order actions.
	 */
	public function possibly_add_capture_to_order_actions( $actions ) {
		if ( ! isset( $_REQUEST['id'] ) ) {
			return $actions;
		}
		$order = wc_get_order(
			wp_kses(
				wp_unslash( $_REQUEST['id'] ),
				array()
			)
		);

		if ( ! $this->check_payment_method_and_auth_flag( $order ) ) {
			return $actions;
		}

		$actions['wc_affirm_capture_charge'] = __(
			'Capture Charge (Full amount)',
			'woocommerce-gateway-affirm'
		);

		return $actions;
	}


	/**
	 * Possibly capture the charge.
	 * Used by woocommerce_order_action_wc_affirm_capture_charge hook
	 * / possibly_add_capture_to_order_actions
	 *
	 * @param object $order order.
	 *
	 * @return bool
	 * @since  1.0.0
	 */
	public function possibly_capture_charge( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $this->check_payment_method_and_auth_flag( $order ) ) {
			return false;
		}

		return $this->get_gateway()->capture_charge( $order );
	}

	/**
	 * Possibly void the charge.
	 *
	 * @param int|WC_Order $order Order ID or Order object.
	 *
	 * @return bool Returns true when succeed
	 */
	public function possibly_void_charge( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $this->check_payment_method_and_auth_flag( $order ) ) {
			return false;
		}

		return $this->get_gateway()->void_charge( $order );
	}

	/**
	 * Possibly refund captured charge of an order when it's cancelled.
	 *
	 * Hooked into order transition action from processing or completed to
	 * cancelled.
	 *
	 * @param int|WC_Order $order Order ID or Order object.
	 *
	 * @return bool Returns true when succeed
	 */
	public function possibly_refund_captured_charge( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $this->check_payment_method_and_auth_flag( $order ) ) {
			return false;
		}

		$order_id = version_compare(
			WC_VERSION,
			'3.0',
			'<'
		) ? $order->id() : $order->get_id();

		return $this->get_gateway()->process_refund(
			$order_id,
			null,
			__(
				'Order is cancelled',
				'woocommerce-gateway-affirm'
			)
		);
	}

	/**
	 * Possibly add capture charge bulk order action
	 * Surprisingly, WP core doesn't really make this easy.
	 * See http://wordpress.stackexchange.com/questions/29822/custom-bulk-action
	 * and https://www.skyverge.com/blog/add-custom-bulk-action/
	 *
	 * @since  1.0.0
	 */
	public function possibly_add_capture_charge_bulk_order_action() {

		global $post_type, $post_status;
		if ( 'shop_order' === $this->get_post_type() && 'trash' !== $post_status ) {
			?>
				<script type="text/javascript">
					jQuery( document ).ready( function ( $ ) {
						if (
							0
							== $( 'select[name^=action] option[value=wc_capture_charge_affirm]' )
								.size()
						) {
							$( 'select[name^=action]' ).append(
								$( '<option>' ).val(
									'wc_capture_charge_affirm'
								).text(
									'
								<?php
									esc_attr_e(
										'Capture Charge (Affirm)',
										'woocommerce-gateway-affirm'
									);
								?>
										' )
							);
						}
					});
				</script>
				<?php
		}
	}


	/**
	 * Handle the capture bulk order action
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public function possibly_capture_charge_bulk_order_action() {

		global $typenow;

		if ( 'shop_order' === $this->get_post_type() ) {

			// Get the action (
			// I'm not entirely happy with using this internal WP function,
			// but this is the only way presently
			// )
			// See https://core.trac.wordpress.org/ticket/16031.
			$wp_list_table = _get_list_table( 'WP_Posts_List_Table' );
			$action        = $wp_list_table->current_action();

			// Bail if not processing a capture.
			if ( 'wc_capture_charge_affirm' !== $action ) {
				return;
			}

			// Security check.
			check_admin_referer( 'bulk-posts' );

			// Make sure order IDs are submitted.
			if ( isset( $_REQUEST['id'] ) ) {
				$order_ids = array_map( 'absint', $_REQUEST['id'] );
			}

			$sendback = remove_query_arg(
				array(
					'captured',
					'untrashed',
					'deleted',
					'ids',
				),
				wp_get_referer()
			);
			if ( ! $sendback ) {
				$post_type = $this->get_post_type();
				$sendback = admin_url( "edit.php?post_type=$post_type" );
			}

			$capture_count = 0;

			if ( ! empty( $order_ids ) ) {
				// Give ourselves an unlimited timeout if possible.
				set_time_limit( 0 );

				foreach ( $order_ids as $order_id ) {

					$order              = wc_get_order( $order_id );
					$capture_successful = $this->possibly_capture_charge( $order );

					if ( $capture_successful ) {
						$capture_count++;
					}
				}
			}

			$sendback = add_query_arg(
				array(
					'captured' => $capture_count,
				),
				$sendback
			);
			$sendback = remove_query_arg(
				array(
					'action',
					'action2',
					'tags_input',
					'post_author',
					'comment_status',
					'ping_status',
					'_status',
					'post',
					'bulk_edit',
					'post_view',
				),
				$sendback
			);
			wp_redirect( $sendback );
			exit();

		} // End if().

	}


	/**
	 * Tell the user how much the capture bulk order action did
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function custom_bulk_admin_notices() {
		global $post_type, $pagenow;

		if ( 'edit.php' === $pagenow
			&& 'shop_order' === $this->get_post_type()
			&& isset( $_REQUEST['captured'] )
		) {

			$capture_count = (int) $_REQUEST['captured'];

			if ( 0 >= $capture_count ) {
				$message = __(
					'Affirm: No charges were able to be captured.',
					'woocommerce-gateway-affirm'
				);
			} else {
				$message = sprintf(
					/* translators: 1: number of charge(s) captured */
					_n(
						'Affirm: %s charge was captured.',
						'Affirm: %s charges were captured.',
						wp_kses(
							wp_unslash( $_REQUEST['captured'] ),
							array()
						),
						'woocommerce-gateway-affirm'
					),
					number_format_i18n(
						wp_kses(
							wp_unslash( $_REQUEST['captured'] ),
							array()
						)
					)
				);
			}

			?>
				<div class='updated'>
					<p>
			<?php echo esc_html( $message ); ?>
					</p>
				</div>
			<?php
		}
	}


	/**
	 * Add the gateway to WooCommerce
	 *
	 * @param array $methods methods.
	 *
	 * @return array
	 * @since  1.0.0
	 */
	public function add_gateway( $methods ) {
		$methods[] = 'WC_Gateway_Affirm';
		return $methods;
	}


	/**
	 * Loads front side script when viewing product and cart pages.
	 *
	 * @since   1.0.0
	 * @version 1.1.0
	 * @return  void
	 */
	public function possibly_enqueue_scripts() {
		if ( ! is_product() && ! is_cart() ) {
			return;
		}

		if ( ! $this->get_gateway()->isValidForUse() ) {
			return;
		}

		if ( ! $this->get_gateway()->enabled ) {
			return;
		}

		// See https://docs.affirm.com/Partners/Email_Service_Providers/Monthly_Payment_Messaging_API#Collect_the_loan_details
		// for maximum and minimum amounts.
		$options = array(
			'minimum' => 5000,    // $50 in cents.
			'maximum' => 3000000, // $30000 in cents.
		);

		// Add ALA options.
		$options = apply_filters( 'wc_gateway_affirm_as_low_as_data', $options );

		wp_register_script(
			'affirm_as_low_as',
			plugins_url( 'assets/js/affirm-as-low-as.js', __FILE__ ),
			array( 'jquery' ),
			WC_GATEWAY_AFFIRM_VERSION
		);
		wp_localize_script(
			'affirm_as_low_as',
			'affirmOptions',
			$options
		);
		wp_enqueue_script( 'affirm_as_low_as' );
	}

	/**
	 * Add Affirm's monthly payment messaging to single product page.
	 *
	 * @since   1.0.0
	 * @version 1.1.0
	 *
	 * @return string
	 */
	public function woocommerce_single_product_summary() {
		if ( $this->get_gateway()->product_ala ) {
			global $product;

			// Only do this for simple, variable, and composite products. This
			// gateway does not (yet) support subscriptions.
			$supported_types = apply_filters(
				'wc_gateway_affirm_supported_product_types',
				array(
					'simple',
					'variable',
					'grouped',
					'composite',
				)
			);

			if ( ! $product->is_type( $supported_types ) ) {
				return;
			}
			$price = $product->get_price() ? $product->get_price() : 0;

			// For intial messaging in grouped product, use the most low-priced one.
			if ( $product->is_type( 'grouped' ) ) {
				$price = $this->get_grouped_product_price( $product );
			}

			// For composite products, use promo_message_composite_products.
			if ( $product->is_type( 'composite' ) ) {
				return;
			}

			$this->render_affirm_monthly_payment_messaging(
				floatval( $price * 100 ),
				'product'
			);
		}
	}

	/**
	 * Conditionally render Affirm's monthly payment messaging
	 * to single product page after product price.
	 *
	 * @return void
	 */
	public function promo_message_after_product_price() {
		if ( $this->get_gateway()->product_ala_options === 'after_product_price' ) {
			$this->woocommerce_single_product_summary();
		}
	}

	/**
	 * Conditionally render Affirm's monthly payment messaging
	 * to single product page after add to cart button.
	 *
	 * @return void
	 */
	public function promo_message_after_add_to_cart() {
		if ( $this->get_gateway()->product_ala_options === 'after_add_to_cart' ) {
			$this->woocommerce_single_product_summary();
		}
	}

	/**
	 * Conditionally render Affirm's monthly payment messaging
	 * to composite product page after composite price before add to cart button.
	 *
	 * @return void
	 */
	public function promo_message_composite_products() {
		global $product;
		// Only use this for composite products.
		if ( $product->is_type( 'composite' ) && $this->get_gateway()->product_ala ) {

			$price = $product->get_price() ? $product->get_price() : 0;

			$this->render_affirm_monthly_payment_messaging(
				floatval( $price * 100 ),
				'product'
			);
		}
	}

	/**
	 * Get grouped product price by returning the most low-priced child.
	 *
	 * @param WC_Product $product Product instance.
	 *
	 * @return float Price.
	 */
	protected function get_grouped_product_price( $product ) {
		$children = array_filter(
			array_map(
				'wc_get_product',
				$product->get_children()
			),
			array(
				$this,
				'filter_visible_group_child',
			)
		);
		uasort( $children, array( $this, 'order_grouped_product_by_price' ) );

		return reset( $children )->get_price();
	}

	/**
	 * Filter visible child in grouped product.
	 *
	 * @param WC_Product $product Child product of grouped product.
	 *
	 * @since   1.1.0
	 * @version 1.1.0
	 *
	 * @return bool True if it's visible group child.
	 */
	public function filter_visible_group_child( $product ) {
		return $product
		&& is_a(
			$product,
			'WC_Product'
		)
		&& (
				'publish' === $product->get_status()
				|| current_user_can(
					'edit_product',
					$product->get_id()
				)
		);
	}

	/**
	 * Sort callback to sort grouped product child based on price, from low to
	 * high
	 *
	 * @param object $a Product A.
	 * @param object $b Product B.
	 *
	 * @since   1.1.0
	 * @version 1.1.0
	 * @return  int
	 */
	public function order_grouped_product_by_price( $a, $b ) {
		if ( $a->get_price() === $b->get_price() ) {
			return 0;
		}
		return ( $a->get_price() < $b->get_price() ) ? -1 : 1;
	}

	/**
	 * Add Affirm's monthly payment messaging below the cart total.
	 *
	 * @return string
	 */
	public function woocommerce_cart_totals_after_order_total() {
		if ( class_exists( 'WC_Subscriptions_Cart' )
		&& WC_Subscriptions_Cart::cart_contains_subscription()
		) {
			return;
		}

		?>
		<tr>
			<th></th>
			<td>
		<?php
		if ( $this->get_gateway()->cart_ala ) {
			$this->render_affirm_monthly_payment_messaging(
				floatval( WC()->cart->total ) * 100,
				'cart'
			);
		}
		?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render Affirm monthly payment messaging.
	 *
	 * @param float  $amount         Total amount to be passed to Affirm.
	 * @param string $affirm_page_type type.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	protected function render_affirm_monthly_payment_messaging(
		$amount,
		$affirm_page_type
	) {
		$attrs = array(
			'amount'         => $amount,
			'promo-id'       => $this->get_gateway()->promo_id,
			'affirm-color'   => $this->get_gateway()->affirm_color,
			'learnmore-show' => $this->get_gateway()->show_learnmore ? 'true' : 'false',
			'page-type'      => $affirm_page_type,
		);

		$data_attrs = '';
		foreach ( $attrs as $attr => $val ) {
			if ( ! $val ) {
				continue;
			}
			$data_attrs .= sprintf( ' data-%s="%s"', $attr, esc_attr( $val ) );
		}

		$affirm_message
		= '<p id="learn-more" class="affirm-as-low-as"' . $data_attrs . '>-</p>';

		if ( ( $amount > $this->get_gateway()->min * 100 )
		&& ( $amount < $this->get_gateway()->max * 100 )
		&& ( 'cart' === $attrs['page-type'] )
		) {
			//phpcs:ignore
			echo $affirm_message;
		} elseif ( 'product' === $attrs['page-type']
		|| 'category' === $attrs['page-type']
		) {
			//phpcs:ignore
			echo $affirm_message;
		}
	}

	/**
	 * Render script tag for Affirm JS runtime in the head.
	 *
	 * @since  1.1.0
	 * @return string
	 */
	public function affirm_js_runtime_script() {
		if ( ! $this->get_gateway()->isValidForUse() ) {
			return;
		}

		if ( ! $this->get_gateway()->enabled ) {
			return;
		}
		$currency     = get_woocommerce_currency();
		$country_code = $this->get_gateway()->get_country_by_currency(
			$currency
		);
		$public_key   = $this->get_gateway()->get_key( 'public', $country_code[1] );
		$testmode     = $this->get_gateway()->testmode;

		if ( $testmode ) {
			$script_url = 'https://sandbox.affirm.com/js/v2/affirm.js';
		} else {
			$script_url = 'https://www.affirm.com/js/v2/affirm.js';
		}
		$language_selector = $this->get_gateway()->use_site_language;
		$locale            = 'en_US';
		if ( 'USD' !== $currency ) {
			$my_current_lang = apply_filters( 'wpml_current_language', NULL );
			if ($my_current_lang) {
				if ( $my_current_lang === $locale ) {
					$locale = 'en_CA';
				}
				elseif ($my_current_lang == 'fr') {
					$locale = 'fr_CA';
				}
				else {
					$locale = 'en_CA';	
				}
			}
			elseif ( 'site_language' === $language_selector ) {
				$site_locale = get_locale();
				if ( $site_locale === $locale ) {
					$locale = 'en_CA';
				} else {
					$locale = $site_locale;
				}
			} else {
				$language = substr(
					wp_kses(
						wp_unslash(
							// phpcs:ignore
							$_SERVER['HTTP_ACCEPT_LANGUAGE']
						),
						array()
					),
					0,
					2
				);
				$site_locale = $language . '_' . $country_code[0];
				if ( $site_locale === $locale ) {
					$locale = 'en_CA';
				} else {
					$locale = $site_locale;	
				}
			}
		}

		?>
		<script>
			if ('undefined' === typeof _affirm_config) {
				var _affirm_config = {
					public_api_key: "<?php echo esc_js( $public_key ); ?>",
					script: "<?php echo esc_js( $script_url ); ?>",
					locale: "<?php echo esc_js( $locale ); ?>",
					country_code: "<?php echo esc_js( $country_code[1] ); ?>",

				};
				(function(l, g, m, e, a, f, b) {
					var d, c = l[m] || {},
						h = document.createElement(f),
						n = document.getElementsByTagName(f)[0],
						k = function(a, b, c) {
							return function() {
								a[b]._.push([c, arguments])
							}
						};
					c[e] = k(c, e, "set");
					d = c[e];
					c[a] = {};
					c[a]._ = [];
					d._ = [];
					c[a][b] = k(c, a, b);
					a = 0;
					for (
						b = "set add save post open " +
							"empty reset on off trigger ready setProduct"
							.split(" ");
						a < b.length; a++
					) d[b[a]] = k(c, e, b[a]);
					a = 0;
					for (b = ["get", "token", "url", "items"]; a < b.length; a++)
						d[b[a]] = function() {};
					h.async = !0;
					h.src = g[f];
					n.parentNode.insertBefore(h, n);
					delete g[f];
					d(g);
					l[m] = c
				})(
					window,
					_affirm_config,
					"affirm",
					"checkout",
					"ui",
					"script",
					"ready"
				);
			}
		</script>
		<?php

	}

	/**
	 * Embed Affirm's JavaScript loader.
	 *
	 * @since 1.0.0
	 *
	 * @deprecated Since 1.1.0
	 */
	public function embed_script_loader() {
		_deprecated_function( __METHOD__, '1.1.0', '' );

		if ( $this->loader_has_been_embedded ) {
			return;
		}

		$this->affirmJsRuntimeScript();

		$this->loader_has_been_embedded = true;
	}

	/**
	 * Add Tracking Code to the Thank You Page
	 *
	 * @param string $order_id order id.
	 */
	public function wc_affirm_checkout_analytics( $order_id ) {
		if ( ! $this->get_gateway()->enhanced_analytics ) {
			return;
		}
		$order        = new WC_Order( $order_id );
		$total        = floor( 100 * $order->get_total() );
		$order_id     = trim( str_replace( '#', '', $order->get_id() ) );
		$payment_type = $order->get_payment_method();
		$currency     = $order->get_currency();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product       = $item->get_product();
			$items_data [] = array(
				'name'      => $product->get_name(),
				'productId' => $product->get_sku(),
				'quantity'  => $item->get_quantity(),
				'price'     => floor( 100 * $item->get_total() ),
			);
		}
		?>
		<script>
			affirm.ui.ready(function () {
				affirm.analytics.trackOrderConfirmed({
						"orderId": "<?php echo esc_js( $order_id ); ?>",
						"total": "<?php echo esc_js( $total ); ?>",
						"paymentMethod": "<?php echo esc_js( $payment_type ); ?>",
						"currency": "<?php echo esc_js( $currency ); ?>",
					},
					null,
					true
				);
			});
		</script>
		<?php
	}

	/**
	 * ALA messaging
	 *
	 * @return string
	 */
	public function woocommerce_after_shop_loop_item() {
		if ( $this->get_gateway()->category_ala ) {
			global $product;

			// Only do this for simple, variable, and composite products. This
			// gateway does not (yet) support subscriptions.
			$supported_types = apply_filters(
				'wc_gateway_affirm_supported_product_types',
				array(
					'simple',
					'variable',
					'grouped',
					'composite',
				)
			);

			if ( ! $product->is_type( $supported_types ) ) {
				return;
			}
			$price = $product->get_price() ? $product->get_price() : 0;

			// For intial messaging in grouped product, use the most low-priced one.
			if ( $product->is_type( 'grouped' ) ) {
				$price = $this->get_grouped_product_price( $product );
			}

			if ( $product->is_type( 'composite' ) ) {
				$price = $product->get_composite_price( 'min' );
			}

			$this->render_affirm_monthly_payment_messaging(
				floatval( $price * 100 ),
				'category'
			);
		}
	}

	/**
	 * Update Checkout button verbiage
	 */
	public function woocommerce_order_button_text() {
		if ( ! $this->get_gateway()->inline ) {
			wp_register_script(
				'affirm_checkout_button',
				plugins_url(
					'assets/js/affirm-checkout-button.js',
					__FILE__
				),
				array( 'jquery' ),
				WC_GATEWAY_AFFIRM_VERSION
			);
			wp_enqueue_script(
				'affirm_checkout_button'
			);
		}
		wp_register_style(
			'affirm_css',
			plugins_url(
				'assets/css/affirm-checkout.css',
				__FILE__
			),
			'',
			WC_GATEWAY_AFFIRM_VERSION
		);
		wp_enqueue_style(
			'affirm_css'
		);

	}

	/**
	 * Displays the Affirm fee
	 *
	 * @param int $order_id The ID of the order.
	 *
	 * @return string return HTML for order fee
	 */
	public function display_order_fee( $order_id ) {
		if ( ! $this->get_gateway()->show_fee ) {
			return;
		}

		if ( ! empty( $this->get_gateway()->getOrderMeta( $order_id, 'fee_amount' ) ) ) {
			$fee_amount = $this->get_gateway()->getOrderMeta( $order_id, 'fee_amount' );
		} else {
			return;
		}
		?>

		<tr>
			<td class="label affirm-fee">
			<?php
				echo wc_help_tip(
					'This is the portion of the captured amount ' .
					'that represents the mertchant fee for the transaction.'
				);
			?>
				Affirm Fee:
			</td>
			<td width="1%"></td>
			<td class="total">
				-&nbsp;<?php esc_attr_e(0.01 * $this->get_gateway()->getOrderMeta( $order_id, 'fee_amount' )); ?>
			</td>
		</tr>
			<?php
	}
	// wc_price( 0.01 * $fee_amount
	/**
	 * Init inline checkout on frontend
	 */
	public function inline_checkout() {
		wp_register_script(
			'affirm_inline_checkout',
			plugins_url(
				'assets/js/affirm-inline-checkout.js',
				__FILE__
			),
			array(
				'jquery',
			),
			WC_GATEWAY_AFFIRM_VERSION,
			false
		);

		if ( $this->get_gateway()->inline ) {

			$wc_session         = WC()->session;
			$is_affirm_selected
			= ! ( empty( $wc_session ) ) && $wc_session->get( 'chosen_payment_method' ) === 'affirm';

			wp_localize_script(
				'affirm_inline_checkout',
				'affirmInlineCheckout',
				array(
					'affirmInlineEnabled'        =>
						true,
					'affirmSelected'             =>
						$is_affirm_selected ? true : false,
					'affirmInlineEndpoint'       =>
						WC_AJAX::get_endpoint( 'wc_affirm_inline_checkout' ),
					'affirmInlineCheckoutObject' =>
						$this->get_inline_checkout_object(),
				)
			);
		} else {
			wp_localize_script(
				'affirm_inline_checkout',
				'affirmInlineCheckout',
				array(
					'affirmInlineEnabled' => false,
				)
			);
		}

		wp_enqueue_script( 'affirm_inline_checkout' );
	}

	/**
	 * Format for inline checkout
	 *
	 * @param array $name_array name array.
	 *
	 * @return array $checkout_object returns inline checkout object JSON
	 */
	public function get_inline_checkout_object( $name_array = false ) {
		$customer = WC()->customer;
		$cart     = WC()->cart;

		$total           = floor( strval( 100 * $cart->total ) );
		$checkout_object = array(
			'merchant'        => array(
				'user_confirmation_url'        => '',
				'user_cancel_url'              => '',
				'user_confirmation_url_action' => 'POST',
			),
			'order_id'        => '',
			'shipping_amount' => '',
			'total'           => $total,
			'tax_amount'      => '',
			'metadata'        => array(
				'platform_type'    => 'WooCommerce',
				'platform_version' => WOOCOMMERCE_VERSION,
				'platform_affirm'  => WC_GATEWAY_AFFIRM_VERSION,
				'mode'             => 'inline',
			),
			'items'           => $this->format_inline_checkout( $cart->get_cart() ),
		);

		$checkout_object['shipping'] = $this->format_address(
			$customer->get_shipping(),
			$name_array ? $name_array['email'] : '',
			$name_array ? $name_array['phone'] : '',
			$name_array ? $name_array['billingFirstName'] : '',
			$name_array ? $name_array['billingLastName'] : ''
		);
		$checkout_object['billing']  = $this->format_address(
			$customer->get_shipping(),
			$name_array ? $name_array['email'] : '',
			$name_array ? $name_array['phone'] : '',
			$name_array ? $name_array['shippingFirstName'] : '',
			$name_array ? $name_array['shippingLastName'] : ''
		);

		return $checkout_object;
	}

	/**
	 * Format for inline checkout
	 *
	 * @param array $items items array.
	 *
	 * @return array $formatted_items formatted items
	 */
	private function format_inline_checkout( $items ) {
		$formatted_items = array();
		foreach ( $items as $item ) {
			$product          = wc_get_product( $item['data']->get_id() );
			$item_image_id    = $product->get_image_id();
			$image_attributes = wp_get_attachment_image_src( $item_image_id );
			$item_image_url   = wc_placeholder_img_src();
			if ( is_array( $image_attributes ) ) {
				$item_image_url = $image_attributes[0];
			}

			$formatted_items[] = array(
				'display_name'   => $item['data']->get_title(),
				'sku'            => $item['data']->get_sku(),
				'unit_price'     => floor( strval( 100 * $item['data']->get_price() ) ),
				'qty'            => $item['quantity'],
				'item_image_url' => $item_image_url,
				'item_url'       => $product->get_permalink(),
			);
		}

		return $formatted_items;
	}

	/**
	 * AJAX function for inline checkout
	 */
	public function ajax_inline_checkout() {
		// @codingStandardsIgnoreStart
		$bill_first_name = $_POST['billing_first_name'] ?
		$_POST['billing_first_name'] : '';
		$bill_last_name  = $_POST['billing_last_name'] ?
		$_POST['billing_last_name'] : '';
		$ship_first_name = $_POST['shipping_first_name'] ?
		$_POST['shipping_first_name'] : $bill_first_name;
		$ship_last_name  = $_POST['shipping_last_name'] ?
		$_POST['shipping_last_name'] : $bill_last_name;
		$phone           = $_POST['billing_phone'] ?
		$_POST['billing_phone'] : '';
		$email           = $_POST['billing_email'] ?
		$_POST['billing_email'] : '';
		// @codingStandardsIgnoreEnd
		$name_array = array(
			'billingFirstName'  => $bill_first_name,
			'billingLastName'   => $bill_last_name,
			'shippingFirstName' => $ship_first_name,
			'shippingLastName'  => $ship_last_name,
			'phone'             => $phone,
			'email'             => $email,
		);

		wp_send_json( $this->get_inline_checkout_object( $name_array ) );

	}

	/**
	 * Format address for inline checkout
	 *
	 * @param array  $address Address array.
	 * @param string $email   Email address.
	 * @param string $phone   Phone Number.
	 * @param string $first   First Name.
	 * @param string $last    Last Name.
	 *
	 * @return array $formatted_address formatted address array
	 */
	private function format_address( $address, $email, $phone, $first, $last ) {
		$formatted_address = false;
		$formatted_address = array(
			'name'         => array(
				'first' => $first,
				'last'  => $last,
			),
			'address'      => array(
				'street1'      => isset( $address['address_1'] ) ?
					$address['address_1'] : '',
				'street2'      => isset( $address['address_2'] ) ?
					$address['address_2'] : '',
				'city'         => isset( $address['city'] ) ?
					$address['city'] : '',
				'region1_code' => isset( $address['state'] ) ?
					$address['state'] : '',
				'postal_code'  => isset( $address['postcode'] ) ?
					$address['postcode'] : '',
				'country'      => isset( $address['country'] ) ?
					$address['country'] : '',
			),
			'phone_number' => $phone ? $phone : '',
			'email'        => $email ? $email : '',
		);

		return $formatted_address;
	}

	/**
	 * Add partial capture module in admin order page.
	 *
	 * @param \WC_Order $order the order object.
	 * @return void
	 * @since  1.4.0
	 */
	public function add_partial_capture_toggle( $order ) {
		global $post;
		if ( $this->is_global( $order->get_currency() ) ) {
			return;
		}

		if ( ! $this->get_gateway()->getIsPartialCaptureEnabled( $order ) || ! $this->get_gateway()->partial_capture ) {
			return;
		}

		$auth_total       = $this->get_gateway()->get_order_auth_amount( $order );
		$auth_remaining   = $this->get_gateway()->get_order_auth_remaining( $order );
		$already_captured = $this->get_gateway()->get_order_captured_total( $order );

		if ( $auth_remaining < 1 ) {
			return;
		}

		include WC_GATEWAY_AFFIRM_PLUGIN_PATH . 'includes/views/html-affirm-admin-order.php';
	}

	/**
	 * Add captured amount in the order totals table.
	 *
	 * @param string $order_id order_id.
	 * @return void
	 * @since  1.4.0
	 */
	public function add_partial_capture_order_totals( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $this->get_gateway()->getIsPartialCaptureEnabled( $order ) || ! $this->get_gateway()->partial_capture ) {
			return;
		}

		$already_captured = $this->get_gateway()->get_order_captured_total( $order );

		include WC_GATEWAY_AFFIRM_PLUGIN_PATH . 'includes/views/html-affirm-admin-order-total.php';

	}

	/**
	 * Enqueue scripts for admin order page.
	 *
	 * @param string $hook action hook to check.
	 * @return void
	 * @since  1.4.0
	 */
	public function admin_enqueue_scripts_order( $hook ) {
		if ( ! $this->get_gateway()->partial_capture ) {
			return;
		}

		global $post, $post_type;
		$order_id = $_REQUEST['id'];


		if ( $order_id && 'shop_order' === $this->get_post_type() && 'woocommerce_page_wc-orders' === $hook ) {
			$order = wc_get_order( $order_id );

			if ( ! $this->get_gateway()->getIsPartialCaptureEnabled( $order ) ) {
				return;
			}

			if ( ! $this->get_gateway()->check_payment_method( $order ) ) {
				return;
			}

			wp_enqueue_style(
				'affirm_admin_css',
				plugins_url(
					'assets/css/affirm-admin.css',
					__FILE__
				),
				'',
				WC_GATEWAY_AFFIRM_VERSION
			);

			wp_enqueue_script(
				'woocommerce-affirm-admin-order',
				plugins_url(
					'assets/js/affirm-admin-order.js',
					__FILE__
				),
				array( 'jquery' ),
				WC_GATEWAY_AFFIRM_VERSION,
				false
			);

			wp_localize_script(
				'woocommerce-affirm-admin-order',
				'wc_affirm_admin_order',
				array(
					'ajax_url'      => admin_url( 'admin-ajax.php' ),
					'capture_nonce' => wp_create_nonce( 'wc_affirm_admin_order_capture-' . $order_id ),
					'action'        => 'wc_affirm_admin_order_capture',
					'order_id'      => $order_id,
				)
			);

		}
	}

	/**
	 * Handle capture AJAX request from admin order page
	 *
	 * @return void
	 * @since  1.4.0
	 *
	 * @throws Exception When Auth amounts are not valid.
	 */
	public function ajax_capture_handler() {
		// phpcs:ignore
		$order_id = $_POST['order_id'];
		// phpcs:ignore
		$amount   = isset( $_POST['amount'] ) ? $_POST['amount'] : 0;

		if ( ! $this->get_gateway()->partial_capture ) {
			return;
		}

		try {

			check_ajax_referer( 'wc_affirm_admin_order_capture-' . $order_id, 'capture_nonce' );

			$order = wc_get_order( $order_id );

			if ( ! $this->get_gateway()->getIsPartialCaptureEnabled( $order ) ) {
				return;
			}

			// Validate capture amount.
			$auth_total       = $this->get_gateway()->get_order_auth_amount( $order );
			$auth_remaining   = $this->get_gateway()->get_order_auth_remaining( $order );
			$already_captured = $this->get_gateway()->get_order_captured_total( $order );

			if ( $auth_remaining < 1 ) {
				throw new Exception( 'This charge has been fully captured.' );
			}

			if ( isset( $amount ) ) {
				if ( $amount - $auth_remaining > 0 ) {
					throw new Exception( 'Capture amount cannot exceed remaining authorized amount.' );
				}

				if ( 0 === intval( $amount ) ) {
					// This AJAX request must not have zero amount in the body paramter.
					throw new Exception( 'Capture amount cannot be 0.' );
				}
			}

			// Capture.
			$success = $this->get_gateway()->capture_charge( $order, $amount );

			if ( $success ) {
				wp_send_json_success();
			} else {
				throw new Exception( 'Capture not successful.' );
			}
		} catch ( Exception $e ) {
			$this->get_gateway()->log( __FUNCTION__, $e->getMessage() . ' order_id: ' . $order_id );
			wp_send_json_error( array( 'error' => $e->getMessage() ) );
		}

		wp_die();
	}

	/**
	 * Check if this is not US
	 *
	 * @param string $currency currency.
	 *
	 * @return bool
	 */
	private function is_global( $currency ) {
		$country_code = $this->get_gateway()->get_country_by_currency(
			$currency
		);

		$global_country = array( 'CAN' );

		if ( in_array(
			$country_code[1],
			$global_country
		)
		) {
			return true;
		}
		return false;
	}

	/**
	 * Hook in WooCommerce Blocks integration. 
	 */

	public static function woocommerce_gateway_affirm_blocks_support() {
		/**
		 * Registers WC_Affirm_Blocks_Checkout class with the server side handling of payment methods. 
		 * 
		 */
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		  require_once dirname( __FILE__ ) . '/includes/class-wc-affirm-blocks-checkout.php';
		  add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
			  $payment_method_registry->register( new WC_Affirm_Blocks_Checkout );
			}
		  );
		}
		/**
		 * Registers WC_Affirm_Blocks_Cart class with the server side handling. 
		 * 
		 */
		if ( interface_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
			require_once dirname( __FILE__ ) . '/includes/class-wc-affirm-blocks-cart.php';
			add_action(
				'woocommerce_blocks_cart_block_registration',
				function(Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry $integration_registry ) { 
					$integration_registry->register( new WC_Affirm_Blocks_Cart() );
				}
			);
		}
	}

	/**
	 * Load Localisation files.
	 *
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain( 
			'woocommerce-gateway-affirm', 
			false, 
			dirname( plugin_basename( __FILE__ ) ) . '/languages' 
		);
	}

	/**
	 * Get Post type by ID
	 *
	 *
	 * @return string
	 */
	public function get_post_type() {
		$order_id = $_REQUEST['id'];
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return OrderUtil::get_order_type( $order_id );	
		} else {
			return get_post_type( $order_id );
		}
	}
}
