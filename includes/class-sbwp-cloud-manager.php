<?php

if (!defined('ABSPATH')) {
    exit;
}

class SBWP_Cloud_Manager
{
    private $providers = array();

    public function init()
    {
        $this->load_providers();
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('sbwp_backup_completed', array($this, 'schedule_cloud_upload'));
        add_action('sbwp_process_cloud_upload', array($this, 'process_cloud_upload'));

        // OAuth Callback Handler
        add_action('wp_ajax_sbwp_oauth_callback', array($this, 'handle_oauth_callback'));
        add_action('wp_ajax_nopriv_sbwp_oauth_callback', array($this, 'handle_oauth_callback'));
    }

    public function handle_oauth_callback()
    {
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
        $error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : '';

        // Try to get credentials from multiple sources (for object caching resilience)
        $creds = null;

        // 1. Try FILE first (bypasses all caching)
        $temp_file = WP_CONTENT_DIR . '/.sbwp_gdrive_temp';
        if (file_exists($temp_file)) {
            $file_content = file_get_contents($temp_file);
            if ($file_content) {
                $creds = json_decode($file_content, true);
            }
        }

        if (empty($creds) || empty($creds['client_id'])) {
            $creds = get_option('sbwp_gdrive_creds');
        }

        // 3. Last Resort: Extract from STATE param (Stateless Auth)
        if ((empty($creds) || empty($creds['client_id'])) && $state) {
            $decoded = json_decode(base64_decode($state), true);
            if (is_array($decoded) && !empty($decoded['cid']) && !empty($decoded['sec'])) {
                $creds = array(
                    'client_id' => $decoded['cid'],
                    'client_secret' => $decoded['sec']
                );
                // Save them now so we have them for the next step
                update_option('sbwp_gdrive_creds', $creds, false);
            }
        }

        if ($error) {
            wp_die("Google Auth Error: $error");
        }

        if (!$code || empty($creds) || empty($creds['client_id'])) {
            $debug_info = 'Code: ' . ($code ? 'present' : 'missing');
            $debug_info .= ', File: ' . (file_exists($temp_file) ? 'exists' : 'missing');
            $debug_info .= ', Option: ' . (get_option('sbwp_gdrive_creds') ? 'yes' : 'no');
            $debug_info .= ', State: ' . ($state ? 'present' : 'missing');

            error_log('SBWP OAuth Debug: ' . $debug_info);
            // Dump state for inspection if needed (careful with secrets in prod logs, but this is debug mode)
            // error_log('State dump: ' . print_r(json_decode(base64_decode($state), true), true));

            wp_die("Invalid request or missing credentials. Please try again. (Debug: $debug_info)");
        }

        $provider = $this->get_provider('gdrive');
        $callback_url = admin_url('admin-ajax.php?action=sbwp_oauth_callback');

        $result = $provider->authenticate($code, $creds['client_id'], $creds['client_secret'], $callback_url);

        if (is_wp_error($result)) {
            wp_die("Authentication Failed: " . $result->get_error_message());
        }

        // Success! Close window
        echo '<!DOCTYPE html><html><body><script>
            window.opener.postMessage({ type: "sbwp_auth_success" }, "*");
            window.close();
        </script><h1 style="font-family:sans-serif;text-align:center;margin-top:20%;">Success! You can close this window.</h1></body></html>';
        exit;
    }

    private function load_providers()
    {
        // Load Interfaces
        require_once SBWP_PLUGIN_DIR . 'includes/interfaces/interface-sbwp-cloud-provider.php';

        // Load Providers
        require_once SBWP_PLUGIN_DIR . 'includes/providers/class-sbwp-google-drive.php';
        require_once SBWP_PLUGIN_DIR . 'includes/providers/class-sbwp-aws-provider.php';
        require_once SBWP_PLUGIN_DIR . 'includes/providers/class-sbwp-do-provider.php';
        require_once SBWP_PLUGIN_DIR . 'includes/providers/class-sbwp-wasabi-provider.php';

        // Register
        $gdrive = new SBWP_Google_Drive_Provider();
        $this->providers[$gdrive->get_id()] = $gdrive;

        $aws = new SBWP_AWS_Provider();
        $this->providers[$aws->get_id()] = $aws;

        $do = new SBWP_DO_Spaces_Provider();
        $this->providers[$do->get_id()] = $do;

        $wasabi = new SBWP_Wasabi_Provider();
        $this->providers[$wasabi->get_id()] = $wasabi;
    }

    public function get_provider($id)
    {
        return isset($this->providers[$id]) ? $this->providers[$id] : false;
    }

