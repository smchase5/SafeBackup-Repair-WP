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
