<?php

class SBWP_Recovery_Portal
{

    public function init()
    {
        add_action('init', array($this, 'check_recovery_mode'));
        add_action('wp_ajax_sbwp_recovery_restore', array($this, 'ajax_restore'));
        add_action('wp_ajax_nopriv_sbwp_recovery_restore', array($this, 'ajax_restore'));

        add_action('wp_ajax_sbwp_recovery_get_plugins', array($this, 'ajax_get_plugins'));
        add_action('wp_ajax_nopriv_sbwp_recovery_get_plugins', array($this, 'ajax_get_plugins'));

        add_action('wp_ajax_sbwp_recovery_toggle_plugin', array($this, 'ajax_toggle_plugin'));
        add_action('wp_ajax_nopriv_sbwp_recovery_toggle_plugin', array($this, 'ajax_toggle_plugin'));

        add_action('wp_ajax_sbwp_recovery_get_log', array($this, 'ajax_get_log'));
        add_action('wp_ajax_nopriv_sbwp_recovery_get_log', array($this, 'ajax_get_log'));
    }

    public function check_recovery_mode()
    {
        if (isset($_GET['sbwp_recovery'])) {

            // Simple auth check for now: user must be admin or have a valid token (TODO: Token)
            // For Phase 3 MVP, we rely on logged-in cookie or we could implement a simple PIN
            if (!current_user_can('manage_options')) {
                // In a real crash scenario, cookies might not work effectively or user is logged out.
                // For MVP, lets assume we need a token or login. 
                // For now, let's just show a login form if not logged in? 
                // To keep it simple for this step: Require Admin Cookie.
                wp_die('Access Denied. Please log in as administrator.');
            }

            $this->render_portal();
            exit;
        }
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
