<?php
/**
 * PLCN_Settings — Admin settings page.
 *
 * Tabs: General, Scripts & Pixels, Gated Handles, Embed Blocker, Consent Log, Tools.
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
        );
    }

    public function add_page(): void {
        add_options_page(
            __( 'Cookie Notice', 'perrylabs-cookie-notice' ),
            __( 'Cookie Notice', 'perrylabs-cookie-notice' ),
            'manage_options',
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

        // PerryLabs tokens.
        wp_enqueue_style(
            'perrylabs-tokens',
            PL_COOKIE_PLUGIN_URL . 'includes/branding/tokens.css',
            array(),
            PL_COOKIE_VERSION
        );

        // WP color picker.
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        // Small inline init for color picker.
        wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".plcn-color-field").wpColorPicker();});' );
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

        // Embed blocker — array of provider keys.
        $allowed_providers = array_keys( PLCN_Embed_Blocker::PROVIDERS );
        $sanitized['embed_blocker'] = array_values( array_intersect(
            $allowed_providers,
            array_map( 'sanitize_key', (array) ( $input['embed_blocker'] ?? array() ) )
        ) );

        // Preserve settings managed in other tabs.
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
        if ( ! current_user_can( 'manage_options' ) ) return;
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

                // Smart preset ID substitution.
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
        if ( ! current_user_can( 'manage_options' ) ) return;
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
        if ( ! current_user_can( 'manage_options' ) ) return;
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

    /* ============================================================== */
    /*  Page renderer                                                  */
    /* ============================================================== */

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $options    = get_option( self::OPTION_NAME, self::defaults() );
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'general';
        ?>
        <div class="wrap">
            <?php PerryLabs_Branding::header( __( 'Cookie Monster Settings', 'perrylabs-cookie-notice' ), PL_COOKIE_VERSION ); ?>

            <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
                <?php
                $tabs = array(
                    'general' => __( 'General', 'perrylabs-cookie-notice' ),
                    'scripts' => __( 'Scripts & Pixels', 'perrylabs-cookie-notice' ),
                    'gated'   => __( 'Gated Handles', 'perrylabs-cookie-notice' ),
                    'embeds'  => __( 'Embed Blocker', 'perrylabs-cookie-notice' ),
                    'log'     => __( 'Consent Log', 'perrylabs-cookie-notice' ),
                    'tools'   => __( 'Tools', 'perrylabs-cookie-notice' ),
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
                case 'scripts': $this->render_scripts_tab( $options ); break;
                case 'gated':   $this->render_gated_tab( $options ); break;
                case 'embeds':  $this->render_embeds_tab( $options ); break;
                case 'log':     $this->render_log_tab(); break;
                case 'tools':   $this->render_tools_tab( $options ); break;
                default:        $this->render_general_tab( $options ); break;
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
                    <th scope="row"><label for="plcn_message"><?php esc_html_e( 'Message', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td><textarea id="plcn_message" name="plcn_options[message]" rows="3" cols="60" class="large-text"><?php echo esc_textarea( $options['message'] ?? '' ); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="plcn_button_text"><?php esc_html_e( 'Single Button Text', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <input type="text" id="plcn_button_text" name="plcn_options[button_text]" value="<?php echo esc_attr( $options['button_text'] ?? 'Got it' ); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e( 'Shown only when compliance mode is "None" or no optional scripts are registered.', 'perrylabs-cookie-notice' ); ?></p>
                    </td>
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
                            <option value="auto"  <?php selected( $options['theme'] ?? 'light', 'auto' ); ?>><?php esc_html_e( 'Auto (follow system preference)', 'perrylabs-cookie-notice' ); ?></option>
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
                        <p class="description"><?php esc_html_e( 'Required for Google Ads in the EEA.', 'perrylabs-cookie-notice' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="plcn_google_consent_wait_ms"><?php esc_html_e( 'Consent Mode wait_for_update (ms)', 'perrylabs-cookie-notice' ); ?></label></th>
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
                    <th scope="row"><label for="plcn_expiry_days"><?php esc_html_e( 'Consent Lifetime (days)', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td><input type="number" id="plcn_expiry_days" name="plcn_options[expiry_days]" value="<?php echo esc_attr( $options['expiry_days'] ?? 365 ); ?>" min="1" class="small-text" /></td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <h2 style="margin-top:24px;"><?php esc_html_e( 'Shortcodes', 'perrylabs-cookie-notice' ); ?></h2>
        <ul style="list-style:disc;margin-left:20px;">
            <li><code>[plcn_settings_link]</code> — <?php esc_html_e( 'Re-open the preferences modal.', 'perrylabs-cookie-notice' ); ?></li>
            <li><code>[plcn_cookie_policy]</code> — <?php esc_html_e( 'Auto-generated table of registered scripts.', 'perrylabs-cookie-notice' ); ?></li>
            <li><code>[plcn_ccpa_optout]</code> — <?php esc_html_e( 'CCPA "Do Not Sell" link.', 'perrylabs-cookie-notice' ); ?></li>
        </ul>
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
                    esc_html__( 'Pre-filled from the %s preset. Enter your %s below and it will be substituted into the snippet automatically.', 'perrylabs-cookie-notice' ),
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
        $id_label     = $preset['id_label'] ?? ( $edit_data ? 'Service ID' : 'Service ID' );
        ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=scripts' ) ); ?>">
            <?php wp_nonce_field( 'plcn_save_script' ); ?>
            <input type="hidden" name="plcn_save_script" value="1" />

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="script_handle"><?php esc_html_e( 'Handle (slug)', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <input type="text" id="script_handle" name="script_handle"
                               value="<?php echo esc_attr( $f_handle ); ?>" class="regular-text" required
                               <?php echo $edit_data ? 'readonly' : ''; ?>
                               pattern="[a-z0-9\-]+" placeholder="my-tracking-script" />
                    </td>
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
                        <p class="description"><?php esc_html_e( 'Your service ID. Any %s in the URL or snippet below will be replaced with this value on save.', 'perrylabs-cookie-notice' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="script_src"><?php esc_html_e( 'External Script URL', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <input type="url" id="script_src" name="script_src" value="<?php echo esc_attr( $f_src ); ?>" class="large-text" placeholder="https://example.com/script.js" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="script_inline"><?php esc_html_e( 'Inline JS Snippet', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <textarea id="script_inline" name="script_inline" rows="6" class="large-text code"><?php echo esc_textarea( $f_inline ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Raw JavaScript (no <script> tags).', 'perrylabs-cookie-notice' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="script_attrs"><?php esc_html_e( 'Script Attributes', 'perrylabs-cookie-notice' ); ?></label></th>
                    <td>
                        <input type="text" id="script_attrs" name="script_attrs" value="<?php echo esc_attr( $f_attrs ); ?>" class="regular-text" placeholder="async,defer" />
                    </td>
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
        <p>
            <?php esc_html_e( 'Gate scripts or stylesheets that other plugins/themes enqueue via wp_enqueue_script / wp_enqueue_style. Enter the WordPress handle and a category — Cookie Monster rewrites the rendered tag so the browser does not execute it until consent.', 'perrylabs-cookie-notice' ); ?>
        </p>
        <p class="description">
            <?php esc_html_e( 'Tip: find handles using Query Monitor or by reading the plugin/theme source.', 'perrylabs-cookie-notice' ); ?>
        </p>

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
        <p>
            <?php esc_html_e( 'Replace third-party iframes with a click-to-load placeholder until the visitor consents. Pick which providers to block — others pass through unchanged.', 'perrylabs-cookie-notice' ); ?>
        </p>
        <form method="post" action="options.php">
            <?php settings_fields( 'plcn_settings_group' ); ?>
            <?php
            // Preserve other fields to avoid wiping them.
            foreach ( array( 'enabled', 'message', 'button_text', 'expiry_days', 'bg_color', 'button_color', 'position', 'theme', 'compliance_mode', 'google_consent_mode', 'google_consent_wait_ms', 'log_consent' ) as $k ) {
                printf( '<input type="hidden" name="plcn_options[%s]" value="%s" />', esc_attr( $k ), esc_attr( (string) ( $options[ $k ] ?? '' ) ) );
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

        <?php if ( empty( $entries ) ) : ?>
            <p class="description"><?php esc_html_e( 'No events yet. Enable "Audit Log" on the General tab to start recording.', 'perrylabs-cookie-notice' ); ?></p>
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
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Policy version bumped. All visitors will be re-prompted on their next visit.', 'perrylabs-cookie-notice' ) . '</p></div>';
        }
        ?>
        <h3><?php esc_html_e( 'Re-prompt all visitors', 'perrylabs-cookie-notice' ); ?></h3>
        <p class="description">
            <?php
            printf(
                esc_html__( 'Current policy version: %d. Bumping invalidates every visitor\'s consent cookie and re-prompts them on their next visit. Use after changing your cookie inventory.', 'perrylabs-cookie-notice' ),
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
        <p class="description">
            <?php esc_html_e( 'Clear your own consent cookies and reload the front page. Other visitors are unaffected.', 'perrylabs-cookie-notice' ); ?>
        </p>
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

        <h3 style="margin-top:32px;"><?php esc_html_e( 'Developer reference', 'perrylabs-cookie-notice' ); ?></h3>
        <ul style="list-style:disc;margin-left:20px;">
            <li><code>plcn_has_consent( 'analytics' )</code> — <?php esc_html_e( 'Check consent in PHP.', 'perrylabs-cookie-notice' ); ?></li>
            <li><code>plcn_register_script( $handle, $config )</code> — <?php esc_html_e( 'Register a script programmatically.', 'perrylabs-cookie-notice' ); ?></li>
            <li><code>plcn_gate_script( $handle, $category )</code> — <?php esc_html_e( 'Gate a WP-enqueued script.', 'perrylabs-cookie-notice' ); ?></li>
            <li><code>plcn_gate_style( $handle, $category )</code> — <?php esc_html_e( 'Gate a WP-enqueued stylesheet.', 'perrylabs-cookie-notice' ); ?></li>
        </ul>
        <?php
    }
}
