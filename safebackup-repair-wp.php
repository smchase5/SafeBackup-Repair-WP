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
define('SBWP_VERSION', '1.0.5');
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
require_once plugin_dir_path(__FILE__) . 'includes/class-sbwp-cloud-manager.php';

function run_safebackup_repair_wp()
{
	// 1. Start Flight Recorder (Crash Monitor)
	require_once plugin_dir_path(__FILE__) . 'includes/class-sbwp-flight-recorder.php';
	$recorder = new SBWP_Flight_Recorder();
	$recorder->init();

	$plugin_admin = new SBWP_Admin_UI();
	$plugin_admin->run();

	$recovery = new SBWP_Recovery_Portal();
	$recovery->init();

	$cloud_manager = new SBWP_Cloud_Manager();
	$cloud_manager->init();

	// Backup Processing Handler for Persistent Backups
	// Note: We use nopriv + a secret token because wp_remote_post loopback might not send auth cookies reliably
	add_action('wp_ajax_sbwp_process_backup', 'sbwp_process_backup_handler');
	add_action('wp_ajax_nopriv_sbwp_process_backup', 'sbwp_process_backup_handler');

	require_once plugin_dir_path(__FILE__) . 'includes/class-sbwp-ai-service.php';
	$ai_service = new SBWP_AI_Settings_REST();
	$ai_service->init();
}
function sbwp_process_backup_handler()
{
	// 1. Verify Secret
	$secret = isset($_POST['secret']) ? sanitize_text_field($_POST['secret']) : '';
	wp_cache_delete('sbwp_backup_secret', 'options');
	$stored_secret = get_option('sbwp_backup_secret');

	if (empty($secret) || $secret !== $stored_secret) {
		wp_send_json_error('Invalid secret token');
		exit;
	}

	// 2. Load Engine and Process Batch
	require_once plugin_dir_path(__FILE__) . 'includes/class-sbwp-backup-engine.php';
	$engine = new SBWP_Backup_Engine();
	$result = $engine->process_batch();

	wp_send_json_success($result);
	exit;
}

add_action('plugins_loaded', 'run_safebackup_repair_wp');
