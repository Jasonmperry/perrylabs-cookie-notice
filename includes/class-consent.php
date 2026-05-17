<?php
/**
 * PLCN_Consent — Consent state, cookie parsing, policy versioning, and the
 * canonical category list (built-in + admin-defined custom).
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

    const BUILTIN_CATEGORIES = array(
        self::CATEGORY_REQUIRED,
        self::CATEGORY_ANALYTICS,
        self::CATEGORY_MARKETING,
        self::CATEGORY_OTHER,
    );

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Full category list: built-ins + admin-defined custom categories.
     * "Required" always comes first; built-ins precede custom; custom render
     * in the order they were defined.
     */
    public function get_categories(): array {
        $options = get_option( 'plcn_options', array() );
        $custom  = array_keys( $options['custom_categories'] ?? array() );

        // Filter out anything colliding with a built-in (admin can't override built-ins by key).
        $custom = array_values( array_filter( $custom, function ( $k ) {
            return ! in_array( $k, self::BUILTIN_CATEGORIES, true );
        } ) );

        return array_values( array_merge( self::BUILTIN_CATEGORIES, $custom ) );
    }

    public function is_valid_category( string $key ): bool {
        return in_array( $key, $this->get_categories(), true );
    }

    public function is_builtin( string $key ): bool {
        return in_array( $key, self::BUILTIN_CATEGORIES, true );
    }

    /**
     * Per-category display metadata. For built-ins, pulls from PLCN_Strings
     * (so admin Messages tab overrides take effect). For custom, pulls from
     * the `custom_categories` option directly.
     *
     * @return array{name:string, desc:string}
     */
    public function get_category_meta( string $key ): array {
        if ( $this->is_builtin( $key ) ) {
            $name_key = 'cat_' . $key . '_name';
            $desc_key = 'cat_' . $key . '_desc';
            return array(
                'name' => PLCN_Strings::get( $name_key, ucfirst( $key ) ),
                'desc' => PLCN_Strings::get( $desc_key ),
            );
        }

        $options = get_option( 'plcn_options', array() );
        $cat     = $options['custom_categories'][ $key ] ?? array();
        return array(
            'name' => $cat['label']       ?? ucwords( str_replace( array( '-', '_' ), ' ', $key ) ),
            'desc' => $cat['description'] ?? '',
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
