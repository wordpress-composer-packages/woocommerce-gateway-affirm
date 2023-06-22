<?php
/**
 * * WC_Gateway_Affirm_Charge_API
 *
 * WC_Gateway_Affirm_Charge_API connects to the affirm API
 * to do all charge actions ie capture, return void, auth
 *
 * PHP version 7.2
 *
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @link     https://www.affirm.com/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * * WC_Gateway_Affirm_Charge_API
 *
 * WC_Gateway_Affirm_Charge_API connects to the affirm API
 * to do all charge actions ie capture, return void, auth
 *
 * PHP version 7.2
 *
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @link     https://www.affirm.com/
 */
class WC_Gateway_Affirm_Charge_API {


	const STATUS_AUTHORIZED = 'authorized';

	/**
	 * Pointer to gateway making the request
	 *
	 * @var WC_Gateway_Affirm
	 */
	protected $gateway;


	/**
	 * Order ID for all interactions with Affirm's Transactions API
	 *
	 * @var integer
	 */
	protected $order_id;


	/**
	 * Constructor
	 *
	 * @param array  $gateway  gateway.
	 * @param string $order_id order id.
	 */
	public function __construct( $gateway, $order_id ) {
		$this->gateway  = $gateway;
		$this->order_id = $order_id;
	}


