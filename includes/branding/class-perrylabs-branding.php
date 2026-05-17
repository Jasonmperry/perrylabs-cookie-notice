<?php
/**
 * PerryLabs Branding helper for WordPress plugins.
 *
 * Source of truth: /_ops/branding/class-perrylabs-branding.php
 * Version: 1.1.0
 *
 * Copy this file into your plugin (e.g. includes/branding/) together with the
 * `assets/perrylabs-logomark.png` image and `tokens.css`. The helper uses
 * `plugin_dir_url( __FILE__ )` to find the bundled image at runtime so it
 * works on any install without external dependencies. S3-hosted logo is used
 * only as a fallback if the local file is missing.
 *
 * Usage:
 *   require_once __DIR__ . '/branding/class-perrylabs-branding.php';
 *   PerryLabs_Branding::enqueue_tokens( PLUGIN_URL . 'includes/branding/tokens.css', VERSION );
 *   PerryLabs_Branding::header( 'My Plugin', VERSION );
 *   // ... admin content ...
 *   PerryLabs_Branding::footer();
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'PerryLabs_Branding' ) ) :

class PerryLabs_Branding {

    const VERSION       = '1.1.0';
    const PERRYLABS_URL = 'https://perrylabs.io';
    const PERSONAL_URL  = 'https://jasonmperry.com';

    /**
     * URL to the bundled logo. Tries the local copy first; falls back to S3.
     */
    public static function logo_url(): string {
        $local_file = __DIR__ . '/assets/perrylabs-logomark.png';
        if ( is_readable( $local_file ) ) {
            return plugin_dir_url( __FILE__ ) . 'assets/perrylabs-logomark.png';
        }
        return 'https://perrylabs-assets.s3.us-east-1.amazonaws.com/PerryLabs-LogoMark.png';
    }

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
                <img src="<?php echo esc_url( self::logo_url() ); ?>" alt="PerryLabs" />
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
                <img src="<?php echo esc_url( self::logo_url() ); ?>" alt="PerryLabs" />
                <span><?php esc_html_e( 'Built by PerryLabs', 'perrylabs' ); ?></span>
            </a>
            <span class="pl-sep">|</span>
            <a href="<?php echo esc_url( self::PERSONAL_URL ); ?>" target="_blank" rel="noopener noreferrer">jasonmperry.com</a>
        </div>
        <?php
    }

    /**
     * Compact attribution line — a single muted text link, suitable as a
     * default for shareable plugins where the full branded header/footer
     * would be intrusive.
     */
    public static function attribution(): void {
        ?>
        <p class="pl-attribution" style="margin-top:18px;color:#646970;font-size:12px;">
            <a href="<?php echo esc_url( self::PERRYLABS_URL ); ?>" target="_blank" rel="noopener noreferrer" style="color:#646970;text-decoration:none;">
                <?php esc_html_e( 'Plugin by PerryLabs', 'perrylabs' ); ?>
            </a>
        </p>
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
