<?php
/**
 * Updates from GitHub Releases, for copies installed from GitHub.
 *
 * The WordPress.org build leaves this file and lib/ out; those copies update
 * through WordPress.org instead.
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib/plugin-update-checker/plugin-update-checker.php';

$khrlscm_updates = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/khrlzhfr/simple-csv-mailer/',
	KHRLSCM_FILE,
	dirname( plugin_basename( KHRLSCM_FILE ) ) // Keep updates in whichever folder this copy was installed in.
);
$khrlscm_updates->getVcsApi()->enableReleaseAssets( '/simple-csv-mailer\.zip$/' );
