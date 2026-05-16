<?php
/**
 * PLCN_REST — REST API endpoints for headless integration.
 *
 * Routes (all under /wp-json/plcn/v1/):
 *   GET  /consent           — current visitor's consent state from cookie
 *   POST /consent           — accept categories from the client (for headless flows)
 *   GET  /scripts           — registered scripts (admin-only, requires manage capability)
 *   GET  /policy            — cookie policy: registered scripts grouped by category
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_REST {

    private static ?PLCN_REST $instance = null;

    const NAMESPACE = 'plcn/v1';

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/consent', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_consent' ),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'post_consent' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'categories' => array(
                        'required' => true,
                        'type'     => 'object',
                    ),
                ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/scripts', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_scripts' ),
            'permission_callback' => array( $this, 'manage_perm' ),
        ) );

        register_rest_route( self::NAMESPACE, '/policy', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_policy' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function manage_perm(): bool {
        return current_user_can( plcn_manage_capability() );
    }

    /**
     * GET /consent — return decoded cookie state for current request.
     */
    public function get_consent( WP_REST_Request $request ): WP_REST_Response {
        $consent = PLCN_Consent::instance()->get_consent();
        return new WP_REST_Response( array(
            'decided'        => ! empty( $consent ),
            'consent'        => $consent,
            'policy_version' => PLCN_Consent::instance()->get_policy_version(),
        ), 200 );
    }

    /**
     * POST /consent — accept a categories object from the client. Used by
     * headless frontends that handle their own banner UI but want to log to
     * the audit table.
     *
     * Body: { "categories": { "analytics": true, "marketing": false, ... } }
     */
    public function post_consent( WP_REST_Request $request ) {
        $categories = $request->get_param( 'categories' );
        if ( ! is_array( $categories ) ) {
            return new WP_Error( 'plcn_bad_request', 'categories must be an object', array( 'status' => 400 ) );
        }

        $clean = array( 'required' => true );
        foreach ( PLCN_Consent::instance()->get_categories() as $cat ) {
            if ( 'required' === $cat ) continue;
            $clean[ $cat ] = ! empty( $categories[ $cat ] );
        }

        $options = get_option( 'plcn_options', array() );
        if ( ! empty( $options['log_consent'] ) ) {
            PLCN_Consent_Log::instance()->record( $clean, 'rest-api', '' );
        }

        return new WP_REST_Response( array(
            'consent'        => $clean,
            'policy_version' => PLCN_Consent::instance()->get_policy_version(),
        ), 200 );
    }

    /**
     * GET /scripts — admin-only. Returns the registered scripts list.
     */
    public function get_scripts(): WP_REST_Response {
        return new WP_REST_Response( PLCN_Script_Registry::instance()->get_all(), 200 );
    }

    /**
     * GET /policy — public-safe view of cookies by category. Used by
     * headless frontends to render their own policy pages.
     */
    public function get_policy(): WP_REST_Response {
        $registry = PLCN_Script_Registry::instance();
        $out = array();
        foreach ( array( 'required', 'analytics', 'marketing', 'other' ) as $cat ) {
            $list = array();
            foreach ( $registry->get_by_category( $cat ) as $script ) {
                $list[] = array(
                    'handle'   => $script['handle'] ?? '',
                    'label'    => $script['label']  ?? '',
                    'category' => $cat,
                );
            }
            $out[ $cat ] = $list;
        }
        return new WP_REST_Response( $out, 200 );
    }
}
