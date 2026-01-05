<?php

class SBWP_Recovery_Portal
{
    private $key_option = 'sbwp_recovery_key';
    private $pin_option = 'sbwp_recovery_pin';

    /**
     * Get the secure storage directory path.
     * Creates the directory with .htaccess protection if it doesn't exist.
     */
    private function get_secure_dir()
    {
        $upload_dir = wp_upload_dir();
        $secure_dir = $upload_dir['basedir'] . '/sbwp-secure';

        if (!file_exists($secure_dir)) {
            wp_mkdir_p($secure_dir);
            // Protect the directory
            file_put_contents($secure_dir . '/.htaccess', "Order deny,allow\nDeny from all");
            file_put_contents($secure_dir . '/index.php', '<?php // Silence is golden.');
        }

        return $secure_dir;
    }

    /**
     * Migrate files from old plugin-root location to secure location.
     */
    private function migrate_legacy_files()
    {
        $secure_dir = $this->get_secure_dir();
        $old_files = [
            '.sbwp-recovery-key' => 'recovery-key',
            '.sbwp-recovery-pin' => 'recovery-pin',
            '.sbwp-ai-key' => 'ai-key',
        ];

        foreach ($old_files as $old_name => $new_name) {
            $old_path = SBWP_PLUGIN_DIR . $old_name;
            $new_path = $secure_dir . '/' . $new_name;

            if (file_exists($old_path) && !file_exists($new_path)) {
                // Copy to new location
                copy($old_path, $new_path);
                @chmod($new_path, 0600);
                // Remove old file
                @unlink($old_path);
            }
        }
    }

    public function init()
    {
        // Migrate legacy files from plugin root to secure location
        $this->migrate_legacy_files();

        add_action('init', array($this, 'check_recovery_mode'));
        add_action('init', array($this, 'ensure_recovery_key'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));


        add_action('wp_ajax_sbwp_recovery_restore', array($this, 'ajax_restore'));
        add_action('wp_ajax_nopriv_sbwp_recovery_restore', array($this, 'ajax_restore'));

        add_action('wp_ajax_sbwp_recovery_get_plugins', array($this, 'ajax_get_plugins'));
        add_action('wp_ajax_nopriv_sbwp_recovery_get_plugins', array($this, 'ajax_get_plugins'));

        add_action('wp_ajax_sbwp_recovery_toggle_plugin', array($this, 'ajax_toggle_plugin'));
        add_action('wp_ajax_nopriv_sbwp_recovery_toggle_plugin', array($this, 'ajax_toggle_plugin'));

        add_action('wp_ajax_sbwp_recovery_get_log', array($this, 'ajax_get_log'));
        add_action('wp_ajax_nopriv_sbwp_recovery_get_log', array($this, 'ajax_get_log'));
    }

