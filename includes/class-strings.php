<?php
/**
 * PLCN_Strings — Centralized user-facing strings.
 *
 * Every visible piece of text on the front-end is defined here with a sensible
 * default. Admins can override any string via the Messages tab in settings.
 *
 * Lookups are wrapped through the `plcn_string` filter so plugins/themes can
 * override programmatically without touching admin.
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_Strings {

    /**
     * Canonical default strings. Anything user-facing should be in here.
     *
     * Keys are stable identifiers; values are sprintf templates where applicable.
     */
    public static function defaults(): array {
        return array(
            // Banner
            'banner_title'              => '',
            'banner_message'            => 'We use cookies to give you the best experience on our website. Choose which categories you want to allow.',
            'banner_message_with_link'  => 'We use cookies to give you the best experience on our website. Read our %s for details.',
            'btn_accept_all'            => 'Accept All',
            'btn_reject_all'            => 'Reject All',
            'btn_customize'             => 'Customize',
            'btn_got_it'                => 'Got it',
            'privacy_policy_link_text'  => 'privacy policy',

            // Preferences modal
            'prefs_title'               => 'Cookie Preferences',
            'prefs_intro'               => 'Choose which categories you want to allow. Strictly necessary cookies are always active.',
            'prefs_save'                => 'Save Preferences',
            'prefs_cancel'              => 'Cancel',

            // Category labels
            'cat_required_name'         => 'Strictly Necessary',
            'cat_required_desc'         => 'Required for the website to function. Cannot be disabled.',
            'cat_analytics_name'        => 'Analytics',
            'cat_analytics_desc'        => 'Help us understand how visitors use the site.',
            'cat_marketing_name'        => 'Marketing',
            'cat_marketing_desc'        => 'Used to deliver relevant ads and track campaigns.',
            'cat_other_name'            => 'Other',
            'cat_other_desc'            => 'Additional third-party services and integrations.',

            // Embed blocker
            'embed_blocked_message'     => 'This %s embed is blocked until you accept marketing cookies.',
            'embed_load_once'           => 'Load this content',
            'embed_open_preferences'    => 'Manage preferences',

            // CCPA
            'ccpa_optout_label'         => 'Do Not Sell My Personal Information',

            // Cookie policy shortcode headers
            'policy_required'           => 'Strictly Necessary',
            'policy_analytics'          => 'Analytics',
            'policy_marketing'          => 'Marketing',
            'policy_other'              => 'Other',
            'policy_required_desc'      => 'Required for the website to function. Always active.',
            'policy_analytics_desc'     => 'Used to measure and understand how visitors use the site.',
            'policy_marketing_desc'     => 'Used to deliver relevant advertising and track campaign performance.',
            'policy_other_desc'         => 'Third-party services not covered above.',
            'policy_empty'              => 'No third-party cookies are configured on this site.',
            'policy_col_service'        => 'Service',
            'policy_col_provider'       => 'Provider',
        );
    }

    /**
     * Get a string by key. Checks admin overrides, then defaults. Filterable.
     */
    public static function get( string $key, string $fallback = '' ): string {
        $options  = get_option( 'plcn_options', array() );
        $overrides = $options['strings'] ?? array();
        $defaults  = self::defaults();

        $value = $overrides[ $key ] ?? $defaults[ $key ] ?? $fallback;

        /**
         * Filter any string before output.
         *
         * @param string $value Resolved string.
         * @param string $key   String identifier.
         */
        return (string) apply_filters( 'plcn_string', $value, $key );
    }

    /**
     * Group string keys for the admin UI. Returns
     *   array( 'group label' => array( key => label ) )
     */
    public static function admin_groups(): array {
        return array(
            __( 'Banner', 'perrylabs-cookie-notice' ) => array(
                'banner_title'              => __( 'Banner title (optional)', 'perrylabs-cookie-notice' ),
                'banner_message'            => __( 'Banner message (no privacy link)', 'perrylabs-cookie-notice' ),
                'banner_message_with_link'  => __( 'Banner message (with privacy link, %s = link)', 'perrylabs-cookie-notice' ),
                'btn_accept_all'            => __( '"Accept All" button', 'perrylabs-cookie-notice' ),
                'btn_reject_all'            => __( '"Reject All" button', 'perrylabs-cookie-notice' ),
                'btn_customize'             => __( '"Customize" button', 'perrylabs-cookie-notice' ),
                'btn_got_it'                => __( 'Single button text (compliance: none)', 'perrylabs-cookie-notice' ),
                'privacy_policy_link_text'  => __( 'Privacy policy link text', 'perrylabs-cookie-notice' ),
            ),
            __( 'Preferences Modal', 'perrylabs-cookie-notice' ) => array(
                'prefs_title'  => __( 'Modal title', 'perrylabs-cookie-notice' ),
                'prefs_intro'  => __( 'Modal intro paragraph', 'perrylabs-cookie-notice' ),
                'prefs_save'   => __( '"Save Preferences" button', 'perrylabs-cookie-notice' ),
                'prefs_cancel' => __( '"Cancel" button', 'perrylabs-cookie-notice' ),
            ),
            __( 'Category labels', 'perrylabs-cookie-notice' ) => array(
                'cat_required_name'  => __( 'Required — name', 'perrylabs-cookie-notice' ),
                'cat_required_desc'  => __( 'Required — description', 'perrylabs-cookie-notice' ),
                'cat_analytics_name' => __( 'Analytics — name', 'perrylabs-cookie-notice' ),
                'cat_analytics_desc' => __( 'Analytics — description', 'perrylabs-cookie-notice' ),
                'cat_marketing_name' => __( 'Marketing — name', 'perrylabs-cookie-notice' ),
                'cat_marketing_desc' => __( 'Marketing — description', 'perrylabs-cookie-notice' ),
                'cat_other_name'     => __( 'Other — name', 'perrylabs-cookie-notice' ),
                'cat_other_desc'     => __( 'Other — description', 'perrylabs-cookie-notice' ),
            ),
            __( 'Embeds & CCPA', 'perrylabs-cookie-notice' ) => array(
                'embed_blocked_message'  => __( 'Blocked embed message (%s = provider)', 'perrylabs-cookie-notice' ),
                'embed_load_once'        => __( 'One-time "Load this content" button', 'perrylabs-cookie-notice' ),
                'embed_open_preferences' => __( '"Manage preferences" button', 'perrylabs-cookie-notice' ),
                'ccpa_optout_label'      => __( 'CCPA opt-out link text', 'perrylabs-cookie-notice' ),
            ),
        );
    }
}
