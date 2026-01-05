<?php

class SBWP_Backup_Engine
{
    private $backup_dir;

    private $retention_limit = 5;
    private $time_limit = 5; // Buffer against slow IO
    private $batch_limit = 500; // Rows per batch (increased for cursor efficiency)
    private $chunk_size_bytes = 50 * 1024 * 1024; // 50MB per SQL/ZIP chunk (lower for shared host compatibility)
    private $pk_cache = []; // Cache primary keys per table
    private $last_checksums = null; // Cache for incremental comparison
    private $session_id = null;

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

    public function create_backup($type = 'full', $resume = false, $session_id = null)
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

            // Create a temporary secret for this backup session
            update_option('sbwp_backup_secret', wp_generate_password(32, false));

            $this->session_id = $session_id;
            $tables = $this->get_tables();
            $files = []; // Files scanned in ZIP_SCAN phase

            $state = array(
                'step' => 'DB',
                'tables' => $tables,
                'current_table_index' => 0,
                'current_cursor' => 0,
                'current_pk' => null,
                'rows_exported' => 0,
                'folder_path' => $folder_path,
                'sql_chunk_index' => 1,
                'sql_chunk_bytes' => 0,
                'files' => $files,
                'file_offset' => 0,
                'zip_path' => $folder_path . '/backup.zip',
                'start_time' => time(),
                'type' => $type,
                'session_id' => $session_id // Persist Session ID
            );

            $this->update_progress(0, 'Starting backup...');
            update_option('sbwp_backup_batch_state', $state, 'no'); // Save immediately

