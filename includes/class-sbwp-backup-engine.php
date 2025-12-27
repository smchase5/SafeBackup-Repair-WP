<?php

class SBWP_Backup_Engine
{

    private $backup_dir;
    private $retention_limit = 5;

    public function __construct()
    {
        $upload_dir = wp_upload_dir();
        $this->backup_dir = $upload_dir['basedir'] . '/safebackup';

        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
            // Secure the directory
            file_put_contents($this->backup_dir . '/index.php', '<?php // Silence is golden.');
            file_put_contents($this->backup_dir . '/.htaccess', 'deny from all');
        }
    }

    public function create_backup($type = 'full')
    {
        // 1. Create a unique ID/Name
        $timestamp = current_time('Y-m-d-H-i-s');
        $backup_name = 'backup-' . $timestamp;
        $folder_path = $this->backup_dir . '/' . $backup_name;

        if (!mkdir($folder_path)) {
            return new WP_Error('mkdir_failed', 'Could not create backup directory.');
        }

        $files = array();

        // 2. Dump Database
        $sql_file = $folder_path . '/database.sql';
        $this->dump_database($sql_file);
        if (file_exists($sql_file)) {
            $files[] = $sql_file;
        }

        // 3. Zip Content (if full)
        if ($type === 'full') {
            $zip_file = $folder_path . '/files.zip';
            $this->zip_content($zip_file);
            if (file_exists($zip_file)) {
                $files[] = $zip_file;
            }
        }

        // 4. Calculate Size & Finalize
        $total_size = 0;
        foreach ($files as $file) {
            $total_size += filesize($file);
        }

        // 5. Save to DB
        global $wpdb;
        $table_name = $wpdb->prefix . 'sb_backups';
        $wpdb->insert(
            $table_name,
            array(
                'created_at' => current_time('mysql'),
                'type' => $type,
                'storage_location' => 'local',
                'file_path_local' => $folder_path, // Storing folder path
                'size_bytes' => $total_size,
                'status' => 'completed',
                'notes' => ''
            )
        );

        // 6. Cleanup Old
        $this->cleanup_old_backups();

        return true;
    }

    private function dump_database($output_file)
    {
        global $wpdb;
        $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);

        $handle = fopen($output_file, 'w+');
        if (!$handle)
            return;

        fwrite($handle, "-- SafeBackup Database Dump\n");
        fwrite($handle, "-- Created: " . current_time('mysql') . "\n\n");

        foreach ($tables as $table_row) {
            $table = $table_row[0];

            // Drop & Create
            fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
            $create_row = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            fwrite($handle, $create_row[1] . ";\n\n");

            // Data
            $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
            if ($rows) {
                foreach ($rows as $row) {
                    $values = array_map(array($wpdb, '_real_escape'), array_values($row));
                    $values_str = "'" . implode("', '", $values) . "'";
                    fwrite($handle, "INSERT INTO `$table` VALUES ($values_str);\n");
                }
                fwrite($handle, "\n");
            }
        }

        fclose($handle);
    }

    private function zip_content($output_file)
    {
        $root_path = WP_CONTENT_DIR;
        $zip = new ZipArchive();
        $zip->open($output_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root_path),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            // Skip directories (they would be added automatically)
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen($root_path) + 1);

                // Exclude backup dir & cache
                if (strpos($file_path, $this->backup_dir) !== false)
                    continue;
                if (strpos($file_path, 'cache') !== false)
                    continue;
                if (strpos($file_path, 'node_modules') !== false)
                    continue;
                if (strpos($file_path, '.git') !== false)
                    continue;

                $zip->addFile($file_path, $relative_path);
            }
        }

        $zip->close();
    }

    private function cleanup_old_backups()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sb_backups';

        $backups = $wpdb->get_results(
            $wpdb->prepare("SELECT id, file_path_local FROM $table_name ORDER BY created_at DESC LIMIT 100 OFFSET %d", $this->retention_limit)
        );

        if ($backups) {
            foreach ($backups as $backup) {
                // Delete folder
                $this->delete_directory($backup->file_path_local);
                // Delete DB row
                $wpdb->delete($table_name, array('id' => $backup->id));
            }
        }
    }

    private function delete_directory($dir)
    {
        if (!is_dir($dir))
            return;
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->delete_directory("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }
}