    public function register_rest_routes()
    {
        register_rest_route('sbwp/v1', '/cloud/providers', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_providers'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('sbwp/v1', '/cloud/connect', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_connect_provider'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('sbwp/v1', '/cloud/settings', array(
            'methods' => array('GET', 'POST'),
            'callback' => array($this, 'rest_cloud_settings'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }

    public function rest_cloud_settings($request)
    {
        if ($request->get_method() === 'GET') {
            return rest_ensure_response(array(
                'retention_count' => (int) get_option('sbwp_cloud_retention_count', 5)
            ));
        }

        if ($request->get_method() === 'POST') {
            $params = $request->get_json_params();
            if (isset($params['retention_count'])) {
                update_option('sbwp_cloud_retention_count', (int) $params['retention_count']);
            }
            return rest_ensure_response(array('success' => true));
        }
    }

    public function check_permission()
    {
        return current_user_can('manage_options');
    }

    public function rest_get_providers()
    {
        $data = array();
        foreach ($this->providers as $provider) {
            $data[] = array(
                'id' => $provider->get_id(),
                'name' => $provider->get_name(),
                'connected' => $provider->is_connected(),
                'user_info' => $provider->get_user_info(),
                'global_creds' => ($provider->get_id() === 'gdrive' && defined('SBWP_GDRIVE_CLIENT_ID'))
            );
        }
        return rest_ensure_response($data);
    }

    public function rest_connect_provider($request)
    {
        $params = $request->get_json_params();
        $provider_id = isset($params['provider_id']) ? $params['provider_id'] : '';
        $action = isset($params['action']) ? $params['action'] : '';

        $provider = $this->get_provider($provider_id);
        if (!$provider) {
            return new WP_Error('invalid_provider', 'Provider not found');
        }

        if ($action === 'prepare') {
            if ($provider_id === 'gdrive') {
                $client_id = isset($params['client_id']) ? $params['client_id'] : '';
                $client_secret = isset($params['client_secret']) ? $params['client_secret'] : '';

                // If using constants, ignore inputs
                if (defined('SBWP_GDRIVE_CLIENT_ID')) {
                    $client_id = SBWP_GDRIVE_CLIENT_ID;
                    $client_secret = SBWP_GDRIVE_CLIENT_SECRET;
                } else {
                    // Store in FILE to bypass object caching completely
                    $temp_file = WP_CONTENT_DIR . '/.sbwp_gdrive_temp';
                    $temp_data = json_encode(array(
                        'client_id' => $client_id,
                        'client_secret' => $client_secret,
                        'ts' => time()
                    ));
                    file_put_contents($temp_file, $temp_data);

                    // Also save permanently as backup
                    update_option('sbwp_gdrive_creds', array(
                        'client_id' => $client_id,
                        'client_secret' => $client_secret
                    ), false);
                }

                $callback_url = admin_url('admin-ajax.php?action=sbwp_oauth_callback');

                // CRITICAL: Embed creds in state as a fail-safe against storage/caching issues
                $extra_state = array(
                    'cid' => $client_id,
                    'sec' => $client_secret
                );

                $auth_url = $provider->get_auth_url($client_id, $client_secret, $callback_url, $extra_state);

                error_log("SBWP Prepare: ClientID=" . substr($client_id, 0, 5) . "..., AuthURL=" . substr($auth_url, 0, 20) . "...");

                return rest_ensure_response(array('success' => true, 'auth_url' => $auth_url));
            }
        }

        if ($action === 'disconnect') {
            $result = $provider->disconnect();
            return rest_ensure_response(array('success' => true));
        }

        if ($action === 'connect') {
            // OAuth providers (GDrive) handle connect in callback usually.
            // S3 providers handle connect here by validating credentials.

            if (in_array($provider_id, array('aws', 'do_spaces', 'wasabi'))) {
                $result = $provider->connect($params);
                if (is_wp_error($result)) {
                    return $result;
                }
                return rest_ensure_response(array('success' => true));
            }

            if ($provider->is_connected()) {
                return rest_ensure_response(array('success' => true));
            }
        }

        return new WP_Error('invalid_action', 'Invalid action');
    }

    public function schedule_cloud_upload($backup_id)
    {
        // Check if Action Scheduler is available (it should be bundled)
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), 'sbwp_process_cloud_upload', array('backup_id' => $backup_id));
        } else {
            // Fallback to immediate execution (might timeout)
            $this->process_cloud_upload($backup_id);
        }
    }

    public function process_cloud_upload($backup_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sb_backups';
        $backup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $backup_id));

        if (!$backup)
            return;

        $files = array();
        $base_path = $backup->file_path_local;

        if (file_exists($base_path . '/files.zip'))
            $files[] = $base_path . '/files.zip';
        if (file_exists($base_path . '/database.sql'))
            $files[] = $base_path . '/database.sql';

        foreach ($this->providers as $provider) {
            if ($provider->is_connected()) {
                foreach ($files as $file_path) {
                    $file_name = basename($file_path);
                    // Append Backup ID to avoid collisions or simpler browsing
                    // Actually, GDrive folders handle unique names, but let's be safe: 
                    // SafeBackup/Site/Timestamp/file.zip is better.
                    // But our provider logic puts it in SafeBackup/Site/.
                    // For now, let's just upload to Site folder with Timestamp in name?
                    // Or modify Provider to accept subfolder?
                    // Provider uses ensure_folder_structure which does SafeBackup/Site.
                    // Let's prepend timestamp to filename for clarity: "2023-10-27_1030_files.zip"

                    // Actually, backup folder name has timestamp.
                    $timestamp_name = basename($base_path) . '_' . $file_name;
                    $provider->upload_backup($file_path, $timestamp_name);
                }

                // Update notes
                $current_notes = $backup->notes;
                $new_notes = $current_notes . " [Synced to " . $provider->get_name() . "]";
                $wpdb->update($table_name, array('notes' => $new_notes), array('id' => $backup_id));

                // 3. Enforce Retention Policy
                $this->enforce_retention_policy($provider);
            }
        }
    }

    private function enforce_retention_policy($provider)
    {
        $limit = get_option('sbwp_cloud_retention_count', 5);
        if ($limit <= 0)
            return; // 0 means unlimited

        // Fetch backups (fetch limit + 5 to find extras)
        $backups = $provider->list_backups($limit + 5);

        if (is_wp_error($backups)) {
            error_log("Retention check failed for " . $provider->get_name() . ": " . $backups->get_error_message());
            return;
        }

        if (count($backups) > $limit) {
            $to_delete = array_slice($backups, $limit);
            foreach ($to_delete as $file) {
                $provider->delete_backup($file['id']);
                error_log("SBWP Retention: Deleted old backup " . $file['name'] . " from " . $provider->get_name());
            }
        }
    }
}
