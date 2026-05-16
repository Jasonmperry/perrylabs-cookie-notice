<?php
/**
 * PLCN_Shortcodes — Public shortcodes for embedding controls and cookie info.
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_Shortcodes {

    private static ?PLCN_Shortcodes $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'plcn_settings_link', array( $this, 'settings_link' ) );
        add_shortcode( 'plcn_cookie_policy', array( $this, 'cookie_policy' ) );
        add_shortcode( 'plcn_ccpa_optout', array( $this, 'ccpa_optout' ) );
    }

    /**
     * [plcn_settings_link text="Cookie Settings" class="my-class"]
     * Renders a button that re-opens the preferences modal.
     */
    public function settings_link( $atts ): string {
        $atts = shortcode_atts(
            array(
                'text'  => __( 'Cookie Settings', 'perrylabs-cookie-notice' ),
                'class' => '',
            ),
            $atts,
            'plcn_settings_link'
        );

        $class = 'plcn-open-preferences' . ( $atts['class'] ? ' ' . esc_attr( $atts['class'] ) : '' );

        return sprintf(
            '<button type="button" class="%s">%s</button>',
            esc_attr( $class ),
            esc_html( $atts['text'] )
        );
    }

    /**
     * [plcn_cookie_policy]
     * Renders a table of registered scripts grouped by category — handy to drop
     * into a Privacy / Cookie Policy page so the list stays in sync with what
     * the plugin actually loads.
     */
    public function cookie_policy( $atts ): string {
        $registry = PLCN_Script_Registry::instance();
        $all      = $registry->get_all();

        if ( empty( $all ) ) {
            return '<p class="plcn-policy-empty">' . esc_html( PLCN_Strings::get( 'policy_empty' ) ) . '</p>';
        }

        $cat_labels = array(
            'required'  => PLCN_Strings::get( 'policy_required' ),
            'analytics' => PLCN_Strings::get( 'policy_analytics' ),
            'marketing' => PLCN_Strings::get( 'policy_marketing' ),
            'other'     => PLCN_Strings::get( 'policy_other' ),
        );

        $cat_descs = array(
            'required'  => PLCN_Strings::get( 'policy_required_desc' ),
            'analytics' => PLCN_Strings::get( 'policy_analytics_desc' ),
            'marketing' => PLCN_Strings::get( 'policy_marketing_desc' ),
            'other'     => PLCN_Strings::get( 'policy_other_desc' ),
        );

        $by_cat = array();
        foreach ( $all as $script ) {
            $cat = $script['category'] ?? 'other';
            $by_cat[ $cat ][] = $script;
        }

        ob_start();
        ?>
        <div class="plcn-cookie-policy">
            <?php foreach ( array( 'required', 'analytics', 'marketing', 'other' ) as $cat ) :
                if ( empty( $by_cat[ $cat ] ) ) {
                    continue;
                }
                ?>
                <h3 class="plcn-policy-cat"><?php echo esc_html( $cat_labels[ $cat ] ); ?></h3>
                <p class="plcn-policy-cat-desc"><?php echo esc_html( $cat_descs[ $cat ] ); ?></p>
                <table class="plcn-policy-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html( PLCN_Strings::get( 'policy_col_service' ) ); ?></th>
                            <th><?php echo esc_html( PLCN_Strings::get( 'policy_col_provider' ) ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $by_cat[ $cat ] as $script ) :
                            $provider = $this->guess_provider( $script );
                            ?>
                            <tr>
                                <td><?php echo esc_html( $script['label'] ?? $script['handle'] ?? '' ); ?></td>
                                <td><?php echo esc_html( $provider ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * [plcn_ccpa_optout text="Do Not Sell My Personal Information"]
     * CCPA-required opt-out link that opens preferences pre-set to reject marketing.
     */
    public function ccpa_optout( $atts ): string {
        $atts = shortcode_atts(
            array(
                'text'  => PLCN_Strings::get( 'ccpa_optout_label' ),
                'class' => '',
            ),
            $atts,
            'plcn_ccpa_optout'
        );

        $class = 'plcn-ccpa-optout plcn-open-preferences' . ( $atts['class'] ? ' ' . esc_attr( $atts['class'] ) : '' );

        return sprintf(
            '<a href="#" class="%s" data-plcn-preset="reject-marketing">%s</a>',
            esc_attr( $class ),
            esc_html( $atts['text'] )
        );
    }

    /**
     * Best-effort: pull a friendly provider name out of the src URL.
     */
    private function guess_provider( array $script ): string {
        $src = $script['src'] ?? '';
        if ( ! $src ) {
            $inline = $script['inline'] ?? '';
            if ( strpos( $inline, 'googletagmanager' ) !== false ) return 'Google';
            if ( strpos( $inline, 'facebook' ) !== false || strpos( $inline, 'fbq' ) !== false ) return 'Meta';
            if ( strpos( $inline, 'lintrk' ) !== false ) return 'LinkedIn';
            if ( strpos( $inline, 'hs-scripts' ) !== false ) return 'HubSpot';
            return __( 'Custom', 'perrylabs-cookie-notice' );
        }
        $host = wp_parse_url( $src, PHP_URL_HOST ) ?: '';
        $host = preg_replace( '/^www\./', '', $host );
        return $host;
    }
}