            // Kick off the background process
            $this->spawn_next_step();
            return array('success' => true, 'folder' => $backup_folder);
        }

        // If resuming via REST API (fallback), we just process one batch
        return $this->process_batch();
    }

    /**
     * Spawns a non-blocking request to continue processing
     */
    public function spawn_next_step()
    {
        sleep(1);
        $url = admin_url('admin-ajax.php');
        $args = array(
            'body' => array(
                'action' => 'sbwp_process_backup',
                'secret' => get_option('sbwp_backup_secret'), // Start simple
            ),
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => false
        );
        error_log("SBWP Spawn: Spawning next step via " . $url . " Blocking=FALSE");
        $result = wp_remote_post($url, $args);

        if (is_wp_error($result)) {
            error_log("SBWP Spawn Error: " . $result->get_error_message());
        } else {
            error_log("SBWP Spawn Success (Async).");
        }
    }

    /**
     * Process a single batch of work
     */
    public function process_batch()
    {
        @set_time_limit(300);
        wp_cache_delete('sbwp_backup_batch_state', 'options');
        $state = get_option('sbwp_backup_batch_state');

        if (!$state) {
            return array('status' => 'error', 'message' => 'No active backup state');
        }

        // Prevent concurrent batch execution
        $lock = get_transient('sbwp_batch_lock');
        if ($lock) {
            error_log("SBWP: Batch execution blocked by lock.");
            return array('status' => 'locked', 'message' => 'Batch locked');
        }
        set_transient('sbwp_batch_lock', true, 60);

        $this->session_id = $state['session_id'] ?? null; // Load session ID
        $this->debug("Batch Start: Step={$state['step']} Offset=" . ($state['file_offset'] ?? 0) . " SID=" . ($this->session_id ?? 'NULL'));

        $start_time = microtime(true);
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
                $msg = "Exporting $tbl (" . number_format($state['rows_exported']) . " rows)";
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
                // Inform user scan is starting (can take time)
                $this->update_progress(45, 'Scanning files for backup...');

                // Scan files (full or incremental)
                if (in_array($state['type'], ['full', 'incremental'])) {
                    $is_incremental = ($state['type'] === 'incremental');
                    $count = $this->scan_files($state['folder_path'], $is_incremental);
                    $state['total_files'] = $count;
                    $state['step'] = 'ZIP';
                    error_log("SBWP Files Scanned: $count" . ($is_incremental ? ' (incremental)' : ''));
                } else {
                    $state['step'] = 'FINISH';
                }
            } elseif ($state['step'] === 'ZIP') {
                $this->debug("Processing ZIP batch. Offset: " . $state['file_offset']);
                error_log("SBWP Trace: Processing ZIP batch. Offset: " . $state['file_offset']);
                $complete = $this->process_zip_batch($state, $start_time);
                if ($complete) {
                    $this->debug("ZIP chunk absolute complete. Moving to FINISH.");
                    $state['step'] = 'FINISH';
                }
            } elseif ($state['step'] === 'FINISH') {
                $this->debug("Starting FINISH phase.");
                $this->finalize_backup($state);
                delete_option('sbwp_backup_batch_state');
                $this->update_progress(100, 'Backup Complete', true);
                error_log("SBWP Trace: Finished. Returning completed.");
                $this->debug("FINISH Phase Done.");
                delete_transient('sbwp_batch_lock'); // Release lock
                return array('status' => 'completed');
            }
        }

        // Save state and return partial
        $saved = update_option('sbwp_backup_batch_state', $state, 'no');
        wp_cache_delete('sbwp_backup_batch_state', 'options');
        delete_transient('sbwp_batch_lock'); // Release lock

        // Spawn next background process
        $this->spawn_next_step();

        error_log("SBWP Loop End: Count=$loop_count Saved=" . ($saved ? 'YES' : 'NO') . " Offset=" . ($state['step'] === 'DB' ? $state['current_row_offset'] : 'N/A'));

        $msg = 'Processing...';
        if ($state['step'] === 'DB') {
            $tbl = isset($state['tables'][$state['current_table_index']]) ? $state['tables'][$state['current_table_index']] : 'Database';
            $msg = "Exporting $tbl (" . number_format($state['rows_exported']) . " rows)";
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

    /**
     * Cancel the current backup
     */
    public function cancel_backup()
    {
        $state = get_option('sbwp_backup_batch_state');
        if (!$state) {
            return array('status' => 'error', 'message' => 'No active backup to cancel');
        }

        // 1. Delete temp directory
        if (isset($state['folder_path']) && file_exists($state['folder_path'])) {
            $this->recursive_delete($state['folder_path']);
        }

        // 2. Cleanup options
        delete_option('sbwp_backup_batch_state');
        delete_option('sbwp_backup_secret');
        wp_cache_delete('sbwp_backup_batch_state', 'options');

        error_log("SBWP: Backup Cancelled by User");
        return array('status' => 'cancelled');
    }

    private function recursive_delete($dir)
    {
        if (!is_dir($dir))
            return;
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->recursive_delete("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    /**
     * Get list of tables
     */
    private function get_tables()
    {
        global $wpdb;
        $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
        return array_map(function ($t) {
            return $t[0];
        }, $tables);
    }

    /**
     * Get primary key column for a table
     */
    private function get_primary_key($table)
    {
        if (isset($this->pk_cache[$table])) {
            return $this->pk_cache[$table];
        }

        global $wpdb;
        $result = $wpdb->get_row("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'", ARRAY_A);

        $pk = $result ? $result['Column_name'] : null;
        $this->pk_cache[$table] = $pk;

        return $pk;
    }

    /**
     * Get current SQL chunk file path
     */
    private function get_sql_chunk_path($state)
    {
        $index = str_pad($state['sql_chunk_index'], 3, '0', STR_PAD_LEFT);
        return $state['folder_path'] . "/database-{$index}.sql";
    }

    /**
     * Get current ZIP chunk file path
     */
    private function get_zip_chunk_path($state)
    {
        $index = str_pad($state['zip_chunk_index'], 3, '0', STR_PAD_LEFT);
        return $state['folder_path'] . "/files-{$index}.zip";
    }

    private function process_db_batch(&$state, $start_time)
    {
        global $wpdb;
        $table_idx = $state['current_table_index'];

        if ($table_idx >= count($state['tables']))
            return true; // All tables done

        $table = $state['tables'][$table_idx];
        $cursor = $state['current_cursor'];
        $sql_file = $this->get_sql_chunk_path($state);

        // Get primary key for cursor-based pagination
        $pk = $state['current_pk'];
        if ($pk === null) {
            $pk = $this->get_primary_key($table);
            $state['current_pk'] = $pk;
        }

        $handle = fopen($sql_file, 'a+');
        if (!$handle) {
            error_log("SBWP Error: Could not open SQL file for writing: $sql_file");
            return true; // Skip table to avoid infinite loop
        }

        // Write header for first chunk, first table
        if ($cursor === 0) {
            if ($table_idx === 0 && $state['sql_chunk_index'] === 1) {
                fwrite($handle, "-- SafeBackup Dump (Chunked)\n");
                fwrite($handle, "-- Generated: " . current_time('mysql') . "\n\n");
            }
            fwrite($handle, "-- Table: $table\n");
            fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
            $create_row = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            fwrite($handle, $create_row[1] . ";\n\n");
        }

        // Use cursor-based pagination if table has a primary key
        if ($pk) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `$table` WHERE `$pk` > %s ORDER BY `$pk` ASC LIMIT %d",
                    $cursor,
                    $this->batch_limit
                ),
                ARRAY_A
            );
        } else {
            // Fallback to OFFSET for tables without PK (rare but possible)
            $rows = $wpdb->get_results(
                "SELECT * FROM `$table` LIMIT {$this->batch_limit} OFFSET $cursor",
                ARRAY_A
            );
        }

        if ($rows) {
            $bytes_written = 0;
            foreach ($rows as $row) {
                $values = array_map(function ($v) use ($wpdb) {
                    return $v === null ? 'NULL' : "'" . $wpdb->_real_escape($v) . "'";
                }, array_values($row));
                $values_str = implode(", ", $values);
                $line = "INSERT INTO `$table` VALUES ($values_str);\n";
                fwrite($handle, $line);
                $bytes_written += strlen($line);
            }
            fwrite($handle, "\n");

            // Update cursor to last processed PK value
            $last_row = end($rows);
            if ($pk && isset($last_row[$pk])) {
                $state['current_cursor'] = $last_row[$pk];
            } else {
                $state['current_cursor'] += count($rows);
            }

            $state['rows_exported'] += count($rows);
            $state['sql_chunk_bytes'] += $bytes_written;

            // Check if we need to start a new chunk
            if ($state['sql_chunk_bytes'] >= $this->chunk_size_bytes) {
                fclose($handle);
                $state['sql_chunk_index']++;
                $state['sql_chunk_bytes'] = 0;
                error_log("SBWP: Starting new SQL chunk {$state['sql_chunk_index']}");
                return false; // Continue processing
            }

            // Real-time update (Throttled by update_progress)
            $tbl = $state['tables'][$state['current_table_index']];
            $msg = "Exporting $tbl (" . number_format($state['rows_exported']) . " rows)";
            $this->update_progress($this->calculate_percent($state), $msg);

        } else {
            // No more rows, move to next table
            error_log("SBWP DB Batch: Table=$table DONE. Total rows: " . $state['rows_exported']);

            $msg = "Exporting $table (Done)";
            $this->update_progress($this->calculate_percent($state), $msg); // DO NOT FORCE (let throttle handle it)

            $state['current_table_index']++;
            $state['current_cursor'] = 0;
            $state['current_pk'] = null; // Reset for next table
        }

        fclose($handle);
        return false;
    }

    private function scan_files($backup_dir_path, $is_incremental = false)
    {
        $root_path = WP_CONTENT_DIR;
        $file_list_path = $backup_dir_path . '/file_list.json';
        $checksums_path = $backup_dir_path . '/file_checksums.json';

        // Load previous checksums for incremental backup
        $prev_checksums = [];
        if ($is_incremental) {
            $prev_checksums = $this->get_last_backup_checksums();
        }

        // Open file for writing
        $handle = fopen($file_list_path, 'w');
        $checksums_handle = fopen($checksums_path, 'w');
        if (!$handle || !$checksums_handle)
            return 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root_path),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $count = 0;
        $checksums_data = [];
        fwrite($handle, "[\n");
        $first = true;

        foreach ($iterator as $name => $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();

                // Exclude backup directories and other large/unnecessary folders
                // Primary backup directories
                if (strpos($file_path, $this->backup_dir) !== false)
                    continue;
                if (strpos($file_path, 'safebackup') !== false)
                    continue;
                if (strpos($file_path, 'sbwp-backups') !== false)
                    continue;
                if (strpos($file_path, 'sbwp-clones') !== false)
                    continue;

                // Common backup folder patterns (other backup plugins)
                if (preg_match('/\/backup-\d{4}-\d{2}-\d{2}/', $file_path))
                    continue;
                if (strpos($file_path, 'updraft') !== false)
                    continue;
                if (strpos($file_path, 'backwpup') !== false)
                    continue;
                if (strpos($file_path, 'ai1wm-backups') !== false)
                    continue;

                // Development/build directories
                if (strpos($file_path, 'node_modules') !== false)
                    continue;
                if (strpos($file_path, '.git') !== false)
                    continue;
                if (strpos($file_path, 'cache') !== false)
                    continue;

                /**
                 * Filter: sbwp_backup_exclusions
                 * Allows users/developers to add custom exclusion patterns.
                 * 
                 * @param array  $exclusions Array of substrings to match in file paths
                 * @param string $file_path  The current file being evaluated
                 * @return array Modified exclusions array
                 */
                $custom_exclusions = apply_filters('sbwp_backup_exclusions', [], $file_path);
                $should_exclude = false;
                foreach ($custom_exclusions as $pattern) {
                    if (strpos($file_path, $pattern) !== false) {
                        $should_exclude = true;
                        break;
                    }
                }
                if ($should_exclude)
                    continue;

                $file_size = $file->getSize();
                $file_mtime = $file->getMTime();

                // For incremental: check if file changed
                if ($is_incremental && isset($prev_checksums[$file_path])) {
                    $prev = $prev_checksums[$file_path];
                    // Skip if size and mtime match (file unchanged)
                    if ($prev['size'] == $file_size && $prev['mtime'] == $file_mtime) {
                        continue;
                    }
                }

                // Store checksum data for later
                $checksums_data[$file_path] = [
                    'size' => $file_size,
                    'mtime' => $file_mtime
                ];

                // Write path to JSON
                if (!$first)
                    fwrite($handle, ",\n");
                fwrite($handle, json_encode($file_path));
                $first = false;
                $count++;

                $this->update_progress(45, "Scanning files... Found $count" . ($is_incremental ? ' changed' : ''));
            }
        }

        fwrite($handle, "\n]");
        fclose($handle);

        // Write checksums for this backup
        fwrite($checksums_handle, json_encode($checksums_data));
        fclose($checksums_handle);

        return $count;
    }

    /**
     * Get checksums from the last completed backup
     */
    private function get_last_backup_checksums()
    {
        if ($this->last_checksums !== null) {
            return $this->last_checksums;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sb_file_checksums';
        $backups_table = $wpdb->prefix . 'sb_backups';

        // Get latest completed backup ID
        $last_backup = $wpdb->get_var(
            "SELECT id FROM $backups_table WHERE status = 'completed' AND type IN ('full', 'incremental') ORDER BY created_at DESC LIMIT 1"
        );

        if (!$last_backup) {
            $this->last_checksums = [];
            return [];
        }

        // Get checksums for that backup
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT file_path, file_size, modified_time FROM $table WHERE backup_id = %d", $last_backup),
            ARRAY_A
        );

        $checksums = [];
        foreach ($rows as $row) {
            $checksums[$row['file_path']] = [
                'size' => (int) $row['file_size'],
                'mtime' => (int) $row['modified_time']
            ];
        }

        $this->last_checksums = $checksums;
        return $checksums;
    }

    /**
     * Store file checksums for this backup
     */
    private function store_checksums($backup_id, $folder_path)
    {
        $checksums_file = $folder_path . '/file_checksums.json';
        if (!file_exists($checksums_file)) {
            return;
        }

        $checksums = json_decode(file_get_contents($checksums_file), true);
        if (!$checksums) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sb_file_checksums';

        // Batch insert for efficiency
        $values = [];
        foreach ($checksums as $path => $data) {
            $values[] = $wpdb->prepare(
                "(%d, %s, %d, %d)",
                $backup_id,
                $path,
                $data['size'],
                $data['mtime']
            );

            // Insert in batches of 100
            if (count($values) >= 100) {
                $wpdb->query(
                    "INSERT INTO $table (backup_id, file_path, file_size, modified_time) VALUES " . implode(',', $values)
                );
                $values = [];
            }
        }

        // Insert remaining
        if (!empty($values)) {
            $wpdb->query(
                "INSERT INTO $table (backup_id, file_path, file_size, modified_time) VALUES " . implode(',', $values)
            );
        }
    }

    private function process_zip_batch(&$state, $start_time)
    {
        $zip_file = $this->get_zip_chunk_path($state);
        $json_file = $state['folder_path'] . '/file_list.json';

        $zip = new ZipArchive();
        $res = $zip->open($zip_file, ZipArchive::CREATE);

        if ($res !== TRUE) {
            return true;
        }

        if (!file_exists($json_file)) {
            return true;
        }

        $json_content = file_get_contents($json_file);
        $file_list = json_decode($json_content, true);

        if (!$file_list) {
            return true;
        }


        $count = 0;
        $max_files_per_batch = 500; // Force return after this many to show progress
        $total_files = count($file_list);
        $idx = $state['file_offset'];
        $chunk_needs_close = false;

        while ($idx < $total_files && $count < $max_files_per_batch && (microtime(true) - $start_time < $this->time_limit)) {
            $file_path = $file_list[$idx];


            // Validate file still exists
            if (file_exists($file_path)) {
                $file_size = filesize($file_path);

                // Check if adding this file would exceed chunk limit
                if ($state['zip_chunk_bytes'] > 0 && ($state['zip_chunk_bytes'] + $file_size) > $this->chunk_size_bytes) {
                    // Close current chunk and start a new one
                    $zip->close();
                    $state['zip_chunk_index']++;
                    $state['zip_chunk_bytes'] = 0;
                    error_log("SBWP: Starting new ZIP chunk {$state['zip_chunk_index']}");

                    // Open new ZIP file
                    $zip_file = $this->get_zip_chunk_path($state);
                    $zip = new ZipArchive();
                    $res = $zip->open($zip_file, ZipArchive::CREATE);
                    if ($res !== TRUE) {
                        error_log("SBWP Error: Could not open new ZIP chunk. Code: $res");
                        return true;
                    }
                }

                $relative_path = substr($file_path, strlen(WP_CONTENT_DIR) + 1);
                $zip->addFile($file_path, $relative_path);
                $state['zip_chunk_bytes'] += $file_size;
            }

            $idx++;
            $count++;

            // Update progress every 200 files (tuned for performance without throttle)
            if ($idx % 200 === 0) {
                $state['file_offset'] = $idx;
                $pct = $this->calculate_percent($state);
                $this->update_progress($pct, "Archiving files ($idx / $total_files)");
            }
        }

        $state['file_offset'] = $idx;
        $close_res = $zip->close();

        return ($idx >= $total_files);
    }

    private function finalize_backup($state)
    {
        $this->debug("Finalize Backup Called.");
        $this->update_progress(90, 'Finalizing...', true);

        $files = array();

        // Collect all SQL chunk files
        for ($i = 1; $i <= ($state['sql_chunk_index'] ?? 1); $i++) {
            $chunk_file = $state['folder_path'] . '/database-' . str_pad($i, 3, '0', STR_PAD_LEFT) . '.sql';
            if (file_exists($chunk_file)) {
                $files[] = $chunk_file;
            }
        }

        if ($state['type'] === 'full') {
            // Collect all ZIP chunk files
            for ($i = 1; $i <= ($state['zip_chunk_index'] ?? 1); $i++) {
                $chunk_file = $state['folder_path'] . '/files-' . str_pad($i, 3, '0', STR_PAD_LEFT) . '.zip';
                if (file_exists($chunk_file)) {
                    $files[] = $chunk_file;
                }
            }
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
        $backup_id = $wpdb->insert_id;

        // Store file checksums for incremental backups
        if (in_array($state['type'], ['full', 'incremental'])) {
            $this->store_checksums($backup_id, $state['folder_path']);
        }

        // If this is an incremental backup AND incremental is enabled in settings, lock the full
        if ($state['type'] === 'incremental') {
            $settings = get_option('sbwp_settings', array());
            if (!empty($settings['incremental_enabled'])) {
                $this->lock_most_recent_full_backup();
            }
        }

        // Trigger Cloud Sync if configured
        do_action('sbwp_backup_completed', $backup_id);

        $this->cleanup_old_backups();
    }

    /**
     * Lock the most recent full backup to prevent deletion
     */
    private function lock_most_recent_full_backup()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sb_backups';

        // Find the most recent full backup that's not already locked
        $full_backup = $wpdb->get_row(
            "SELECT id FROM $table_name WHERE type = 'full' AND status = 'completed' ORDER BY created_at DESC LIMIT 1"
        );

        if ($full_backup) {
            $wpdb->update(
                $table_name,
                array('is_locked' => 1),
                array('id' => $full_backup->id)
            );
        }
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

    private $last_update_time = 0;

    private function update_progress($percent, $message, $force = false)
    {
        $now = microtime(true);

        // GUARD: Don't let zombie processes overwrite completed backup
        // But allow NEW backups (different session_id) to overwrite old 100%
        if ($percent < 100) {
            wp_cache_delete('sbwp_backup_progress', 'options');
            $current = get_option('sbwp_backup_progress');
            if ($current && isset($current['percent']) && $current['percent'] >= 100) {
                // Only block if it's the SAME session trying to overwrite itself
                if (isset($current['session_id']) && $current['session_id'] === $this->session_id) {
                    $this->debug("BLOCKED: Zombie tried to write $percent% over 100%");
                    return; // Backup already completed, don't overwrite!
                }
                // Different session = new backup, allow overwrite
            }
        }

        // Always update on 100%, if forced, or if 0.5 seconds has passed
        if ($percent >= 100 || $force || ($now - $this->last_update_time) > 0.5) {
            $saved = update_option('sbwp_backup_progress', array(
                'percent' => $percent,
                'message' => $message,
                'active' => $percent < 100, // Explicit active flag
                'session_id' => $this->session_id
            ), 'no');
            wp_cache_delete('sbwp_backup_progress', 'options'); // Force fresh reads
            $this->last_update_time = $now;
            $this->debug("Progress: $percent% - Msg='$message' - SID={$this->session_id} - Saved=" . ($saved ? 'YES' : 'NO'));
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
            $wpdb->prepare("SELECT id, file_path_local, is_locked FROM $table_name ORDER BY created_at DESC LIMIT 100 OFFSET %d", $this->retention_limit)
        );

        if ($backups) {
            foreach ($backups as $backup) {
                // Skip locked backups (required for incremental restores)
                if (!empty($backup->is_locked)) {
                    continue;
                }
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

    private function debug($msg)
    {
        // Debug logging disabled for production
        return;
    }
}
