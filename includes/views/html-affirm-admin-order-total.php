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

?>
<tr style="">
	<td class="label captured-total">
		Captured:
	</td>
	<td width="1%"></td>
	<td class="total captured-total">
		<?php
			$amout = wc_price( $already_captured / 100, array( 'currency' => $order->get_currency() ) );
			esc_attr( $amout );
		?>
	</td>
</tr>
