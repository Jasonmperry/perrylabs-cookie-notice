<?php
/**
 * PLCN_Embed_Blocker — Stops third-party iframes (YouTube, Vimeo, Maps,
 * Twitter, Facebook) from dropping cookies before the user consents.
 *
 * Strategy: filter the rendered HTML and swap the iframe `src` to `data-src`,
 * wrap with a placeholder that explains why it's blocked and offers a
 * one-time "Load anyway" or full "Manage preferences" button.
 *
 * @package PerryLabs\CookieNotice
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PLCN_Embed_Blocker {

    private static ?PLCN_Embed_Blocker $instance = null;

    /**
     * Patterns for known embed providers. Each entry:
     *   pattern  => regex matched against iframe src
     *   label    => provider name shown in placeholder
     *   category => consent category (marketing for ads/social, other for maps)
     */
    const PROVIDERS = array(
        'youtube' => array(
            'pattern'  => '#(youtube\.com|youtube-nocookie\.com|youtu\.be)#i',
            'label'    => 'YouTube',
            'category' => 'marketing',
        ),
        'vimeo'   => array(
            'pattern'  => '#(player\.vimeo\.com|vimeo\.com/video)#i',
            'label'    => 'Vimeo',
            'category' => 'marketing',
        ),
        'gmaps'   => array(
            'pattern'  => '#(google\.com/maps|maps\.google\.|google\.com/maps/embed)#i',
            'label'    => 'Google Maps',
            'category' => 'other',
        ),
        'twitter' => array(
            'pattern'  => '#(twitter\.com|platform\.twitter\.com|x\.com)#i',
            'label'    => 'X / Twitter',
            'category' => 'marketing',
        ),
        'facebook'=> array(
            'pattern'  => '#(facebook\.com/plugins|facebook\.com/v\d+)#i',
            'label'    => 'Facebook',
            'category' => 'marketing',
        ),
        'instagram'=> array(
            'pattern'  => '#(instagram\.com/p/|www\.instagram\.com/embed)#i',
            'label'    => 'Instagram',
            'category' => 'marketing',
        ),
        'soundcloud'=> array(
            'pattern'  => '#(soundcloud\.com)#i',
            'label'    => 'SoundCloud',
            'category' => 'other',
        ),
        'spotify' => array(
            'pattern'  => '#(open\.spotify\.com/embed)#i',
            'label'    => 'Spotify',
            'category' => 'other',
        ),
    );

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Skip in admin / feeds.
        if ( is_admin() ) {
            return;
        }

        // Hook into content output. `the_content` is the primary path for posts;
        // we also hook `widget_text_content` for sidebar HTML and `do_blocks` output.
        add_filter( 'the_content',          array( $this, 'filter_content' ), 99 );
        add_filter( 'widget_text_content',  array( $this, 'filter_content' ), 99 );
        add_filter( 'render_block',         array( $this, 'filter_block' ), 99, 2 );
    }

    /**
     * Return enabled providers based on settings.
     */
    private function enabled_providers(): array {
        $options = get_option( 'plcn_options', array() );
        $enabled = $options['embed_blocker'] ?? array();
        if ( empty( $enabled ) ) {
            return array();
        }
        return array_intersect_key( self::PROVIDERS, array_flip( $enabled ) );
    }

    /**
     * Apply blocking to a chunk of HTML.
     */
    public function filter_content( $html ) {
        if ( ! is_string( $html ) || '' === $html ) return $html;

        $providers = $this->enabled_providers();
        if ( empty( $providers ) ) return $html;

        // Quick reject if no iframes at all.
        if ( false === stripos( $html, '<iframe' ) ) return $html;

        return preg_replace_callback(
            '#<iframe\b([^>]*)\bsrc=(["\'])([^"\']+)\2([^>]*)></iframe>#i',
            function ( $m ) use ( $providers ) {
                $attrs_pre  = $m[1];
                $quote      = $m[2];
                $src        = $m[3];
                $attrs_post = $m[4];

                $matched = null;
                foreach ( $providers as $key => $p ) {
                    if ( preg_match( $p['pattern'], $src ) ) {
                        $matched = $p + array( 'key' => $key );
                        break;
                    }
                }

                if ( ! $matched ) {
                    return $m[0]; // not a tracked provider, leave alone
                }

                // Server-side check: if consent already granted, don't block.
                if ( PLCN_Consent::instance()->has_consent( $matched['category'] ) ) {
                    return $m[0];
                }

                $placeholder_src = 'about:blank';
                $iframe = sprintf(
                    '<iframe%s src=%s%s%s data-plcn-src="%s" data-plcn-category="%s" data-plcn-provider="%s" data-plcn-blocked="1" loading="lazy"></iframe>',
                    $attrs_pre,
                    $quote, esc_attr( $placeholder_src ), $quote,
                    $attrs_post,
                    esc_attr( $src ),
                    esc_attr( $matched['category'] ),
                    esc_attr( $matched['key'] )
                );

                return $this->render_placeholder( $iframe, $matched );
            },
            $html
        );
    }

    /**
     * Same filtering, plumbed in at the block level so even raw HTML inside a
     * block (Custom HTML, etc.) gets rewritten.
     */
    public function filter_block( $block_content, $block ) {
        return $this->filter_content( $block_content );
    }

    /**
     * Wrap an iframe with a click-to-load placeholder.
     */
    private function render_placeholder( string $iframe_html, array $provider ): string {
        $label    = esc_html( $provider['label'] );
        $category = esc_attr( $provider['category'] );

        $template = PLCN_Strings::get( 'embed_blocked_message' );
        $message  = esc_html( false !== strpos( $template, '%s' )
            ? sprintf( $template, $provider['label'] )
            : $template
        );

        $accept_text      = esc_html( PLCN_Strings::get( 'embed_load_once' ) );
        $preferences_text = esc_html( PLCN_Strings::get( 'embed_open_preferences' ) );

        return sprintf(
            '<div class="plcn-embed-wrap" data-plcn-embed-category="%1$s">' .
                '<div class="plcn-embed-placeholder">' .
                    '<p class="plcn-embed-message">%2$s</p>' .
                    '<div class="plcn-embed-actions">' .
                        '<button type="button" class="plcn-embed-load">%3$s</button>' .
                        '<button type="button" class="plcn-embed-prefs plcn-open-preferences">%4$s</button>' .
                    '</div>' .
                '</div>' .
                '<div class="plcn-embed-iframe-holder" hidden>%5$s</div>' .
            '</div>',
            $category,
            $message,
            $accept_text,
            $preferences_text,
            $iframe_html
        );
    }
}
