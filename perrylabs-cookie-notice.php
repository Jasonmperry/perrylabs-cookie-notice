<?php
/**
 * Plugin Name: PerryLabs Cookie Notice
 * Plugin URI: https://perrylabs.io
 * Description: Lightweight cookie consent banner with implied consent. No bloat, no external dependencies.
 * Version: 1.0.0
 * Author: PerryLabs
 * Author URI: https://jasonmperry.com
 * License: GPL-2.0-or-later
 * Text Domain: perrylabs-cookie-notice
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Set default options on activation.
 */
function plcn_activate() {
	$defaults = array(
		'plcn_enabled'      => 1,
		'plcn_message'      => 'We use cookies to ensure that we give you the best experience on our website. If you continue to use this site we will assume that you are happy with it.',
		'plcn_button_text'  => 'Got it',
		'plcn_expiry_days'  => 365,
		'plcn_bg_color'     => '#111',
		'plcn_button_color' => '#ffb25d',
		'plcn_position'     => 'bottom',
	);

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value );
		}
	}
}
register_activation_hook( __FILE__, 'plcn_activate' );

/**
 * Register admin settings page under Settings > Cookie Notice.
 */
function plcn_admin_menu() {
	add_options_page(
		__( 'Cookie Notice', 'perrylabs-cookie-notice' ),
		__( 'Cookie Notice', 'perrylabs-cookie-notice' ),
		'manage_options',
		'plcn-settings',
		'plcn_settings_page'
	);
}
add_action( 'admin_menu', 'plcn_admin_menu' );

/**
 * Register settings.
 */
function plcn_register_settings() {
	register_setting( 'plcn_settings_group', 'plcn_enabled', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 1,
	) );
	register_setting( 'plcn_settings_group', 'plcn_message', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => '',
	) );
	register_setting( 'plcn_settings_group', 'plcn_button_text', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => 'Got it',
	) );
	register_setting( 'plcn_settings_group', 'plcn_expiry_days', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 365,
	) );
	register_setting( 'plcn_settings_group', 'plcn_bg_color', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '#111',
	) );
	register_setting( 'plcn_settings_group', 'plcn_button_color', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '#ffb25d',
	) );
	register_setting( 'plcn_settings_group', 'plcn_position', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => 'bottom',
	) );
}
add_action( 'admin_init', 'plcn_register_settings' );

/**
 * Render settings page.
 */
