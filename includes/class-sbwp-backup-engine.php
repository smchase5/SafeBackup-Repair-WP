<?php

class SBWP_Backup_Engine
{

    private $backup_dir;
    private $retention_limit = 5;
    private $time_limit = 5; // Buffer against slow IO
    private $batch_limit = 200;

    public function __construct()
    {
        $upload_dir = wp_upload_dir();
        $this->backup_dir = $upload_dir['basedir'] . '/safebackup';

        // Load user's retention setting
        $settings = get_option('sbwp_settings', array());
        if (isset($settings['retention_limit']) && $settings['retention_limit'] > 0) {
            $this->retention_limit = (int) $settings['retention_limit'];
        }

        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
            // Secure the directory
            file_put_contents($this->backup_dir . '/index.php', '<?php // Silence is golden.');
            file_put_contents($this->backup_dir . '/.htaccess', 'deny from all');
        }
    }

    public function create_backup($type = 'full', $resume = false)
    {
        @set_time_limit(300); // Prevent PHP timeout
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // Release session lock so progress polling works
        }
        $start_time = microtime(true);
        wp_cache_delete('sbwp_backup_batch_state', 'options'); // Force fresh state read
        $state = get_option('sbwp_backup_batch_state'); // Use option

        error_log("SBWP Start: Resume=" . ($resume ? 'YES' : 'NO') . " State=" . ($state ? 'FOUND' : 'MISSING'));

        if ($resume && !$state) {
            return new WP_Error('session_expired', 'Backup session lost. State missing.');
        }

        // Initialize if not resuming or state missing
        if (!$state || !$resume) {
            $timestamp = current_time('Y-m-d-H-i-s');
            $backup_name = 'backup-' . $timestamp;
            $folder_path = $this->backup_dir . '/' . $backup_name;

            if (!mkdir($folder_path)) {
                return new WP_Error('mkdir_failed', 'Could not create backup directory.');
            }

            global $wpdb;
            $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
            $table_names = array_map(function ($t) {
                return $t[0];
            }, $tables);

            $state = array(
                'step' => 'DB', // DB, ZIP, FINISH
                'folder_path' => $folder_path,
                'tables' => $table_names,
                'current_table_index' => 0,
                'current_row_offset' => 0,
                'file_list' => array(), // Populated before ZIP step
                'file_offset' => 0,
                'type' => $type
            );
            $this->update_progress(0, 'Starting backup...');
            update_option('sbwp_backup_batch_state', $state, 'no'); // Save immediately
        }

        $loop_count = 0;

        error_log("SBWP Trace: Loop Start. TimeLimit={$this->time_limit}");

        // Process Loop
        while (microtime(true) - $start_time < $this->time_limit) {
            // error_log("SBWP Trace: Loop Tick $loop_count");
            $loop_count++;

            // Update Progress (Throttled)
            $msg = 'Processing...';
            if ($state['step'] === 'DB') {
                $tbl = isset($state['tables'][$state['current_table_index']]) ? $state['tables'][$state['current_table_index']] : 'Database';
                $msg = "Exporting $tbl (" . $state['current_row_offset'] . " rows)";
            } elseif ($state['step'] === 'ZIP') {
                $msg = "Archiving files (" . $state['file_offset'] . " / " . ($state['total_files'] ?? 0) . ")";
            }
            $this->update_progress($this->calculate_percent($state), $msg);


            if ($state['step'] === 'DB') {
                // ... (rest is same)
                $complete = $this->process_db_batch($state, $start_time);
                if ($complete) {
                    $state['step'] = 'ZIP_SCAN';
                    error_log("SBWP Step Done: DB");
                }
            } elseif ($state['step'] === 'ZIP_SCAN') {
                // ...
                // Inform user scan is starting (can take time)
                $this->update_progress(45, 'Scanning files for backup...');

                // Scan files once
                if ($state['type'] === 'full') {
                    $count = $this->scan_files($state['folder_path']);
                    $state['total_files'] = $count;
                    // file_list is no longer in state
                    $state['step'] = 'ZIP';
                    error_log("SBWP Files Scanned: " . $count);
                } else {
                    $state['step'] = 'FINISH';
                }
            } elseif ($state['step'] === 'ZIP') {
                $complete = $this->process_zip_batch($state, $start_time);
                if ($complete) {
                    $state['step'] = 'FINISH';
                }
            } elseif ($state['step'] === 'FINISH') {
                $this->finalize_backup($state);
                delete_option('sbwp_backup_batch_state');
                $this->update_progress(100, 'Backup Complete');
                error_log("SBWP Trace: Finished. Returning completed.");
                return array('status' => 'completed');
            }
        }

        // Save state and return partial
        $saved = update_option('sbwp_backup_batch_state', $state, 'no');
        wp_cache_delete('sbwp_backup_batch_state', 'options'); // Ensure fresh reads
        wp_cache_delete('sbwp_backup_batch_state', 'options');

        error_log("SBWP Loop End: Count=$loop_count Saved=" . ($saved ? 'YES' : 'NO') . " Offset=" . ($state['step'] === 'DB' ? $state['current_row_offset'] : 'N/A'));

        $msg = 'Processing...';
        if ($state['step'] === 'DB') {
            $tbl = isset($state['tables'][$state['current_table_index']]) ? $state['tables'][$state['current_table_index']] : 'Database';
            $msg = "Exporting $tbl (" . $state['current_row_offset'] . " rows)";
        } elseif ($state['step'] === 'ZIP') {
            $msg = "Archiving files (" . $state['file_offset'] . " / " . ($state['total_files'] ?? 0) . ")";
        }

        $percent = $this->calculate_percent($state);
        $this->update_progress($percent, $msg); // Sync for polling

        error_log("SBWP Trace: Returning processing status.");
        return array(
            'status' => 'processing',
            'message' => $msg,
            'percent' => $percent
        );
    }

    private function process_db_batch(&$state, $start_time)
    {
        global $wpdb;
        $table_idx = $state['current_table_index'];

        if ($table_idx >= count($state['tables']))
            return true; // All tables done

        $table = $state['tables'][$table_idx];
        $offset = $state['current_row_offset'];
        $sql_file = $state['folder_path'] . '/database.sql';

        $handle = fopen($sql_file, 'a+');
        if (!$handle) {
            error_log("SBWP Error: Could not open SQL file for writing: $sql_file");
            return true; // Skip table to avoid infinite loop? Or fail? Fail safe: skip.
        }

        if ($offset === 0) {
            // First time for this table? Write header? 
            // Actually, we just append. 
            // If it's the VERY first table, maybe header.
            if ($table_idx === 0) {
                fwrite($handle, "-- SafeBackup Dump\n\n");
            }
            fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
            $create_row = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            fwrite($handle, $create_row[1] . ";\n\n");
        }

        $rows = $wpdb->get_results("SELECT * FROM `$table` LIMIT $this->batch_limit OFFSET $offset", ARRAY_A);

        if ($rows) {
            foreach ($rows as $row) {
                $values = array_map(array($wpdb, '_real_escape'), array_values($row));
                $values_str = "'" . implode("', '", $values) . "'";
                fwrite($handle, "INSERT INTO `$table` VALUES ($values_str);\n");
            }
            fwrite($handle, "\n");
            $prev_offset = $state['current_row_offset'];
            $state['current_row_offset'] += count($rows);

            // Real-time update (Throttled by update_progress)
            $tbl = isset($state['tables'][$state['current_table_index']]) ? $state['tables'][$state['current_table_index']] : 'Database';
            $msg = "Exporting $tbl (" . $state['current_row_offset'] . " rows)";
            $this->update_progress($this->calculate_percent($state), $msg);

            // error_log("SBWP DB Batch: Table=$table Got=" . count($rows) . " Offset: $prev_offset -> " . $state['current_row_offset']);
        } else {
            // No more rows, move to next table
            error_log("SBWP DB Batch: Table=$table DONE.");

            // Validate table done visual
            $msg = "Exporting $table (Done)";
            $this->update_progress($this->calculate_percent($state), $msg, true); // Force update

            $state['current_table_index']++;
            $state['current_row_offset'] = 0;
        }

        fclose($handle);
        return false;
    }

    private function scan_files($backup_dir_path)
    {
        $root_path = WP_CONTENT_DIR;
        $file_list_path = $backup_dir_path . '/file_list.json';

        // Open file for writing
        $handle = fopen($file_list_path, 'w');
        if (!$handle)
            return 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root_path),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $count = 0;
        fwrite($handle, "[\n");
        $first = true;

        foreach ($iterator as $name => $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                // Exclude backup dir & cache check
                if (strpos($file_path, $this->backup_dir) !== false)
                    continue;
                if (strpos($file_path, 'cache') !== false)
                    continue;
                if (strpos($file_path, 'node_modules') !== false)
                    continue;
                if (strpos($file_path, '.git') !== false)
                    continue;

                // Write path to JSON
                if (!$first)
                    fwrite($handle, ",\n");
                fwrite($handle, json_encode($file_path));
                $first = false;
                $count++;

                // Update progress every 0.5s during scan
                $now = microtime(true);
                // Use a local static or just raw check, create_backup instance persists for this call
                // But scan_files is inside create_backup so we can just call update_progress
                // But update_progress uses its own static timer. That's fine.
                // We just need to check if we should even call it to avoid function overhead? 
                // No, update_progress is fast.
                $this->update_progress(45, "Scanning files... Found $count");
            }
        }

        fwrite($handle, "\n]");
        fclose($handle);

        return $count;
    }

    private function process_zip_batch(&$state, $start_time)
    {
        $zip_file = $state['folder_path'] . '/files.zip';
        $json_file = $state['folder_path'] . '/file_list.json';

        $zip = new ZipArchive();
        $res = $zip->open($zip_file, ZipArchive::CREATE);

        if ($res !== TRUE) {
            error_log("SBWP Error: Zip Open Failed. Code: $res");
            return true;
        }

        if (!file_exists($json_file)) {
            error_log("SBWP Error: JSON file missing: $json_file");
            return true;
        }

        $json_content = file_get_contents($json_file);
        $file_list = json_decode($json_content, true);

        if (!$file_list) {
            error_log("SBWP Error: JSON decode failed or empty. Content Length: " . strlen($json_content));
            return true;
        }

        $batch_size = 50;
        $count = 0;
        $max_files_per_batch = 500; // Force return after this many to show progress
        $total_files = count($file_list); // Should match state['total_files']
        $idx = $state['file_offset'];

        // error_log("SBWP ZIP Trace: Start. Index=$idx Total=$total_files Limit=" . $this->time_limit);

        while ($idx < $total_files && $count < $max_files_per_batch && (microtime(true) - $start_time < $this->time_limit)) {
            $file_path = $file_list[$idx];

            // Validate file still exists
            if (file_exists($file_path)) {
                $relative_path = substr($file_path, strlen(WP_CONTENT_DIR) + 1);
                $zip->addFile($file_path, $relative_path);
            }

            $idx++;
            $count++;

            // Update progress every 50 files
            if ($idx % 50 === 0) {
                $state['file_offset'] = $idx;
                $pct = $this->calculate_percent($state);
                $this->update_progress($pct, "Archiving files ($idx / $total_files)");
            }
        }

        $state['file_offset'] = $idx; // Save state BEFORE close in case close times out
        $zip->close();

        return ($idx >= $total_files);
    }

    private function finalize_backup($state)
    {
        $this->update_progress(90, 'Finalizing...');

        $files = array();
        $sql_file = $state['folder_path'] . '/database.sql';
        if (file_exists($sql_file))
            $files[] = $sql_file;

        if ($state['type'] === 'full') {
            $zip_file = $state['folder_path'] . '/files.zip';
            if (file_exists($zip_file))
                $files[] = $zip_file;
        }

        $total_size = 0;
        foreach ($files as $file) {
            $total_size += filesize($file);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'sb_backups';
        $wpdb->insert(
            $table_name,
            array(
                'created_at' => current_time('mysql'),
                'type' => $state['type'],
                'storage_location' => 'local',
                'file_path_local' => $state['folder_path'],
                'size_bytes' => $total_size,
                'status' => 'completed',
                'notes' => ''
            )
        );

        $this->cleanup_old_backups();
    }

    private function calculate_percent($state)
    {
        if ($state['step'] === 'DB') {
            $total_tables = count($state['tables']);
            $current = $state['current_table_index'];
            $base = $total_tables > 0 ? ($current / $total_tables) * 40 : 0;
            // Add a tiny bit for rows to show movement? 
            // Hard to know max rows, but let's just ensure if current > 0 it's at least 1%
            // Or just return floor($base).
            // Let's rely on the text message for row details.
            return max(1, floor($base));
        }
        if ($state['step'] === 'ZIP_SCAN')
            return 45;
        if ($state['step'] === 'ZIP') {
            $total = isset($state['total_files']) ? $state['total_files'] : 0;
            $current = $state['file_offset'];
            // 50-90%
            return $total > 0 ? 50 + floor(($current / $total) * 40) : 50;
        }
        return 95;
    }

    private function update_progress($percent, $message, $force = false)
    {
        static $last_update = 0;
        $now = microtime(true);

        // Always update on 100%, if forced, or if 0.5 seconds has passed
        if ($percent >= 100 || $force || ($now - $last_update) > 0.5) {
            update_option('sbwp_backup_progress', array('percent' => $percent, 'message' => $message), 'no');
            wp_cache_delete('sbwp_backup_progress', 'options'); // Force fresh reads
            $last_update = $now;
        }
    }

    public function delete_backup($id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sb_backups';

        $backup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));

        if (!$backup) {
            return new WP_Error('not_found', 'Backup not found');
        }

        // Delete files
        if (!empty($backup->file_path_local) && is_dir($backup->file_path_local)) {
            $this->delete_directory($backup->file_path_local);
        }

        // Delete DB record
        $result = $wpdb->delete($table_name, array('id' => $id));

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete database record');
        }

        return true;
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
