/**
 * JS for inline checkout
 *
 * @package WooCommerce
 */

jQuery( document ).ready(
	function ( $ ) {
		let affirmInitCheckout = true;

		if (affirmInlineCheckout.affirmInlineEnabled) {
			setTimeout(
				function () {
					if (affirmInlineCheckout.affirmSelected) {
						getAffirmInlineCheckoutobject()
					}
				},
				1000
			)

			var checkoutForm = $( 'form.checkout' );
			checkoutForm.on(
				'change',
				function (e) {
					if ($( '#payment_method_affirm' ).prop( 'checked' )) {
						setTimeout(
							function () {
								getAffirmInlineCheckoutobject()
							},
							1000
						);
					}
				}
			)

			let checkoutObject
			let checkoutFormData
			function getAffirmInlineCheckoutobject()
			{
				let formData = checkoutForm.serialize()
				if (checkoutFormData !== formData) {
					$( '.payment_box.payment_method_affirm' ).attr( 'id', 'affirm-inline-checkout' )
					checkoutFormData = formData
					$.post(
						affirmInlineCheckout.affirmInlineEndpoint,
						formData,
						function (response) {
							if (response != checkoutObject) {
								checkoutObject = response
								if (affirmInitCheckout) {
									affirm.ui.ready(
										function () {
											affirm.checkout( checkoutObject )
											affirm.checkout.inline(
												{
													merchant: {
														inline_container: "affirm-inline-checkout"
													}
												}
											);
										}
									)
									affirmInitCheckout = false
								} else {
									affirm.checkout.inline(
										{
											container: "affirm-inline-checkout",
											data: checkoutObject,
										}
									);
								}
							}
						},
					)
				}
			}
		}
	}
)
