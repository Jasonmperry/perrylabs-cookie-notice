<?php
/**
 * PLCN_Consent — Consent state, cookie parsing, and policy versioning.
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_Consent {

    private static ?PLCN_Consent $instance = null;

    const COOKIE_NAME = 'plcn_consent';

    const CATEGORY_REQUIRED  = 'required';
    const CATEGORY_ANALYTICS = 'analytics';
    const CATEGORY_MARKETING = 'marketing';
    const CATEGORY_OTHER     = 'other';

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get_categories(): array {
        return array(
            self::CATEGORY_REQUIRED,
            self::CATEGORY_ANALYTICS,
            self::CATEGORY_MARKETING,
            self::CATEGORY_OTHER,
        );
    }

    /**
     * Current policy version from settings. Bumping this re-prompts all users.
     */
    public function get_policy_version(): int {
        $options = get_option( 'plcn_options', array() );
        return isset( $options['policy_version'] ) ? (int) $options['policy_version'] : 1;
    }

    public function get_consent(): array {
        if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            // Check for legacy dismissal cookie.
            if ( ! empty( $_COOKIE['plcn_dismissed'] ) ) {
                return array(
                    'required'  => true,
                    'analytics' => true,
                    'marketing' => true,
                    'other'     => true,
                    'version'   => 0,
                );
            }
            return array();
        }

        $data = json_decode( stripslashes( $_COOKIE[ self::COOKIE_NAME ] ), true );
        if ( ! is_array( $data ) ) {
            return array();
        }

        // Stale-version detection: if the user's cookie is from an older policy, treat as undecided.
        $cookie_version = isset( $data['version'] ) ? (int) $data['version'] : 0;
        if ( $cookie_version < $this->get_policy_version() ) {
            return array();
        }

        return $data;
    }

    public function has_consent( string $category ): bool {
        if ( $category === self::CATEGORY_REQUIRED ) {
            return true;
        }
        $consent = $this->get_consent();
        return ! empty( $consent[ $category ] );
    }

    public function is_decided(): bool {
        $consent = $this->get_consent();
        return ! empty( $consent );
    }
}
