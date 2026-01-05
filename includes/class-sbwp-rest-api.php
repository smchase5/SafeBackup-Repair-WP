<?php

class SBWP_REST_API
{

    public function __construct()
    {
        // Auto-migrate: add is_locked column if it doesn't exist
        global $wpdb;
        $table_name = $wpdb->prefix . 'sb_backups';
        $column = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'is_locked'");
        if (empty($column)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN is_locked tinyint(1) DEFAULT 0 NOT NULL");
        }
    }

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

        register_rest_route('sbwp/v1', '/backup/cancel', array(
            'methods' => 'POST',
            'callback' => array($this, 'cancel_backup'),
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

        // Backup file browser routes
        register_rest_route('sbwp/v1', '/backups/(?P<id>\d+)/files', array(
            'methods' => 'GET',
            'callback' => array($this, 'list_backup_files'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('sbwp/v1', '/backups/(?P<id>\d+)/download', array(
            'methods' => 'GET',
            'callback' => array($this, 'download_backup'),
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
        $params = $request->get_json_params();
        $type = sanitize_text_field($params['type'] ?? 'full');
        $session_id = sanitize_text_field($params['session_id'] ?? '');

        error_log("SBWP REST: Create Backup Request. Type=$type SID=$session_id");

        $engine = new SBWP_Backup_Engine();
        if (!in_array($type, ['full', 'incremental', 'db_only'])) {
            $type = 'full';
        }

        $resume = !empty($params['resume']);

        try {
            $result = $engine->create_backup($type, $resume, $session_id);
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
        global $wpdb;
        $id = intval($request['id']);
        $table_name = $wpdb->prefix . 'sb_backups';

        // Check if backup is locked
        $backup = $wpdb->get_row($wpdb->prepare("SELECT is_locked FROM $table_name WHERE id = %d", $id));
        if ($backup && !empty($backup->is_locked)) {
            return new WP_Error(
                'backup_locked',
                'This backup is locked because it is required for incremental restores. To delete it, first delete all incremental backups that depend on it.',
                array('status' => 403)
            );
        }

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

    /**
     * List files in a backup (reads ZIP contents without extracting)
     */
    public function list_backup_files($request)
    {
        global $wpdb;
        $id = intval($request['id']);

        // Get backup folder path
        $table_name = $wpdb->prefix . 'sb_backups';
        $backup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));

        if (!$backup) {
            return new WP_Error('not_found', 'Backup not found', array('status' => 404));
        }

        $backup_folder = $backup->file_path_local;
        if (!is_dir($backup_folder)) {
            return new WP_Error('not_found', 'Backup folder not found: ' . $backup_folder, array('status' => 404));
        }

        $files = array();

        // List SQL files
        $sql_files = glob($backup_folder . '/database-*.sql');
        foreach ($sql_files as $sql_file) {
            $files[] = array(
                'name' => basename($sql_file),
                'path' => '/' . basename($sql_file),
                'type' => 'file',
                'size' => filesize($sql_file),
                'category' => 'database'
            );
        }

        // List files from ZIP archives
        $zip_files = glob($backup_folder . '/files-*.zip');
        if (empty($zip_files)) {
            // Check for legacy single files.zip
            $legacy_zip = $backup_folder . '/files.zip';
            if (file_exists($legacy_zip)) {
                $zip_files = array($legacy_zip);
            }
        }

        $file_tree = array();
        foreach ($zip_files as $zip_path) {
            $zip = new ZipArchive();
            if ($zip->open($zip_path) === TRUE) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $path = '/' . $stat['name'];
                    $size = $stat['size'];

                    // Build tree structure
                    $parts = explode('/', trim($stat['name'], '/'));
                    $current = &$file_tree;

                    for ($j = 0; $j < count($parts); $j++) {
                        $part = $parts[$j];
                        $is_file = ($j === count($parts) - 1) && (substr($stat['name'], -1) !== '/');

                        if (!isset($current[$part])) {
                            $current[$part] = array(
                                'name' => $part,
                                'path' => '/' . implode('/', array_slice($parts, 0, $j + 1)),
                                'type' => $is_file ? 'file' : 'folder',
                                'size' => $is_file ? $size : 0,
                                'children' => array()
                            );
                        }
                        $current = &$current[$part]['children'];
                    }
                }
                $zip->close();
            }
        }

        // Convert tree to flat array with proper structure
        $this->flatten_tree($file_tree, $files);

        return rest_ensure_response(array(
            'id' => $id,
            'created_at' => $backup->created_at,
            'type' => $backup->type,
            'size_bytes' => $backup->size_bytes,
            'files' => $files
        ));
    }

    private function flatten_tree(&$tree, &$output, $depth = 0)
    {
        foreach ($tree as $node) {
            $item = array(
                'name' => $node['name'],
                'path' => $node['path'],
                'type' => $node['type'],
                'size' => $node['size'],
                'depth' => $depth
            );

            if ($node['type'] === 'folder' && !empty($node['children'])) {
                $item['hasChildren'] = true;
            }

            $output[] = $item;

            if (!empty($node['children'])) {
                $this->flatten_tree($node['children'], $output, $depth + 1);
            }
        }
    }

    /**
     * Download backup files
     */
    public function download_backup($request)
    {
        global $wpdb;
        $id = intval($request['id']);
        $path = isset($request['path']) ? sanitize_text_field($request['path']) : '/';

        $table_name = $wpdb->prefix . 'sb_backups';
        $backup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));

        if (!$backup) {
            return new WP_Error('not_found', 'Backup not found', array('status' => 404));
        }

        $backup_folder = $backup->file_path_local;

        // Full backup download
        if ($path === '/' || $path === '') {
            // Create a combined ZIP of all backup files
            $combined_zip_path = $backup_folder . '/backup-download.zip';
            $zip = new ZipArchive();

            if ($zip->open($combined_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                return new WP_Error('zip_error', 'Could not create download archive');
            }

            // Add all SQL files
            foreach (glob($backup_folder . '/database-*.sql') as $sql_file) {
                $zip->addFile($sql_file, basename($sql_file));
            }

            // Add all ZIP chunks
            foreach (glob($backup_folder . '/files-*.zip') as $zip_file) {
                $zip->addFile($zip_file, basename($zip_file));
            }

            // Legacy single zip
            if (file_exists($backup_folder . '/files.zip')) {
                $zip->addFile($backup_folder . '/files.zip', 'files.zip');
            }

            $zip->close();

            // Return download URL
            $upload_dir = wp_upload_dir();
            $relative_path = str_replace($upload_dir['basedir'], '', $combined_zip_path);
            $download_url = $upload_dir['baseurl'] . $relative_path;

            return rest_ensure_response(array(
                'download_url' => $download_url,
                'filename' => 'backup-' . date('Y-m-d', strtotime($backup->created_at)) . '.zip'
            ));
        }

        // Single file download - extract from ZIP
        $file_path = ltrim($path, '/');

        // Check SQL files first
        if (preg_match('/^database-\d+\.sql$/', $file_path)) {
            $sql_path = $backup_folder . '/' . $file_path;
            if (file_exists($sql_path)) {
                $upload_dir = wp_upload_dir();
                $relative_path = str_replace($upload_dir['basedir'], '', $sql_path);
                return rest_ensure_response(array(
                    'download_url' => $upload_dir['baseurl'] . $relative_path,
                    'filename' => $file_path
                ));
            }
        }

        // Extract from ZIP files
        $zip_files = glob($backup_folder . '/files-*.zip');
        if (empty($zip_files) && file_exists($backup_folder . '/files.zip')) {
            $zip_files = array($backup_folder . '/files.zip');
        }

        // Collect all matching files
        $files_to_extract = array();
        $is_exact_match = false;

        foreach ($zip_files as $zip_path) {
            $zip = new ZipArchive();
            if ($zip->open($zip_path) === TRUE) {
                // First try exact match
                $index = $zip->locateName($file_path);
                if ($index !== false) {
                    $stat = $zip->statIndex($index);
                    // Check if it's a file (not directory)
                    if (substr($stat['name'], -1) !== '/') {
                        $is_exact_match = true;
                        $files_to_extract[] = array('zip' => $zip_path, 'file' => $file_path);
                    }
                }

                // If not exact match, look for prefix match (folder)
                if (!$is_exact_match) {
                    $prefix = rtrim($file_path, '/') . '/';
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        if (strpos($stat['name'], $prefix) === 0 && substr($stat['name'], -1) !== '/') {
                            $files_to_extract[] = array('zip' => $zip_path, 'file' => $stat['name']);
                        }
                    }
                }
                $zip->close();
            }
        }

        if (empty($files_to_extract)) {
            return new WP_Error('not_found', 'File not found in backup: ' . $file_path, array('status' => 404));
        }

        $temp_dir = $backup_folder . '/temp-extract/';
        wp_mkdir_p($temp_dir);

        // If single file, extract and return
        if ($is_exact_match && count($files_to_extract) === 1) {
            $item = $files_to_extract[0];
            $zip = new ZipArchive();
            if ($zip->open($item['zip']) === TRUE) {
                $zip->extractTo($temp_dir, $item['file']);
                $zip->close();

                $extracted_path = $temp_dir . $item['file'];
                if (file_exists($extracted_path)) {
                    $upload_dir = wp_upload_dir();
                    $relative_path = str_replace($upload_dir['basedir'], '', $extracted_path);
                    return rest_ensure_response(array(
                        'download_url' => $upload_dir['baseurl'] . $relative_path,
                        'filename' => basename($item['file'])
                    ));
                }
            }
        }

        // Multiple files (folder download) - create a temp zip
        $folder_name = basename(rtrim($file_path, '/'));
        $download_zip_path = $temp_dir . $folder_name . '.zip';
        $download_zip = new ZipArchive();

        if ($download_zip->open($download_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            return new WP_Error('zip_error', 'Could not create download archive');
        }

        foreach ($files_to_extract as $item) {
            $zip = new ZipArchive();
            if ($zip->open($item['zip']) === TRUE) {
                $content = $zip->getFromName($item['file']);
                if ($content !== false) {
                    // Store with relative path from the requested folder
                    $prefix = rtrim($file_path, '/') . '/';
                    $relative = str_replace($prefix, '', $item['file']);
                    if (empty($relative))
                        $relative = basename($item['file']);
                    $download_zip->addFromString($relative, $content);
                }
                $zip->close();
            }
        }

        $download_zip->close();

        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'], '', $download_zip_path);
        return rest_ensure_response(array(
            'download_url' => $upload_dir['baseurl'] . $relative_path,
            'filename' => $folder_name . '.zip'
        ));
    }

    public function get_settings()
    {
        $settings = get_option('sbwp_settings', array(
            'retention_limit' => 5,
            'alert_email' => '',
            'alerts_enabled' => false,
            'incremental_enabled' => true,
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
            'incremental_enabled' => true,
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
        if (isset($params['incremental_enabled'])) {
            $new_value = (bool) $params['incremental_enabled'];

            // If enabling incremental, check for full backup
            if ($new_value && !$settings['incremental_enabled']) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'sb_backups';
                $full_backup = $wpdb->get_row(
                    "SELECT id FROM $table_name WHERE type = 'full' AND status = 'completed' ORDER BY created_at DESC LIMIT 1"
                );

                if (!$full_backup) {
                    return new WP_Error(
                        'no_full_backup',
                        'You must create a full backup before enabling incremental backups.',
                        array('status' => 400)
                    );
                }

                // Lock the most recent full backup
                $wpdb->update(
                    $table_name,
                    array('is_locked' => 1),
                    array('id' => $full_backup->id)
                );
            } elseif (!$new_value) {
                // Disabling incremental - unlock all backups
                global $wpdb;
                $table_name = $wpdb->prefix . 'sb_backups';
                error_log("SBWP: Disabling incremental - unlocking all backups");
                $result = $wpdb->query("UPDATE $table_name SET is_locked = 0 WHERE is_locked = 1");
                error_log("SBWP: Unlock query affected rows: " . $result);
            }

            $settings['incremental_enabled'] = $new_value;
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

    public function cancel_backup()
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sbwp-backup-engine.php';
        $engine = new SBWP_Backup_Engine();
        $result = $engine->cancel_backup();

        // Clear progress option too
        delete_option('sbwp_backup_progress');

        return rest_ensure_response($result);
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
