<?php
/**
 * Plugin Name: PerryLabs Cookie Notice
 * Plugin URI: https://perrylabs.io
 * Description: Granular cookie consent with category-based opt-in, Google Consent Mode v2, script gating, geo-targeting, and a server-side audit log. No external dependencies.
 * Version: 3.2.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: PerryLabs
 * Author URI: https://jasonmperry.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: perrylabs-cookie-notice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PL_COOKIE_VERSION', '3.2.0' );
define( 'PL_COOKIE_CODENAME', 'Cookie Monster' );
define( 'PL_COOKIE_PLUGIN_FILE', __FILE__ );
define( 'PL_COOKIE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PL_COOKIE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PL_COOKIE_PLUGIN_DIR . 'includes/branding/class-perrylabs-branding.php';
require_once PL_COOKIE_PLUGIN_DIR . 'includes/class-strings.php';
require_once PL_COOKIE_PLUGIN_DIR . 'includes/class-consent.php';
require_once PL_COOKIE_PLUGIN_DIR . 'includes/class-script-registry.php';
require_once PL_COOKIE_PLUGIN_DIR . 'includes/class-script-gate.php';
require_once PL_COOKIE_PLUGIN_DIR . 'includes/class-embed-blocker.php';
require_once PL_COOKIE_PLUGIN_DIR . 'includes/class-consent-log.php';
require_once PL_COOKIE_PLUGIN_DIR . 'includes/class-geo.php';
require_once PL_COOKIE_PLUGIN_DIR . 'includes/class-banner.php';
require_once PL_COOKIE_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once PL_COOKIE_PLUGIN_DIR . 'includes/class-rest.php';
require_once PL_COOKIE_PLUGIN_DIR . 'includes/class-cli.php';
require_once PL_COOKIE_PLUGIN_DIR . 'includes/class-settings.php';

add_action( 'plugins_loaded', function () {
    PLCN_Consent::instance();
    PLCN_Script_Registry::instance();
    PLCN_Script_Gate::instance();
    PLCN_Embed_Blocker::instance();
    PLCN_Consent_Log::instance();
    PLCN_Geo::instance();
    PLCN_Shortcodes::instance();
    PLCN_REST::instance();

    if ( ! is_admin() ) {
        new PLCN_Banner();
    }

    if ( is_admin() ) {
        new PLCN_Settings();
    }
} );

/**
 * Capability required to manage the plugin. Filterable so site owners can
 * delegate management to a custom role (e.g. "compliance_officer").
 */
function plcn_manage_capability(): string {
    return (string) apply_filters( 'plcn_manage_capability', 'manage_options' );
}

register_activation_hook( __FILE__, function () {
    // Create the audit log table.
    PLCN_Consent_Log::install_table();

    // Migrate old individual options to new format.
    $old_keys = array(
        'plcn_enabled', 'plcn_message', 'plcn_button_text',
        'plcn_expiry_days', 'plcn_bg_color', 'plcn_button_color', 'plcn_position',
    );

    $existing = get_option( 'plcn_options', false );
    if ( false === $existing ) {
        $options = array();
        $has_old = false;
        foreach ( $old_keys as $old_key ) {
            $value = get_option( $old_key, null );
            if ( null !== $value ) {
                $short_key = str_replace( 'plcn_', '', $old_key );
                $options[ $short_key ] = $value;
                $has_old = true;
            }
        }

        if ( $has_old ) {
            update_option( 'plcn_options', wp_parse_args( $options, PLCN_Settings::defaults() ) );
            foreach ( $old_keys as $old_key ) {
                delete_option( $old_key );
            }
        } else {
            add_option( 'plcn_options', PLCN_Settings::defaults() );
        }
    }
} );

// Plugin action links.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=plcn-settings' ) ) . '">' . __( 'Settings', 'perrylabs-cookie-notice' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
} );

/**
 * Check if the user has consented to a specific category.
 *
 * @param string $category Category slug (required, analytics, marketing, other).
 * @return bool
 */
function plcn_has_consent( string $category ): bool {
    return PLCN_Consent::instance()->has_consent( $category );
}

/**
 * Register an external script/pixel for consent management.
 *
 * @param string $handle Unique handle for the script.
 * @param array  $config Script configuration.
 */
function plcn_register_script( string $handle, array $config ): void {
    PLCN_Script_Registry::instance()->register( $handle, $config );
}

/**
 * Gate an already-enqueued WordPress script behind consent.
 *
 * Use this when another plugin/theme registers a script via wp_enqueue_script
 * and you want it to only load after consent for a given category.
 *
 * @param string $handle   The enqueued script handle.
 * @param string $category Category slug.
 */
function plcn_gate_script( string $handle, string $category ): void {
    PLCN_Script_Gate::instance()->gate( $handle, $category );
}

/**
 * Gate an enqueued stylesheet behind consent.
 *
 * @param string $handle   The enqueued style handle.
 * @param string $category Category slug.
 */
function plcn_gate_style( string $handle, string $category ): void {
    PLCN_Script_Gate::instance()->gate_style( $handle, $category );
}
