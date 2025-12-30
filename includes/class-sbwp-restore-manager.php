<?php

class SBWP_Restore_Manager
{

    public function restore_backup($backup_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sb_backups';

        $backup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $backup_id));

        if (!$backup || !file_exists($backup->file_path_local)) {
            return new WP_Error('not_found', 'Backup not found.');
        }

        $this->enable_maintenance_mode();

        $errors = array();

        // 1. Restore Files
        $zip_file = $backup->file_path_local . '/files.zip';
        if (file_exists($zip_file)) {
            $res = $this->restore_files($zip_file);
            if (is_wp_error($res))
                $errors[] = $res->get_error_message();
        }

        // 2. Restore DB
        $sql_file = $backup->file_path_local . '/database.sql';
        if (file_exists($sql_file)) {
            $res = $this->restore_db($sql_file);
            if (is_wp_error($res))
                $errors[] = $res->get_error_message();
        }

        $this->disable_maintenance_mode();

        if (!empty($errors)) {
            return new WP_Error('restore_failed', implode(', ', $errors));
        }

        return true;
    }

    public function get_backup_content_list($backup_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sb_backups';
        $backup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $backup_id));

        if (!$backup || !file_exists($backup->file_path_local . '/files.zip')) {
            return new WP_Error('not_found', 'Backup ZIP not found.');
        }

        $zip = new ZipArchive;
        if ($zip->open($backup->file_path_local . '/files.zip') !== TRUE) {
            return new WP_Error('zip_error', 'Could not open ZIP file.');
        }

        $plugins = array();
        $themes = array();

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Check for plugins
            if (strpos($name, 'plugins/') === 0) {
                $parts = explode('/', $name);
                if (isset($parts[1]) && !empty($parts[1])) {
                    $plugins[$parts[1]] = true;
                }
            }

            // Check for themes
            if (strpos($name, 'themes/') === 0) {
                $parts = explode('/', $name);
                if (isset($parts[1]) && !empty($parts[1])) {
                    $themes[$parts[1]] = true;
                }
            }
        }

        $zip->close();

        return array(
            'plugins' => array_keys($plugins),
            'themes' => array_keys($themes)
        );
    }

    public function restore_specific_items($backup_id, $items)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sb_backups';
        $backup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $backup_id));

        if (!$backup || !file_exists($backup->file_path_local . '/files.zip')) {
            return new WP_Error('not_found', 'Backup ZIP not found.');
        }

        $this->enable_maintenance_mode();

        $zip = new ZipArchive;
        if ($zip->open($backup->file_path_local . '/files.zip') !== TRUE) {
            $this->disable_maintenance_mode();
            return new WP_Error('zip_error', 'Could not open ZIP file.');
        }

        $files_to_extract = array();
        $target_plugins = isset($items['plugins']) ? $items['plugins'] : array();
        $target_themes = isset($items['themes']) ? $items['themes'] : array();

        // Find all files matching the selection
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Check plugins
            foreach ($target_plugins as $slug) {
                if (strpos($name, 'plugins/' . $slug . '/') === 0) {
                    $files_to_extract[] = $name;
                    continue 2;
                }
            }

            // Check themes
            foreach ($target_themes as $slug) {
                if (strpos($name, 'themes/' . $slug . '/') === 0) {
                    $files_to_extract[] = $name;
                    continue 2;
                }
            }
        }

        if (empty($files_to_extract)) {
            $zip->close();
            $this->disable_maintenance_mode();
            return new WP_Error('no_files', 'No matching files found in backup.');
        }

        // Extract specific files
        $success = $zip->extractTo(WP_CONTENT_DIR, $files_to_extract);
        $zip->close();

        $this->disable_maintenance_mode();

        if (!$success) {
            return new WP_Error('extract_failed', 'Failed to extract selected files.');
        }

        return true;
    }

    private function restore_files($zip_file)
    {
        $zip = new ZipArchive;
        if ($zip->open($zip_file) === TRUE) {
            $zip->extractTo(WP_CONTENT_DIR);
            $zip->close();
            return true;
        } else {
            return new WP_Error('unzip_failed', 'Failed to unzip files.');
        }
    }

    private function restore_db($sql_file)
    {
        global $wpdb;

        $handle = fopen($sql_file, "r");
        if (!$handle)
            return new WP_Error('db_read_failed', 'Could not open SQL file.');

        $query = '';
        while (($line = fgets($handle)) !== false) {
            // Skip comments and empty lines
            if (substr($line, 0, 2) == '--' || $line == '')
                continue;

            $query .= $line;
            if (substr(trim($line), -1, 1) == ';') {
                $wpdb->query($query);
                $query = '';
            }
        }

        fclose($handle);
        return true;
    }

    private function enable_maintenance_mode()
    {
        $maintenance_file = ABSPATH . '.maintenance';
        $content = '<?php $upgrading = ' . time() . '; ?>';
        file_put_contents($maintenance_file, $content);
    }

    private function disable_maintenance_mode()
    {
        $maintenance_file = ABSPATH . '.maintenance';
        if (file_exists($maintenance_file)) {
            unlink($maintenance_file);
        }
    }
}
