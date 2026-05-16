<?php
/**
 * PLCN_Banner — Renders the consent banner, preferences panel, and overlay.
 *
 * Also: Google Consent Mode v2 default signal, DNT / GPC honor, URL skip
 * patterns, custom CSS injection, all-strings-customizable.
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_Banner {

    public function __construct() {
        add_action( 'wp_head', array( $this, 'output_consent_mode_default' ), 0 );
        add_action( 'wp_head', array( $this, 'output_custom_css' ), 99 );
        add_action( 'wp_footer', array( $this, 'render' ), 100 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Should the banner render on the current URL? Respects:
     *   - Admin "enabled" toggle
     *   - "Skip for admins" toggle
     *   - URL skip patterns
     *   - DNT / Global Privacy Control (treated as Reject All — banner skipped)
     */
    private function should_render(): bool {
        $options = get_option( 'plcn_options', PLCN_Settings::defaults() );

        if ( empty( $options['enabled'] ) ) return false;

        if ( ! empty( $options['skip_for_admins'] ) && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            return false;
        }

        // DNT / GPC: treat as Reject All. Banner is not shown.
        if ( ! empty( $options['honor_dnt'] ) && $this->browser_says_no_track() ) {
            return false;
        }

        if ( $this->current_url_matches_skip_patterns( $options['skip_urls'] ?? '' ) ) {
            return false;
        }

        return true;
    }

    private function browser_says_no_track(): bool {
        // DNT header (legacy but still set by Firefox / some Safari builds).
        if ( isset( $_SERVER['HTTP_DNT'] ) && '1' === (string) $_SERVER['HTTP_DNT'] ) {
            return true;
        }
        // Global Privacy Control (current standard).
        if ( isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === (string) $_SERVER['HTTP_SEC_GPC'] ) {
            return true;
        }
        return false;
    }

    private function current_url_matches_skip_patterns( string $patterns_raw ): bool {
        $patterns = array_filter( array_map( 'trim', explode( "\n", $patterns_raw ) ) );
        if ( empty( $patterns ) ) return false;

        $path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?: '/';

        foreach ( $patterns as $pattern ) {
            // Convert glob-like pattern (/checkout/*) to regex.
            $regex = '#^' . str_replace( array( '\*', '\?' ), array( '.*', '.' ), preg_quote( $pattern, '#' ) ) . '$#';
            if ( preg_match( $regex, $path ) ) {
                return true;
            }
        }
        return false;
    }

    public function output_consent_mode_default(): void {
        $options = get_option( 'plcn_options', PLCN_Settings::defaults() );
        if ( empty( $options['google_consent_mode'] ) ) return;
        if ( ! $this->should_render() && ! PLCN_Consent::instance()->is_decided() ) {
            // Banner suppressed AND no decision recorded — short-circuit gtag setup.
            return;
        }

        $mode    = $options['compliance_mode'] ?? 'none';
        $consent = PLCN_Consent::instance();

        $default_state = 'denied';
        if ( 'none' === $mode || 'ccpa' === $mode ) {
            $default_state = 'granted';
        }

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

    /**
     * Output the admin's custom CSS (sanitized in settings).
     */
    public function output_custom_css(): void {
        $options = get_option( 'plcn_options', array() );
        $css = $options['custom_css'] ?? '';
        if ( empty( $css ) ) return;
        echo "<style id='plcn-custom-css'>\n" . wp_strip_all_tags( $css ) . "\n</style>\n";
    }

    public function enqueue_assets(): void {
        if ( ! $this->should_render() && ! PLCN_Consent::instance()->is_decided() ) {
            // No banner needed and no consent state to apply — skip assets entirely.
            // (We still load when consent is decided, so the JS can activate gated assets.)
        }

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
                'honorDnt'          => ! empty( $options['honor_dnt'] ),
                'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
                'logNonce'          => wp_create_nonce( 'plcn_consent_log' ),
                'categories'        => PLCN_Consent::instance()->get_categories(),
                'scripts'           => $scripts_js,
            ) );
        }
    }

    public function render(): void {
        if ( ! $this->should_render() ) return;

        $options = get_option( 'plcn_options', PLCN_Settings::defaults() );

        $bg_color     = $options['bg_color'] ?? '#111';
        $button_color = $options['button_color'] ?? '#ffb25d';
        $position     = $options['position'] ?? 'bottom';
        $compliance   = $options['compliance_mode'] ?? 'none';
        $theme        = in_array( $options['theme'] ?? 'light', array( 'light', 'dark', 'auto' ), true ) ? $options['theme'] : 'light';
        $privacy_url  = $options['privacy_policy_url'] ?? '';

        // Theme variables on :root.
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

        // Build banner message, optionally injecting a privacy link.
        if ( $privacy_url ) {
            $link    = sprintf(
                '<a href="%s" class="plcn-policy-link" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url( $privacy_url ),
                esc_html( PLCN_Strings::get( 'privacy_policy_link_text' ) )
            );
            $template = PLCN_Strings::get( 'banner_message_with_link' );
            // We avoid sprintf because the message can be admin-edited; if there's no %s, fall back gracefully.
            if ( false !== strpos( $template, '%s' ) ) {
                $message = wp_kses(
                    str_replace( '%s', $link, $template ),
                    array( 'a' => array( 'href' => array(), 'class' => array(), 'target' => array(), 'rel' => array() ) )
                );
            } else {
                $message = esc_html( $template );
            }
        } else {
            $message = esc_html( PLCN_Strings::get( 'banner_message' ) );
        }

        $banner_title = PLCN_Strings::get( 'banner_title' );

        $cat_meta = array(
            'required'  => array( 'name' => PLCN_Strings::get( 'cat_required_name' ),  'desc' => PLCN_Strings::get( 'cat_required_desc' ) ),
            'analytics' => array( 'name' => PLCN_Strings::get( 'cat_analytics_name' ), 'desc' => PLCN_Strings::get( 'cat_analytics_desc' ) ),
            'marketing' => array( 'name' => PLCN_Strings::get( 'cat_marketing_name' ), 'desc' => PLCN_Strings::get( 'cat_marketing_desc' ) ),
            'other'     => array( 'name' => PLCN_Strings::get( 'cat_other_name' ),     'desc' => PLCN_Strings::get( 'cat_other_desc' ) ),
        );

        $btn_style     = 'background:' . esc_attr( $button_color ) . ';color:' . esc_attr( $bg_color ) . ';';
        $btn_alt_style = 'background:transparent;color:#fff;border:1px solid ' . esc_attr( $button_color ) . ';';
        ?>

        <div id="plcn-overlay-backdrop"></div>

        <div id="plcn-banner" class="<?php echo esc_attr( $pos_class ); ?>" role="dialog" aria-modal="false" aria-live="polite" aria-label="<?php esc_attr_e( 'Cookie notice', 'perrylabs-cookie-notice' ); ?>" style="background:<?php echo esc_attr( $bg_color ); ?>;">
            <div class="plcn-banner-inner">
                <div class="plcn-banner-text">
                    <?php if ( $banner_title ) : ?>
                        <p class="plcn-banner-title"><?php echo esc_html( $banner_title ); ?></p>
                    <?php endif; ?>
                    <p class="plcn-banner-message"><?php echo $message; // already escaped above ?></p>
                </div>
                <div class="plcn-banner-actions">
                    <?php if ( $has_optional && 'none' !== $compliance ) : ?>
                        <button type="button" class="plcn-btn plcn-btn-primary" data-action="accept-all" style="<?php echo $btn_style; // phpcs:ignore ?>">
                            <?php echo esc_html( PLCN_Strings::get( 'btn_accept_all' ) ); ?>
                        </button>
                        <button type="button" class="plcn-btn plcn-btn-primary plcn-btn-reject" data-action="reject-all" style="<?php echo $btn_alt_style; // phpcs:ignore ?>">
                            <?php echo esc_html( PLCN_Strings::get( 'btn_reject_all' ) ); ?>
                        </button>
                        <button type="button" class="plcn-btn plcn-btn-link" data-action="manage-preferences">
                            <?php echo esc_html( PLCN_Strings::get( 'btn_customize' ) ); ?>
                        </button>
                    <?php else : ?>
                        <button type="button" class="plcn-btn plcn-btn-primary" data-action="accept-all" style="<?php echo $btn_style; // phpcs:ignore ?>">
                            <?php echo esc_html( PLCN_Strings::get( 'btn_got_it' ) ); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div id="plcn-preferences" role="dialog" aria-modal="true" aria-labelledby="plcn-prefs-title" aria-hidden="true">
            <div class="plcn-prefs-header">
                <h3 id="plcn-prefs-title"><?php echo esc_html( PLCN_Strings::get( 'prefs_title' ) ); ?></h3>
                <p><?php echo esc_html( PLCN_Strings::get( 'prefs_intro' ) ); ?></p>
            </div>
            <div class="plcn-prefs-body">
                <?php foreach ( PLCN_Consent::instance()->get_categories() as $cat ) :
                    $is_required  = ( 'required' === $cat );
                    $cat_scripts  = $registry->get_by_category( $cat );
                    $script_names = array_filter( array_map( function ( $s ) {
                        return $s['label'] ?? $s['handle'] ?? '';
                    }, $cat_scripts ) );

                    foreach ( $gate->get_gated_scripts() as $h => $c ) {
                        if ( $c === $cat ) $script_names[] = $h;
                    }
                    ?>
                    <div class="plcn-category">
                        <div class="plcn-category-header">
                            <div class="plcn-category-info">
                                <span class="plcn-category-name"><?php echo esc_html( $cat_meta[ $cat ]['name'] ); ?></span>
                                <span class="plcn-category-desc"><?php echo esc_html( $cat_meta[ $cat ]['desc'] ); ?></span>
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
                    <?php echo esc_html( PLCN_Strings::get( 'prefs_cancel' ) ); ?>
                </button>
                <button type="button" class="plcn-btn plcn-btn-save" data-action="save-preferences" style="<?php echo $btn_style; // phpcs:ignore ?>">
                    <?php echo esc_html( PLCN_Strings::get( 'prefs_save' ) ); ?>
                </button>
            </div>
        </div>
        <?php
    }
}
