<?php
/**
 * Plugin Name:       GUTZ DC Average
 * Plugin URI:        https://www.gutz-dc.de/
 * Description:       Elementor widget that shows the 3-dart average of a team in a league season, inside a custom text.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            GUTZ DC e.V.
 * Text Domain:       gutz-dc-average
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GUTZ_DC_AVERAGE_VERSION', '1.0.0' );
define( 'GUTZ_DC_AVERAGE_FILE', __FILE__ );
define( 'GUTZ_DC_AVERAGE_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Registers the widget stylesheet so the widget can pull it in on demand
 * through get_style_depends(). Runs on the front end and inside the
 * Elementor editor preview iframe alike.
 */
function gutz_dc_average_register_assets(): void {
	wp_register_style(
		'gutz-dc-average',
		plugins_url( 'assets/css/gutz-dc-average.css', GUTZ_DC_AVERAGE_FILE ),
		[],
		GUTZ_DC_AVERAGE_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'gutz_dc_average_register_assets' );

/**
 * Registers the Elementor widget. Elementor 3.5+ / 4.x API.
 *
 * @param \Elementor\Widgets_Manager $widgets_manager
 */
function gutz_dc_average_register_widget( $widgets_manager ): void {
	require_once GUTZ_DC_AVERAGE_PATH . 'includes/class-gutz-dc-average-api.php';
	require_once GUTZ_DC_AVERAGE_PATH . 'includes/class-gutz-dc-average-widget.php';

	$widgets_manager->register( new GUTZ_DC_Average_Widget() );
}
add_action( 'elementor/widgets/register', 'gutz_dc_average_register_widget' );

/**
 * Tells the user why nothing showed up if Elementor is missing.
 */
function gutz_dc_average_dependency_notice(): void {
	if ( did_action( 'elementor/loaded' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'GUTZ DC Average needs the Elementor plugin to be installed and active.', 'gutz-dc-average' )
	);
}
add_action( 'admin_notices', 'gutz_dc_average_dependency_notice' );

/**
 * Drops the cached averages and match protocols when the plugin is deactivated.
 */
function gutz_dc_average_deactivate(): void {
	require_once GUTZ_DC_AVERAGE_PATH . 'includes/class-gutz-dc-average-api.php';
	GUTZ_DC_Average_API::flush_cache();
}
register_deactivation_hook( __FILE__, 'gutz_dc_average_deactivate' );
