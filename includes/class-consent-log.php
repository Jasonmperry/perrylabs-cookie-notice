<?php
/**
 * PLCN_Consent_Log — Server-side audit log of consent events.
 *
 * Stores hashed IP / UA only (not raw) to limit PII exposure while still
 * providing a defensible record for compliance audits.
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_Consent_Log {

    private static ?PLCN_Consent_Log $instance = null;

    const TABLE = 'plcn_consent_log';

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_nopriv_plcn_record_consent', array( $this, 'ajax_record' ) );
        add_action( 'wp_ajax_plcn_record_consent', array( $this, 'ajax_record' ) );
        add_action( 'admin_post_plcn_export_log', array( $this, 'stream_csv' ) );
    }

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install_table(): void {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            recorded_at DATETIME NOT NULL,
            policy_version INT(11) NOT NULL DEFAULT 1,
            categories TEXT NOT NULL,
            geo_region VARCHAR(32) DEFAULT '',
            ip_hash CHAR(64) NOT NULL DEFAULT '',
            ua_hash CHAR(64) NOT NULL DEFAULT '',
            action VARCHAR(32) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY recorded_at (recorded_at),
            KEY policy_version (policy_version)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * AJAX endpoint — records a consent event from the client.
     */
    public function ajax_record(): void {
        check_ajax_referer( 'plcn_consent_log', 'nonce' );

        $categories_raw = isset( $_POST['categories'] ) ? wp_unslash( $_POST['categories'] ) : '{}';
        $categories     = json_decode( $categories_raw, true );
        if ( ! is_array( $categories ) ) {
            $categories = array();
        }

        // Strip non-boolean values just in case.
        $clean = array();
        foreach ( PLCN_Consent::instance()->get_categories() as $cat ) {
            $clean[ $cat ] = ! empty( $categories[ $cat ] );
        }

        $action = isset( $_POST['plcn_action'] ) ? sanitize_text_field( wp_unslash( $_POST['plcn_action'] ) ) : '';
        $region = isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : '';

        $this->record( $clean, $action, $region );

        wp_send_json_success();
    }

    /**
     * Insert a row.
     */
    public function record( array $categories, string $action = '', string $region = '' ): void {
        global $wpdb;

        $ip = $this->get_client_ip();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Salt with wp_salt so the same IP doesn't produce the same hash across sites.
        $ip_hash = $ip ? hash( 'sha256', $ip . wp_salt( 'nonce' ) ) : '';
        $ua_hash = $ua ? hash( 'sha256', $ua . wp_salt( 'nonce' ) ) : '';

        $wpdb->insert(
            self::table_name(),
            array(
                'recorded_at'    => current_time( 'mysql', 1 ),
                'policy_version' => PLCN_Consent::instance()->get_policy_version(),
                'categories'     => wp_json_encode( $categories ),
                'geo_region'     => $region,
                'ip_hash'        => $ip_hash,
                'ua_hash'        => $ua_hash,
                'action'         => $action,
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Fetch the most recent N entries.
     */
    public function recent( int $limit = 50 ): array {
        global $wpdb;
        $table = self::table_name();
        $limit = max( 1, min( 500, $limit ) );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
    }

    public function count_total(): int {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /**
     * Stream the entire log as CSV to the browser (downloads as a file).
     * Called from the admin "Export CSV" button.
     */
    public function stream_csv(): void {
        if ( ! current_user_can( plcn_manage_capability() ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'plcn_export_log' );

        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="plcn-consent-log-' . gmdate( 'Y-m-d' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'id', 'recorded_at', 'policy_version', 'categories', 'geo_region', 'ip_hash', 'ua_hash', 'action' ) );
        foreach ( $rows as $row ) {
            fputcsv( $out, $row );
        }
        fclose( $out );
        exit;
    }

    private function get_client_ip(): string {
        $headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = explode( ',', $_SERVER[ $header ] )[0];
                return trim( $ip );
            }
        }
        return '';
    }
}
