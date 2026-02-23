<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Enqueue Frontend Scripts
 */
function sr_enqueue_frontend_scripts() {
	wp_enqueue_script(
		'sr-pincode-checker',
		RS_ENHANCED_URL . 'includes/pincode-checker.js',
		[ 'jquery' ], '3.1.0', true
	);
	wp_localize_script( 'sr-pincode-checker', 'sr_ajax', [
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'sr-pincode-nonce' )
	]);
}
add_action( 'wp_enqueue_scripts', 'sr_enqueue_frontend_scripts' );

/* -------------------------------------------
   Save Rapidshyp tracking token from URL
--------------------------------------------*/
function rs_save_tracking_token_from_url( $order_id, $tracking_url ) {
	if ( empty( $order_id ) || empty( $tracking_url ) ) return;

	// Extract /t/<token>
	if ( preg_match( '/\/t\/([0-9A-Za-z_-]+)/', $tracking_url, $matches ) ) {
		$token = sanitize_text_field( $matches[1] );
		update_post_meta( $order_id, '_rapidshyp_tracking_token', $token );
		if ( $order = wc_get_order( $order_id ) ) {
			$order->add_order_note( 'Rapidshyp Tracking Token Saved: ' . $token );
		}
	}
}

/* -------------------------------------------
   Detect tracking links added in order notes
   (Rapidshyp token, IndiaPost, generic URLs)
--------------------------------------------*/
add_action( 'woocommerce_order_add_note', function( $comment_id, $comment ) {

	if ( empty( $comment->comment_post_ID ) ) return;
	$order_id = intval( $comment->comment_post_ID );
	$content  = $comment->comment_content;

	// Find first URL in the note
	if ( preg_match( '/https?:\/\/[^\s"]+/i', $content, $m ) ) {
		$url = esc_url_raw( $m[0] );
		update_post_meta( $order_id, '_rapidshyp_tracking_url', $url );

		// Rapidshyp token
		if ( preg_match( '/\/t\/([0-9A-Za-z_-]+)/', $url, $t ) ) {
			update_post_meta( $order_id, '_rapidshyp_tracking_token', sanitize_text_field($t[1]) );
		}

		// IndiaPost/tracking number like 'EE123456789IN' (two letters + 9 digits + two letters)
		if ( preg_match( '/[A-Z]{2}[0-9]{9}[A-Z]{2}/', $url, $ip ) ) {
			update_post_meta( $order_id, '_rapidshyp_tracking_token', sanitize_text_field( $ip[0] ) );
		}

		// Add confirm note
		if ( $order = wc_get_order( $order_id ) ) {
			$order->add_order_note( 'Tracking link saved from note: ' . $url );
		}
	}
}, 10, 2 );

/* -------------------------------------------
   Pincode checker (cart)
--------------------------------------------*/
function sr_display_cart_pincode_checker() {
	if ( 'cart' === get_option( 'woocommerce_enable_shipping_calc' ) ) {
		echo '<div class="sr-pincode-checker-wrapper" style="margin: 1em 0;">
			<h5 style="font-weight: bold;">Check Delivery Availability</h5>
			<p>Enter your pincode to check serviceability.</p>
			<div class="sr-pincode-form" style="display: flex; gap: 10px; align-items: center;">
				<input type="text" id="sr_cart_pincode" placeholder="Enter Pincode" maxlength="6" style="flex-grow: 1; max-width: 180px;" />
				<button type="button" id="sr_cart_pincode_btn" class="button">Check</button>
			</div>
			<div id="sr_cart_pincode_result" style="margin-top: 10px; font-weight: bold;"></div>
		</div>';
	}
}
add_action( 'woocommerce_after_shipping_calculator', 'sr_display_cart_pincode_checker' );