    /**
     * Register REST API routes for recovery settings
     */
    public function register_rest_routes()
    {
        register_rest_route('sbwp/v1', '/recovery/settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_recovery_settings'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('sbwp/v1', '/recovery/settings', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_recovery_settings'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('sbwp/v1', '/recovery/regenerate-key', array(
            'methods' => 'POST',
            'callback' => array($this, 'regenerate_key'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('sbwp/v1', '/crash-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_crash_status'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));
    }

    /**
     * Ensure a recovery key exists - sync between database and file
     */
    public function ensure_recovery_key()
    {
        $key_file = $this->get_secure_dir() . '/recovery-key';

        // Check if file already has a key
        if (file_exists($key_file)) {
            $file_key = trim(file_get_contents($key_file));
            if (!empty($file_key)) {
                // Sync to database for REST API access
                if (get_option($this->key_option) !== $file_key) {
                    update_option($this->key_option, $file_key);
                }
                return;
            }
        }

        // Generate new key if none exists
        $key = bin2hex(random_bytes(16));

        // Save to file (for standalone recovery.php)
        file_put_contents($key_file, $key);
        @chmod($key_file, 0600);

        // Save to database (for WordPress-based access)
        update_option($this->key_option, $key);
    }

    /**
     * Get recovery key (from file first, fallback to database)
     */
    public function get_recovery_key()
    {
        $key_file = $this->get_secure_dir() . '/recovery-key';
        if (file_exists($key_file)) {
            $key = trim(file_get_contents($key_file));
            if (!empty($key)) {
                return $key;
            }
        }
        return get_option($this->key_option, '');
    }

    /**
     * Get recovery URL - points to standalone recovery.php
     */
    public function get_recovery_url()
    {
        $key = $this->get_recovery_key();
        // Point to standalone recovery.php file
        return plugins_url('recovery.php', SBWP_PLUGIN_DIR . 'x') . '?key=' . $key;
    }

    /**
     * REST: Get recovery settings
     */
    public function get_recovery_settings()
    {
        $pin = get_option($this->pin_option, '');

        return rest_ensure_response(array(
            'url' => $this->get_recovery_url(),
            'key' => $this->get_recovery_key(),
            'has_pin' => !empty($pin),
        ));
    }

    /**
     * REST: Save recovery settings (PIN)
     */
    public function save_recovery_settings($request)
    {
        $params = $request->get_json_params();

        if (isset($params['pin'])) {
            $pin = sanitize_text_field($params['pin']);
            $pin_file = $this->get_secure_dir() . '/recovery-pin';

            if (empty($pin)) {
                delete_option($this->pin_option);
                if (file_exists($pin_file)) {
                    @unlink($pin_file);
                }
            } else {
                // DB: WP Hash
                update_option($this->pin_option, wp_hash_password($pin));

                // File: PHP Standard Hash (for standalone recovery.php)
                file_put_contents($pin_file, password_hash($pin, PASSWORD_DEFAULT));
                @chmod($pin_file, 0600);
            }
        }

        return $this->get_recovery_settings();
    }

    /**
     * REST: Regenerate recovery key
     */
    public function regenerate_key()
    {
        $key = wp_generate_password(32, false);
        update_option($this->key_option, $key);

        return $this->get_recovery_settings();
    }

    /**
     * REST: Get crash status (checks for recent errors)
     */
    public function get_crash_status()
    {
        $has_crash = false;
        $error_summary = '';

        $log_path = WP_CONTENT_DIR . '/debug.log';

        if (file_exists($log_path)) {
            $file_modified = filemtime($log_path);
            $five_mins_ago = time() - (5 * 60);

            // Check if log was modified in last 5 minutes
            if ($file_modified > $five_mins_ago) {
                // Read last few lines looking for fatal errors
                $lines = $this->tail_file($log_path, 50);
                $content = implode("\n", $lines);

                if (
                    stripos($content, 'Fatal error') !== false ||
                    stripos($content, 'Parse error') !== false
                ) {
                    $has_crash = true;

                    // Extract first error line for summary
                    foreach ($lines as $line) {
                        if (
                            stripos($line, 'Fatal error') !== false ||
                            stripos($line, 'Parse error') !== false
                        ) {
                            $error_summary = trim($line);
                            break;
                        }
                    }
                }
            }
        }

        return rest_ensure_response(array(
            'has_crash' => $has_crash,
            'error_summary' => $error_summary,
            'recovery_url' => $this->get_recovery_url(),
        ));
    }

    public function check_recovery_mode()
    {
        if (!isset($_GET['sbwp_recovery'])) {
            return;
        }

        // Check authentication: either admin login OR valid key
        $is_authenticated = false;

        // Method 1: Admin is logged in
        if (current_user_can('manage_options')) {
            $is_authenticated = true;
        }

        // Method 2: Valid recovery key provided
        $provided_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $stored_key = $this->get_recovery_key();

        if (!empty($provided_key) && !empty($stored_key) && hash_equals($stored_key, $provided_key)) {
            $is_authenticated = true;
        }

        if (!$is_authenticated) {
            wp_die('Access Denied. Invalid recovery key or not logged in as administrator.', 'SafeBackup Recovery', array('response' => 403));
        }

        // Check PIN if set
        $stored_pin_hash = get_option($this->pin_option, '');
        if (!empty($stored_pin_hash)) {
            $provided_pin = isset($_GET['pin']) ? sanitize_text_field($_GET['pin']) : '';

            if (empty($provided_pin)) {
                // Show PIN form
                $this->render_pin_form();
                exit;
            }

            if (!wp_check_password($provided_pin, $stored_pin_hash)) {
                wp_die('Invalid PIN.', 'SafeBackup Recovery', array('response' => 403));
            }
        }

        $this->render_portal();
        exit;
    }

    private function render_pin_form()
    {
        ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>SafeBackup Recovery - Enter PIN</title>
            <style>
                body {
                    font-family: system-ui, -apple-system, sans-serif;
                    background: #0f172a;
                    color: #f1f5f9;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin: 0;
                }

                .container {
                    background: #1e293b;
                    padding: 2rem;
                    border-radius: 1rem;
                    max-width: 400px;
                    width: 90%;
                }

                h1 {
                    margin: 0 0 0.5rem;
                    font-size: 1.5rem;
                }

                p {
                    color: #94a3b8;
                    margin: 0 0 1.5rem;
                    font-size: 0.875rem;
                }

                input {
                    width: 100%;
                    padding: 0.75rem;
                    border: 1px solid #334155;
                    border-radius: 0.5rem;
                    background: #0f172a;
                    color: #f1f5f9;
                    font-size: 1rem;
                    margin-bottom: 1rem;
                    box-sizing: border-box;
                }

                button {
                    width: 100%;
                    padding: 0.75rem;
                    background: #10b981;
                    color: white;
                    border: none;
                    border-radius: 0.5rem;
                    font-size: 1rem;
                    cursor: pointer;
                }

                button:hover {
                    background: #059669;
                }
            </style>
        </head>

        <body>
            <div class="container">
                <h1>🔒 Recovery Portal</h1>
                <p>Enter your PIN to access the recovery portal.</p>
                <form method="GET">
                    <input type="hidden" name="sbwp_recovery" value="1">
                    <input type="hidden" name="key" value="<?php echo esc_attr($_GET['key'] ?? ''); ?>">
                    <input type="password" name="pin" placeholder="Enter PIN" autofocus>
                    <button type="submit">Access Recovery Portal</button>
                </form>
            </div>
        </body>

        </html>
        <?php
    }

    private function render_portal()
    {
        // Enqueue our specialized Recovery App
        // Since we are taking over the page, we print raw HTML
        ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>SafeBackup Recovery Portal</title>
            <?php
            // Manually load the CSS from our build
            $plugin_url = SBWP_PLUGIN_URL;
            // Helper to find the css file
            $css_files = glob(SBWP_PLUGIN_DIR . 'assets/compiled/assets/*.css');
            if ($css_files) {
                $css_url = $plugin_url . 'assets/compiled/assets/' . basename($css_files[0]);
                echo "<link rel='stylesheet' href='$css_url'>";
            }
            ?>
        </head>

        <body class="bg-slate-950 text-slate-50 min-h-screen">
            <div id="sbwp-recovery-root"></div>
            <script>
                window.sbwpRecoveryData = {
                    ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
                    restUrl: '<?php echo get_rest_url(null, 'sbwp/v1'); ?>',
                    nonce: '<?php echo wp_create_nonce('sbwp_recovery_nonce'); ?>'
                };
            </script>
            <?php
            // Load JS
            $js_files = glob(SBWP_PLUGIN_DIR . 'assets/compiled/assets/*.js');
            // We need to target the RECOVERY entry point specifically if we split chunks.
            // For now, let's assume we might reuse main or have a second entry.
            // We will update vite config to output 'recovery.js' entry.
    
            // If using the same build with a different entry file in manifest...
            // Let's assume for this step we will use a separate entry in Vite.
            // We will verify the filename later.
            echo "<script type='module' src='{$plugin_url}assets/compiled/assets/recovery.js'></script>";
            ?>
        </body>

        </html>
        <?php
    }

    public function ajax_restore()
    {
        check_ajax_referer('sbwp_recovery_nonce', 'nonce');

        if (!current_user_can('manage_options'))
            wp_send_json_error('Permission denied');

        $backup_id = intval($_POST['backup_id']);

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sbwp-restore-manager.php';
        $manager = new SBWP_Restore_Manager();

        $result = $manager->restore_backup($backup_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success('Restore complete');
        }
    }

    public function ajax_get_plugins()
    {
        check_ajax_referer('sbwp_recovery_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error('Permission denied');

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $response = array();

        foreach ($all_plugins as $file => $data) {
            $is_active = is_plugin_active($file);
            // Skip our own plugin to prevent accidental lockout? 
            // Maybe allow it but warn. For now, allow all.
            $response[] = array(
                'file' => $file,
                'name' => $data['Name'],
                'version' => $data['Version'],
                'active' => $is_active
            );
        }

        wp_send_json_success($response);
    }

    public function ajax_toggle_plugin()
    {
        check_ajax_referer('sbwp_recovery_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error('Permission denied');

        $plugin = sanitize_text_field($_POST['plugin']);
        $action = sanitize_text_field($_POST['plugin_action']); // 'activate' or 'deactivate'

        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ($action === 'activate') {
            $result = activate_plugin($plugin);
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }
        } else {
            deactivate_plugins($plugin);
        }

        wp_send_json_success();
    }

    public function ajax_get_log()
    {
        check_ajax_referer('sbwp_recovery_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error('Permission denied');

        $log_path = WP_CONTENT_DIR . '/debug.log';

        if (!file_exists($log_path)) {
            wp_send_json_error('debug.log not found. Please enable WP_DEBUG_LOG in wp-config.php.');
        }

        // Read last 500 lines
        $lines = $this->tail_file($log_path, 500);

        wp_send_json_success(array(
            'content' => implode("", $lines),
            'size' => size_format(filesize($log_path))
        ));
    }

    private function tail_file($filepath, $lines = 100)
    {
        $f = @fopen($filepath, "rb");
        if ($f === false)
            return array("Unable to read file");

        $buffer = 4096;
        fseek($f, -1, SEEK_END);
        if (fread($f, 1) != "\n")
            $lines -= 1;

        $output = '';
        $chunk = '';

        while (ftell($f) > 0 && $lines >= 0) {
            $seek = min(ftell($f), $buffer);
            fseek($f, -$seek, SEEK_CUR);
            $chunk = fread($f, $seek);
            $output = $chunk . $output;
            fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);
            $lines -= substr_count($chunk, "\n");
        }

        fclose($f);
        return explode("\n", $output);
    }
}
