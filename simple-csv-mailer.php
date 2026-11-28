<?php
/**
 * Plugin Name:       khrlzhfr Simple CSV Mailer
 * Plugin URI:        https://github.com/khrlzhfr/simple-csv-mailer
 * Description:       Send personalised emails to a CSV list using wp_mail(), with merge tags, attachments and a sending rate, from Tools → Mailer.
 * Version:           1.5.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Khairil Zhafri
 * Author URI:        https://khrlzh.fr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       khrlzhfr-simple-csv-mailer
 */

defined( 'ABSPATH' ) || exit;

// Another copy of this plugin is already running (for example one installed under its earlier folder name): stay inactive and say so on the Plugins screen.
if ( defined( 'KHRLSCM_FILE' ) ) {
	add_action(
		'admin_notices',
		static function () {
			global $pagenow;
			if ( 'plugins.php' !== $pagenow || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: plugin name, 2: folder of the copy that is running */
						__( '%1$s is installed twice. The copy in the %2$s folder is running. Deactivate and delete the other copy; its settings and data are kept.', 'khrlzhfr-simple-csv-mailer' ),
						'khrlzhfr Simple CSV Mailer',
						dirname( plugin_basename( KHRLSCM_FILE ) )
					)
				)
			);
		}
	);
	return;
}

define( 'KHRLSCM_FILE', __FILE__ );
define( 'KHRLSCM_VERSION', '1.5.0' );

require_once __DIR__ . '/includes/class-khrlscm-mailer.php';

// Copies installed from GitHub update from GitHub Releases; the WordPress.org build leaves this file out.
if ( file_exists( __DIR__ . '/includes/github-updater.php' ) ) {
	require_once __DIR__ . '/includes/github-updater.php';
}

register_activation_hook( __FILE__, [ 'KHRLSCM_Mailer', 'activate' ] );

// Background sending runs from WP-Cron, outside wp-admin.
add_action( KHRLSCM_Mailer::HOOK, [ 'KHRLSCM_Mailer', 'background_run' ] );

if ( is_admin() ) {
	KHRLSCM_Mailer::init();
}
