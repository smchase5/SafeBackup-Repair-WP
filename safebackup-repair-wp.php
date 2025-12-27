<?php
/**
 * Plugin Name:       SafeBackup & Repair WP
 * Plugin URI:        https://example.com/safebackup-repair-wp
 * Description:       Simple backups and a powerful Recovery Portal to fix your site when it crashes.
 * Version:           1.0.0
 * Author:            Your Name
 * Author URI:        https://example.com
 * Text Domain:       safebackup-repair-wp
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

// Define constants
define('SBWP_VERSION', '1.0.0');
define('SBWP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SBWP_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * The code that runs during plugin activation.
 */
function activate_safebackup_repair_wp()
{
	require_once plugin_dir_path(__FILE__) . 'includes/class-sbwp-activator.php';
	SBWP_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_safebackup_repair_wp()
{
	require_once plugin_dir_path(__FILE__) . 'includes/class-sbwp-deactivator.php';
	SBWP_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_safebackup_repair_wp');
register_deactivation_hook(__FILE__, 'deactivate_safebackup_repair_wp');

/**
 * Core plugin class to load dependencies and admin UI.
 */
require_once plugin_dir_path(__FILE__) . 'includes/class-sbwp-admin-ui.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-sbwp-recovery-portal.php';

function run_safebackup_repair_wp()
{
	$plugin_admin = new SBWP_Admin_UI();
	$plugin_admin->run();

	$recovery = new SBWP_Recovery_Portal();
	$recovery->init();
}
run_safebackup_repair_wp();
