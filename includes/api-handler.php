<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RS_API_Handler {

    public static function get_api_key() {
        $api_key = get_transient( 'rs_api_key' );
        if ( false !== $api_key ) {
            return $api_key;
        }

        $api_key = get_option( 'rs_api_key' );

        if ( empty( $api_key ) ) {
            return false;
        }

        set_transient( 'rs_api_key', $api_key, 12 * HOUR_IN_SECONDS );
        return $api_key;
    }

    public static function delete_api_key_transient() {
        delete_transient( 'rs_api_key' );
    }
}
// 🛑 STOP HERE. DO NOT INCLUDE sr_set_customer_shipping_details() or its hooks in this file.
