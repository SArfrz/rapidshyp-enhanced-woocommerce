<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Shipping_Rapidshyp_Enhanced extends WC_Shipping_Method {

    public function __construct( $instance_id = 0 ) {
        parent::__construct( $instance_id );
        $this->id                 = 'rapidshyp_enhanced';
        $this->method_title       = __( 'Rapidshyp Enhanced Shipping', 'rapidshyp-enhanced' );
        $this->method_description = __( 'Handles shipping rates dynamically, offering free shipping based on cart total.', 'rapidshyp-enhanced' );
        $this->enabled            = $this->get_option( 'enabled' );
        $this->title              = $this->get_option( 'title', __( 'Standard Shipping', 'rapidshyp-enhanced' ) );
        $this->init();
    }

    public function init() {
        $this->init_form_fields();
        $this->init_settings();
        add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( 'Enable/Disable', 'rapidshyp-enhanced' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this shipping method', 'rapidshyp-enhanced' ),
                'default' => 'yes'
            ],
            'title' => [
                'title'       => __( 'Method Title', 'rapidshyp-enhanced' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'rapidshyp-enhanced' ),
                'default'     => __( 'Standard Shipping', 'rapidshyp-enhanced' ),
                'desc_tip'    => true,
            ],
        ];
    }

    public function calculate_shipping( $package = [] ) {
        $free_shipping_threshold = (float) get_option( 'rs_free_shipping_threshold', 499 );
        $fallback_shipping_charge = (float) get_option( 'rs_shipping_charge_fallback', 50 );
        
        // This ensures we use the final, tax-inclusive total for the calculation.
        $cart_subtotal = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax();

        $cost = $fallback_shipping_charge;
        $label = $this->title;

        // Apply free shipping only when cart total is strictly GREATER THAN the threshold
        if ( $free_shipping_threshold > 0 && $cart_subtotal > $free_shipping_threshold ) {
            $cost = 0;
            $label = __( 'Free Shipping', 'rapidshyp-enhanced' );
        }

        $rate = [ 'id' => $this->id . ':' . $this->instance_id, 'label' => $label, 'cost' => $cost, 'calc_tax' => 'per_order' ];
        $this->add_rate( $rate );
    }
}