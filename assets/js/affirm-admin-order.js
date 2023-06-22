/**
 * JS for Affirm Admin Order - Partial Capture
 *
 * @package WooCommerce
 */

jQuery(
	function ($) {
		var auth_remaining_amount = parseFloat( $( 'td#affirm-auth-remaining span.amount' ).text().substr( 1 ).replace( /\,/g,'' ) );

		// Partial capture toggle.
		$(
			function() {
				$( 'div.wc-affirm-partial-capture' ).appendTo( "#woocommerce-order-items .inside" );
				if (auth_remaining_amount == 0) {
					$( '#affirm-capture-amount' ).attr( "disabled", true );
					$( '#affirm-capture-remaining' ).attr( "disabled", true );
				}
			}
		);

		$( '#woocommerce-order-items' )
		// Toggle partial capture UI.
		.on(
			'click',
			'button.capture-affirm',
			function() {
				$( 'div.wc-affirm-partial-capture' ).slideDown();
				$( 'div.wc-order-data-row-toggle' ).not( 'div.wc-affirm-partial-capture' ).slideUp();
			}
		)

		// Update capture button amount on input change.
		.on(
			'input keyup',
			'.wc-affirm-partial-capture #affirm-capture-amount',
			function() {
				var current_val = $( this ).val();
				updateCaptureAmount( current_val );
			}
		)

		// Handle capture button click.
		.on(
			'click',
			'button.capture-action',
			function() {
				var input_capture_amount = parseFloat( $( 'input#affirm-capture-amount' ).val() );

				if ( input_capture_amount - auth_remaining_amount > 0 ) {
					return window.alert( 'Capture amount cannot exceed remaining authorized amount.' )
				}

				var formatted_amount = accounting.formatMoney( input_capture_amount );
				var confirm_prompt   = 'Are you sure you wish to capture ' + formatted_amount + '?';

				// POST Capture.
				if ( window.confirm( confirm_prompt ) ) {
					$.post(
						wc_affirm_admin_order.ajax_url,
						{
							capture_nonce: wc_affirm_admin_order.capture_nonce,
							action: wc_affirm_admin_order.action,
							order_id: wc_affirm_admin_order.order_id,
							amount: input_capture_amount
						},
						function(response) {
							if (true === response.success ) {
								window.location.reload();
							} else {
								window.alert( response.data.error );
							}
						}
					);

				}
			}
		)

		// Checkbox to capture remaining.
		$( '#affirm-capture-remaining' ).change(
			function() {
				if (this.checked) {
					$( '.wc-affirm-partial-capture #affirm-capture-amount' ).val( formatCurrency( auth_remaining_amount ).slice( 1 ) );
					updateCaptureAmount( auth_remaining_amount );
				} else {
					$( '.wc-affirm-partial-capture #affirm-capture-amount' ).val( "" )
					updateCaptureAmount( 0 );
				};
			}
		)

		// Helper to re-render update capture amount.
		function updateCaptureAmount( val ) {
			var total = accounting.unformat( val, woocommerce_admin.mon_decimal_point );

			if ( total !== auth_remaining_amount ) {
				$( '#affirm-capture-remaining' ).attr( "checked", false );
			} else {
				$( '#affirm-capture-remaining' ).attr( "checked", true );
			}

			if ( typeof total !== 'number' || ! ( total > 0 ) ) {
				total = 0;
				$( 'button.capture-action' ).attr( "disabled", true );
			} else {
				$( 'button.capture-action' ).attr( "disabled", false );
			}

			$( 'button.capture-action .woocommerce-Price-amount.amount' ).text( formatCurrency( total ) );
		}

		// Format currency.
		function formatCurrency( val ) {
			return accounting.formatMoney(
				val,
				{
					symbol:    woocommerce_admin_meta_boxes.currency_format_symbol,
					decimal:   woocommerce_admin_meta_boxes.currency_format_decimal_sep,
					thousand:  woocommerce_admin_meta_boxes.currency_format_thousand_sep,
					precision: woocommerce_admin_meta_boxes.currency_format_num_decimals,
					format:    woocommerce_admin_meta_boxes.currency_format
				}
			);
		}
	}
);
