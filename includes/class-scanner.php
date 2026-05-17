<?php
/**
 * PLCN_Scanner — Cookie scanner.
 *
 * Fetches a list of URLs server-side, parses Set-Cookie response headers,
 * and matches discovered cookies against PLCN_Cookie_DB to suggest the
 * service / category they belong to. Also scans the HTML body for known
 * tracker signatures (e.g. gtag/fbevents) so we can warn even when the
 * cookies are set client-side.
 *
 * Limits:
 *   - Cannot execute JS, so JS-set cookies are detected via script signatures
 *     rather than direct observation.
 *   - Requests go out as the WP HTTP API (server-side). Cookie behavior may
 *     differ from a real browser, but this is enough to surface 90% of the
 *     cookies typical sites set.
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_Scanner {

    private static ?PLCN_Scanner $instance = null;

    const OPTION_LAST_RUN = 'plcn_scanner_last_run';

    /**
     * Known tracker signatures detected in HTML bodies. Keyed by service slug
     * (matching PLCN_Cookie_DB / preset slug where applicable).
     */
    const HTML_SIGNATURES = array(
        'google-analytics-4' => array( 'googletagmanager.com/gtag/js', "gtag('config'" ),
        'google-tag-manager' => array( 'googletagmanager.com/gtm.js', "GTM-" ),
        'facebook-pixel'     => array( 'connect.facebook.net/en_US/fbevents.js', 'fbq(' ),
        'linkedin-insight'   => array( '_linkedin_partner_id', 'snap.licdn.com/li.lms-analytics/insight.min.js' ),
        'hubspot'            => array( 'js.hs-scripts.com', 'js.hsadspixel.net' ),
        'stripe'             => array( 'js.stripe.com/v3' ),
        'cloudflare'         => array( 'static.cloudflareinsights.com/beacon.min.js' ),
        'youtube'            => array( 'youtube.com/embed/', 'youtube-nocookie.com/embed/' ),
        'vimeo'              => array( 'player.vimeo.com/video/' ),
        'sentry'             => array( 'browser.sentry-cdn.com', '@sentry/browser' ),
    );

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Scan a list of URLs (or, if empty, just the home page). Returns a
     * structured result and persists it for the Scanner admin tab.
     */
    public function scan( array $urls = array() ): array {
        if ( empty( $urls ) ) {
            $urls = array( home_url( '/' ) );
        }

        $started_at      = current_time( 'mysql', 1 );
        $discovered      = array(); // cookie_name => entry
        $signatures      = array(); // service_slug => array of URLs that matched
        $errors          = array();
        $checked_urls    = array();

        foreach ( $urls as $url ) {
            $url = esc_url_raw( $url );
            if ( empty( $url ) ) continue;

            $response = wp_remote_get( $url, array(
                'timeout'     => 8,
                'redirection' => 3,
                'user-agent'  => 'PerryLabs-Cookie-Scanner/1.0 (+plcn)',
                'headers'     => array( 'Accept' => 'text/html,*/*' ),
            ) );

            if ( is_wp_error( $response ) ) {
                $errors[] = array( 'url' => $url, 'error' => $response->get_error_message() );
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );

            $checked_urls[] = array(
                'url'    => $url,
                'status' => (int) $code,
                'size'   => strlen( $body ),
            );

            // Parse cookies set by the response.
            $cookies = wp_remote_retrieve_cookies( $response );
            if ( is_array( $cookies ) ) {
                foreach ( $cookies as $c ) {
                    $name = is_object( $c ) ? ( $c->name ?? '' ) : '';
                    if ( '' === $name ) continue;

                    $service = PLCN_Cookie_DB::identify( $name );
                    $expires = is_object( $c ) && property_exists( $c, 'expires' ) ? $c->expires : 0;
                    $duration = $this->duration_label( $expires );

                    if ( ! isset( $discovered[ $name ] ) ) {
                        $discovered[ $name ] = array(
                            'name'     => $name,
                            'service'  => $service,
                            'category' => $this->category_for_service( $service ),
                            'duration' => $duration,
                            'count'    => 0,
                            'first_seen_on' => $url,
                        );
                    }
                    $discovered[ $name ]['count']++;
                }
            }

            // Detect known scripts in the HTML body.
            foreach ( self::HTML_SIGNATURES as $service => $needles ) {
                foreach ( $needles as $needle ) {
                    if ( false !== stripos( $body, $needle ) ) {
                        if ( ! isset( $signatures[ $service ] ) ) {
                            $signatures[ $service ] = array();
                        }
                        if ( ! in_array( $url, $signatures[ $service ], true ) ) {
                            $signatures[ $service ][] = $url;
                        }
                        break;
                    }
                }
            }
        }

        $result = array(
            'started_at'  => $started_at,
            'finished_at' => current_time( 'mysql', 1 ),
            'cookies'     => array_values( $discovered ),
            'signatures'  => $signatures,
            'errors'      => $errors,
            'urls'        => $checked_urls,
        );

        update_option( self::OPTION_LAST_RUN, $result, false );
        return $result;
    }

    public function get_last(): array {
        $data = get_option( self::OPTION_LAST_RUN, array() );
        return is_array( $data ) ? $data : array();
    }

    /**
     * Given a service slug, return its default category (from the matching
     * preset if any, otherwise "other").
     */
    private function category_for_service( string $service ): string {
        if ( '' === $service ) return 'other';
        $preset = PLCN_Script_Registry::PRESETS[ $service ] ?? null;
        return $preset['category'] ?? 'other';
    }

    /**
     * Convert an `expires` epoch into a human-readable label. 0 means session.
     */
    private function duration_label( $expires ): string {
        if ( empty( $expires ) ) return 'session';
        $now  = time();
        $diff = (int) $expires - $now;
        if ( $diff < 0 ) return 'expired';
        if ( $diff < 90 )           return $diff . ' seconds';
        if ( $diff < 3600 )         return round( $diff / 60 ) . ' minutes';
        if ( $diff < 86400 )        return round( $diff / 3600 ) . ' hours';
        if ( $diff < 86400 * 60 )   return round( $diff / 86400 ) . ' days';
        if ( $diff < 86400 * 365 )  return round( $diff / ( 86400 * 30 ) ) . ' months';
        return round( $diff / ( 86400 * 365 ), 1 ) . ' years';
    }
}
