<?php
/**
 * Plugin Name:       Simple CSV Mailer
 * Plugin URI:        https://github.com/khrlzhfr/simple-csv-mailer
 * Description:       Send personalised emails to a CSV list using wp_mail(), with merge tags, attachments and a sending rate, from Tools → Mailer.
 * Version:           1.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Khairil Zhafri
 * Author URI:        https://khrlzh.fr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       simple-csv-mailer
 */

defined( 'ABSPATH' ) || exit;

define( 'SCM_FILE', __FILE__ );
define( 'SCM_VERSION', '1.2.0' );

require_once __DIR__ . '/includes/class-scm-mailer.php';

// Copies installed from GitHub update from GitHub Releases; the WordPress.org build leaves this file out.
if ( file_exists( __DIR__ . '/includes/github-updater.php' ) ) {
	require_once __DIR__ . '/includes/github-updater.php';
}

register_activation_hook( __FILE__, [ 'SCM_Mailer', 'activate' ] );

if ( is_admin() ) {
	SCM_Mailer::init();
}
