<?php
/**
 * PLCN_Settings — Admin settings page.
 *
 * Tabs: General | Messages | Scripts & Pixels | Gated Handles | Embed Blocker
 *       | Advanced | Consent Log | Tools.
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_Settings {

    const OPTION_NAME = 'plcn_options';
    const PAGE_SLUG   = 'plcn-settings';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_page' ) );
        add_action( 'admin_init', array( $this, 'register' ) );
        add_action( 'admin_init', array( $this, 'handle_script_actions' ) );
        add_action( 'admin_init', array( $this, 'handle_gated_actions' ) );
        add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_post_plcn_import_settings', array( $this, 'handle_import' ) );
        add_action( 'admin_post_plcn_export_settings', array( $this, 'handle_export' ) );
    }

    public static function defaults(): array {
        return array(
            'enabled'                => 1,
            'message'                => 'We use cookies to give you the best experience on our website. Choose which categories you want to allow.',
            'button_text'            => 'Got it',
            'expiry_days'            => 365,
            'bg_color'               => '#111',
            'button_color'           => '#ffb25d',
            'position'               => 'bottom',
            'theme'                  => 'light',
            'compliance_mode'        => 'none',
            'policy_version'         => 1,
            'google_consent_mode'    => 0,
            'google_consent_wait_ms' => 500,
            'log_consent'            => 0,
            'scripts'                => array(),
            'gated_scripts'          => array(),
            'gated_styles'           => array(),
            'embed_blocker'          => array(),
            'skip_for_admins'        => 1,
            // v3.2.0 additions
            'privacy_policy_url'     => '',
            'honor_dnt'              => 1,
            'skip_urls'              => '',
            'custom_css'             => '',
            'strings'                => array(),
        );
    }

    public function add_page(): void {
        add_options_page(
            __( 'Cookie Notice', 'perrylabs-cookie-notice' ),
            __( 'Cookie Notice', 'perrylabs-cookie-notice' ),
            plcn_manage_capability(),
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    public function register(): void {
        register_setting( 'plcn_settings_group', self::OPTION_NAME, array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize' ),
            'default'           => self::defaults(),
        ) );
    }

    public function enqueue_admin_assets( $hook ): void {
        if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) return;

        wp_enqueue_style(
            'perrylabs-tokens',
            PL_COOKIE_PLUGIN_URL . 'includes/branding/tokens.css',
            array(),
            PL_COOKIE_VERSION
        );

        // Live preview also needs the front-end stylesheet.
        wp_enqueue_style(
            'plcn-cookie-monster',
            PL_COOKIE_PLUGIN_URL . 'assets/cookie-monster.css',
            array(),
            PL_COOKIE_VERSION
        );

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".plcn-color-field").wpColorPicker({change:function(e,ui){if(window.plcnUpdatePreview)plcnUpdatePreview();}});});' );
    }

    public function sanitize( $input ): array {
        $defaults = self::defaults();
        $existing = get_option( self::OPTION_NAME, $defaults );
        $sanitized = array();

        $sanitized['enabled']                = ! empty( $input['enabled'] ) ? 1 : 0;
        $sanitized['message']                = sanitize_textarea_field( $input['message'] ?? $defaults['message'] );
        $sanitized['button_text']            = sanitize_text_field( $input['button_text'] ?? $defaults['button_text'] );
        $sanitized['expiry_days']            = absint( $input['expiry_days'] ?? $defaults['expiry_days'] );
        $sanitized['bg_color']               = sanitize_hex_color( $input['bg_color'] ?? '' ) ?: $defaults['bg_color'];
        $sanitized['button_color']           = sanitize_hex_color( $input['button_color'] ?? '' ) ?: $defaults['button_color'];
        $sanitized['position']               = in_array( $input['position'] ?? '', array( 'bottom', 'top', 'overlay' ), true ) ? $input['position'] : 'bottom';
        $sanitized['theme']                  = in_array( $input['theme'] ?? '', array( 'light', 'dark', 'auto' ), true ) ? $input['theme'] : 'light';
        $sanitized['compliance_mode']        = in_array( $input['compliance_mode'] ?? '', array( 'none', 'gdpr', 'ccpa', 'both' ), true ) ? $input['compliance_mode'] : 'none';
        $sanitized['google_consent_mode']    = ! empty( $input['google_consent_mode'] ) ? 1 : 0;
        $sanitized['google_consent_wait_ms'] = max( 0, min( 5000, absint( $input['google_consent_wait_ms'] ?? 500 ) ) );
        $sanitized['log_consent']            = ! empty( $input['log_consent'] ) ? 1 : 0;
        $sanitized['skip_for_admins']        = ! empty( $input['skip_for_admins'] ) ? 1 : 0;
        $sanitized['honor_dnt']              = ! empty( $input['honor_dnt'] ) ? 1 : 0;
        $sanitized['privacy_policy_url']     = esc_url_raw( $input['privacy_policy_url'] ?? '' );
        $sanitized['skip_urls']              = sanitize_textarea_field( $input['skip_urls'] ?? '' );
        $sanitized['custom_css']             = wp_strip_all_tags( $input['custom_css'] ?? '' );

        // Strings — admin overrides for any string key.
        $string_input = (array) ( $input['strings'] ?? array() );
        $clean_strings = array();
        foreach ( PLCN_Strings::defaults() as $key => $default ) {
            if ( isset( $string_input[ $key ] ) ) {
                $clean_strings[ $key ] = wp_kses_post( $string_input[ $key ] );
            }
        }
        $sanitized['strings'] = $clean_strings;

        $allowed_providers = array_keys( PLCN_Embed_Blocker::PROVIDERS );
        $sanitized['embed_blocker'] = array_values( array_intersect(
            $allowed_providers,
            array_map( 'sanitize_key', (array) ( $input['embed_blocker'] ?? array() ) )
        ) );

        $sanitized['policy_version'] = (int) ( $existing['policy_version'] ?? 1 );
        $sanitized['scripts']        = $existing['scripts'] ?? array();
        $sanitized['gated_scripts']  = $existing['gated_scripts'] ?? array();
        $sanitized['gated_styles']   = $existing['gated_styles'] ?? array();

        return $sanitized;
    }

    /* ============================================================== */
    /*  Action handlers                                                */
    /* ============================================================== */

    public function handle_script_actions(): void {
        if ( ! current_user_can( plcn_manage_capability() ) ) return;
        if ( empty( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) return;

        if ( ! empty( $_GET['plcn_delete_script'] ) && ! empty( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'], 'plcn_delete_script' ) ) {
                PLCN_Script_Registry::delete_script( sanitize_text_field( $_GET['plcn_delete_script'] ) );
                wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=scripts&deleted=1' ) );
                exit;
            }
        }

        if ( ! empty( $_POST['plcn_save_script'] ) && ! empty( $_POST['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_POST['_wpnonce'], 'plcn_save_script' ) ) {
                $handle = sanitize_title( $_POST['script_handle'] ?? '' );
                if ( empty( $handle ) ) return;

                $src    = $_POST['script_src']    ?? '';
                $inline = wp_unslash( $_POST['script_inline'] ?? '' );

                $service_id = sanitize_text_field( $_POST['script_service_id'] ?? '' );
                if ( $service_id ) {
                    $src    = str_replace( '%s', $service_id, $src );
                    $inline = str_replace( '%s', $service_id, $inline );
                }

                $config = array(
                    'handle'     => $handle,
                    'label'      => sanitize_text_field( $_POST['script_label'] ?? $handle ),
                    'category'   => in_array( $_POST['script_category'] ?? '', array( 'required', 'analytics', 'marketing', 'other' ), true )
                        ? $_POST['script_category']
                        : 'other',
                    'service_id' => $service_id,
                    'src'        => esc_url_raw( $src ),
                    'inline'     => $inline,
                    'attrs'      => array_filter( array_map( 'sanitize_text_field', explode( ',', $_POST['script_attrs'] ?? '' ) ) ),
                    'load_in'    => in_array( $_POST['script_load_in'] ?? '', array( 'head', 'footer' ), true )
                        ? $_POST['script_load_in']
                        : 'head',
                );

                PLCN_Script_Registry::save_script( $handle, $config );
                wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=scripts&saved=1' ) );
                exit;
            }
        }
    }

    public function handle_gated_actions(): void {
        if ( ! current_user_can( plcn_manage_capability() ) ) return;
        if ( empty( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) return;

        if ( ! empty( $_POST['plcn_save_gated'] ) && ! empty( $_POST['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_POST['_wpnonce'], 'plcn_save_gated' ) ) {
                $handle   = sanitize_key( $_POST['gated_handle'] ?? '' );
                $category = in_array( $_POST['gated_category'] ?? '', array( 'analytics', 'marketing', 'other' ), true )
                    ? $_POST['gated_category'] : 'other';
                $type     = in_array( $_POST['gated_type'] ?? '', array( 'script', 'style' ), true )
                    ? $_POST['gated_type'] : 'script';

                if ( $handle ) {
                    $opts = get_option( self::OPTION_NAME, self::defaults() );
                    $key  = 'script' === $type ? 'gated_scripts' : 'gated_styles';
                    $opts[ $key ][ $handle ] = $category;
                    update_option( self::OPTION_NAME, $opts );
                    wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=gated&saved=1' ) );
                    exit;
                }
            }
        }

        if ( ! empty( $_GET['plcn_delete_gated'] ) && ! empty( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'], 'plcn_delete_gated' ) ) {
                $handle = sanitize_key( $_GET['plcn_delete_gated'] );
                $type   = sanitize_key( $_GET['type'] ?? 'script' );
                $opts   = get_option( self::OPTION_NAME, self::defaults() );
                $key    = 'script' === $type ? 'gated_scripts' : 'gated_styles';
                unset( $opts[ $key ][ $handle ] );
                update_option( self::OPTION_NAME, $opts );
                wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=gated&deleted=1' ) );
                exit;
            }
        }
    }

    public function handle_admin_actions(): void {
        if ( ! current_user_can( plcn_manage_capability() ) ) return;
        if ( empty( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) return;

        if ( ! empty( $_POST['plcn_bump_version'] ) && ! empty( $_POST['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_POST['_wpnonce'], 'plcn_bump_version' ) ) {
                $opts = get_option( self::OPTION_NAME, self::defaults() );
                $opts['policy_version'] = (int) ( $opts['policy_version'] ?? 1 ) + 1;
                update_option( self::OPTION_NAME, $opts );
                wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=tools&reset=1' ) );
                exit;
            }
        }

        if ( ! empty( $_POST['plcn_clear_log'] ) && ! empty( $_POST['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_POST['_wpnonce'], 'plcn_clear_log' ) ) {
                global $wpdb;
                $wpdb->query( 'TRUNCATE TABLE ' . PLCN_Consent_Log::table_name() );
                wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=log&cleared=1' ) );
                exit;
            }
        }
    }

    /**
     * Stream settings as a JSON download.
     */
    public function handle_export(): void {
        if ( ! current_user_can( plcn_manage_capability() ) ) wp_die( 'Forbidden', 403 );
        check_admin_referer( 'plcn_export_settings' );

        $payload = array(
            'plugin'  => 'perrylabs-cookie-notice',
            'version' => PL_COOKIE_VERSION,
            'options' => get_option( self::OPTION_NAME, array() ),
        );

        nocache_headers();
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="plcn-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    public function handle_import(): void {
        if ( ! current_user_can( plcn_manage_capability() ) ) wp_die( 'Forbidden', 403 );
        check_admin_referer( 'plcn_import_settings' );

        if ( empty( $_FILES['plcn_settings_file']['tmp_name'] ) ) {
            wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=tools&import_error=missing' ) );
            exit;
        }

        $raw  = file_get_contents( $_FILES['plcn_settings_file']['tmp_name'] );
        $data = json_decode( $raw, true );

        if ( ! is_array( $data ) || empty( $data['options'] ) || ! is_array( $data['options'] ) ) {
            wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=tools&import_error=invalid' ) );
            exit;
        }

        // Run through sanitize() so we apply current schema rules.
        $merged = wp_parse_args( $data['options'], self::defaults() );
        update_option( self::OPTION_NAME, $this->sanitize( $merged ) );

        wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=tools&imported=1' ) );
        exit;
    }

    /* ============================================================== */
    /*  Page renderer                                                  */
    /* ============================================================== */

    public function render_page(): void {
        if ( ! current_user_can( plcn_manage_capability() ) ) return;

        $options    = get_option( self::OPTION_NAME, self::defaults() );
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'general';
        ?>
        <div class="wrap">
            <?php PerryLabs_Branding::header( __( 'Cookie Monster Settings', 'perrylabs-cookie-notice' ), PL_COOKIE_VERSION ); ?>

            <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
                <?php
                $tabs = array(
                    'general'  => __( 'General', 'perrylabs-cookie-notice' ),
                    'messages' => __( 'Messages', 'perrylabs-cookie-notice' ),
                    'scripts'  => __( 'Scripts & Pixels', 'perrylabs-cookie-notice' ),
                    'gated'    => __( 'Gated Handles', 'perrylabs-cookie-notice' ),
                    'embeds'   => __( 'Embed Blocker', 'perrylabs-cookie-notice' ),
                    'advanced' => __( 'Advanced', 'perrylabs-cookie-notice' ),
                    'log'      => __( 'Consent Log', 'perrylabs-cookie-notice' ),
                    'tools'    => __( 'Tools', 'perrylabs-cookie-notice' ),
                );
                foreach ( $tabs as $slug => $label ) {
                    $cls = ( $active_tab === $slug ) ? 'nav-tab nav-tab-active' : 'nav-tab';
                    printf(
                        '<a href="%s" class="%s">%s</a>',
                        esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=' . $slug ) ),
                        esc_attr( $cls ),
                        esc_html( $label )
                    );
                }
                ?>
            </nav>

            <?php
            switch ( $active_tab ) {
                case 'messages': $this->render_messages_tab( $options ); break;
                case 'scripts':  $this->render_scripts_tab( $options ); break;
                case 'gated':    $this->render_gated_tab( $options ); break;
                case 'embeds':   $this->render_embeds_tab( $options ); break;
                case 'advanced': $this->render_advanced_tab( $options ); break;
                case 'log':      $this->render_log_tab(); break;
                case 'tools':    $this->render_tools_tab( $options ); break;
                default:         $this->render_general_tab( $options ); break;
            }
            ?>

            <?php PerryLabs_Branding::footer(); ?>
        </div>
        <?php
    }

    /* ============================================================== */
    /*  General tab                                                    */
    /* ============================================================== */

    private function render_general_tab( array $options ): void {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'plcn_settings_group' ); ?>

            <h2><?php esc_html_e( 'Display', 'perrylabs-cookie-notice' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="plcn_enabled"><?php esc_html_e( 'Enable Cookie Notice', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td><input type="checkbox" id="plcn_enabled" name="plcn_options[enabled]" value="1" <?php checked( 1, $options['enabled'] ?? 1 ); ?> /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="plcn_position"><?php esc_html_e( 'Banner Position', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <select id="plcn_position" name="plcn_options[position]">
                            <option value="bottom" <?php selected( $options['position'] ?? 'bottom', 'bottom' ); ?>><?php esc_html_e( 'Footer (bottom bar)', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="top" <?php selected( $options['position'] ?? 'bottom', 'top' ); ?>><?php esc_html_e( 'Header (top bar)', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="overlay" <?php selected( $options['position'] ?? 'bottom', 'overlay' ); ?>><?php esc_html_e( 'Overlay (centered modal)', 'perrylabs-cookie-notice' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="plcn_theme"><?php esc_html_e( 'Preferences Modal Theme', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <select id="plcn_theme" name="plcn_options[theme]">
                            <option value="light" <?php selected( $options['theme'] ?? 'light', 'light' ); ?>><?php esc_html_e( 'Light', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="dark"  <?php selected( $options['theme'] ?? 'light', 'dark' ); ?>><?php esc_html_e( 'Dark', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="auto"  <?php selected( $options['theme'] ?? 'light', 'auto' ); ?>><?php esc_html_e( 'Auto (system)', 'perrylabs-cookie-notice' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="plcn_bg_color"><?php esc_html_e( 'Banner Background', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td><input type="text" id="plcn_bg_color" class="plcn-color-field" name="plcn_options[bg_color]" value="<?php echo esc_attr( $options['bg_color'] ?? '#111' ); ?>" data-default-color="#111111" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="plcn_button_color"><?php esc_html_e( 'Accent Color', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td><input type="text" id="plcn_button_color" class="plcn-color-field" name="plcn_options[button_color]" value="<?php echo esc_attr( $options['button_color'] ?? '#ffb25d' ); ?>" data-default-color="#ffb25d" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="plcn_privacy_policy_url"><?php esc_html_e( 'Privacy Policy URL', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <input type="url" id="plcn_privacy_policy_url" name="plcn_options[privacy_policy_url]" value="<?php echo esc_attr( $options['privacy_policy_url'] ?? '' ); ?>" class="large-text" placeholder="https://example.com/privacy" />
                        <p class="description"><?php esc_html_e( 'Set to auto-insert a "privacy policy" link into the banner message.', 'perrylabs-cookie-notice' ); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Compliance', 'perrylabs-cookie-notice' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="plcn_compliance_mode"><?php esc_html_e( 'Compliance Mode', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <select id="plcn_compliance_mode" name="plcn_options[compliance_mode]">
                            <option value="none" <?php selected( $options['compliance_mode'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'None — simple notice', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="gdpr" <?php selected( $options['compliance_mode'] ?? 'none', 'gdpr' ); ?>><?php esc_html_e( 'GDPR (EU) — explicit opt-in', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="ccpa" <?php selected( $options['compliance_mode'] ?? 'none', 'ccpa' ); ?>><?php esc_html_e( 'CCPA (California) — opt-out', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="both" <?php selected( $options['compliance_mode'] ?? 'none', 'both' ); ?>><?php esc_html_e( 'Both — geo-targeted', 'perrylabs-cookie-notice' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="plcn_google_consent_mode"><?php esc_html_e( 'Google Consent Mode v2', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <input type="checkbox" id="plcn_google_consent_mode" name="plcn_options[google_consent_mode]" value="1" <?php checked( 1, $options['google_consent_mode'] ?? 0 ); ?> />
                        <label for="plcn_google_consent_mode"><?php esc_html_e( 'Emit default + update signals for Google Ads / Analytics', 'perrylabs-cookie-notice' ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="plcn_google_consent_wait_ms"><?php esc_html_e( 'wait_for_update (ms)', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td><input type="number" id="plcn_google_consent_wait_ms" name="plcn_options[google_consent_wait_ms]" value="<?php echo esc_attr( $options['google_consent_wait_ms'] ?? 500 ); ?>" min="0" max="5000" class="small-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="plcn_log_consent"><?php esc_html_e( 'Audit Log', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <input type="checkbox" id="plcn_log_consent" name="plcn_options[log_consent]" value="1" <?php checked( 1, $options['log_consent'] ?? 0 ); ?> />
                        <label for="plcn_log_consent"><?php esc_html_e( 'Record consent decisions (hashed IP/UA)', 'perrylabs-cookie-notice' ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="plcn_honor_dnt"><?php esc_html_e( 'Honor DNT / GPC', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <input type="checkbox" id="plcn_honor_dnt" name="plcn_options[honor_dnt]" value="1" <?php checked( 1, $options['honor_dnt'] ?? 1 ); ?> />
                        <label for="plcn_honor_dnt"><?php esc_html_e( 'Treat Do Not Track and Global Privacy Control signals as "reject all" — banner is skipped', 'perrylabs-cookie-notice' ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="plcn_expiry_days"><?php esc_html_e( 'Consent Lifetime (days)', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td><input type="number" id="plcn_expiry_days" name="plcn_options[expiry_days]" value="<?php echo esc_attr( $options['expiry_days'] ?? 365 ); ?>" min="1" class="small-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="plcn_skip_for_admins"><?php esc_html_e( 'Skip for admins', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <input type="checkbox" id="plcn_skip_for_admins" name="plcn_options[skip_for_admins]" value="1" <?php checked( 1, $options['skip_for_admins'] ?? 1 ); ?> />
                        <label for="plcn_skip_for_admins"><?php esc_html_e( 'Hide the banner from logged-in admin users', 'perrylabs-cookie-notice' ); ?></label>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <?php $this->render_live_preview( $options ); ?>
        <?php
    }

    /**
     * Live preview block — renders a mini banner using the same CSS as the front-end.
     */
    private function render_live_preview( array $options ): void {
        $bg     = $options['bg_color']     ?? '#111';
        $accent = $options['button_color'] ?? '#ffb25d';
        $msg    = PLCN_Strings::get( 'banner_message' );
        $title  = PLCN_Strings::get( 'banner_title' );
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Live preview', 'perrylabs-cookie-notice' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Approximates the front-end banner with your current colors and messages. Save the form to refresh.', 'perrylabs-cookie-notice' ); ?></p>
        <div id="plcn-admin-preview" style="margin-top:8px;padding:16px;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:6px;">
            <div class="plcn-banner-inner" id="plcn-preview-bar" style="background:<?php echo esc_attr( $bg ); ?>;color:#fff;padding:14px 18px;border-radius:6px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;justify-content:space-between;">
                <div style="flex:1;min-width:260px;">
                    <?php if ( $title ) : ?>
                        <p style="margin:0 0 4px;font-weight:600;font-size:14px;" id="plcn-preview-title"><?php echo esc_html( $title ); ?></p>
                    <?php endif; ?>
                    <p style="margin:0;font-size:13px;line-height:1.5;" id="plcn-preview-msg"><?php echo esc_html( $msg ); ?></p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="button" id="plcn-preview-accept" style="background:<?php echo esc_attr( $accent ); ?>;color:<?php echo esc_attr( $bg ); ?>;border:0;padding:9px 16px;border-radius:6px;font-weight:600;font-size:13px;cursor:default;">
                        <?php echo esc_html( PLCN_Strings::get( 'btn_accept_all' ) ); ?>
                    </button>
                    <button type="button" id="plcn-preview-reject" style="background:transparent;color:#fff;border:1px solid <?php echo esc_attr( $accent ); ?>;padding:9px 16px;border-radius:6px;font-weight:600;font-size:13px;cursor:default;">
                        <?php echo esc_html( PLCN_Strings::get( 'btn_reject_all' ) ); ?>
                    </button>
                    <button type="button" style="background:transparent;color:rgba(255,255,255,0.78);border:0;padding:9px 6px;font-size:13px;text-decoration:underline;cursor:default;">
                        <?php echo esc_html( PLCN_Strings::get( 'btn_customize' ) ); ?>
                    </button>
                </div>
            </div>
        </div>
        <script>
        window.plcnUpdatePreview = function () {
            var bgEl = document.getElementById('plcn_bg_color');
            var acEl = document.getElementById('plcn_button_color');
            var bg = bgEl ? bgEl.value : '<?php echo esc_js( $bg ); ?>';
            var ac = acEl ? acEl.value : '<?php echo esc_js( $accent ); ?>';
            var bar = document.getElementById('plcn-preview-bar');
            var acc = document.getElementById('plcn-preview-accept');
            var rej = document.getElementById('plcn-preview-reject');
            if (bar) bar.style.background = bg;
            if (acc) { acc.style.background = ac; acc.style.color = bg; }
            if (rej) rej.style.borderColor = ac;
        };
        </script>
        <?php
    }

    /* ============================================================== */
    /*  Messages tab                                                    */
    /* ============================================================== */

    private function render_messages_tab( array $options ): void {
        $current = $options['strings'] ?? array();
        ?>
        <p><?php esc_html_e( 'Customize every visible message and label. Blanks fall back to the default text shown in placeholders.', 'perrylabs-cookie-notice' ); ?></p>
        <form method="post" action="options.php">
            <?php settings_fields( 'plcn_settings_group' ); ?>

            <?php
            // Hidden fields preserving everything else.
            foreach ( $options as $k => $v ) {
                if ( in_array( $k, array( 'strings', 'scripts', 'gated_scripts', 'gated_styles', 'embed_blocker', 'policy_version' ), true ) ) continue;
                if ( is_array( $v ) ) continue;
                printf( '<input type="hidden" name="plcn_options[%s]" value="%s" />', esc_attr( $k ), esc_attr( (string) $v ) );
            }
            ?>

            <?php foreach ( PLCN_Strings::admin_groups() as $group_label => $keys ) : ?>
                <h2><?php echo esc_html( $group_label ); ?></h2>
                <table class="form-table" role="presentation">
                    <?php foreach ( $keys as $key => $label ) :
                        $default = PLCN_Strings::defaults()[ $key ] ?? '';
                        $value   = $current[ $key ] ?? '';
                        $multi   = strlen( $default ) > 80;
                        ?>
                        <tr>
                            <th scope="row"><label for="plcn_str_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                            <td>
                                <?php if ( $multi ) : ?>
                                    <textarea id="plcn_str_<?php echo esc_attr( $key ); ?>" name="plcn_options[strings][<?php echo esc_attr( $key ); ?>]" rows="2" class="large-text" placeholder="<?php echo esc_attr( $default ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
                                <?php else : ?>
                                    <input type="text" id="plcn_str_<?php echo esc_attr( $key ); ?>" name="plcn_options[strings][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $default ); ?>" />
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endforeach; ?>

            <?php submit_button( __( 'Save Messages', 'perrylabs-cookie-notice' ) ); ?>
        </form>
        <?php
    }

    /* ============================================================== */
    /*  Advanced tab                                                    */
    /* ============================================================== */

    private function render_advanced_tab( array $options ): void {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'plcn_settings_group' ); ?>

            <?php
            foreach ( $options as $k => $v ) {
                if ( in_array( $k, array( 'skip_urls', 'custom_css', 'strings', 'scripts', 'gated_scripts', 'gated_styles', 'embed_blocker', 'policy_version' ), true ) ) continue;
                if ( is_array( $v ) ) continue;
                printf( '<input type="hidden" name="plcn_options[%s]" value="%s" />', esc_attr( $k ), esc_attr( (string) $v ) );
            }
            ?>

            <h2><?php esc_html_e( 'Skip the banner on specific URLs', 'perrylabs-cookie-notice' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="plcn_skip_urls"><?php esc_html_e( 'URL patterns', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <textarea id="plcn_skip_urls" name="plcn_options[skip_urls]" rows="6" class="large-text code" placeholder="/checkout/*&#10;/login/&#10;/wp-login.php"><?php echo esc_textarea( $options['skip_urls'] ?? '' ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One path per line. Supports * and ? wildcards. Matches the path portion only (e.g. /checkout/*).', 'perrylabs-cookie-notice' ); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Custom CSS', 'perrylabs-cookie-notice' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="plcn_custom_css"><?php esc_html_e( 'CSS', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <textarea id="plcn_custom_css" name="plcn_options[custom_css]" rows="12" class="large-text code"><?php echo esc_textarea( $options['custom_css'] ?? '' ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Injected as a <style> block in <head>. Useful selectors: #plcn-banner, #plcn-preferences, .plcn-btn, .plcn-embed-wrap, .plcn-policy-table.', 'perrylabs-cookie-notice' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /* ============================================================== */
    /*  Scripts tab                                                    */
    /* ============================================================== */

    private function render_scripts_tab( array $options ): void {
        $scripts     = $options['scripts'] ?? array();
        $edit_handle = isset( $_GET['edit_script'] ) ? sanitize_text_field( $_GET['edit_script'] ) : '';
        $edit_data   = ! empty( $edit_handle ) && isset( $scripts[ $edit_handle ] ) ? $scripts[ $edit_handle ] : null;

        $preset_key = isset( $_GET['preset'] ) ? sanitize_text_field( $_GET['preset'] ) : '';
        $preset     = ! empty( $preset_key ) && isset( PLCN_Script_Registry::PRESETS[ $preset_key ] ) ? PLCN_Script_Registry::PRESETS[ $preset_key ] : null;

        if ( ! empty( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Script saved.', 'perrylabs-cookie-notice' ) . '</p></div>';
        }
        if ( ! empty( $_GET['deleted'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Script deleted.', 'perrylabs-cookie-notice' ) . '</p></div>';
        }
        ?>

        <div style="margin-bottom:20px;">
            <h3 style="margin-bottom:8px;"><?php esc_html_e( 'Quick Add', 'perrylabs-cookie-notice' ); ?></h3>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php foreach ( PLCN_Script_Registry::PRESETS as $key => $p ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=scripts&preset=' . $key ) ); ?>"
                       class="button button-secondary">
                        <?php echo esc_html( $p['label'] ); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <h3><?php esc_html_e( 'Registered Scripts', 'perrylabs-cookie-notice' ); ?></h3>
        <?php if ( empty( $scripts ) ) : ?>
            <p class="description"><?php esc_html_e( 'No scripts registered yet. Use Quick Add or the form below.', 'perrylabs-cookie-notice' ); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="margin-bottom:24px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Handle', 'perrylabs-cookie-notice' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'perrylabs-cookie-notice' ); ?></th>
                        <th><?php esc_html_e( 'Category', 'perrylabs-cookie-notice' ); ?></th>
                        <th><?php esc_html_e( 'ID', 'perrylabs-cookie-notice' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'perrylabs-cookie-notice' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $scripts as $handle => $script ) :
                        $delete_url = wp_nonce_url(
                            admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=scripts&plcn_delete_script=' . urlencode( $handle ) ),
                            'plcn_delete_script'
                        );
                        $edit_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=scripts&edit_script=' . urlencode( $handle ) );
                        ?>
                        <tr>
                            <td><code><?php echo esc_html( $handle ); ?></code></td>
                            <td><?php echo esc_html( $script['label'] ?? $handle ); ?></td>
                            <td><?php echo esc_html( ucfirst( $script['category'] ?? 'other' ) ); ?></td>
                            <td><code><?php echo esc_html( $script['service_id'] ?? '—' ); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'perrylabs-cookie-notice' ); ?></a>
                                |
                                <a href="<?php echo esc_url( $delete_url ); ?>" style="color:#b32d2e;" onclick="return confirm('<?php esc_attr_e( 'Delete this script?', 'perrylabs-cookie-notice' ); ?>');"><?php esc_html_e( 'Delete', 'perrylabs-cookie-notice' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>
            <?php
            if ( $edit_data ) {
                esc_html_e( 'Edit Script', 'perrylabs-cookie-notice' );
            } elseif ( $preset ) {
                printf( esc_html__( 'Add Script: %s', 'perrylabs-cookie-notice' ), esc_html( $preset['label'] ) );
            } else {
                esc_html_e( 'Add Script', 'perrylabs-cookie-notice' );
            }
            ?>
        </h3>

        <?php if ( $preset ) : ?>
            <p class="description">
                <?php
                printf(
                    esc_html__( 'Pre-filled from the %1$s preset. Enter your %2$s below — it will be substituted on save.', 'perrylabs-cookie-notice' ),
                    '<strong>' . esc_html( $preset['label'] ) . '</strong>',
                    '<strong>' . esc_html( $preset['id_label'] ?? 'ID' ) . '</strong>'
                );
                ?>
            </p>
        <?php endif; ?>

        <?php
        $f_handle     = $edit_data ? $edit_handle : ( $preset_key ?: '' );
        $f_label      = $edit_data ? ( $edit_data['label'] ?? '' ) : ( $preset ? $preset['label'] : '' );
        $f_category   = $edit_data ? ( $edit_data['category'] ?? 'other' ) : ( $preset ? $preset['category'] : 'other' );
        $f_service_id = $edit_data ? ( $edit_data['service_id'] ?? '' ) : '';
        $f_src        = $edit_data ? ( $edit_data['src'] ?? '' ) : ( $preset ? $preset['src'] : '' );
        $f_inline     = $edit_data ? ( $edit_data['inline'] ?? '' ) : ( $preset ? $preset['inline'] : '' );
        $f_attrs      = $edit_data ? implode( ',', (array) ( $edit_data['attrs'] ?? array() ) ) : ( $preset ? implode( ',', $preset['attrs'] ?? array() ) : '' );
        $f_load_in    = $edit_data ? ( $edit_data['load_in'] ?? 'head' ) : ( $preset ? ( $preset['load_in'] ?? 'head' ) : 'head' );
        $id_label     = $preset['id_label'] ?? 'Service ID';
        ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=scripts' ) ); ?>">
            <?php wp_nonce_field( 'plcn_save_script' ); ?>
            <input type="hidden" name="plcn_save_script" value="1" />

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="script_handle"><?php esc_html_e( 'Handle (slug)', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td><input type="text" id="script_handle" name="script_handle" value="<?php echo esc_attr( $f_handle ); ?>" class="regular-text" required <?php echo $edit_data ? 'readonly' : ''; ?> pattern="[a-z0-9\-]+" placeholder="my-tracking-script" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="script_label"><?php esc_html_e( 'Display Name', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td><input type="text" id="script_label" name="script_label" value="<?php echo esc_attr( $f_label ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="script_category"><?php esc_html_e( 'Category', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <select id="script_category" name="script_category">
                            <option value="required" <?php selected( $f_category, 'required' ); ?>><?php esc_html_e( 'Required', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="analytics" <?php selected( $f_category, 'analytics' ); ?>><?php esc_html_e( 'Analytics', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="marketing" <?php selected( $f_category, 'marketing' ); ?>><?php esc_html_e( 'Marketing', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="other" <?php selected( $f_category, 'other' ); ?>><?php esc_html_e( 'Other', 'perrylabs-cookie-notice' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="script_service_id"><?php echo esc_html( $id_label ); ?></label></th>
                    <td>
                        <input type="text" id="script_service_id" name="script_service_id" value="<?php echo esc_attr( $f_service_id ); ?>" class="regular-text" placeholder="e.g. G-XXXXX or GTM-XXXXX" />
                        <p class="description"><?php esc_html_e( 'Any %s in the URL/snippet will be replaced with this value on save.', 'perrylabs-cookie-notice' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="script_src"><?php esc_html_e( 'External Script URL', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td><input type="url" id="script_src" name="script_src" value="<?php echo esc_attr( $f_src ); ?>" class="large-text" placeholder="https://example.com/script.js" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="script_inline"><?php esc_html_e( 'Inline JS Snippet', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td><textarea id="script_inline" name="script_inline" rows="6" class="large-text code"><?php echo esc_textarea( $f_inline ); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="script_attrs"><?php esc_html_e( 'Script Attributes', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td><input type="text" id="script_attrs" name="script_attrs" value="<?php echo esc_attr( $f_attrs ); ?>" class="regular-text" placeholder="async,defer" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="script_load_in"><?php esc_html_e( 'Load In', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <select id="script_load_in" name="script_load_in">
                            <option value="head" <?php selected( $f_load_in, 'head' ); ?>><?php esc_html_e( 'Head', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="footer" <?php selected( $f_load_in, 'footer' ); ?>><?php esc_html_e( 'Footer', 'perrylabs-cookie-notice' ); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( $edit_data ? __( 'Update Script', 'perrylabs-cookie-notice' ) : __( 'Add Script', 'perrylabs-cookie-notice' ) ); ?>
        </form>
        <?php
    }

    /* ============================================================== */
    /*  Gated Handles tab                                              */
    /* ============================================================== */

    private function render_gated_tab( array $options ): void {
        if ( ! empty( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Gated handle saved.', 'perrylabs-cookie-notice' ) . '</p></div>';
        }
        if ( ! empty( $_GET['deleted'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Gated handle removed.', 'perrylabs-cookie-notice' ) . '</p></div>';
        }

        $gated_scripts = $options['gated_scripts'] ?? array();
        $gated_styles  = $options['gated_styles']  ?? array();
        ?>
        <p><?php esc_html_e( 'Gate scripts or stylesheets that other plugins/themes enqueue. Enter the WordPress handle and a category — the rendered tag is rewritten until consent.', 'perrylabs-cookie-notice' ); ?></p>

        <h3 style="margin-top:24px;"><?php esc_html_e( 'Gated Scripts', 'perrylabs-cookie-notice' ); ?></h3>
        <?php $this->render_gated_table( $gated_scripts, 'script' ); ?>

        <h3 style="margin-top:24px;"><?php esc_html_e( 'Gated Stylesheets', 'perrylabs-cookie-notice' ); ?></h3>
        <?php $this->render_gated_table( $gated_styles, 'style' ); ?>

        <h3 style="margin-top:24px;"><?php esc_html_e( 'Add a Gated Handle', 'perrylabs-cookie-notice' ); ?></h3>
        <form method="post">
            <?php wp_nonce_field( 'plcn_save_gated' ); ?>
            <input type="hidden" name="plcn_save_gated" value="1" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="gated_handle"><?php esc_html_e( 'WP Handle', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td><input type="text" id="gated_handle" name="gated_handle" class="regular-text" placeholder="google-analytics" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gated_type"><?php esc_html_e( 'Type', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <select id="gated_type" name="gated_type">
                            <option value="script"><?php esc_html_e( 'Script', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="style"><?php esc_html_e( 'Stylesheet', 'perrylabs-cookie-notice' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gated_category"><?php esc_html_e( 'Category', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <select id="gated_category" name="gated_category">
                            <option value="analytics"><?php esc_html_e( 'Analytics', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="marketing"><?php esc_html_e( 'Marketing', 'perrylabs-cookie-notice' ); ?></option>
                            <option value="other"><?php esc_html_e( 'Other', 'perrylabs-cookie-notice' ); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Add Gated Handle', 'perrylabs-cookie-notice' ) ); ?>
        </form>
        <?php
    }

    private function render_gated_table( array $gated, string $type ): void {
        if ( empty( $gated ) ) {
            echo '<p class="description">' . esc_html__( 'None registered.', 'perrylabs-cookie-notice' ) . '</p>';
            return;
        }
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Handle', 'perrylabs-cookie-notice' ); ?></th>
                    <th><?php esc_html_e( 'Category', 'perrylabs-cookie-notice' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'perrylabs-cookie-notice' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $gated as $handle => $cat ) :
                    $delete = wp_nonce_url(
                        admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=gated&plcn_delete_gated=' . urlencode( $handle ) . '&type=' . $type ),
                        'plcn_delete_gated'
                    );
                    ?>
                    <tr>
                        <td><code><?php echo esc_html( $handle ); ?></code></td>
                        <td><?php echo esc_html( ucfirst( $cat ) ); ?></td>
                        <td><a href="<?php echo esc_url( $delete ); ?>" style="color:#b32d2e;"><?php esc_html_e( 'Remove', 'perrylabs-cookie-notice' ); ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ============================================================== */
    /*  Embed Blocker tab                                              */
    /* ============================================================== */

    private function render_embeds_tab( array $options ): void {
        $enabled = $options['embed_blocker'] ?? array();
        ?>
        <p><?php esc_html_e( 'Replace third-party iframes with a click-to-load placeholder until consent. Pick which providers to block — others pass through.', 'perrylabs-cookie-notice' ); ?></p>
        <form method="post" action="options.php">
            <?php settings_fields( 'plcn_settings_group' ); ?>
            <?php
            foreach ( $options as $k => $v ) {
                if ( in_array( $k, array( 'embed_blocker', 'strings', 'scripts', 'gated_scripts', 'gated_styles', 'policy_version' ), true ) ) continue;
                if ( is_array( $v ) ) continue;
                printf( '<input type="hidden" name="plcn_options[%s]" value="%s" />', esc_attr( $k ), esc_attr( (string) $v ) );
            }
            ?>
            <table class="widefat striped" style="max-width:640px;">
                <thead>
                    <tr>
                        <th style="width:60px;"><?php esc_html_e( 'Block', 'perrylabs-cookie-notice' ); ?></th>
                        <th><?php esc_html_e( 'Provider', 'perrylabs-cookie-notice' ); ?></th>
                        <th><?php esc_html_e( 'Default category', 'perrylabs-cookie-notice' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( PLCN_Embed_Blocker::PROVIDERS as $key => $p ) :
                        $checked = in_array( $key, $enabled, true );
                        ?>
                        <tr>
                            <td><input type="checkbox" name="plcn_options[embed_blocker][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?> /></td>
                            <td><?php echo esc_html( $p['label'] ); ?></td>
                            <td><?php echo esc_html( ucfirst( $p['category'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    /* ============================================================== */
    /*  Consent Log tab                                                */
    /* ============================================================== */

    private function render_log_tab(): void {
        $log     = PLCN_Consent_Log::instance();
        $entries = $log->recent( 100 );
        $total   = $log->count_total();

        if ( ! empty( $_GET['cleared'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Consent log cleared.', 'perrylabs-cookie-notice' ) . '</p></div>';
        }
        ?>
        <p>
            <?php
            printf(
                esc_html__( '%d total events recorded. Showing the most recent 100. IP and user agent are hashed.', 'perrylabs-cookie-notice' ),
                (int) $total
            );
            ?>
        </p>

        <?php if ( $total > 0 ) : ?>
            <p>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=plcn_export_log' ), 'plcn_export_log' ) ); ?>" class="button button-secondary">
                    <?php esc_html_e( 'Export CSV', 'perrylabs-cookie-notice' ); ?>
                </a>
            </p>
        <?php endif; ?>

        <?php if ( empty( $entries ) ) : ?>
            <p class="description"><?php esc_html_e( 'No events yet. Enable "Audit Log" on the General tab.', 'perrylabs-cookie-notice' ); ?></p>
            <?php return;
        endif; ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'When', 'perrylabs-cookie-notice' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'perrylabs-cookie-notice' ); ?></th>
                    <th><?php esc_html_e( 'Categories', 'perrylabs-cookie-notice' ); ?></th>
                    <th><?php esc_html_e( 'Region', 'perrylabs-cookie-notice' ); ?></th>
                    <th><?php esc_html_e( 'Policy v.', 'perrylabs-cookie-notice' ); ?></th>
                    <th><?php esc_html_e( 'IP hash', 'perrylabs-cookie-notice' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $entries as $row ) :
                    $cats = json_decode( $row['categories'], true );
                    $cat_summary = array();
                    if ( is_array( $cats ) ) {
                        foreach ( $cats as $k => $v ) {
                            $cat_summary[] = $k . ': ' . ( $v ? 'yes' : 'no' );
                        }
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html( $row['recorded_at'] ); ?></td>
                        <td><?php echo esc_html( $row['action'] ); ?></td>
                        <td style="font-size:12px;"><?php echo esc_html( implode( ' · ', $cat_summary ) ); ?></td>
                        <td><?php echo esc_html( $row['geo_region'] ); ?></td>
                        <td><?php echo esc_html( $row['policy_version'] ); ?></td>
                        <td style="font-family:monospace;font-size:11px;"><?php echo esc_html( substr( $row['ip_hash'], 0, 12 ) ); ?>…</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top:24px;"><?php esc_html_e( 'Maintenance', 'perrylabs-cookie-notice' ); ?></h3>
        <form method="post">
            <?php wp_nonce_field( 'plcn_clear_log' ); ?>
            <input type="hidden" name="plcn_clear_log" value="1" />
            <button type="submit" class="button button-secondary" onclick="return confirm('<?php esc_attr_e( 'Permanently delete all consent log entries?', 'perrylabs-cookie-notice' ); ?>');">
                <?php esc_html_e( 'Clear Consent Log', 'perrylabs-cookie-notice' ); ?>
            </button>
        </form>
        <?php
    }

    /* ============================================================== */
    /*  Tools tab                                                      */
    /* ============================================================== */

    private function render_tools_tab( array $options ): void {
        if ( ! empty( $_GET['reset'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Policy version bumped. All visitors will be re-prompted.', 'perrylabs-cookie-notice' ) . '</p></div>';
        }
        if ( ! empty( $_GET['imported'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings imported.', 'perrylabs-cookie-notice' ) . '</p></div>';
        }
        if ( ! empty( $_GET['import_error'] ) ) {
            $msg = ( 'invalid' === $_GET['import_error'] )
                ? __( 'The uploaded file is not a valid Cookie Monster settings export.', 'perrylabs-cookie-notice' )
                : __( 'No file was uploaded.', 'perrylabs-cookie-notice' );
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        }
        ?>
        <h3><?php esc_html_e( 'Re-prompt all visitors', 'perrylabs-cookie-notice' ); ?></h3>
        <p class="description">
            <?php
            printf(
                esc_html__( 'Current policy version: %d. Bumping invalidates every visitor\'s consent cookie.', 'perrylabs-cookie-notice' ),
                (int) ( $options['policy_version'] ?? 1 )
            );
            ?>
        </p>
        <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field( 'plcn_bump_version' ); ?>
            <input type="hidden" name="plcn_bump_version" value="1" />
            <button type="submit" class="button button-secondary" onclick="return confirm('<?php esc_attr_e( 'Re-prompt every visitor on their next visit?', 'perrylabs-cookie-notice' ); ?>');">
                <?php esc_html_e( 'Bump Policy Version', 'perrylabs-cookie-notice' ); ?>
            </button>
        </form>

        <h3 style="margin-top:32px;"><?php esc_html_e( 'Reset my consent (for testing)', 'perrylabs-cookie-notice' ); ?></h3>
        <p>
            <button type="button" class="button button-secondary" id="plcn-reset-mine">
                <?php esc_html_e( 'Clear my consent + open front page', 'perrylabs-cookie-notice' ); ?>
            </button>
        </p>
        <script>
        (function () {
            var btn = document.getElementById('plcn-reset-mine');
            if (!btn) return;
            btn.addEventListener('click', function () {
                ['plcn_consent','plcn_geo','plcn_dismissed'].forEach(function (n) {
                    document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
                });
                window.open(<?php echo wp_json_encode( home_url( '/' ) ); ?>, '_blank');
            });
        })();
        </script>

        <h3 style="margin-top:32px;"><?php esc_html_e( 'Export settings', 'perrylabs-cookie-notice' ); ?></h3>
        <p class="description"><?php esc_html_e( 'Download all settings as a JSON file. Useful for cloning across staging/production.', 'perrylabs-cookie-notice' ); ?></p>
        <p>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=plcn_export_settings' ), 'plcn_export_settings' ) ); ?>" class="button button-secondary">
                <?php esc_html_e( 'Download settings JSON', 'perrylabs-cookie-notice' ); ?>
            </a>
        </p>

        <h3 style="margin-top:32px;"><?php esc_html_e( 'Import settings', 'perrylabs-cookie-notice' ); ?></h3>
        <p class="description"><?php esc_html_e( 'Replace current settings with a previously exported JSON file. Existing scripts, gated handles, and policy version are preserved.', 'perrylabs-cookie-notice' ); ?></p>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'plcn_import_settings' ); ?>
            <input type="hidden" name="action" value="plcn_import_settings" />
            <p><input type="file" name="plcn_settings_file" accept="application/json" required /></p>
            <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'perrylabs-cookie-notice' ); ?></button></p>
        </form>

        <h3 style="margin-top:32px;"><?php esc_html_e( 'Developer reference', 'perrylabs-cookie-notice' ); ?></h3>
        <ul style="list-style:disc;margin-left:20px;">
            <li><code>plcn_has_consent( 'analytics' )</code></li>
            <li><code>plcn_register_script( $handle, $config )</code></li>
            <li><code>plcn_gate_script( $handle, $category )</code> / <code>plcn_gate_style</code></li>
            <li>REST: <code>GET /wp-json/plcn/v1/consent</code>, <code>POST /wp-json/plcn/v1/consent</code>, <code>GET /wp-json/plcn/v1/policy</code></li>
            <li>WP-CLI: <code>wp plcn settings export|import</code>, <code>wp plcn policy bump</code>, <code>wp plcn log export|clear</code></li>
            <li>Capability filter: <code>add_filter( 'plcn_manage_capability', fn() =&gt; 'edit_others_posts' );</code></li>
            <li>String override filter: <code>add_filter( 'plcn_string', fn( $v, $key ) =&gt; ..., 10, 2 );</code></li>
        </ul>
        <?php
    }
}
