<?php
/**
 * Plugin Name:       GUTZ DC Matches
 * Plugin URI:        https://www.gutz-dc.de/
 * Description:       Elementor widget that lists the next league matches of a team, with both club logos.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            GUTZ DC e.V.
 * Text Domain:       gutz-dc-matches
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GUTZ_DC_MATCHES_VERSION', '1.0.0' );
define( 'GUTZ_DC_MATCHES_FILE', __FILE__ );
define( 'GUTZ_DC_MATCHES_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Registers the widget stylesheet so the widget can pull it in on demand
 * through get_style_depends(). Runs on the front end and inside the
 * Elementor editor preview iframe alike.
 */
function gutz_dc_matches_register_assets(): void {
	wp_register_style(
		'gutz-dc-matches',
		plugins_url( 'assets/css/gutz-dc-matches.css', GUTZ_DC_MATCHES_FILE ),
		[],
		GUTZ_DC_MATCHES_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'gutz_dc_matches_register_assets' );

/**
 * Registers the Elementor widget. Elementor 3.5+ / 4.x API.
 *
 * @param \Elementor\Widgets_Manager $widgets_manager
 */
function gutz_dc_matches_register_widget( $widgets_manager ): void {
	require_once GUTZ_DC_MATCHES_PATH . 'includes/class-gutz-dc-matches-api.php';
	require_once GUTZ_DC_MATCHES_PATH . 'includes/class-gutz-dc-matches-widget.php';

	$widgets_manager->register( new GUTZ_DC_Matches_Widget() );
}
add_action( 'elementor/widgets/register', 'gutz_dc_matches_register_widget' );

/**
 * Tells the user why nothing showed up if Elementor is missing.
 */
function gutz_dc_matches_dependency_notice(): void {
	if ( did_action( 'elementor/loaded' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'GUTZ DC Matches needs the Elementor plugin to be installed and active.', 'gutz-dc-matches' )
	);
}
add_action( 'admin_notices', 'gutz_dc_matches_dependency_notice' );

/**
 * Drops the cached match payloads when the plugin is deactivated.
 */
function gutz_dc_matches_deactivate(): void {
	require_once GUTZ_DC_MATCHES_PATH . 'includes/class-gutz-dc-matches-api.php';
	GUTZ_DC_Matches_API::flush_cache();
}
register_deactivation_hook( __FILE__, 'gutz_dc_matches_deactivate' );