	/**
	 * Exchange the checkout token provided to us by Affirm in the postback
	 * for a charge id
	 *
	 * @param string $checkout_token checkout token.
	 * @param string $country_code country code.
	 *
	 * @return array|WP_Error Returns array containing charge ID. Otherwise
	 *                        WP_Error is returned
	 * @since  1.0.0
	 */
	public function request_charge_id_for_token( $checkout_token, $country_code ) {

		$response = $this->post_authenticated_json_request(
			'api/v1/transactions',
			array(
				'transaction_id' => $checkout_token,
				'expand'         => 'checkout',
			),
			$country_code
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Check HTTP status.
		$http_status = intval( wp_remote_retrieve_response_code( $response ) );
		if ( in_array( $http_status, array( 400, 401 ), true ) ) {
			return new WP_Error(
				'authorization_failed',
				__(
					'There was an issue authorizing your Affirm loan. Please check out again or use a different payment method.',
					'woocommerce-gateway-affirm'
				)
			);
		}

		if ( ! array_key_exists( 'body', $response ) ) {
			return new WP_Error(
				'unexpected_response',
				__(
					'Unexpected response from Affirm. Missing response body.',
					'woocommerce-gateway-affirm'
				)
			);
		}

		$body = json_decode( $response['body'] );
		if ( ! property_exists( $body, 'id' ) ) {
			return new WP_Error(
				'unexpected_response',
				__(
					'Unexpected response from Affirm. Missing id in response body.',
					'woocommerce-gateway-affirm'
				)
			);
		}

		// Validate this charge corresponds to the order.
		$validates = false;

		if ( property_exists( $body, 'checkout' ) ) {
			$checkout_expand = $body->checkout;

			if ( property_exists( $checkout_expand, 'metadata' ) ) {
				$metadata = $checkout_expand->metadata;

				if ( property_exists( $metadata, 'order_key' ) ) {
					$order             = wc_get_order( $this->order_id );
					$order_amount      = intval( floor( strval( 100 * $order->get_total() ) ) );
					$authorized_amount = $body->amount;
					$order_key         = version_compare(
						WC_VERSION,
						'3.0',
						'<'
					) ? $order->order_key : $order->get_order_key();
					$validates         = ( $metadata->order_key === $order_key );
					$amount_validation = ( $order_amount === $authorized_amount );
				}
			}
		}

		$result = array(
			'charge_id'         => $body->id,
			'validates'         => $validates,
			'amount_validation' => $amount_validation,
			'authorized_amount' => $authorized_amount,
		);

		return $result;
	}

	/**
	 * Read the charge information for a specific charge.
	 *
	 * @param string $charge_id Charge ID.
	 * @param string $country_code country code.
	 *
	 * @since  1.0.1
	 * @return bool|array Returns false if failed,
	 * otherwise array of charge information
	 */
	public function read_charge( $charge_id, $country_code ) {
		if ( empty( $charge_id ) ) {
			return false;
		}

		$response = $this->get_authenticated_json_request(
			"api/v1/transactions/{$charge_id}",
			$country_code
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body   = wp_remote_retrieve_body( $response );
		$charge = json_decode( $body );

		if ( empty( $charge->id ) ) {
			return false;
		}

		if ( $charge_id !== $charge->id ) {
			return false;
		}

		return $charge;
	}

	/**
	 * Capture the charge
	 *
	 * @param string  $charge_id charge id.
	 * @param integer $amount capture amount.
	 * @param string  $country_code country code.
	 *
	 * @return bool
	 * @since  1.0.0
	 *
	 * @throws Exception When non 200 response code.
	 */
	public function capture_charge( $charge_id, $amount, $country_code ) {

		$amount_in_cents = $amount ? intval( $amount * 100 ) : null;

		$request_body             = array();
		$request_body['order_id'] = strval( $this->order_id );
		if ( $amount_in_cents ) {
			$request_body['amount'] = $amount_in_cents;
		}

		$response = $this->post_authenticated_json_request(
			"api/v1/transactions/{$charge_id}/capture",
			$request_body,
			$country_code
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( ! array_key_exists( 'response', $response ) ) {
			return false;
		}

		$response_response = $response['response'];
		if ( ! array_key_exists( 'code', $response_response ) ) {
			return false;
		}

		if ( 409 === $response_response['code'] && ! is_null( $amount_in_cents ) ) {
			$_message = 'Charges on this instrument cannot be captured for an amount unequal to authorization hold amount (Status Code 409)';
			throw new Exception( $_message );
		} elseif ( 200 !== $response_response['code'] ) {
			return false;
		}

		if ( ! array_key_exists( 'body', $response ) ) {
			return false;
		}

		$body = json_decode( $response['body'] );

		$fee_amount      = property_exists( $body, 'fee' ) ? $body->fee : 0;
		$captured_amount = property_exists( $body, 'amount' ) ? $body->amount : 0;
		$event_id        = property_exists( $body, 'id' ) ? $body->id : '';

		return array(
			'fee_amount'      => $fee_amount, // in cents.
			'captured_amount' => $captured_amount, // in cents.
			'charge_id'       => $charge_id,
			'event_id'        => $event_id,
		);
	}


	/**
	 * Void the charge
	 *
	 * @param string $charge_id charge id.
	 * @param string $country_code country code.
	 *
	 * @return bool
	 * @since  1.0.0
	 */
	public function void_charge( $charge_id, $country_code ) {
		$charge = $this->read_charge( $charge_id, $country_code );
		if ( ! $charge ) {
			return false;
		}

		// Make sure charge is in authorized state.
		if ( self::STATUS_AUTHORIZED !== $charge->status ) {
			return false;
		}

		$response = $this->post_authenticated_json_request(
			"api/v1/transactions/{$charge_id}/void",
			array(),
			$country_code
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return true;
	}


	/**
	 * Refund the charge
	 * Amount in cents (e.g. $50.00 = 5000)
	 *
	 * @param string $charge_id charge_id.
	 * @param int    $amount    amount.
	 * @param string $country_code country code.
	 *
	 * @return array
	 *
	 * @since 1.0.0
	 */
	public function refund_charge( $charge_id, $amount, $country_code ) {

		$response = $this->post_authenticated_json_request(
			"api/v1/transactions/{$charge_id}/refund",
			array(
				'amount' => $amount,
			),
			$country_code
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( ! array_key_exists( 'response', $response ) ) {
			return false;
		}

		$response_response = $response['response'];
		if ( ! array_key_exists( 'code', $response_response ) ) {
			return false;
		}

		if ( 200 !== $response_response['code'] ) {
			return false;
		}

		if ( ! array_key_exists( 'body', $response ) ) {
			return false;
		}

		$body = json_decode( $response['body'] );

		$refund_amount  = 0;
		$transaction_id = '';
		$fee_refunded   = 0;

		if ( property_exists( $body, 'amount' ) ) {
			$refund_amount = intval( $body->amount );
		}

		if ( property_exists( $body, 'id' ) ) {
			$id = $body->id;
		}

		if ( property_exists( $body, 'fee_refunded' ) ) {
			$fee_refunded = intval( $body->fee_refunded );
		}

		return array(
			'amount'       => $refund_amount, // in cents.
			'id'           => $id,
			'fee_refunded' => $fee_refunded,
		);
	}


	/**
	 * Helper to POST json data to Affirm using Basic Authentication
	 *
	 * @param string $route The API endpoint we are POSTing to e.g. 'api/v1/transactions'.
	 * @param array  $body  The data (if any) to jsonify and POST to the endpoint.
	 * @param string $country_code country code.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private function post_authenticated_json_request( $route, $body = false, $country_code ) {

		if ( $this->gateway->testmode ) {
			$server = 'https://api.global-sandbox.affirm.com/';
		} else {
			$server = 'https://api.global.affirm.com/';
		}

		$url                       = $server . $route;
		$idempotency_key_from_uuid = wp_generate_uuid4();

		$options = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization'   => 'Basic ' . base64_encode(
					$this->gateway->get_key(
						'public',
						$country_code
					) . ':' . $this->gateway->get_key(
						'private',
						$country_code
					)
				),
				'Content-Type'    => 'application/json',
				'Idempotency-Key' => $idempotency_key_from_uuid,
				'Country-Code'    => $country_code,
			),
		);

		if ( ! empty( $body ) ) {
			$options['body'] = wp_json_encode( $body );
		}

		return wp_safe_remote_post( $url, $options );
	}

	/**
	 * Helper to GET json data from Affirm using Basic Authentication.
	 *
	 * @param string $route The API endpoint we are POSTing to e.g. 'api/v1/transactions'.
	 * @param string $country_code country code.
	 *
	 * @since  1.0.1
	 * @return string
	 */
	private function get_authenticated_json_request( $route, $country_code ) {
		if ( $this->gateway->testmode ) {
			$server = 'https://api.global-sandbox.affirm.com/';
		} else {
			$server = 'https://api.global.affirm.com/';
		}

		$url = $server . $route;

		$options = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode(
					$this->gateway->get_key(
						'public',
						$country_code
					) . ':' . $this->gateway->get_key(
						'private',
						$country_code
					)
				),
				'Content-Type'  => 'application/json',
				'Country-Code'  => $country_code,

			),
		);

		return wp_safe_remote_get( $url, $options );
	}
}
