<?php
/**
 * Uninstall — wipe all plugin data when the user deletes the plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'plcn_options' );

// Legacy individual options.
$legacy_keys = array(
    'plcn_enabled', 'plcn_message', 'plcn_button_text',
    'plcn_expiry_days', 'plcn_bg_color', 'plcn_button_color', 'plcn_position',
);
foreach ( $legacy_keys as $key ) {
    delete_option( $key );
}

global $wpdb;

// Geo detection transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_plcn_geo_%' OR option_name LIKE '_transient_timeout_plcn_geo_%'" );

// Audit log table.
$table = $wpdb->prefix . 'plcn_consent_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
