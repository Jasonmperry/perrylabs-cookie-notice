<?php
/**
 * PerryLabs Branding helper for WordPress plugins.
 *
 * Source of truth: /_ops/branding/class-perrylabs-branding.php
 * Version: 1.0.0
 *
 * Copy this file into your plugin (e.g. includes/branding/), require_once it,
 * and call PerryLabs_Branding::header( 'My Plugin' ) / ::footer() from your
 * admin templates. tokens.css is also bundled and should be enqueued via
 * PerryLabs_Branding::enqueue_tokens().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'PerryLabs_Branding' ) ) :

class PerryLabs_Branding {

    const VERSION       = '1.0.0';
    const PERRYLABS_URL = 'https://perrylabs.io';
    const PERSONAL_URL  = 'https://jasonmperry.com';
    const LOGOMARK_URL  = 'https://perrylabs-assets.s3.us-east-1.amazonaws.com/PerryLabs-LogoMark.png';

    /**
     * Enqueue the tokens.css that ships with the plugin.
     *
     * @param string $tokens_css_url Public URL to the tokens.css file.
     * @param string $version        Version string for cache busting.
     */
    public static function enqueue_tokens( string $tokens_css_url, string $version = self::VERSION ): void {
        wp_enqueue_style( 'perrylabs-tokens', $tokens_css_url, array(), $version );
    }

    /**
     * Render the standard admin header.
     *
     * @param string $title          Page heading.
     * @param string $plugin_version Optional version badge (shown muted).
     */
    public static function header( string $title, string $plugin_version = '' ): void {
        ?>
        <div class="pl-admin-header">
            <a href="<?php echo esc_url( self::PERRYLABS_URL ); ?>" target="_blank" rel="noopener noreferrer">
                <img src="<?php echo esc_url( self::LOGOMARK_URL ); ?>" alt="PerryLabs" />
            </a>
            <h1><?php echo esc_html( $title ); ?></h1>
            <?php if ( $plugin_version ) : ?>
                <span class="pl-version">v<?php echo esc_html( $plugin_version ); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the standard admin footer.
     */
    public static function footer(): void {
        ?>
        <div class="pl-admin-footer">
            <a class="pl-built-by" href="<?php echo esc_url( self::PERRYLABS_URL ); ?>" target="_blank" rel="noopener noreferrer">
                <img src="<?php echo esc_url( self::LOGOMARK_URL ); ?>" alt="PerryLabs" />
                <span><?php esc_html_e( 'Built by PerryLabs', 'perrylabs' ); ?></span>
            </a>
            <span class="pl-sep">|</span>
            <a href="<?php echo esc_url( self::PERSONAL_URL ); ?>" target="_blank" rel="noopener noreferrer">jasonmperry.com</a>
        </div>
        <?php
    }

    /**
     * Returns the canonical color palette as an associative array (for use in PHP color logic).
     *
     * @return array<string,string>
     */
    public static function colors(): array {
        return array(
            'navy'        => '#0A1628',
            'dark_blue'   => '#0F1B2E',
            'deep_blue'   => '#14213D',
            'deepest'     => '#080F23',
            'graphite'    => '#2B2D42',
            'aqua'        => '#00B4D8',
            'magenta'     => '#E63946',
            'off_white'   => '#F8F9FA',
        );
    }
}

endif;