function plcn_settings_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Cookie Notice Settings', 'perrylabs-cookie-notice' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'plcn_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="plcn_enabled"><?php esc_html_e( 'Enable Cookie Notice', 'perrylabs-cookie-notice' ); ?></label>
					</th>
					<td>
						<input type="checkbox" id="plcn_enabled" name="plcn_enabled" value="1" <?php checked( 1, get_option( 'plcn_enabled', 1 ) ); ?> />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="plcn_message"><?php esc_html_e( 'Message', 'perrylabs-cookie-notice' ); ?></label>
					</th>
					<td>
						<textarea id="plcn_message" name="plcn_message" rows="4" cols="60" class="large-text"><?php echo esc_textarea( get_option( 'plcn_message', '' ) ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="plcn_button_text"><?php esc_html_e( 'Button Text', 'perrylabs-cookie-notice' ); ?></label>
					</th>
					<td>
						<input type="text" id="plcn_button_text" name="plcn_button_text" value="<?php echo esc_attr( get_option( 'plcn_button_text', 'Got it' ) ); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="plcn_expiry_days"><?php esc_html_e( 'Cookie Expiry (days)', 'perrylabs-cookie-notice' ); ?></label>
					</th>
					<td>
						<input type="number" id="plcn_expiry_days" name="plcn_expiry_days" value="<?php echo esc_attr( get_option( 'plcn_expiry_days', 365 ) ); ?>" min="1" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="plcn_bg_color"><?php esc_html_e( 'Background Color', 'perrylabs-cookie-notice' ); ?></label>
					</th>
					<td>
						<input type="text" id="plcn_bg_color" name="plcn_bg_color" value="<?php echo esc_attr( get_option( 'plcn_bg_color', '#111' ) ); ?>" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="plcn_button_color"><?php esc_html_e( 'Button Color', 'perrylabs-cookie-notice' ); ?></label>
					</th>
					<td>
						<input type="text" id="plcn_button_color" name="plcn_button_color" value="<?php echo esc_attr( get_option( 'plcn_button_color', '#ffb25d' ) ); ?>" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="plcn_position"><?php esc_html_e( 'Position', 'perrylabs-cookie-notice' ); ?></label>
					</th>
					<td>
						<select id="plcn_position" name="plcn_position">
							<option value="bottom" <?php selected( get_option( 'plcn_position', 'bottom' ), 'bottom' ); ?>><?php esc_html_e( 'Bottom', 'perrylabs-cookie-notice' ); ?></option>
							<option value="top" <?php selected( get_option( 'plcn_position', 'bottom' ), 'top' ); ?>><?php esc_html_e( 'Top', 'perrylabs-cookie-notice' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Render cookie notice banner on the front end.
 */
function plcn_render_notice() {
	// Bail if disabled.
	if ( ! get_option( 'plcn_enabled', 1 ) ) {
		return;
	}

	// If already dismissed via cookie, do not render.
	if ( ! empty( $_COOKIE['plcn_dismissed'] ) ) {
		return;
	}

	$message      = get_option( 'plcn_message', 'We use cookies to ensure that we give you the best experience on our website. If you continue to use this site we will assume that you are happy with it.' );
	$button_text  = get_option( 'plcn_button_text', 'Got it' );
	$expiry_days  = absint( get_option( 'plcn_expiry_days', 365 ) );
	$bg_color     = get_option( 'plcn_bg_color', '#111' );
	$button_color = get_option( 'plcn_button_color', '#ffb25d' );
	$position     = get_option( 'plcn_position', 'bottom' );

	/** Filters are kept for backward compatibility and programmatic overrides. */
	$message     = apply_filters( 'plcn_message', $message );
	$button_text = apply_filters( 'plcn_button_text', $button_text );

	$pos_css = 'bottom' === $position ? 'bottom: 0;' : 'top: 0;';
	$shadow  = 'bottom' === $position
		? 'box-shadow: 0 -6px 20px rgba(0, 0, 0, 0.2);'
		: 'box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);';
	?>
	<div id="plcn-notice" role="dialog" aria-live="polite" aria-label="<?php esc_attr_e( 'Cookie notice', 'perrylabs-cookie-notice' ); ?>">
		<div class="plcn-notice__inner">
			<p class="plcn-notice__text"><?php echo esc_html( $message ); ?></p>
			<button type="button" class="plcn-notice__button" id="plcn-dismiss">
				<?php echo esc_html( $button_text ); ?>
			</button>
		</div>
	</div>
	<style>
		#plcn-notice {
			position: fixed;
			left: 0;
			right: 0;
			<?php echo $pos_css; ?>
			z-index: 99999;
			background: <?php echo esc_attr( $bg_color ); ?>;
			color: #fff;
			<?php echo $shadow; ?>
		}
		#plcn-notice .plcn-notice__inner {
			max-width: 1200px;
			margin: 0 auto;
			padding: 12px 16px;
			display: flex;
			gap: 12px;
			align-items: center;
			justify-content: space-between;
		}
		#plcn-notice .plcn-notice__text {
			margin: 0;
			font-size: 14px;
			line-height: 1.4;
		}
		#plcn-notice .plcn-notice__button {
			background: <?php echo esc_attr( $button_color ); ?>;
			border: 0;
			color: <?php echo esc_attr( $bg_color ); ?>;
			padding: 8px 14px;
			border-radius: 4px;
			cursor: pointer;
			font-weight: 600;
			white-space: nowrap;
		}
		@media (max-width: 768px) {
			#plcn-notice .plcn-notice__inner {
				flex-direction: column;
				align-items: flex-start;
			}
			#plcn-notice .plcn-notice__button {
				align-self: flex-end;
			}
		}
	</style>
	<script>
		(function () {
			var btn = document.getElementById('plcn-dismiss');
			var notice = document.getElementById('plcn-notice');
			if (!btn || !notice) return;
			btn.addEventListener('click', function () {
				var expires = new Date();
				expires.setDate(expires.getDate() + <?php echo (int) $expiry_days; ?>);
				document.cookie = 'plcn_dismissed=1; expires=' + expires.toUTCString() + '; path=/; SameSite=Lax';
				notice.parentNode.removeChild(notice);
			});
		}());
	</script>
	<?php
}
add_action( 'wp_footer', 'plcn_render_notice', 100 );

/**
 * Add settings link on Plugins page.
 */
function plcn_plugin_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=plcn-settings' ) ) . '">' . __( 'Settings', 'perrylabs-cookie-notice' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'plcn_plugin_action_links' );
