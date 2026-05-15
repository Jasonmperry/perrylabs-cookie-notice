<?php
/**
 * PerryLabs admin header partial.
 *
 * Source: /_ops/branding/admin-header.php
 *
 * Usage:
 *   $pl_title   = 'My Plugin Settings';
 *   $pl_version = MY_PLUGIN_VERSION;
 *   require __DIR__ . '/branding/admin-header.php';
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$pl_title   = $pl_title   ?? '';
$pl_version = $pl_version ?? '';
$pl_logo    = 'https://perrylabs-assets.s3.us-east-1.amazonaws.com/PerryLabs-LogoMark.png';
$pl_url     = 'https://perrylabs.io';
?>
<div class="pl-admin-header">
    <a href="<?php echo esc_url( $pl_url ); ?>" target="_blank" rel="noopener noreferrer">
        <img src="<?php echo esc_url( $pl_logo ); ?>" alt="PerryLabs" />
    </a>
    <h1><?php echo esc_html( $pl_title ); ?></h1>
    <?php if ( $pl_version ) : ?>
        <span class="pl-version">v<?php echo esc_html( $pl_version ); ?></span>
    <?php endif; ?>
</div>