/* -------------------------------------------
   Ajax pincode check (serviceability)
--------------------------------------------*/
function sr_handle_pincode_check_ajax() {
	if ( ! class_exists( 'RS_API_Handler' ) ) {
		wp_send_json_error([ 'message' => 'System Error: Shipping handler class not available.' ]);
	}
	check_ajax_referer( 'sr-pincode-nonce', 'security' );

	$pincode = sanitize_text_field( $_POST['pincode'] ?? '' );
	if ( empty($pincode) || ! ctype_digit($pincode) || strlen($pincode) !== 6 ) {
		wp_send_json_error([ 'message' => 'Please enter a valid 6-digit pincode.' ]);
	}

	$api_key = RS_API_Handler::get_api_key();
	$pickup_pincode = get_option( 'rs_default_pickup_pincode', '' );

	if ( empty($api_key) || empty($pickup_pincode) ) {
		wp_send_json_error([ 'message' => '✗ Configuration Error: Missing API Key or Pickup Pincode in settings.' ]);
	}

	$payload = [
		'Pickup_pincode'    => $pickup_pincode,
		'Delivery_pincode'  => $pincode,
		'cod'               => true,
		'total_order_value' => 1.0,
		'weight'            => 1.0,
	];

	$response = wp_remote_post( 'https://api.rapidshyp.com/rapidshyp/apis/v1/serviceabilty_check', [
		'headers' => [ 'Content-Type' => 'application/json', 'rapidshyp-token' => $api_key ],
		'body'    => wp_json_encode( $payload ),
		'timeout' => 15
	] );

	if ( is_wp_error($response) ) {
		wp_send_json_error([ 'message' => 'WordPress HTTP Error. Please try again.' ]);
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( isset($body['status']) && $body['status'] === true && !empty($body['serviceable_courier_list']) ) {
		$message = sprintf( 'Delivery available to <strong>%s</strong>.', esc_html($pincode) );
		$earliest_edd_str = null;
		foreach ( $body['serviceable_courier_list'] as $courier ) {
			if ( !empty($courier['edd']) && ( $earliest_edd_str === null || strtotime($courier['edd']) < strtotime($earliest_edd_str) ) ) {
				$earliest_edd_str = $courier['edd'];
			}
		}
		$etd_message = '';
		if ( $earliest_edd_str && ($edd_date = DateTime::createFromFormat('d-m-Y', $earliest_edd_str)) ) {
			$edd_date->modify('+1 day');
			$etd_message = sprintf( 'Estimated delivery by <strong>%s</strong>.', $edd_date->format('F j, Y') );
		}
		wp_send_json_success([ 'message' => $message, 'etd' => $etd_message ]);
	} else {
		wp_send_json_error([ 'message' => 'Delivery not available to this pincode.' ]);
	}
}
add_action( 'wp_ajax_sr_check_pincode', 'sr_handle_pincode_check_ajax' );
add_action( 'wp_ajax_nopriv_sr_check_pincode', 'sr_handle_pincode_check_ajax' );

/* -------------------------------------------
   Checkout autofill and serviceability
--------------------------------------------*/
function sr_fetch_city_state_by_pincode_ajax() {
	if ( ! class_exists( 'RS_API_Handler' ) ) {
		wp_send_json_success([
			'message' => '✗ System Error: API handler not loaded.',
			'city' => '',
			'state' => '',
			'is_serviceable' => false
		]);
	}
	check_ajax_referer( 'sr-pincode-nonce', 'security' );

	$pincode = sanitize_text_field( $_POST['pincode'] ?? '' );
	if ( empty($pincode) || ! ctype_digit($pincode) || strlen($pincode) !== 6 ) {
		wp_send_json_success([ 'message' => '✗ Invalid pincode format.', 'city' => '', 'state' => '', 'is_serviceable' => false ]);
	}

	$api_key = RS_API_Handler::get_api_key();
	$city = '';
	$state = '';

	$location_response = wp_remote_get( "https://api.postalpincode.in/pincode/{$pincode}" );
	if ( ! is_wp_error($location_response) ) {
		$loc = json_decode( wp_remote_retrieve_body($location_response), true );
		if ( isset($loc[0]['Status']) && $loc[0]['Status'] === 'Success' ) {
			$po = $loc[0]['PostOffice'][0];
			$city  = ucwords(strtolower($po['District']));
			$state = ucwords(strtolower($po['State']));
		}
	}

	$is_serviceable = false;
	$pickup_pincode = get_option( 'rs_default_pickup_pincode', '' );

	if ( !empty($api_key) && !empty($pickup_pincode) ) {
		$cart_total = (WC()->cart) ? (float) WC()->cart->get_subtotal() : 1.0;
		$payload = [
			'Pickup_pincode'    => $pickup_pincode,
			'Delivery_pincode'  => $pincode,
			'cod'               => true,
			'total_order_value' => $cart_total,
			'weight'            => 1.0,
		];

		$rs_resp = wp_remote_post( 'https://api.rapidshyp.com/rapidshyp/apis/v1/serviceabilty_check', [
			'headers' => [ 'Content-Type' => 'application/json', 'rapidshyp-token' => $api_key ],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15
		]);

		if ( ! is_wp_error($rs_resp) ) {
			$rs = json_decode( wp_remote_retrieve_body($rs_resp), true );
			if ( isset($rs['status']) && $rs['status'] === true ) {
				$is_serviceable = true;
			}
		}
	}

	$message = $is_serviceable ? '✓ Serviceable: Delivery available.' : '✗ Not Serviceable: Delivery not available.';

	wp_send_json_success([ 'message' => $message, 'city' => $city, 'state' => $state, 'is_serviceable' => $is_serviceable ]);
}
add_action( 'wp_ajax_sr_fetch_city_state_by_pincode', 'sr_fetch_city_state_by_pincode_ajax' );
add_action( 'wp_ajax_nopriv_sr_fetch_city_state_by_pincode', 'sr_fetch_city_state_by_pincode_ajax' );

/* -------------------------------------------
   Update customer shipping address (AJAX)
--------------------------------------------*/
function sr_set_customer_shipping_details() {
	check_ajax_referer( 'sr-pincode-nonce', 'security' );
	if ( ! function_exists('WC') || ! ( $customer = WC()->customer ) ) {
		wp_send_json_error([ 'message' => 'WooCommerce environment not fully loaded for session update.' ]);
	}

	$pincode = sanitize_text_field($_POST['pincode'] ?? '');
	$city    = sanitize_text_field($_POST['city'] ?? '');
	$state   = sanitize_text_field($_POST['state'] ?? '');

	if ( $pincode ) $customer->set_shipping_postcode($pincode);
	if ( $city )    $customer->set_shipping_city($city);

	if ( $state ) {
		$state_code = WC()->countries->get_state_code_from_name( $state, $customer->get_shipping_country() );
		$customer->set_shipping_state( $state_code ?: $state );
	}

	$customer->save();
	wp_send_json_success();
}
add_action( 'wp_ajax_sr_set_customer_shipping_details', 'sr_set_customer_shipping_details' );
add_action( 'wp_ajax_nopriv_sr_set_customer_shipping_details', 'sr_set_customer_shipping_details' );

/* -------------------------------------------
   COD fee and shipping notice
--------------------------------------------*/
function rs_add_cod_fee( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
	if ( 'cod' === WC()->session->get( 'chosen_payment_method' ) ) {
		$fee = (float) get_option('rs_cod_charge', 0);
		if ( $fee > 0 ) $cart->add_fee( __( 'Cash on Delivery Charge', 'rapidshyp-enhanced' ), $fee, true );
	}
}
add_action( 'woocommerce_cart_calculate_fees', 'rs_add_cod_fee', 10 );

function rs_display_shipping_notice() {
	if ( ! is_cart() && ! is_checkout() ) return;
	if ( ! WC()->cart ) return;
	WC()->cart->calculate_totals();

	$threshold = (float) get_option( 'rs_free_shipping_threshold', 499 );
	if ( $threshold <= 0 || WC()->cart->is_empty() ) return;

	$cart_total = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax();

	if ( $cart_total <= $threshold ) {
		$remaining = ceil( ( $threshold + 1 ) - $cart_total );
		$message = sprintf( 'Add %s more to get <strong>Free Shipping!</strong>', wc_price( $remaining ) );
		$html = '<tr><td colspan="2" style="color:#a00;font-weight:bold;text-align:center;">' . $message . '</td></tr>';
	} else {
		$message = '🎉 <strong>You have unlocked Free Shipping!</strong>';
		$html = '<tr><td colspan="2" style="color:green;font-weight:bold;text-align:center;">' . $message . '</td></tr>';
	}

	echo $html;
}
add_action( 'woocommerce_cart_totals_after_shipping', 'rs_display_shipping_notice', 10 );
add_action( 'woocommerce_review_order_after_shipping', 'rs_display_shipping_notice', 10 );
add_action( 'woocommerce_review_order_after_order_total', 'rs_display_shipping_notice', 10 );
