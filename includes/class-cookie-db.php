<?php
/**
 * PLCN_Cookie_DB — Known-cookie database.
 *
 * Maps known third-party services to the cookies they set. Used to auto-fill
 * the per-script metadata when an admin picks a preset, and by the cookie
 * scanner to recognize cookies it discovers.
 *
 * Each entry is keyed by a service slug (matches a preset where applicable)
 * with an array of cookie definitions:
 *   array(
 *       'name'     => 'cookie name (string or regex starting with ~)',
 *       'purpose'  => 'short human-readable purpose',
 *       'duration' => 'human readable (e.g. "2 years", "session")',
 *       'provider' => 'service or domain that sets it',
 *   )
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_Cookie_DB {

    /**
     * Known cookies by service slug. Names prefixed with "~" are regex patterns.
     */
    const COOKIES = array(
        'google-analytics-4' => array(
            array( 'name' => '_ga',     'purpose' => 'Distinguishes unique users (Google Analytics)',  'duration' => '2 years',  'provider' => 'Google' ),
            array( 'name' => '~^_ga_',  'purpose' => 'Persists session state per GA4 property',        'duration' => '2 years',  'provider' => 'Google' ),
            array( 'name' => '_gid',    'purpose' => 'Distinguishes unique users (Google Analytics)',  'duration' => '24 hours', 'provider' => 'Google' ),
            array( 'name' => '_gat',    'purpose' => 'Throttles request rate (Google Analytics)',      'duration' => '1 minute', 'provider' => 'Google' ),
        ),
        'google-tag-manager' => array(
            array( 'name' => '_ga',         'purpose' => 'Distinguishes unique users (via GTM container)', 'duration' => '2 years',  'provider' => 'Google' ),
            array( 'name' => '~^_gcl_',     'purpose' => 'Stores Google Ads click identifiers (gclid)',    'duration' => '90 days',  'provider' => 'Google' ),
            array( 'name' => 'IDE',         'purpose' => 'Used by Google DoubleClick for ad targeting',    'duration' => '13 months','provider' => 'Google' ),
        ),
        'facebook-pixel' => array(
            array( 'name' => '_fbp',  'purpose' => 'Identifies browsers for ad delivery / measurement', 'duration' => '90 days', 'provider' => 'Meta' ),
            array( 'name' => 'fr',    'purpose' => 'Used for ad delivery and measurement',              'duration' => '90 days', 'provider' => 'Meta' ),
            array( 'name' => '_fbc',  'purpose' => 'Stores last-visit click identifier (fbclid)',       'duration' => '90 days', 'provider' => 'Meta' ),
        ),
        'linkedin-insight' => array(
            array( 'name' => 'AnalyticsSyncHistory', 'purpose' => 'Sync identifier between LinkedIn services', 'duration' => '30 days', 'provider' => 'LinkedIn' ),
            array( 'name' => 'bcookie',              'purpose' => 'Browser ID for ad targeting',               'duration' => '1 year',  'provider' => 'LinkedIn' ),
            array( 'name' => 'li_gc',                'purpose' => 'Stores consent state for non-essential',    'duration' => '6 months','provider' => 'LinkedIn' ),
            array( 'name' => '~^UserMatchHistory',   'purpose' => 'LinkedIn Ads ID syncing',                   'duration' => '30 days', 'provider' => 'LinkedIn' ),
        ),
        'hubspot' => array(
            array( 'name' => '__hstc',     'purpose' => 'Main analytics cookie: domain, UTM, visit count',   'duration' => '6 months', 'provider' => 'HubSpot' ),
            array( 'name' => 'hubspotutk', 'purpose' => 'Visitor identifier passed to HubSpot CRM',          'duration' => '6 months', 'provider' => 'HubSpot' ),
            array( 'name' => '__hssc',     'purpose' => 'Tracks sessions',                                   'duration' => '30 minutes','provider' => 'HubSpot' ),
            array( 'name' => '__hssrc',    'purpose' => 'Notes that the user restarted the browser',         'duration' => 'session',  'provider' => 'HubSpot' ),
        ),
        'stripe' => array(
            array( 'name' => '__stripe_mid', 'purpose' => 'Fraud prevention (Stripe)',     'duration' => '1 year',  'provider' => 'Stripe' ),
            array( 'name' => '__stripe_sid', 'purpose' => 'Fraud prevention (Stripe)',     'duration' => '30 minutes','provider' => 'Stripe' ),
        ),
        'sentry' => array(
            // Sentry sets no client-cookie by default; relies on session storage. Listed for completeness.
        ),
        'cloudflare' => array(
            array( 'name' => '__cf_bm',     'purpose' => 'Bot management challenge / verification', 'duration' => '30 minutes', 'provider' => 'Cloudflare' ),
            array( 'name' => 'cf_clearance','purpose' => 'Stores recent challenge pass',             'duration' => '30 days',     'provider' => 'Cloudflare' ),
        ),
        'youtube' => array(
            array( 'name' => 'VISITOR_INFO1_LIVE', 'purpose' => 'Bandwidth estimation for video playback', 'duration' => '6 months', 'provider' => 'YouTube' ),
            array( 'name' => 'YSC',                'purpose' => 'Session identifier for video views',      'duration' => 'session',  'provider' => 'YouTube' ),
            array( 'name' => '~^__Secure-YEC',     'purpose' => 'Used for consent / preference signaling', 'duration' => '6 months', 'provider' => 'YouTube' ),
        ),
        'vimeo' => array(
            array( 'name' => 'vuid',  'purpose' => 'Vimeo viewer identifier for video analytics', 'duration' => '2 years', 'provider' => 'Vimeo' ),
            array( 'name' => 'player','purpose' => 'Stores player preferences (volume, quality)', 'duration' => '1 year',  'provider' => 'Vimeo' ),
        ),
        'wordpress-core' => array(
            array( 'name' => '~^wordpress_logged_in_', 'purpose' => 'Authenticates logged-in user sessions',         'duration' => '14 days', 'provider' => 'WordPress' ),
            array( 'name' => '~^wp-settings-',         'purpose' => 'Persists admin screen options per user',        'duration' => '1 year',  'provider' => 'WordPress' ),
            array( 'name' => '~^wp-postpass_',         'purpose' => 'Password-protected post access',                'duration' => '10 days', 'provider' => 'WordPress' ),
            array( 'name' => 'comment_author_*',       'purpose' => 'Remembers comment author identity for the form','duration' => '347 days','provider' => 'WordPress' ),
        ),
    );

    /**
     * Look up known cookies for a given service slug.
     */
    public static function for_service( string $service_slug ): array {
        return self::COOKIES[ $service_slug ] ?? array();
    }

    /**
     * Find the service slug that owns a specific cookie name.
     * Returns the matched service slug or '' if unknown.
     */
    public static function identify( string $cookie_name ): string {
        foreach ( self::COOKIES as $service => $cookies ) {
            foreach ( $cookies as $c ) {
                $name = $c['name'] ?? '';
                if ( $name === $cookie_name ) {
                    return $service;
                }
                if ( '' !== $name && '~' === $name[0] ) {
                    $pattern = '#' . substr( $name, 1 ) . '#';
                    if ( @preg_match( $pattern, $cookie_name ) ) {
                        return $service;
                    }
                }
                // Glob-style * for the legacy comment_author_* form.
                if ( false !== strpos( $name, '*' ) ) {
                    $regex = '#^' . str_replace( '\*', '.*', preg_quote( $name, '#' ) ) . '$#';
                    if ( preg_match( $regex, $cookie_name ) ) {
                        return $service;
                    }
                }
            }
        }
        return '';
    }

    /**
     * Compose a single human-readable line for a cookie entry, used by the
     * policy shortcode and scanner UI.
     */
    public static function describe( array $cookie ): string {
        $name     = $cookie['name']     ?? '';
        $purpose  = $cookie['purpose']  ?? '';
        $duration = $cookie['duration'] ?? '';
        return trim( "{$name} — {$purpose}" . ( $duration ? " ({$duration})" : '' ) );
    }
}
