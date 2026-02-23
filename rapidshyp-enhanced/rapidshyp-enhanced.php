<?php
/**
 * Plugin Name: Rapidshyp Enhanced
 * Description: Custom Rapidshyp plugin with WooCommerce shipping, auto AWB saving, tracking token saving, and webhook status updates.
 * Version: 2.5.1
 * Author: Sarfaraz Akhtar
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RS_ENHANCED_PATH', plugin_dir_path( __FILE__ ) );
define( 'RS_ENHANCED_URL',  plugin_dir_url( __FILE__ ) );

require_once RS_ENHANCED_PATH . 'includes/api-handler.php';
require_once RS_ENHANCED_PATH . 'includes/frontend-hooks.php';

/* -----------------------------
   Load Shipping Method
--------------------------------*/
function rs_init_shipping_method() {
    if ( ! class_exists( 'WC_Shipping_Rapidshyp_Enhanced' ) ) {
        require_once RS_ENHANCED_PATH . 'includes/class-wc-shipping-rapidshyp-enhanced.php';
    }
}
add_action( 'woocommerce_shipping_init', 'rs_init_shipping_method' );

function rs_add_shipping_method( $methods ) {
    $methods['rapidshyp_enhanced'] = 'WC_Shipping_Rapidshyp_Enhanced';
    return $methods;
}
add_filter( 'woocommerce_shipping_methods', 'rs_add_shipping_method' );

/* -----------------------------
   Admin settings UI
--------------------------------*/
function rs_add_admin_submenu() {
    add_submenu_page(
        'woocommerce',
        'Rapidshyp Enhanced Settings',
        'Rapidshyp Enhanced',
        'manage_woocommerce',
        'rapidshyp-enhanced',
        'rs_render_admin_settings_page'
    );
}
add_action( 'admin_menu', 'rs_add_admin_submenu' );

function rs_render_admin_settings_page() { ?>
    <div class="wrap">
        <h1>Rapidshyp Enhanced Settings</h1>
        <form method="post" action="options.php">
            <?php
                settings_fields( 'rs_settings_group' );
                do_settings_sections( 'rapidshyp-enhanced' );
                submit_button();
            ?>
        </form>

        <h2>Webhook URL</h2>
        <p>Paste this into Rapidshyp Webhook panel:</p>
        <pre><code><?php echo esc_url( rest_url( 'rapidshyp-enhanced/v1/webhook/order-status' ) ); ?></code></pre>
    </div>
<?php }

function rs_register_settings() {
    $settings_group = 'rs_settings_group';

    // 1. Register all settings in the background
    register_setting( $settings_group, 'rs_api_key' );
    register_setting( $settings_group, 'rs_pickup_location_name' );
    register_setting( $settings_group, 'rs_store_name', ['default' => 'DEFAULT'] );
    register_setting( $settings_group, 'rs_default_pickup_pincode' );
    register_setting( $settings_group, 'rs_shipping_charge_fallback', [ 'sanitize_callback' => 'floatval' ] );
    register_setting( $settings_group, 'rs_free_shipping_threshold', [ 'sanitize_callback' => 'floatval' ] );
    register_setting( $settings_group, 'rs_cod_charge', [ 'sanitize_callback' => 'floatval' ] );

    // 2. Create the visual sections
    add_settings_section( 'rs_api_settings_section', 'Rapidshyp API Settings', null, 'rapidshyp-enhanced' );
    add_settings_section( 'rs_shipping_settings_section', 'Shipping Configuration', null, 'rapidshyp-enhanced' );
    add_settings_section( 'rs_cod_settings_section', 'Cash on Delivery (COD) Settings', null, 'rapidshyp-enhanced' );

    // 3. Add API fields
    add_settings_field('rs_api_key', 'Rapidshyp API Key', 'rs_text_field_callback', 'rapidshyp-enhanced', 'rs_api_settings_section', ['label_for' => 'rs_api_key']);
    add_settings_field('rs_store_name', 'Rapidshyp Store Name', 'rs_text_field_callback', 'rapidshyp-enhanced', 'rs_api_settings_section', ['label_for' => 'rs_store_name']);
    add_settings_field('rs_pickup_location_name', 'Pickup Location Name', 'rs_text_field_callback', 'rapidshyp-enhanced', 'rs_api_settings_section', ['label_for'=>'rs_pickup_location_name']);
    add_settings_field('rs_default_pickup_pincode', 'Pickup Pincode', 'rs_text_field_callback', 'rapidshyp-enhanced', 'rs_api_settings_section', ['label_for'=>'rs_default_pickup_pincode']);

    // 4. RESTORED: Add Shipping & COD fields
    add_settings_field('rs_shipping_charge_fallback', 'Fallback Shipping Charge (₹)', 'rs_text_field_callback', 'rapidshyp-enhanced', 'rs_shipping_settings_section', ['label_for' => 'rs_shipping_charge_fallback', 'type' => 'number']);
    add_settings_field('rs_free_shipping_threshold', 'Free Shipping Threshold (₹)', 'rs_text_field_callback', 'rapidshyp-enhanced', 'rs_shipping_settings_section', ['label_for' => 'rs_free_shipping_threshold', 'type' => 'number']);
    add_settings_field('rs_cod_charge', 'COD Service Charge (₹)', 'rs_text_field_callback', 'rapidshyp-enhanced', 'rs_cod_settings_section', ['label_for' => 'rs_cod_charge', 'type' => 'number']);
}
add_action( 'admin_init', 'rs_register_settings' );

