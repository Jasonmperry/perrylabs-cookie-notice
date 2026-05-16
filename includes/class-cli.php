<?php
/**
 * PLCN_CLI — WP-CLI commands.
 *
 * Examples:
 *   wp plcn settings export > plcn-settings.json
 *   wp plcn settings import plcn-settings.json
 *   wp plcn policy bump
 *   wp plcn log export --since=2026-01-01 > consent.csv
 *   wp plcn log clear --yes
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

class PLCN_CLI {

    /**
     * Manage settings (export / import).
     *
     * ## OPTIONS
     *
     * <action>
     * : export | import
     *
     * [<file>]
     * : Path to the JSON file. Required for import. For export, omit to use stdout.
     *
     * ## EXAMPLES
     *
     *     wp plcn settings export > plcn-settings.json
     *     wp plcn settings import plcn-settings.json
     */
    public function settings( $args, $assoc_args ): void {
        list( $action ) = $args + array( null );
        $file = $args[1] ?? '';

        if ( 'export' === $action ) {
            $payload = array(
                'plugin'  => 'perrylabs-cookie-notice',
                'version' => PL_COOKIE_VERSION,
                'options' => get_option( 'plcn_options', array() ),
            );
            $json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            if ( $file ) {
                file_put_contents( $file, $json );
                WP_CLI::success( "Wrote {$file}" );
            } else {
                echo $json . "\n";
            }
            return;
        }

        if ( 'import' === $action ) {
            if ( ! $file || ! file_exists( $file ) ) {
                WP_CLI::error( 'Provide a path to an existing JSON file.' );
            }
            $data = json_decode( file_get_contents( $file ), true );
            if ( ! is_array( $data ) || empty( $data['options'] ) ) {
                WP_CLI::error( 'Invalid settings file.' );
            }
            update_option( 'plcn_options', $data['options'] );
            WP_CLI::success( 'Settings imported.' );
            return;
        }

        WP_CLI::error( 'Unknown action. Use "export" or "import".' );
    }

    /**
     * Bump the policy version (force re-consent for every visitor).
     *
     * ## EXAMPLES
     *
     *     wp plcn policy bump
     */
    public function policy( $args ): void {
        list( $action ) = $args + array( null );

        if ( 'bump' === $action ) {
            $opts = get_option( 'plcn_options', array() );
            $opts['policy_version'] = (int) ( $opts['policy_version'] ?? 1 ) + 1;
            update_option( 'plcn_options', $opts );
            WP_CLI::success( "Policy version bumped to {$opts['policy_version']}." );
            return;
        }

        WP_CLI::error( 'Unknown action. Use "bump".' );
    }

    /**
     * Manage the consent log.
     *
     * ## OPTIONS
     *
     * <action>
     * : export | clear
     *
     * [--since=<date>]
     * : Only export rows recorded after this date (YYYY-MM-DD).
     *
     * [--yes]
     * : Skip confirmation prompts (required for clear).
     *
     * ## EXAMPLES
     *
     *     wp plcn log export > consent.csv
     *     wp plcn log export --since=2026-01-01 > recent.csv
     *     wp plcn log clear --yes
     */
    public function log( $args, $assoc_args ): void {
        list( $action ) = $args + array( null );
        global $wpdb;
        $table = PLCN_Consent_Log::table_name();

        if ( 'export' === $action ) {
            $where = '';
            $params = array();
            if ( ! empty( $assoc_args['since'] ) ) {
                $where = ' WHERE recorded_at >= %s';
                $params[] = $assoc_args['since'];
            }
            $sql = "SELECT * FROM {$table}{$where} ORDER BY id DESC";
            $rows = $params
                ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
                : $wpdb->get_results( $sql, ARRAY_A );

            $out = fopen( 'php://output', 'w' );
            fputcsv( $out, array( 'id', 'recorded_at', 'policy_version', 'categories', 'geo_region', 'ip_hash', 'ua_hash', 'action' ) );
            foreach ( $rows as $row ) {
                fputcsv( $out, $row );
            }
            fclose( $out );
            return;
        }

        if ( 'clear' === $action ) {
            WP_CLI::confirm( 'Permanently delete every row in the consent log?', $assoc_args );
            $wpdb->query( "TRUNCATE TABLE {$table}" );
            WP_CLI::success( 'Consent log cleared.' );
            return;
        }

        WP_CLI::error( 'Unknown action. Use "export" or "clear".' );
    }
}

WP_CLI::add_command( 'plcn', 'PLCN_CLI' );
