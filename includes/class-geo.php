<?php
/**
 * PLCN_Geo — Lightweight geo detection for compliance mode routing.
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_Geo {

    private static ?PLCN_Geo $instance = null;

    const COOKIE_NAME = 'plcn_geo';
    const GEO_API_URL = 'https://ipapi.co/json/';

    /**
     * EU member-state ISO codes.
     */
    const EU_COUNTRIES = array(
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        // EEA + UK for broad GDPR coverage.
        'IS', 'LI', 'NO', 'GB', 'CH',
    );

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_nopriv_plcn_geo_detect', array( $this, 'ajax_detect' ) );
        add_action( 'wp_ajax_plcn_geo_detect', array( $this, 'ajax_detect' ) );
    }

    /**
     * AJAX handler: proxy the geo API call so the browser JS can fetch the region
     * without CORS issues. Result is cached server-side in a transient keyed by IP.
     */
    public function ajax_detect(): void {
        // If the cookie already tells us, just return that.
        if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            wp_send_json_success( array( 'region' => sanitize_text_field( $_COOKIE[ self::COOKIE_NAME ] ) ) );
        }

        $ip = $this->get_client_ip();
        $transient_key = 'plcn_geo_' . md5( $ip );
        $cached = get_transient( $transient_key );

        if ( false !== $cached ) {
            wp_send_json_success( array( 'region' => $cached ) );
        }

        $response = wp_remote_get( self::GEO_API_URL, array(
            'timeout' => 5,
            'headers' => array( 'Accept' => 'application/json' ),
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_success( array( 'region' => 'other' ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $region = $this->classify( $body );

        set_transient( $transient_key, $region, DAY_IN_SECONDS );

        wp_send_json_success( array( 'region' => $region ) );
    }

    /**
     * Classify API response into eu / california / other.
     */
    private function classify( ?array $data ): string {
        if ( empty( $data ) || empty( $data['country_code'] ) ) {
            return 'other';
        }

        $country = strtoupper( $data['country_code'] );

        if ( in_array( $country, self::EU_COUNTRIES, true ) ) {
            return 'eu';
        }

        if ( 'US' === $country && isset( $data['region_code'] ) && 'CA' === strtoupper( $data['region_code'] ) ) {
            return 'california';
        }

        return 'other';
    }

    /**
     * Best-effort client IP.
     */
    private function get_client_ip(): string {
        $headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = explode( ',', $_SERVER[ $header ] )[0];
                return trim( $ip );
            }
        }
        return '0.0.0.0';
    }
}
