<?php
/**
 * PLCN_Script_Gate — Gates WordPress-enqueued scripts and styles behind consent.
 *
 * Other plugins/themes register scripts via wp_enqueue_script(). When a script
 * is registered with this gate against a category, the rendered <script> tag is
 * rewritten to `type="text/plain"` with a `data-plcn-category` attribute. The
 * front-end JS swaps the type to `text/javascript` after consent is granted,
 * causing the browser to execute it as a normal script.
 *
 * Stylesheets are gated by rewriting the rel attribute to `preload` (as=style),
 * then swapped back to `stylesheet` after consent.
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_Script_Gate {

    private static ?PLCN_Script_Gate $instance = null;

    /** @var array<string,string> handle => category */
    private array $gated_scripts = array();

    /** @var array<string,string> handle => category */
    private array $gated_styles = array();

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Filter inputs from saved options (handles set in admin via comma list).
        add_action( 'wp_loaded', array( $this, 'load_gated_from_options' ) );

        // Rewrite tags.
        add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 10, 3 );
        add_filter( 'style_loader_tag', array( $this, 'filter_style_tag' ), 10, 4 );
    }

    public function gate( string $handle, string $category ): void {
        $this->gated_scripts[ $handle ] = $this->normalize_category( $category );
    }

    public function gate_style( string $handle, string $category ): void {
        $this->gated_styles[ $handle ] = $this->normalize_category( $category );
    }

    public function get_gated_scripts(): array {
        return $this->gated_scripts;
    }

    public function get_gated_styles(): array {
        return $this->gated_styles;
    }

    /**
     * Pull "Gated handles" from saved options. Stored as:
     *   gated_scripts => array( 'handle' => 'category' )
     */
    public function load_gated_from_options(): void {
        $options = get_option( 'plcn_options', array() );

        foreach ( ( $options['gated_scripts'] ?? array() ) as $handle => $category ) {
            $this->gate( sanitize_key( $handle ), $category );
        }
        foreach ( ( $options['gated_styles'] ?? array() ) as $handle => $category ) {
            $this->gate_style( sanitize_key( $handle ), $category );
        }
    }

    /**
     * Rewrite script tag if gated and consent not granted (server-side check).
     */
    public function filter_script_tag( string $tag, string $handle, string $src ): string {
        if ( is_admin() ) {
            return $tag;
        }
        if ( ! isset( $this->gated_scripts[ $handle ] ) ) {
            return $tag;
        }

        $category = $this->gated_scripts[ $handle ];
        if ( $this->is_category_allowed( $category ) ) {
            return $tag;
        }

        // Rewrite type to text/plain so the browser does not execute it.
        // The front-end JS will swap it once consent is given.
        $tag = preg_replace(
            '/<script\b/i',
            '<script type="text/plain" data-plcn-category="' . esc_attr( $category ) . '" data-plcn-handle="' . esc_attr( $handle ) . '"',
            $tag,
            1
        );

        return $tag;
    }

    /**
     * Rewrite link tag to defer stylesheet load until consent.
     */
    public function filter_style_tag( string $tag, string $handle, string $href, string $media ): string {
        if ( is_admin() ) {
            return $tag;
        }
        if ( ! isset( $this->gated_styles[ $handle ] ) ) {
            return $tag;
        }

        $category = $this->gated_styles[ $handle ];
        if ( $this->is_category_allowed( $category ) ) {
            return $tag;
        }

        // Swap rel="stylesheet" to a no-op data attribute; JS restores it on consent.
        $tag = preg_replace(
            '/\srel=(["\'])stylesheet\1/i',
            ' rel="preload" as="style" data-plcn-style="1" data-plcn-category="' . esc_attr( $category ) . '" data-plcn-handle="' . esc_attr( $handle ) . '"',
            $tag,
            1
        );

        return $tag;
    }

    /**
     * Server-side check: should a script in this category be allowed to output?
     * Mirrors PLCN_Script_Registry::should_output_script() but for enqueued assets.
     */
    private function is_category_allowed( string $category ): bool {
        if ( PLCN_Consent::CATEGORY_REQUIRED === $category ) {
            return true;
        }

        $options = get_option( 'plcn_options', array() );
        $mode    = $options['compliance_mode'] ?? 'none';
        $consent = PLCN_Consent::instance();

        switch ( $mode ) {
            case 'none':
                return true;
            case 'gdpr':
                return $consent->has_consent( $category );
            case 'ccpa':
                // Opt-out model: allowed unless explicitly opted out.
                if ( ! $consent->is_decided() ) {
                    return true;
                }
                return $consent->has_consent( $category );
            case 'both':
                // Pre-decision: server-side can't know region. Default to strict (deny).
                // The JS layer reverses this after geo lookup if needed.
                if ( ! $consent->is_decided() ) {
                    return false;
                }
                return $consent->has_consent( $category );
            default:
                return true;
        }
    }

    private function normalize_category( string $category ): string {
        $valid = array(
            PLCN_Consent::CATEGORY_REQUIRED,
            PLCN_Consent::CATEGORY_ANALYTICS,
            PLCN_Consent::CATEGORY_MARKETING,
            PLCN_Consent::CATEGORY_OTHER,
        );
        return in_array( $category, $valid, true ) ? $category : PLCN_Consent::CATEGORY_OTHER;
    }
}
