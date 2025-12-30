<?php

class SBWP_REST_API
{

    public function register_routes()
    {
        register_rest_route('sbwp/v1', '/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_stats'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('sbwp/v1', '/backups', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_backups'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('sbwp/v1', '/backups', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_backup'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('sbwp/v1', '/backups/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_backup'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('sbwp/v1', '/backups/(?P<id>\d+)/restore', array(
            'methods' => 'POST',
            'callback' => array($this, 'restore_backup'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('sbwp/v1', '/backups/(?P<id>\d+)/contents', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_backup_contents'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('sbwp/v1', '/backup/progress', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_backup_progress'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('sbwp/v1', '/settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_settings'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('sbwp/v1', '/settings', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_settings'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }

    public function check_permission()
    {
        return current_user_can('manage_options');
    }

    public function get_stats()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sb_backups';

        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_size = $wpdb->get_var("SELECT SUM(size_bytes) FROM $table_name");

        $last_backup = $wpdb->get_var("SELECT created_at FROM $table_name ORDER BY created_at DESC LIMIT 1");

        return rest_ensure_response(array(
            'count' => (int) $count,
            'total_size' => $this->format_size((int) $total_size),
            'last_backup' => $last_backup ? human_time_diff(strtotime($last_backup), current_time('timestamp')) . ' ago' : 'Never',
        ));
    }

    public function get_backups()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sb_backups';
        $backups = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
        return rest_ensure_response($backups);
    }

    public function create_backup($request)
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sbwp-backup-engine.php';
        $engine = new SBWP_Backup_Engine();
        $params = $request->get_json_params();
        $resume = isset($params['resume']) ? $params['resume'] : false;

        try {
            $result = $engine->create_backup('full', $resume);
        } catch (Throwable $e) {
            error_log("SBWP Fatal Error: " . $e->getMessage());
            return new WP_Error('fatal_error', $e->getMessage());
        } catch (Exception $e) { // Legacy catch
            error_log("SBWP Exception: " . $e->getMessage());
            return new WP_Error('exception', $e->getMessage());
        }

        if (is_wp_error($result)) {
            error_log("SBWP REST Error: " . $result->get_error_message());
            return $result;
        }

        error_log("SBWP REST Success: Status=" . (isset($result['status']) ? $result['status'] : 'Unknown'));
        // Force explicit JSON response to avoid WP REST API serialization issues
        if (ob_get_length())
            ob_clean();
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate'); // Prevent caching
        echo json_encode($result);
        die();
    }

    public function delete_backup($request)
    {
        $id = $request['id'];
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sbwp-backup-engine.php';
        $engine = new SBWP_Backup_Engine();

        $result = $engine->delete_backup($id);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array('success' => true, 'id' => $id, 'message' => 'Backup deleted.'));
    }

    public function restore_backup($request)
    {
        $id = $request['id'];
        $params = $request->get_json_params();
        $items = isset($params['items']) ? $params['items'] : null;

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sbwp-restore-manager.php';
        $manager = new SBWP_Restore_Manager();

        if ($items) {
            // Partial restore
            $result = $manager->restore_specific_items($id, $items);
        } else {
            // Full restore
            $result = $manager->restore_backup($id);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array('success' => true, 'message' => 'Restore completed successfully.'));
    }

    public function get_backup_contents($request)
    {
        $id = $request['id'];
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sbwp-restore-manager.php';
        $manager = new SBWP_Restore_Manager();

        $result = $manager->get_backup_content_list($id);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public function get_settings()
    {
        $settings = get_option('sbwp_settings', array(
            'retention_limit' => 5,
            'alert_email' => '',
            'alerts_enabled' => false,
        ));
        return rest_ensure_response($settings);
    }

    public function update_settings($request)
    {
        $params = $request->get_json_params();
        $settings = get_option('sbwp_settings', array(
            'retention_limit' => 5,
            'alert_email' => '',
            'alerts_enabled' => false,
        ));

        if (isset($params['retention_limit'])) {
            $settings['retention_limit'] = absint($params['retention_limit']);
        }
        if (isset($params['alert_email'])) {
            $settings['alert_email'] = sanitize_email($params['alert_email']);
        }
        if (isset($params['alerts_enabled'])) {
            $settings['alerts_enabled'] = (bool) $params['alerts_enabled'];
        }

        update_option('sbwp_settings', $settings);

        return rest_ensure_response(array('success' => true, 'settings' => $settings));
    }

    public function get_backup_progress()
    {
        wp_cache_delete('sbwp_backup_progress', 'options'); // Force fresh read
        $progress = get_option('sbwp_backup_progress');
        if (!$progress) {
            return rest_ensure_response(array('percent' => 0, 'message' => 'Idle'));
        }
        return rest_ensure_response($progress);
    }

    private function format_size($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        } elseif ($bytes == 1) {
            return $bytes . ' byte';
        } else {
            return '0 bytes';
        }
    }
}
