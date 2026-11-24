<?php
/**
 * Plugin Name:       GUTZ DC League
 * Plugin URI:        https://www.gutz-dc.de/
 * Description:       Elementor widget that lists played and upcoming matches of a 3K Darts league.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            GUTZ DC e.V.
 * Text Domain:       gutz-dc-league
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GUTZ_DC_LEAGUE_VERSION', '1.0.0' );
define( 'GUTZ_DC_LEAGUE_FILE', __FILE__ );
define( 'GUTZ_DC_LEAGUE_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Registers the widget stylesheet so the widget can pull it in on demand
 * through get_style_depends(). Runs on the front end and inside the
 * Elementor editor preview iframe alike.
 */
function gutz_dc_league_register_assets(): void {
	wp_register_style(
		'gutz-dc-league',
		plugins_url( 'assets/css/gutz-dc-league.css', GUTZ_DC_LEAGUE_FILE ),
		[],
		GUTZ_DC_LEAGUE_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'gutz_dc_league_register_assets' );

/**
 * Registers the Elementor widget. Elementor 3.5+ / 4.x API.
 *
 * @param \Elementor\Widgets_Manager $widgets_manager
 */
function gutz_dc_league_register_widget( $widgets_manager ): void {
	require_once GUTZ_DC_LEAGUE_PATH . 'includes/class-gutz-dc-league-api.php';
	require_once GUTZ_DC_LEAGUE_PATH . 'includes/class-gutz-dc-league-widget.php';

	$widgets_manager->register( new GUTZ_DC_League_Widget() );
}
add_action( 'elementor/widgets/register', 'gutz_dc_league_register_widget' );

/**
 * Tells the user why nothing showed up if Elementor is missing.
 */
function gutz_dc_league_dependency_notice(): void {
	if ( did_action( 'elementor/loaded' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'GUTZ DC League needs the Elementor plugin to be installed and active.', 'gutz-dc-league' )
	);
}
add_action( 'admin_notices', 'gutz_dc_league_dependency_notice' );

/**
 * Drops the cached league payloads when the plugin is deactivated.
 */
function gutz_dc_league_deactivate(): void {
	require_once GUTZ_DC_LEAGUE_PATH . 'includes/class-gutz-dc-league-api.php';
	GUTZ_DC_League_API::flush_cache();
}
register_deactivation_hook( __FILE__, 'gutz_dc_league_deactivate' );
