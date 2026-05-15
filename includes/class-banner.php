<?php
/**
 * PLCN_Banner — Renders the consent banner, preferences panel, and overlay.
 *
 * Also emits the Google Consent Mode v2 `default` signal as early as possible,
 * so any Google scripts that happen to load before our JS still respect the
 * user's choices.
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_Banner {

    public function __construct() {
        add_action( 'wp_head', array( $this, 'output_consent_mode_default' ), 0 );
        add_action( 'wp_footer', array( $this, 'render' ), 100 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Google Consent Mode v2 default signal. Loaded as early as possible so it
     * runs before any gtag() calls from other plugins.
     */
    public function output_consent_mode_default(): void {
        $options = get_option( 'plcn_options', PLCN_Settings::defaults() );
        if ( empty( $options['google_consent_mode'] ) ) {
            return;
        }

        $mode    = $options['compliance_mode'] ?? 'none';
        $consent = PLCN_Consent::instance();

        // If the user has already decided, we'll inject the actual values; otherwise default to 'denied' under GDPR.
        $default_state = 'denied';
        if ( 'none' === $mode || 'ccpa' === $mode ) {
            $default_state = 'granted';
        }

        // If decided, reflect actual state.
        $analytics_state = $default_state;
        $marketing_state = $default_state;
        if ( $consent->is_decided() ) {
            $analytics_state = $consent->has_consent( 'analytics' ) ? 'granted' : 'denied';
            $marketing_state = $consent->has_consent( 'marketing' ) ? 'granted' : 'denied';
        }

        $wait_for_update = isset( $options['google_consent_wait_ms'] ) ? (int) $options['google_consent_wait_ms'] : 500;

        ?>
<script data-plcn-consent-mode="1">
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent','default',{
  ad_storage: '<?php echo esc_js( $marketing_state ); ?>',
  ad_user_data: '<?php echo esc_js( $marketing_state ); ?>',
  ad_personalization: '<?php echo esc_js( $marketing_state ); ?>',
  analytics_storage: '<?php echo esc_js( $analytics_state ); ?>',
  functionality_storage: 'granted',
  security_storage: 'granted',
  personalization_storage: '<?php echo esc_js( $analytics_state ); ?>',
  wait_for_update: <?php echo (int) $wait_for_update; ?>
});
</script>
        <?php
    }

    public function enqueue_assets(): void {
        if ( file_exists( PL_COOKIE_PLUGIN_DIR . 'assets/cookie-monster.css' ) ) {
            wp_enqueue_style(
                'plcn-cookie-monster',
                PL_COOKIE_PLUGIN_URL . 'assets/cookie-monster.css',
                array(),
                PL_COOKIE_VERSION
            );
        }

        if ( file_exists( PL_COOKIE_PLUGIN_DIR . 'assets/cookie-monster.js' ) ) {
            wp_enqueue_script(
                'plcn-cookie-monster',
                PL_COOKIE_PLUGIN_URL . 'assets/cookie-monster.js',
                array(),
                PL_COOKIE_VERSION,
                true
            );

            $options  = get_option( 'plcn_options', PLCN_Settings::defaults() );
            $registry = PLCN_Script_Registry::instance();

            // Build scripts data for JS injection.
            $scripts_js = array();
            foreach ( $registry->get_all() as $handle => $script ) {
                $scripts_js[ $handle ] = array(
                    'handle'   => $handle,
                    'label'    => $script['label'] ?? $handle,
                    'category' => $script['category'] ?? 'other',
                    'src'      => $script['src'] ?? '',
                    'inline'   => $script['inline'] ?? '',
                    'attrs'    => $script['attrs'] ?? array(),
                    'load_in'  => $script['load_in'] ?? 'head',
                );
            }

            wp_localize_script( 'plcn-cookie-monster', 'plcnConfig', array(
                'cookieName'        => PLCN_Consent::COOKIE_NAME,
                'expiryDays'        => $options['expiry_days'] ?? 365,
                'complianceMode'    => $options['compliance_mode'] ?? 'none',
                'policyVersion'     => (int) ( $options['policy_version'] ?? 1 ),
                'googleConsentMode' => ! empty( $options['google_consent_mode'] ),
                'logConsent'        => ! empty( $options['log_consent'] ),
                'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
                'logNonce'          => wp_create_nonce( 'plcn_consent_log' ),
                'categories'        => PLCN_Consent::instance()->get_categories(),
                'scripts'           => $scripts_js,
            ) );
        }
    }

    public function render(): void {
        $options = get_option( 'plcn_options', PLCN_Settings::defaults() );

        if ( empty( $options['enabled'] ) ) {
            return;
        }

        $message      = $options['message'] ?? '';
        $button_text  = $options['button_text'] ?? 'Got it';
        $bg_color     = $options['bg_color'] ?? '#111';
        $button_color = $options['button_color'] ?? '#ffb25d';
        $position     = $options['position'] ?? 'bottom';
        $compliance   = $options['compliance_mode'] ?? 'none';
        $theme        = in_array( $options['theme'] ?? 'light', array( 'light', 'dark', 'auto' ), true ) ? $options['theme'] : 'light';

        // Set theme variables on the root.
        echo '<style id="plcn-theme-vars">:root{--plcn-bg:' . esc_attr( $bg_color ) . ';--plcn-accent:' . esc_attr( $button_color ) . ';}</style>';
        echo '<script id="plcn-theme-attr">document.documentElement.setAttribute("data-plcn-theme","' . esc_js( $theme ) . '");</script>';

        $registry     = PLCN_Script_Registry::instance();
        $gate         = PLCN_Script_Gate::instance();
        $has_optional = ! empty( $registry->get_by_category( 'analytics' ) )
            || ! empty( $registry->get_by_category( 'marketing' ) )
            || ! empty( $registry->get_by_category( 'other' ) )
            || ! empty( $gate->get_gated_scripts() )
            || ! empty( $gate->get_gated_styles() );

        $pos_class = 'plcn-pos-' . esc_attr( $position );

        $cat_meta = array(
            'required'  => array(
                'name' => __( 'Strictly Necessary', 'perrylabs-cookie-notice' ),
                'desc' => __( 'Required for the website to function. Cannot be disabled.', 'perrylabs-cookie-notice' ),
            ),
            'analytics' => array(
                'name' => __( 'Analytics', 'perrylabs-cookie-notice' ),
                'desc' => __( 'Help us understand how visitors use the site.', 'perrylabs-cookie-notice' ),
            ),
            'marketing' => array(
                'name' => __( 'Marketing', 'perrylabs-cookie-notice' ),
                'desc' => __( 'Used to deliver relevant ads and track campaigns.', 'perrylabs-cookie-notice' ),
            ),
            'other'     => array(
                'name' => __( 'Other', 'perrylabs-cookie-notice' ),
                'desc' => __( 'Additional third-party services and integrations.', 'perrylabs-cookie-notice' ),
            ),
        );

        // Equal-prominence button styling.
        $btn_style    = 'background:' . esc_attr( $button_color ) . ';color:' . esc_attr( $bg_color ) . ';';
        $btn_alt_style = 'background:transparent;color:#fff;border:1px solid ' . esc_attr( $button_color ) . ';';
        ?>

        <!-- Overlay backdrop -->
        <div id="plcn-overlay-backdrop"></div>

        <!-- Consent banner -->
        <div id="plcn-banner" class="<?php echo esc_attr( $pos_class ); ?>" role="dialog" aria-modal="false" aria-live="polite" aria-label="<?php esc_attr_e( 'Cookie notice', 'perrylabs-cookie-notice' ); ?>" style="background:<?php echo esc_attr( $bg_color ); ?>;">
            <div class="plcn-banner-inner">
                <p class="plcn-banner-message"><?php echo esc_html( $message ); ?></p>
                <div class="plcn-banner-actions">
                    <?php if ( $has_optional && 'none' !== $compliance ) : ?>
                        <button type="button" class="plcn-btn plcn-btn-primary" data-action="accept-all" style="<?php echo $btn_style; // phpcs:ignore ?>">
                            <?php esc_html_e( 'Accept All', 'perrylabs-cookie-notice' ); ?>
                        </button>
                        <button type="button" class="plcn-btn plcn-btn-primary plcn-btn-reject" data-action="reject-all" style="<?php echo $btn_alt_style; // phpcs:ignore ?>">
                            <?php esc_html_e( 'Reject All', 'perrylabs-cookie-notice' ); ?>
                        </button>
                        <button type="button" class="plcn-btn plcn-btn-link" data-action="manage-preferences">
                            <?php esc_html_e( 'Customize', 'perrylabs-cookie-notice' ); ?>
                        </button>
                    <?php else : ?>
                        <button type="button" class="plcn-btn plcn-btn-primary" data-action="accept-all" style="<?php echo $btn_style; // phpcs:ignore ?>">
                            <?php echo esc_html( $button_text ); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Preferences panel -->
        <div id="plcn-preferences" role="dialog" aria-modal="true" aria-labelledby="plcn-prefs-title" aria-hidden="true">
            <div class="plcn-prefs-header">
                <h3 id="plcn-prefs-title"><?php esc_html_e( 'Cookie Preferences', 'perrylabs-cookie-notice' ); ?></h3>
                <p><?php esc_html_e( 'Choose which categories you want to allow. Strictly necessary cookies are always active.', 'perrylabs-cookie-notice' ); ?></p>
            </div>
            <div class="plcn-prefs-body">
                <?php foreach ( PLCN_Consent::instance()->get_categories() as $cat ) :
                    $is_required  = ( 'required' === $cat );
                    $cat_scripts  = $registry->get_by_category( $cat );
                    $script_names = array_filter( array_map( function ( $s ) {
                        return $s['label'] ?? $s['handle'] ?? '';
                    }, $cat_scripts ) );

                    // Also surface gated WP-enqueued handles.
                    foreach ( $gate->get_gated_scripts() as $h => $c ) {
                        if ( $c === $cat ) {
                            $script_names[] = $h;
                        }
                    }
                    ?>
                    <div class="plcn-category">
                        <div class="plcn-category-header">
                            <div class="plcn-category-info">
                                <span class="plcn-category-name"><?php echo esc_html( $cat_meta[ $cat ]['name'] ?? ucfirst( $cat ) ); ?></span>
                                <span class="plcn-category-desc"><?php echo esc_html( $cat_meta[ $cat ]['desc'] ?? '' ); ?></span>
                                <?php if ( ! empty( $script_names ) ) : ?>
                                    <span class="plcn-category-scripts"><?php echo esc_html( implode( ', ', $script_names ) ); ?></span>
                                <?php endif; ?>
                            </div>
                            <label class="plcn-toggle">
                                <input type="checkbox" data-category="<?php echo esc_attr( $cat ); ?>"
                                    <?php echo $is_required ? 'checked disabled' : ''; ?> />
                                <span class="plcn-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="plcn-prefs-footer">
                <button type="button" class="plcn-btn plcn-btn-cancel" data-action="cancel-preferences">
                    <?php esc_html_e( 'Cancel', 'perrylabs-cookie-notice' ); ?>
                </button>
                <button type="button" class="plcn-btn plcn-btn-save" data-action="save-preferences" style="<?php echo $btn_style; // phpcs:ignore ?>">
                    <?php esc_html_e( 'Save Preferences', 'perrylabs-cookie-notice' ); ?>
                </button>
            </div>
        </div>
        <?php
    }
}
