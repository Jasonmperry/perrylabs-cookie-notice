<?php
/**
 * PLCN_Script_Registry — Manages registered scripts/pixels with compliance-aware output.
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_Script_Registry {

    private static ?PLCN_Script_Registry $instance = null;
    private array $scripts = array();

    const PRESETS = array(
        'google-analytics-4' => array(
            'label'    => 'Google Analytics 4',
            'category' => 'analytics',
            'src'      => 'https://www.googletagmanager.com/gtag/js?id=%s',
            'inline'   => "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','%s');",
            'attrs'    => array( 'async' ),
            'id_label' => 'Measurement ID (G-XXXXX)',
            'load_in'  => 'head',
        ),
        'google-tag-manager' => array(
            'label'    => 'Google Tag Manager',
            'category' => 'analytics',
            'src'      => 'https://www.googletagmanager.com/gtm.js?id=%s',
            'inline'   => "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','%s');",
            'attrs'    => array(),
            'id_label' => 'Container ID (GTM-XXXXX)',
            'load_in'  => 'head',
        ),
        'facebook-pixel' => array(
            'label'    => 'Facebook Pixel',
            'category' => 'marketing',
            'src'      => '',
            'inline'   => "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','%s');fbq('track','PageView');",
            'attrs'    => array(),
            'id_label' => 'Pixel ID',
            'load_in'  => 'head',
        ),
        'linkedin-insight' => array(
            'label'    => 'LinkedIn Insight Tag',
            'category' => 'marketing',
            'src'      => '',
            'inline'   => "_linkedin_partner_id='%s';(function(l){if(!l){window.lintrk=function(a,b){window.lintrk.q.push([a,b])};window.lintrk.q=[]}var s=document.getElementsByTagName('script')[0];var b=document.createElement('script');b.type='text/javascript';b.async=true;b.src='https://snap.licdn.com/li.lms-analytics/insight.min.js';s.parentNode.insertBefore(b,s);})(window.lintrk);",
            'attrs'    => array(),
            'id_label' => 'Partner ID',
            'load_in'  => 'head',
        ),
        'hubspot' => array(
            'label'    => 'HubSpot Tracking',
            'category' => 'analytics',
            'src'      => 'https://js.hs-scripts.com/%s.js',
            'inline'   => '',
            'attrs'    => array( 'async', 'defer' ),
            'id_label' => 'Portal ID',
            'load_in'  => 'head',
        ),
    );

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Load admin-registered scripts from options.
        $saved = $this->get_saved_scripts();
        foreach ( $saved as $handle => $config ) {
            $this->scripts[ $handle ] = $config;
        }

        // Fire hook for programmatic registration.
        add_action( 'wp_loaded', function () {
            do_action( 'plcn_register_scripts' );
        } );

        // Note: registered scripts are injected client-side only by cookie-monster.js.
        // Doing it server-side too caused double-injection for returning consented visitors
        // and was inconsistent under page caching. The gated_assets path (class-script-gate.php)
        // still uses server-side tag rewriting because those scripts are enqueued externally.
    }

    public function register( string $handle, array $config ): void {
        $this->scripts[ $handle ] = wp_parse_args( $config, array(
            'handle'   => $handle,
            'label'    => $handle,
            'category' => PLCN_Consent::CATEGORY_OTHER,
            'src'      => '',
            'inline'   => '',
            'attrs'    => array(),
            'load_in'  => 'head',
            'priority' => 10,
        ) );
    }

    public function get_all(): array {
        return $this->scripts;
    }

    public function get_by_category( string $category ): array {
        return array_filter( $this->scripts, function ( $script ) use ( $category ) {
            return ( $script['category'] ?? '' ) === $category;
        } );
    }

    public function get_saved_scripts(): array {
        $options = get_option( 'plcn_options', array() );
        return $options['scripts'] ?? array();
    }

    /**
     * Save a script to the plcn_options['scripts'] store.
     */
    public static function save_script( string $handle, array $config ): void {
        $options = get_option( PLCN_Settings::OPTION_NAME, PLCN_Settings::defaults() );
        $options['scripts'][ $handle ] = $config;
        update_option( PLCN_Settings::OPTION_NAME, $options );
    }

    /**
     * Delete a script from the plcn_options['scripts'] store.
     */
    public static function delete_script( string $handle ): void {
        $options = get_option( PLCN_Settings::OPTION_NAME, PLCN_Settings::defaults() );
        unset( $options['scripts'][ $handle ] );
        update_option( PLCN_Settings::OPTION_NAME, $options );
    }

    /**
     * Server-side output for head scripts.
     * Note: The JS-based injection handles dynamic consent changes.
     * This provides a fallback for the initial load when consent is already given.
     */
    public function output_consented_scripts(): void {
        $this->output_scripts_for_location( 'head' );
    }

    /**
     * Server-side output for footer scripts.
     */
    public function output_consented_footer_scripts(): void {
        $this->output_scripts_for_location( 'footer' );
    }

    /**
     * Output scripts for a specific location, respecting compliance mode.
     */
    private function output_scripts_for_location( string $location ): void {
        if ( is_admin() ) {
            return;
        }

        $consent  = PLCN_Consent::instance();
        $options  = get_option( 'plcn_options', PLCN_Settings::defaults() );
        $mode     = $options['compliance_mode'] ?? 'none';

        // If "both" mode, we can't determine region server-side on first load.
        // The JS will handle injection after geo detection. Only output if consent cookie exists.
        if ( 'both' === $mode && ! $consent->is_decided() ) {
            return;
        }

        foreach ( $this->scripts as $script ) {
            $script_location = $script['load_in'] ?? 'head';
            if ( $script_location !== $location ) {
                continue;
            }

            $category = $script['category'] ?? PLCN_Consent::CATEGORY_OTHER;

            if ( ! $this->should_output_script( $category, $mode, $consent ) ) {
                continue;
            }

            $this->render_script_tag( $script );
        }
    }

    /**
     * Determine if a script should be output based on compliance mode and consent state.
     */
    private function should_output_script( string $category, string $mode, PLCN_Consent $consent ): bool {
        // Required scripts always load.
        if ( PLCN_Consent::CATEGORY_REQUIRED === $category ) {
            return true;
        }

        switch ( $mode ) {
            case 'none':
                // Simple notice — all scripts load regardless.
                return true;

            case 'gdpr':
                // Explicit opt-in required.
                return $consent->has_consent( $category );

            case 'ccpa':
                // Load unless explicitly opted out.
                if ( ! $consent->is_decided() ) {
                    return true;
                }
                return $consent->has_consent( $category );

            case 'both':
                // If we get here, consent is decided. Default to GDPR (stricter).
                return $consent->has_consent( $category );

            default:
                return true;
        }
    }

    /**
     * Render a single script tag (external + inline).
     */
    private function render_script_tag( array $script ): void {
        // Output external script.
        if ( ! empty( $script['src'] ) ) {
            $attrs = '';
            foreach ( ( $script['attrs'] ?? array() ) as $attr ) {
                $attrs .= ' ' . esc_attr( $attr );
            }
            printf( '<script src="%s"%s></script>' . "\n", esc_url( $script['src'] ), $attrs );
        }

        // Output inline script.
        if ( ! empty( $script['inline'] ) ) {
            echo '<script>' . $script['inline'] . '</script>' . "\n";
        }
    }
}