// Improved callback to support different types (text/number)
function rs_text_field_callback( $args ) {
    $option_name = $args['label_for'];
    $option_value = get_option( $option_name, '' );
    $type = isset($args['type']) ? $args['type'] : 'text';

    printf( '<input type="%s" id="%s" name="%s" value="%s" class="regular-text" />',
        esc_attr( $type ), esc_attr( $option_name ), esc_attr( $option_name ), esc_attr( $option_value )
    );
}

/* -----------------------------
   Create Rapidshyp order on thank you
--------------------------------*/
function rs_auto_create_rapidshyp_order( $order_id ) {
    if ( ! $order_id ) return;
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    if ( get_post_meta( $order_id, '_rapidshyp_awb', true ) ) return;

    $api_key = RS_API_Handler::get_api_key();
    if ( ! $api_key ) return;

    $shipping = $order->get_address( 'shipping' );
    $billing  = $order->get_address( 'billing' );

    $items = [];
    foreach ( $order->get_items() as $item ) {
        $items[] = [
            'name'  => $item->get_name(),
            'qty'   => $item->get_quantity(),
            'price' => $item->get_total(),
        ];
    }

    $body = [
        'merchant_order_id'     => (string) $order_id,
        'store'                 => get_option( 'rs_store_name', 'DEFAULT' ),
        'pickup_location_name'  => get_option( 'rs_pickup_location_name', '' ),
        'pickup_pincode'        => get_option( 'rs_default_pickup_pincode', '' ),

        'consignee_name'        => trim(($shipping['first_name'].' '.$shipping['last_name'])),
        'consignee_phone'       => $shipping['phone'] ?: $billing['phone'] ?: $order->get_billing_phone(),
        'consignee_address'     => trim($shipping['address_1'].' '.$shipping['address_2']),
        'consignee_city'        => $shipping['city'],
        'consignee_state'       => $shipping['state'],
        'consignee_pincode'     => $shipping['postcode'],

        'items'                 => $items,
        'cod_amount'            => ($order->get_payment_method()==='cod') ? $order->get_total() : 0,
    ];

    $endpoint = 'https://api.rapidshyp.com/rapidshyp/apis/v1/create_order';

    $response = wp_remote_post( $endpoint, [
        'headers' => [
            'Content-Type'    => 'application/json',
            'rapidshyp-token' => $api_key
        ],
        'body'    => wp_json_encode( $body ),
        'timeout' => 30,
    ]);

    if ( is_wp_error( $response ) ) return;

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $data ) ) return;

    $awb = $data['awb'] ?? ($data['records'][0]['awb'] ?? '') ?? ($data['records'][0]['shipment_details'][0]['awb'] ?? '');
    $tracking_url = $data['tracking_url'] ?? ($data['records'][0]['tracking_url'] ?? '') ?? ($data['records'][0]['shipment_details'][0]['labelURL'] ?? '');
    
    $token = '';
    if ( $tracking_url && preg_match('/\/t\/([0-9]+)/', $tracking_url, $m ) ) {
        $token = $m[1];
    }

    if ( $awb ) update_post_meta( $order_id, '_rapidshyp_awb', sanitize_text_field( $awb ) );
    if ( $tracking_url ) update_post_meta( $order_id, '_rapidshyp_tracking_url', esc_url_raw( $tracking_url ) );
    if ( $token ) update_post_meta( $order_id, '_rapidshyp_tracking_token', sanitize_text_field( $token ) );

    if ( $awb ) {
        $order->add_order_note( "Rapidshyp AWB Saved: {$awb}" );
    }
}
add_action( 'woocommerce_thankyou', 'rs_auto_create_rapidshyp_order', 10, 1 );

/* -----------------------------
   Webhook endpoint
--------------------------------*/
add_action( 'rest_api_init', function() {
    register_rest_route( 'rapidshyp-enhanced/v1', '/webhook/order-status', [
        'methods'  => 'POST',
        'callback' => 'rs_handle_rapidshyp_order_status_webhook',
        'permission_callback' => '__return_true',
    ]);
});

function rs_handle_rapidshyp_order_status_webhook( WP_REST_Request $request ) {
    $payload = $request->get_json_params();
    if ( ! is_array( $payload ) ) return rest_ensure_response([ 'success' => false, 'message' => 'Invalid payload' ]);

    $order_id = 0;
    if ( ! empty( $payload['merchant_order_id'] ) && is_numeric( $payload['merchant_order_id'] ) ) {
        $order_id = intval( $payload['merchant_order_id'] );
    } elseif ( ! empty( $payload['token'] ) ) {
        $token = sanitize_text_field( $payload['token'] );
        $orders = get_posts( [
            'post_type'   => 'shop_order',
            'meta_key'    => '_rapidshyp_tracking_token',
            'meta_value'  => $token,
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);
        if ( ! empty( $orders ) ) $order_id = intval( $orders[0] );
    }

    if ( ! $order_id ) return rest_ensure_response([ 'success' => false, 'message' => 'Order mapping failed' ]);

    $order = wc_get_order( $order_id );
    if ( ! $order ) return rest_ensure_response([ 'success' => false, 'message' => 'Order not found' ]);

    $awb    = $payload['awb'] ?? $payload['airwaybill'] ?? $payload['tracking_number'] ?? '';
    $status = $payload['status'] ?? $payload['shipment_status'] ?? '';

    if ( $awb ) update_post_meta( $order_id, '_rapidshyp_awb', sanitize_text_field( $awb ) );
    if ( $status ) update_post_meta( $order_id, '_rapidshyp_status', sanitize_text_field( $status ) );

    $note = 'Rapidshyp Status Update: ' . ($status ? sanitize_text_field($status) : 'Processing');
    $order->add_order_note( $note );

    return rest_ensure_response([ 'success' => true ]);
}