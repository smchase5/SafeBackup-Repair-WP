<?php
/**
 * SafeBackup Standalone Recovery Portal
 * 
 * This file works 100% independently - NO WordPress required.
 * Access directly: /wp-content/plugins/SafeBackup-Repair-WP/recovery.php?key=YOUR_KEY
 */

// Prevent direct output buffering issues
ob_start();

// ============================================================================
// CONFIGURATION
// ============================================================================

define('SBWP_RECOVERY_VERSION', '1.0.0');

// Find WordPress paths by walking up from this file
$plugin_dir = dirname(__FILE__);
$plugins_dir = dirname($plugin_dir);
$wp_content_dir = dirname($plugins_dir);
$wp_root = dirname($wp_content_dir);

// Secure storage directory (in uploads)
$secure_dir = $wp_content_dir . '/uploads/sbwp-secure';

// Ensure secure directory exists with protection
if (!file_exists($secure_dir)) {
    @mkdir($secure_dir, 0755, true);
    @file_put_contents($secure_dir . '/.htaccess', "Order deny,allow\nDeny from all");
    @file_put_contents($secure_dir . '/index.php', '<?php // Silence is golden.');
}

// Key file locations (in secure uploads directory)
$key_file = $secure_dir . '/recovery-key';
$pin_file = $secure_dir . '/recovery-pin';
$ai_key_file = $secure_dir . '/ai-key';

// Legacy file locations (in plugin directory) - for migration
$legacy_key_file = $plugin_dir . '/.sbwp-recovery-key';
$legacy_pin_file = $plugin_dir . '/.sbwp-recovery-pin';
$legacy_ai_key_file = $plugin_dir . '/.sbwp-ai-key';

// Migrate legacy files if they exist
function sbwp_migrate_legacy_file($old_path, $new_path)
{
    if (file_exists($old_path) && !file_exists($new_path)) {
        @copy($old_path, $new_path);
        @chmod($new_path, 0600);
        @unlink($old_path);
    }
}
sbwp_migrate_legacy_file($legacy_key_file, $key_file);
sbwp_migrate_legacy_file($legacy_pin_file, $pin_file);
sbwp_migrate_legacy_file($legacy_ai_key_file, $ai_key_file);

// ============================================================================
// AUTHENTICATION
// ============================================================================

function sbwp_get_recovery_key()
{
    global $key_file;
    if (file_exists($key_file)) {
        return trim(file_get_contents($key_file));
    }
    return '';
}

function sbwp_generate_recovery_key()
{
    global $key_file;
    $key = bin2hex(random_bytes(16));
    file_put_contents($key_file, $key);
    chmod($key_file, 0600); // Secure permissions
    return $key;
}

function sbwp_get_pin_hash()
{
    global $pin_file;
    if (file_exists($pin_file)) {
        return trim(file_get_contents($pin_file));
    }
    return '';
}

function sbwp_verify_pin($provided_pin, $stored_hash)
{
    return password_verify($provided_pin, $stored_hash);
}

function sbwp_set_pin($pin)
{
    global $pin_file;
    if (empty($pin)) {
        if (file_exists($pin_file)) {
            unlink($pin_file);
        }
        return true;
    }
    $hash = password_hash($pin, PASSWORD_DEFAULT);
    file_put_contents($pin_file, $hash);
    chmod($pin_file, 0600);
    return true;
}

// Ensure key exists
$stored_key = sbwp_get_recovery_key();
if (empty($stored_key)) {
    $stored_key = sbwp_generate_recovery_key();
}

function sbwp_get_ai_key()
{
    global $ai_key_file;
    if (file_exists($ai_key_file))
        return trim(file_get_contents($ai_key_file));
    return '';
}

/**
 * Connect to WordPress database directly (parsing wp-config.php)
 */
function sbwp_connect_db()
{
    global $wp_root;

    $config_path = $wp_root . '/wp-config.php';
    if (!file_exists($config_path)) {
        return false;
    }

    $config_content = file_get_contents($config_path);

    // Extract database credentials
    preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"](.+?)['\"]\s*\)/", $config_content, $db_name);
    preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"](.+?)['\"]\s*\)/", $config_content, $db_name);
    preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"](.+?)['\"]\s*\)/", $config_content, $db_user);
    preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"](.*)['\"]\s*\)/", $config_content, $db_pass);
    preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"](.+?)['\"]\s*\)/", $config_content, $db_host);
    preg_match("/\\\$table_prefix\s*=\s*['\"](.+?)['\"]/", $config_content, $prefix);

    if (empty($db_name[1]) || empty($db_user[1]) || empty($db_host[1])) {
        return false;
    }

    // Parse DB_HOST for port or socket
    $host = $db_host[1];
    $port = null;
    $socket = null;

    if (strpos($host, ':') !== false) {
        $parts = explode(':', $host, 2);
        if (is_numeric($parts[1])) {
            $host = $parts[0];
            $port = (int) $parts[1];
        } else {
            // It's a socket
            $host = $parts[0];
            $socket = $parts[1];
        }
    }

    try {
        $mysqli = new mysqli($host, $db_user[1], $db_pass[1] ?? '', $db_name[1], $port, $socket);
        if ($mysqli->connect_error) {
            return false;
        }
        return ['conn' => $mysqli, 'prefix' => $prefix[1] ?? 'wp_'];
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get WordPress site URL from database (standalone, no WP required)
 */
function sbwp_get_site_url()
{
    global $wp_root;

    // Try to connect to DB
    $db = sbwp_connect_db();
    if (!$db) {
        return '';
    }

    try {
        $mysqli = $db['conn'];
        $prefix = $db['prefix'];

        // Get siteurl option
        $table = $prefix . 'options';
        $result = $mysqli->query("SELECT option_value FROM $table WHERE option_name = 'siteurl' LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $mysqli->close();
            return rtrim($row['option_value'], '/');
        }
        $mysqli->close();
    } catch (Exception $e) {
        // Silently fail
    }

    return '';
}


/**
 * Get active plugins list from DB
 */
function sbwp_get_active_plugins()
{
    $db = sbwp_connect_db();
    if (!$db)
        return [];

    $conn = $db['conn'];
    $prefix = $db['prefix'];
    $table = $prefix . 'options';

    $result = $conn->query("SELECT option_value FROM $table WHERE option_name = 'active_plugins' LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        $data = unserialize($row['option_value']);
        $conn->close();
        return is_array($data) ? $data : [];
    }
    $conn->close();
    return [];
}

/**
 * Update active plugins list in DB
 */
function sbwp_update_active_plugins($active_plugins)
{
    if (!is_array($active_plugins))
        return false;

    // Ensure array is indexed correctly (0, 1, 2...)
    $active_plugins = array_values(array_unique($active_plugins));
    sort($active_plugins);

    $db = sbwp_connect_db();
    if (!$db)
        return false;

    $conn = $db['conn'];
    $prefix = $db['prefix'];
    $table = $prefix . 'options';

    $new_value = serialize($active_plugins);
    $stmt = $conn->prepare("UPDATE $table SET option_value = ? WHERE option_name = 'active_plugins'");
    $stmt->bind_param('s', $new_value);
    $success = $stmt->execute();
    $stmt->close();
    $conn->close();

    return $success;
}

/**
 * Get main plugin file for a folder
 */
function sbwp_get_plugin_main_file($folder, $plugins_dir)
{
    $dir = $plugins_dir . '/' . $folder;
    if (!is_dir($dir))
        return false;

    // Standard location: folder/folder.php
    if (file_exists($dir . '/' . $folder . '.php')) {
        return $folder . '/' . $folder . '.php';
    }

    // Search for header
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    // Limit depth and file count for performance
    $count = 0;
    foreach ($iterator as $file) {
        if ($count++ > 50)
            break; // Only check first 50 files

        if ($file->getExtension() === 'php') {
            $content = @file_get_contents($file->getPathname(), false, null, 0, 8192); // Read first 8kb only
            if ($content && stripos($content, 'Plugin Name:') !== false) {
                return $folder . '/' . $file->getFilename();
            }
        }
    }

    return false;
}

function sbwp_call_openai($error_msg, $key)
{
    $url = 'https://api.openai.com/v1/chat/completions';
    $data = [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => 'Explain this PHP error in 1 simple sentence for a non-technical user. Do NOT suggest a fix or solution. Just explain what the error means in plain English. Example: "A plugin tried to use a private function that it cannot access."'],
            ['role' => 'user', 'content' => $error_msg]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key
    ]);
    // Add timeouts to prevent hanging
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 seconds to connect
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60 seconds max total

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return 'Connection error: ' . $error;
    }

    $json = json_decode($response, true);
    return $json['choices'][0]['message']['content'] ?? 'Error calling AI: ' . ($json['error']['message'] ?? 'Unknown error');
}

// Check authentication
$provided_key = $_GET['key'] ?? '';
$is_authenticated = !empty($provided_key) && hash_equals($stored_key, $provided_key);

if (!$is_authenticated) {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><title>Access Denied</title><style>body{font-family:system-ui;background:#0f172a;color:#f1f5f9;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}.box{background:#1e293b;padding:2rem;border-radius:1rem;text-align:center;max-width:400px}h1{margin:0 0 1rem;color:#ef4444}</style></head><body><div class="box"><h1>🔒 Access Denied</h1><p>Invalid or missing recovery key.</p><p style="color:#64748b;font-size:0.875rem">Check your SafeBackup settings for the correct recovery URL.</p></div></body></html>');
}

// Check PIN if set
$stored_pin_hash = sbwp_get_pin_hash();
if (!empty($stored_pin_hash)) {
    $provided_pin = $_GET['pin'] ?? $_POST['pin'] ?? '';
    if (empty($provided_pin)) {
        // Show PIN form
        ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Recovery Portal - Enter PIN</title>
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
                    <input type="hidden" name="key" value="<?php echo htmlspecialchars($provided_key); ?>">
                    <input type="password" name="pin" placeholder="Enter PIN" autofocus required>
                    <button type="submit">Access Recovery Portal</button>
                </form>
            </div>
        </body>

        </html>
        <?php
        exit;
    }

    if (!sbwp_verify_pin($provided_pin, $stored_pin_hash)) {
        http_response_code(403);
        die('<!DOCTYPE html><html><head><title>Invalid PIN</title><style>body{font-family:system-ui;background:#0f172a;color:#f1f5f9;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}.box{background:#1e293b;padding:2rem;border-radius:1rem;text-align:center}h1{color:#ef4444}</style></head><body><div class="box"><h1>Invalid PIN</h1><p>The PIN you entered is incorrect.</p></div></body></html>');
    }
}

// ============================================================================
// API HANDLERS
// ============================================================================

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');

    switch ($action) {

        case 'get_plugins':
            $plugins = [];
            $dirs = glob($plugins_dir . '/*', GLOB_ONLYDIR);

            $active_plugins_db = sbwp_get_active_plugins(); // Get ['folder/file.php', ...]

            foreach ($dirs as $dir) {
                $basename = basename($dir);
                // Skip disabled folders if they still exist from legacy
                if (substr($basename, -9) === '.disabled')
                    continue;

                $main_file = sbwp_get_plugin_main_file($basename, $plugins_dir); // Returns 'folder/file.php'

                // Get header info
                $display_name = $basename;
                if ($main_file && file_exists($plugins_dir . '/' . $main_file)) {
                    $content = file_get_contents($plugins_dir . '/' . $main_file);
                    if (preg_match('/Plugin Name:\s*(.+)/i', $content, $m)) {
                        $display_name = trim($m[1]);
                    }
                }

                // Check if active in DB
                $is_active = false;
                if ($main_file && in_array($main_file, $active_plugins_db)) {
                    $is_active = true;
                }

                $plugins[] = [
                    'folder' => $basename,
                    'name' => $display_name,
                    'slug' => $basename,
                    'disabled' => !$is_active, // Renaming key to 'active'? No, keep 'disabled' for frontend compat
                    'is_self' => (strpos($basename, 'SafeBackup') !== false)
                ];
            }
            echo json_encode(['success' => true, 'plugins' => $plugins]);
            exit;

        case 'toggle_plugin':
            $folder = $_POST['folder'] ?? '';
            $new_state = $_POST['state'] ?? ''; // 'enable' or 'disable'

            if (empty($folder)) {
                echo json_encode(['success' => false, 'error' => 'Missing folder']);
                exit;
            }

            $current_path = $plugins_dir . '/' . $folder;
            if (!is_dir($current_path)) {
                echo json_encode(['success' => false, 'error' => 'Plugin not found']);
                exit;
            }

            // Get main file for DB op
            $main_file = sbwp_get_plugin_main_file($folder, $plugins_dir);
            if (!$main_file) {
                echo json_encode(['success' => false, 'error' => 'Could not identify plugin file']);
                exit;
            }

            $active_plugins = sbwp_get_active_plugins();
            $is_active = in_array($main_file, $active_plugins);

            if ($new_state === 'disable' && $is_active) {
                // Remove from list
                $active_plugins = array_diff($active_plugins, [$main_file]);
                if (sbwp_update_active_plugins($active_plugins)) {
                    echo json_encode(['success' => true, 'new_folder' => $folder]); // Return folder, UI will refresh list
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to update database']);
                }
            } elseif ($new_state === 'enable' && !$is_active) {
                // Add to list
                $active_plugins[] = $main_file;
                if (sbwp_update_active_plugins($active_plugins)) {
                    echo json_encode(['success' => true, 'new_folder' => $folder]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to update database']);
                }
            } else {
                // Already in desired state
                echo json_encode(['success' => true, 'new_folder' => $folder]);
            }
            exit;

        case 'get_debug_log':
            $log_content = "";

            // 1. Read Flight Recorder Log
            $flight_log = $secure_dir . '/crash.log';
            if (file_exists($flight_log)) {
                $log_content .= "==================================================\n";
                $log_content .= "✈️ FLIGHT RECORDER (SafeBackup Internal Crash Log)\n";
                $log_content .= "   Catches fatal errors even if WP_DEBUG is OFF.\n";
                $log_content .= "==================================================\n";

                $lines = file($flight_log);
                $log_content .= implode("", array_slice($lines, -20)); // Last 20 crashes
                $log_content .= "\n\n";
            } else {
                $log_content .= "No Flight Recorder data found yet.\n\n";
            }

            // 2. Read Standard WP Debug Log
            $log_path = $wp_content_dir . '/debug.log';
            if (file_exists($log_path)) {
                $log_content .= "==================================================\n";
                $log_content .= "🐛 WORDPRESS DEBUG LOG (wp-content/debug.log)\n";
                $log_content .= "==================================================\n";

                // Read last 100 lines
                $lines = [];
                $fp = fopen($log_path, 'r');
                if ($fp) {
                    fseek($fp, -min(filesize($log_path), 50000), SEEK_END);
                    while (!feof($fp)) {
                        $lines[] = fgets($fp);
                    }
                    fclose($fp);
                }
                $log_content .= implode('', array_slice($lines, -100));
            } else {
                $log_content .= "Standard debug.log not found.\n";
            }

            echo json_encode([
                'success' => true,
                'content' => $log_content,
                'size' => strlen($log_content)
            ]);
            exit;

        case 'get_themes':
            $themes_dir = $wp_content_dir . '/themes';
            $themes = [];
            $dirs = glob($themes_dir . '/*', GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                $basename = basename($dir);
                $is_disabled = substr($basename, -9) === '.disabled';
                $name = $is_disabled ? substr($basename, 0, -9) : $basename;

                $display_name = $name;
                $style_file = $dir . '/style.css';
                if (file_exists($style_file)) {
                    $content = file_get_contents($style_file);
                    if (preg_match('/Theme Name:\s*(.+)/i', $content, $m)) {
                        $display_name = trim($m[1]);
                    }
                }

                $themes[] = [
                    'folder' => $basename,
                    'name' => $display_name,
                    'disabled' => $is_disabled
                ];
            }
            echo json_encode(['success' => true, 'themes' => $themes]);
            exit;

        case 'toggle_theme':
            $folder = $_POST['folder'] ?? '';
            $new_state = $_POST['state'] ?? '';
            $themes_dir = $wp_content_dir . '/themes';

            if (empty($folder)) {
                echo json_encode(['success' => false, 'error' => 'Missing folder']);
                exit;
            }

            $current_path = $themes_dir . '/' . $folder;
            if (!is_dir($current_path)) {
                echo json_encode(['success' => false, 'error' => 'Theme not found']);
                exit;
            }

            $is_disabled = substr($folder, -9) === '.disabled';

            if ($new_state === 'disable' && !$is_disabled) {
                $new_path = $current_path . '.disabled';
                rename($current_path, $new_path);
                echo json_encode(['success' => true, 'new_folder' => basename($new_path)]);
            } elseif ($new_state === 'enable' && $is_disabled) {
                $new_path = substr($current_path, 0, -9);
                rename($current_path, $new_path);
                echo json_encode(['success' => true, 'new_folder' => basename($new_path)]);
            } else {
                echo json_encode(['success' => true, 'new_folder' => $folder]);
            }
            exit;


        case 'clear_logs':
            // Clear/archive the debug logs
            $cleared = [];

            // Clear flight recorder
            $flight_log = $secure_dir . '/crash.log';
            if (file_exists($flight_log)) {
                $archive = $flight_log . '.archived.' . date('Y-m-d-H-i-s');
                if (rename($flight_log, $archive)) {
                    $cleared[] = 'flight_recorder';
                }
            }

            // Clear debug.log
            $log_path = $wp_content_dir . '/debug.log';
            if (file_exists($log_path)) {
                $archive = $log_path . '.archived.' . date('Y-m-d-H-i-s');
                if (rename($log_path, $archive)) {
                    $cleared[] = 'debug_log';
                }
            }

            echo json_encode([
                'success' => true,
                'cleared' => $cleared,
                'message' => 'Logs cleared. Old logs archived with timestamp.'
            ]);
            exit;

        case 'scan_crash':
            $log_excerpt = '';
            $debug_info = [];
            $cutoff_time = time() - 3600; // Only include logs from last hour

            // Helper function to filter log lines by timestamp
            $filter_recent_logs = function ($content) use ($cutoff_time) {
                $lines = explode("\n", $content);
                $recent_lines = [];

                foreach ($lines as $line) {
                    // WordPress logs use format: [DD-Mon-YYYY HH:MM:SS UTC]
                    if (preg_match('/^\[(\d{2}-\w{3}-\d{4}\s+\d{2}:\d{2}:\d{2})\s+\w+\]/', $line, $m)) {
                        $timestamp = strtotime($m[1]);
                        if ($timestamp !== false && $timestamp >= $cutoff_time) {
                            $recent_lines[] = $line;
                        }
                    } elseif (!empty($recent_lines)) {
                        // Include continuation lines (stack traces, etc)
                        $recent_lines[] = $line;
                    }
                }

                return implode("\n", $recent_lines);
            };

            // 1. Read Flight Recorder Log (always recent since it's crash-specific)
            $flight_log = $secure_dir . '/crash.log';
            $debug_info['flight_log_path'] = $flight_log;
            $debug_info['flight_log_exists'] = file_exists($flight_log);

            if (file_exists($flight_log)) {
                // Try shell first, fallback to PHP
                $content = @shell_exec('tail -n 15 ' . escapeshellarg($flight_log) . ' 2>/dev/null');
                if (empty($content)) {
                    // Fallback: read last 5KB with PHP
                    $size = filesize($flight_log);
                    $content = @file_get_contents($flight_log, false, null, max(0, $size - 5000));
                    if ($content) {
                        $lines = explode("\n", $content);
                        $content = implode("\n", array_slice($lines, -15));
                    }
                }
                if ($content) {
                    $log_excerpt .= "--- FLIGHT RECORDER LOG ---\n" . trim($content) . "\n\n";
                }
            }

            // 2. Read WP Debug Log - FILTERED BY TIME
            $log_path = $wp_content_dir . '/debug.log';
            $debug_info['debug_log_path'] = $log_path;
            $debug_info['debug_log_exists'] = file_exists($log_path);
            $debug_info['log_cutoff'] = date('Y-m-d H:i:s', $cutoff_time);

            if (file_exists($log_path)) {
                // Read more lines to filter, since we'll be pruning old ones
                $content = @shell_exec('tail -n 200 ' . escapeshellarg($log_path) . ' 2>/dev/null');
                if (empty($content)) {
                    $size = filesize($log_path);
                    $content = @file_get_contents($log_path, false, null, max(0, $size - 50000));
                }

                if ($content) {
                    $filtered_content = $filter_recent_logs($content);
                    if (!empty(trim($filtered_content))) {
                        $log_excerpt .= "--- WP DEBUG LOG (last hour only) ---\n" . trim($filtered_content);
                    } else {
                        $log_excerpt .= "--- WP DEBUG LOG ---\n(No errors in the last hour)\n";
                    }
                }
            }

            if (empty(trim($log_excerpt))) {
                $log_excerpt = "No recent crash logs found. Debug: " . json_encode($debug_info);
            }

            // 3. Get plugin list
            $plugins = [];
            $plugin_dirs_list = @glob($plugins_dir . '/*', GLOB_ONLYDIR);
            if ($plugin_dirs_list) {
                foreach ($plugin_dirs_list as $dir) {
                    $plugins[] = basename($dir);
                }
            }

            // 4. SMART SYMBOL SEARCH - Extract symbols from error and find which plugin contains them
            $suspects = [];

            // Extract potential class names, namespaces, and function names from the error
            $symbols_to_search = [];
            $full_class_names = []; // Store fully qualified class names for precise matching

            // Match fully-qualified class names with namespaces (e.g., "SSWP\PostTypes\PropertyCPT")
            if (preg_match_all('/([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]+)+)/', $log_excerpt, $m)) {
                foreach ($m[1] as $fqcn) {
                    $full_class_names[] = $fqcn;
                    $parts = explode('\\', $fqcn);
                    // Add the root namespace (e.g., "SSWP") - this is the most unique identifier
                    $symbols_to_search[] = $parts[0];
                    // Add the class name (e.g., "PropertyCPT")
                    $symbols_to_search[] = end($parts);
                    // NOTE: We intentionally DON'T add intermediate parts like "PostTypes" 
                    // because they are too generic and cause false positives
                }
            }

            // Match class names (e.g., "class 'MyClass'", "Class MyClass", "MyClass::")
            if (preg_match_all('/class\s+[\'"]?([A-Z][A-Za-z0-9_]+)[\'"]?/i', $log_excerpt, $m)) {
                $symbols_to_search = array_merge($symbols_to_search, $m[1]);
            }
            if (preg_match_all('/([A-Z][A-Za-z0-9_]+)::/', $log_excerpt, $m)) {
                $symbols_to_search = array_merge($symbols_to_search, $m[1]);
            }

            // Match function names from call stack (e.g., "function my_function")
            if (preg_match_all('/function\s+([a-z_][a-z0-9_]+)/i', $log_excerpt, $m)) {
                $symbols_to_search = array_merge($symbols_to_search, $m[1]);
            }

            // Match file paths to extract plugin folder (HIGHEST PRIORITY)
            if (preg_match_all('/wp-content\/plugins\/([^\/]+)\//', $log_excerpt, $m)) {
                foreach ($m[1] as $plugin_folder) {
                    // Skip our own plugins
                    if (stripos($plugin_folder, 'safebackup') !== false)
                        continue;

                    if (!isset($suspects[$plugin_folder])) {
                        $suspects[$plugin_folder] = [];
                    }
                    $suspects[$plugin_folder][] = '★ DIRECTLY IN ERROR STACK TRACE ★';
                }
            }

            // Remove duplicates and common PHP/WP classes
            $symbols_to_search = array_unique($symbols_to_search);
            $ignore_symbols = [
                'WP_Error',
                'WP_Query',
                'WP_Post',
                'WP_User',
                'WP_Hook',
                'WP_REST_Request',
                'Exception',
                'Error',
                'stdClass',
                'DateTime',
                'PDO',
                'mysqli',
                'ReflectionMethod',
                'ReflectionClass',
                'Closure'
            ];
            $symbols_to_search = array_diff($symbols_to_search, $ignore_symbols);

            // Search each plugin for these symbols
            if ((!empty($symbols_to_search) || !empty($full_class_names)) && !empty($plugins)) {
                // EXCLUDE our own plugins - don't want users to deactivate the recovery tool!
                $excluded_plugins = [
                    'SafeBackup-Repair-WP',
                    'SafeBackup-Repair-WP-Pro',
                    'safebackup-repair-wp',
                    'safebackup-repair-wp-pro'
                ];

                foreach ($plugins as $plugin_folder) {

                    // Skip our own plugins
                    if (
                        in_array($plugin_folder, $excluded_plugins) ||
                        stripos($plugin_folder, 'safebackup') !== false
                    )
                        continue;

                    $plugin_path = $plugins_dir . '/' . $plugin_folder;

                    // Get all PHP files in this plugin
                    $php_files = [];
                    try {
                        $iterator = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($plugin_path, RecursiveDirectoryIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::LEAVES_ONLY
                        );

                        $file_count = 0;
                        foreach ($iterator as $file) {
                            if ($file->getExtension() === 'php' && $file_count < 1000) {
                                $php_files[] = $file->getPathname();
                                $file_count++;
                            }
                        }
                    } catch (Exception $e) {
                        continue;
                    }

                    // Search for symbols in these files
                    foreach ($php_files as $php_file) {
                        $content = @file_get_contents($php_file);
                        if (!$content)
                            continue;

                        // Check for FULLY QUALIFIED CLASS NAMES first (most accurate)
                        foreach ($full_class_names as $fqcn) {
                            // Handle both \ and \\ in the FQCN
                            $normalized_fqcn = str_replace('\\\\', '\\', $fqcn);
                            $parts = explode('\\', $normalized_fqcn);
                            $class_name = end($parts);
                            $root_namespace = $parts[0]; // e.g., "SSWP"

                            // Look for: namespace that starts with root namespace (e.g., "namespace SSWP")
                            // AND class definition
                            $namespace_found = (
                                stripos($content, "namespace $root_namespace\\") !== false ||
                                stripos($content, "namespace $root_namespace;") !== false
                            );
                            $class_found = preg_match('/class\s+' . preg_quote($class_name, '/') . '\b/i', $content);

                            if ($namespace_found && $class_found) {
                                if (!isset($suspects[$plugin_folder])) {
                                    $suspects[$plugin_folder] = [];
                                }
                                $suspects[$plugin_folder][] = "★ DEFINES CLASS: $fqcn ★";
                            }
                        }

                        // Check for individual symbols
                        foreach ($symbols_to_search as $symbol) {
                            // Search for class definition or namespace declaration
                            if (
                                preg_match('/class\s+' . preg_quote($symbol, '/') . '\b/i', $content) ||
                                preg_match('/namespace\s+' . preg_quote($symbol, '/') . '\b/i', $content) ||
                                preg_match('/namespace\s+[^;]*\\\\' . preg_quote($symbol, '/') . '\b/i', $content)
                            ) {
                                if (!isset($suspects[$plugin_folder])) {
                                    $suspects[$plugin_folder] = [];
                                }
                                $suspects[$plugin_folder][] = $symbol;
                            }
                        }
                    }
                }
            }

            // Clean up suspects - remove duplicates and sort by importance
            foreach ($suspects as $plugin => $found_symbols) {
                $suspects[$plugin] = array_values(array_unique($found_symbols));
            }

            // Sort suspects so plugins with ★ markers (high confidence) come first
            uasort($suspects, function ($a, $b) {
                $a_priority = count(array_filter($a, fn($s) => strpos($s, '★') !== false));
                $b_priority = count(array_filter($b, fn($s) => strpos($s, '★') !== false));
                return $b_priority - $a_priority;
            });

            echo json_encode([
                'success' => true,
                'log' => $log_excerpt,
                'plugins' => $plugins,
                'suspects' => $suspects,
                'symbols_searched' => array_values($symbols_to_search),
                'fqcn_searched' => $full_class_names,
                'php_version' => PHP_VERSION,
                'log_timeframe' => 'Last hour only'
            ]);
            exit;

        case 'analyze_crash':
            $log = $_POST['log'] ?? '';
            $plugins = $_POST['plugins'] ?? [];
            $suspects = $_POST['suspects'] ?? []; // Smart Symbol intelligence

            if (empty($log)) {
                echo json_encode(['success' => false, 'error' => 'No error log data found.']);
                exit;
            }

            // FIRST: Check if we have DEFINITIVE suspects from code search
            // If we found the exact class definition, use that instead of asking AI
            $high_confidence_suspects = [];
            if (!empty($suspects)) {
                foreach ($suspects as $plugin => $symbols) {
                    foreach ((array) $symbols as $s) {
                        if (strpos($s, '★') !== false) {
                            $high_confidence_suspects[$plugin] = $symbols;
                            break;
                        }
                    }
                }
            }

            // If we have ONE definitive suspect, return it directly - no AI needed!
            if (count($high_confidence_suspects) === 1) {
                $culprit = array_key_first($high_confidence_suspects);
                $evidence = implode(', ', $high_confidence_suspects[$culprit]);

                echo json_encode([
                    'success' => true,
                    'analysis' => [
                        'suspected_plugin_folder' => $culprit,
                        'confidence' => 0.99,
                        'explanation' => "Code search definitively identified this plugin. Evidence: $evidence",
                        'fix_action' => 'disable',
                        'source' => 'code_search'
                    ]
                ]);
                exit;
            }

            // If we have MULTIPLE definitive suspects, still use code search but lower confidence
            if (count($high_confidence_suspects) > 1) {
                // Pick the first one (they're sorted by priority)
                $culprit = array_key_first($high_confidence_suspects);
                $evidence = implode(', ', $high_confidence_suspects[$culprit]);
                $others = array_keys($high_confidence_suspects);
                array_shift($others);

                echo json_encode([
                    'success' => true,
                    'analysis' => [
                        'suspected_plugin_folder' => $culprit,
                        'confidence' => 0.85,
                        'explanation' => "Code search found the class in this plugin. Evidence: $evidence. Other possible plugins: " . implode(', ', $others),
                        'fix_action' => 'disable',
                        'source' => 'code_search'
                    ]
                ]);
                exit;
            }

            // NO definitive suspects - fall back to AI analysis
            $key = sbwp_get_ai_key();
            if (!$key) {
                echo json_encode(['success' => false, 'error' => 'AI Key not configured and no definitive match found via code search.']);
                exit;
            }

            $prompt = "You are a WordPress recovery expert.\n";
            $prompt .= "Analyze the error log and identify the plugin causing the crash.\n";
            $prompt .= "Return ONLY valid JSON: { \"suspected_plugin_folder\": \"folder-name\", \"confidence\": 0.95, \"explanation\": \"...\", \"fix_action\": \"disable\" }\n";
            $prompt .= "If unsure, set confidence < 0.5.\n\n";

            // Still include any partial matches for context
            if (!empty($suspects)) {
                $prompt .= "=== CODE SEARCH HINTS ===\n";
                $prompt .= "We searched plugin files but found no definitive match. Partial matches:\n";
                foreach ($suspects as $plugin => $symbols) {
                    $prompt .= "- '$plugin': " . implode(', ', (array) $symbols) . "\n";
                }
                $prompt .= "\n";
            }

            $prompt .= "Active Plugins: " . implode(', ', $plugins) . "\n";
            $prompt .= "Error Log:\n" . $log;

            $response = sbwp_call_openai($prompt, $key);

            // Clean response (remove markdown code blocks if any)
            $response = preg_replace('/^```json/', '', $response);
            $response = preg_replace('/```$/', '', $response);
            $response = trim($response);

            echo json_encode(['success' => true, 'analysis' => json_decode($response)]);
            exit;

        case 'explain_error': // Keeping for legacy/debug tab
            $last_error = '';

            // 1. Check Flight Recorder first (most relevant crashes)
            $flight_log = $secure_dir . '/crash.log';
            if (file_exists($flight_log)) {
                $lines = file($flight_log);
                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    if (preg_match('/(Fatal error|Parse error|Uncaught Exception|CRASH:)/i', $lines[$i])) {
                        $last_error = $lines[$i];
                        break;
                    }
                }
            }

            // 2. Fallback to WP Debug Log if no crash found yet
            $log_path = $wp_content_dir . '/debug.log';
            if (!$last_error && file_exists($log_path)) {
                $lines = file($log_path);
                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    if (preg_match('/(Fatal error|Parse error|Uncaught Exception|CRASH:)/i', $lines[$i])) {
                        $last_error = $lines[$i];
                        break;
                    }
                }
            }

            if (!$last_error) {
                echo json_encode(['success' => false, 'error' => 'No fatal errors found to explain']);
                exit;
            }

            $key = sbwp_get_ai_key();
            if (!$key) {
                echo json_encode(['success' => false, 'error' => 'AI Key not configured']);
                exit;
            }

            $explanation = sbwp_call_openai($last_error, $key);
            echo json_encode(['success' => true, 'explanation' => $explanation]);

            exit;

        case 'explain_single_error':
            $error = $_POST['error'] ?? '';
            if (empty($error)) {
                echo json_encode(['success' => false, 'error' => 'No error text provided']);
                exit;
            }

            $key = sbwp_get_ai_key();
            if (!$key) {
                echo json_encode(['success' => false, 'error' => 'AI Key not configured']);
                exit;
            }

            $explanation = sbwp_call_openai($error, $key);
            echo json_encode(['success' => true, 'explanation' => $explanation]);
            exit;

        case 'apply_fix':
            $folder = $_POST['folder'] ?? '';
            if (empty($folder)) {
                echo json_encode(['success' => false, 'error' => 'No folder specified']);
                exit;
            }

            // Security: Prevent directory traversal
            $folder = basename($folder);
            $target = $plugins_dir . '/' . $folder;

            if (!is_dir($target)) {
                echo json_encode(['success' => false, 'error' => 'Plugin not found: ' . $folder]);
                exit;
            }

            // 1. Get plugin main file
            $plugin_main_file = sbwp_get_plugin_main_file($folder, $plugins_dir);
            if (!$plugin_main_file) {
                // Try searching with more depth if standard helper fails
                $plugin_main_file = '';
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $file) {
                    if ($file->getExtension() === 'php') {
                        $content = @file_get_contents($file->getPathname());
                        if ($content && stripos($content, 'Plugin Name:') !== false) {
                            $plugin_main_file = $folder . '/' . $file->getFilename();
                            break;
                        }
                    }
                }
            }

            if (!$plugin_main_file) {
                echo json_encode(['success' => false, 'error' => 'Could not find main plugin file']);
                exit;
            } else {
                // DEBUG: Output detected file
                // file_put_contents($plugin_dir . '/.sbwp-debug.log', "Targeting: " . $plugin_main_file . "\n", FILE_APPEND);
            }

            // 2. Remove from active_plugins option in DB
            $active_plugins = sbwp_get_active_plugins();

            if (in_array($plugin_main_file, $active_plugins)) {
                $active_plugins = array_diff($active_plugins, [$plugin_main_file]);
                if (sbwp_update_active_plugins($active_plugins)) {

                    // Log for Undo
                    $undo_log = $plugin_dir . '/.sbwp-ai-undo-log';
                    $entry = json_encode([
                        'plugin_folder' => $folder,
                        'plugin_file' => $plugin_main_file,
                        'action' => 'deactivated_via_db',
                        'time' => time()
                    ]) . "\n";
                    file_put_contents($undo_log, $entry, FILE_APPEND);

                    echo json_encode([
                        'success' => true,
                        'message' => 'Plugin deactivated via database update.',
                        'method' => 'database'
                    ]);
                    exit;
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to update database']);
                    exit;
                }
            } else {
                echo json_encode(['success' => true, 'message' => 'Plugin was already inactive.']);
                exit;
            }

        case 'undo_fix':
            $undo_log = $plugin_dir . '/.sbwp-ai-undo-log';
            if (!file_exists($undo_log)) {
                echo json_encode(['success' => false, 'error' => 'No undo history found']);
                exit;
            }

            $lines = file($undo_log);
            $last_line = trim(array_pop($lines));
            $data = json_decode($last_line, true);

            if (!$data) {
                echo json_encode(['success' => false, 'error' => 'Corrupt undo log']);
                exit;
            }

            // Handle new format (deactivated_via_db)
            if (isset($data['action']) && $data['action'] === 'deactivated_via_db') {
                $plugin_file = $data['plugin_file'];

                // Reactivate in DB
                // Reactivate in DB
                $db = sbwp_connect_db();

                if ($db) {
                    $conn = $db['conn'];
                    $prefix = $db['prefix'];
                    $option_name = $prefix . 'options';
                    $query = "SELECT option_value FROM $option_name WHERE option_name = 'active_plugins'";
                    $result = $conn->query($query);

                    if ($result && $row = $result->fetch_assoc()) {
                        $active_plugins = unserialize($row['option_value']);
                        if (!is_array($active_plugins))
                            $active_plugins = [];

                        if (!in_array($plugin_file, $active_plugins)) {
                            $active_plugins[] = $plugin_file;
                            sort($active_plugins); // Sort for consistency
                            $new_value = serialize($active_plugins);

                            $update = $conn->prepare("UPDATE $option_name SET option_value = ? WHERE option_name = 'active_plugins'");
                            $update->bind_param('s', $new_value);

                            if ($update->execute()) {
                                file_put_contents($undo_log, implode("", $lines));
                                echo json_encode(['success' => true, 'message' => 'Plugin reactivated via database.', 'restored' => $data['plugin_folder']]);
                                exit;
                            }
                        } else {
                            // Already active
                            file_put_contents($undo_log, implode("", $lines));
                            echo json_encode(['success' => true, 'message' => 'Plugin was already active.', 'restored' => $data['plugin_folder']]);
                            exit;
                        }
                    }
                }

                echo json_encode(['success' => false, 'error' => 'Database connection failed during undo']);
                exit;
            }

            // Handle new format (deactivated_via_fix - folder name is preserved)
            if (isset($data['action']) && $data['action'] === 'deactivated_via_fix') {
                // Plugin folder is already named correctly, just needs to be reactivated in WP
                // Save remaining lines back
                file_put_contents($undo_log, implode("", $lines));
                echo json_encode([
                    'success' => true,
                    'plugin' => $data['plugin_folder'],
                    'message' => 'To re-enable this plugin, go to WordPress Plugins page and activate it.',
                    'needs_activation' => true
                ]);
                exit;
            }

            // Handle legacy format (folder was renamed to .disabled)
            if (isset($data['renamed']) && isset($data['original'])) {
                $current = $plugins_dir . '/' . $data['renamed'];
                $original = $plugins_dir . '/' . $data['original'];

                if (is_dir($current)) {
                    rename($current, $original);
                    file_put_contents($undo_log, implode("", $lines));
                    echo json_encode(['success' => true, 'restored' => $data['original']]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Plugin folder not found for undo']);
                }
                exit;
            }

            echo json_encode(['success' => false, 'error' => 'Unknown undo log format']);
            exit;
    }
}

// ============================================================================
// RENDER UI
// ============================================================================
$base_url = strtok($_SERVER['REQUEST_URI'], '?');
$auth_params = 'key=' . urlencode($provided_key);
if (!empty($provided_pin)) {
    $auth_params .= '&pin=' . urlencode($provided_pin);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SafeBackup Recovery Portal</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            color: #f1f5f9;
            min-height: 100vh;
            padding: 2rem;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #334155;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo h1 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .logo span {
            font-size: 1.75rem;
        }

        .badge {
            background: #10b981;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge.warning {
            background: #f59e0b;
        }

        .badge.danger {
            background: #ef4444;
        }

        .alert {
            background: #450a0a;
            border: 1px solid #dc2626;
            padding: 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .alert-icon {
            font-size: 1.5rem;
        }

        .alert h3 {
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }

        .alert p {
            font-size: 0.875rem;
            color: #fca5a5;
        }

        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #334155;
            padding-bottom: 0.5rem;
        }

        .tab {
            padding: 0.5rem 1rem;
            background: transparent;
            color: #94a3b8;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.2s;
            display: flex;
            align-items: center;
        }

        .tab:hover {
            color: #f1f5f9;
            background: #1e293b;
        }

        .tab.active {
            color: #f1f5f9;
            background: #3b82f6;
        }

        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
        }

        .plugin-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .plugin-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            background: #0f172a;
            border-radius: 0.5rem;
            transition: all 0.2s;
        }

        .plugin-item:hover {
            background: #1e1b4b;
        }

        .plugin-item.disabled {
            opacity: 0.6;
        }

        .plugin-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .plugin-icon {
            font-size: 1.25rem;
        }

        .plugin-name {
            font-weight: 500;
        }

        .plugin-folder {
            font-size: 0.75rem;
            color: #64748b;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid #334155;
            color: #94a3b8;
        }

        .btn-outline:hover {
            background: #334155;
            color: #f1f5f9;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .log-viewer {
            background: #0f172a;
            border-radius: 0.5rem;
            padding: 1rem;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 0.75rem;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
            color: #94a3b8;
        }

        .log-viewer .error {
            color: #fb923c;
        }

        .log-viewer .warning {
            color: #f59e0b;
        }

        .log-viewer .error-line {
            cursor: pointer;
            text-decoration: underline;
            text-decoration-style: dotted;
            text-underline-offset: 3px;
            transition: all 0.2s;
            display: block;
            padding: 2px 4px;
            margin: 2px -4px;
            border-radius: 4px;
        }

        .log-viewer .error-line:hover {
            background: rgba(239, 68, 68, 0.15);
            text-decoration-style: solid;
        }

        .log-viewer .error-line.active {
            background: rgba(139, 92, 246, 0.2);
            border-left: 3px solid #8b5cf6;
            padding-left: 8px;
        }

        .error-explanation {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
            border: 1px solid #5b21b6;
            border-radius: 8px;
            padding: 12px 16px;
            margin: 8px 0 12px 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 0.85rem;
            line-height: 1.5;
            color: #e2e8f0;
            animation: slideDown 0.2s ease-out;
        }

        .error-explanation .ai-icon {
            display: inline-block;
            margin-right: 8px;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hidden {
            display: none !important;
        }

        .status-msg {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .status-msg.success {
            background: #064e3b;
            color: #6ee7b7;
        }

        .status-msg.error {
            background: #450a0a;
            color: #fca5a5;
        }

        .loading {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid #334155;
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* AI Modal & Features */
        .ai-banner {
            background: linear-gradient(90deg, #4f46e5 0%, #7c3aed 100%);
            padding: 2rem;
            border-radius: 1rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: white;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .ai-banner h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .ai-banner p {
            color: #c7d2fe;
            max-width: 500px;
        }

        .ai-btn-large {
            background: white;
            color: #4f46e5;
            font-weight: bold;
            font-size: 1.125rem;
            padding: 1rem 2rem;
            border-radius: 0.75rem;
            border: none;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .ai-btn-large:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2);
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(8px);
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s;
        }

        .modal-overlay.open {
            opacity: 1;
            pointer-events: auto;
        }

        .ai-modal {
            background: #1e1b4b;
            border: 1px solid #4338ca;
            width: 600px;
            max-width: 90%;
            border-radius: 1.5rem;
            padding: 2rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        /* Radar Animation */
        .ai-radar {
            width: 120px;
            height: 120px;
            margin: 0 auto 2rem;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .radar-msg {
            font-size: 3rem;
            z-index: 10;
        }

        .radar-ring {
            position: absolute;
            border: 2px solid #6366f1;
            border-radius: 50%;
            opacity: 0;
            animation: pulse-ring 2s cubic-bezier(0.215, 0.61, 0.355, 1) infinite;
        }

        .radar-ring:nth-child(2) {
            animation-delay: 0.5s;
        }

        .radar-ring:nth-child(3) {
            animation-delay: 1s;
        }

        @keyframes pulse-ring {
            0% {
                transform: scale(0.5);
                opacity: 0;
            }

            50% {
                opacity: 0.5;
            }

            100% {
                transform: scale(1.5);
                opacity: 0;
            }
        }

        .step-list {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            opacity: 0.5;
            transition: opacity 0.3s;
        }

        .step-item.active {
            opacity: 1;
            color: #818cf8;
        }

        .step-icon {
            width: 2rem;
            height: 2rem;
            background: #312e81;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .step-item.active .step-icon {
            background: #4f46e5;
            color: white;
        }

        .diagnosis-card {
            background: #312e81;
            border-radius: 1rem;
            padding: 1.5rem;
            border: 1px solid #4338ca;
            margin-top: 1rem;
        }

        .confidence-bar {
            height: 6px;
            background: #1e1b4b;
            border-radius: 3px;
            margin: 0.5rem 0;
            overflow: hidden;
        }

        .confidence-fill {
            height: 100%;
            background: #10b981;
            border-radius: 3px;
            width: 0%;
            transition: width 1s ease-out;
        }

        .step-item.completed {
            opacity: 1;
            color: #10b981;
        }

        .step-item.completed .step-icon {
            background: #10b981;
            color: white;
            box-shadow: 0 0 15px rgba(16, 185, 129, 0.4);
        }

        .cursor-blink::after {
            display: none;
            /* Removed */
        }

        .anim-text-wrapper {
            height: 1.5em;
            /* Prevent layout shift */
            overflow: hidden;
            position: relative;
            width: 100%;
        }

        .anim-enter {
            animation: slideUpIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        .anim-exit {
            animation: slideUpOut 0.4s ease-in forwards;
            position: absolute;
            width: 100%;
            top: 0;
            left: 0;
        }

        @keyframes slideUpIn {
            0% {
                opacity: 0;
                transform: translateY(20px);
            }

            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideUpOut {
            0% {
                opacity: 1;
                transform: translateY(0);
            }

            100% {
                opacity: 0;
                transform: translateY(-20px);
            }
        }

        .culprit-box {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 0.75rem;
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .culprit-label {
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 0.75rem;
            color: #94a3b8;
            margin-bottom: 0.5rem;
            display: block;
        }

        .culprit-name {
            font-size: 2rem;
            font-weight: 700;
            color: #fff;
            margin: 0;
            line-height: 1.2;
            word-break: break-word;
        }

        .text-green-500 {
            color: #10b981;
        }

        .text-yellow-500 {
            color: #f59e0b;
        }
    </style>
</head>

<body>
    <!-- AI Modal -->
    <div id="ai-modal" class="modal-overlay">
        <div class="ai-modal">
            <div id="ai-scan-view">
                <div class="ai-radar">
                    <div class="radar-ring" style="width:100px;height:100px;"></div>
                    <div class="radar-ring" style="width:100px;height:100px;"></div>
                    <div class="radar-msg">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            style="color:#818cf8">
                            <path d="M12 8V4H8" />
                            <rect width="16" height="12" x="4" y="8" rx="2" />
                            <path d="M2 14h2" />
                            <path d="M20 14h2" />
                            <path d="M15 13v2" />
                            <path d="M9 13v2" />
                        </svg>
                    </div>
                </div>
                <h2 style="text-align:center;font-size:1.5rem;margin-bottom:0.5rem">AI Auto-Fix</h2>
                <div id="ai-status-text" class="anim-text-wrapper"
                    style="text-align:center;color:#94a3b8;margin-bottom:2rem">Initializing...</div>

                <div class="step-list">
                    <div id="step-1" class="step-item">
                        <div class="step-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 21l-4.486-4.494M19 10.5a8.5 8.5 0 1 1-17 0 8.5 8.5 0 0 1 17 0z" />
                            </svg>
                        </div>
                        <span>Scan</span>
                    </div>
                    <div id="step-2" class="step-item">
                        <div class="step-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5" />
                            </svg>
                        </div>
                        <span>Analyze</span>
                    </div>
                    <div id="step-3" class="step-item">
                        <div class="step-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2">
                                <path
                                    d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" />
                            </svg>
                        </div>
                        <span>Fix</span>
                    </div>
                </div>
            </div>

            <div id="ai-result-view" class="hidden">
                <h2 style="text-align:center;color:#a5b4fc;margin-bottom:1.5rem">Analysis Complete</h2>
                <div class="diagnosis-card">

                    <div class="culprit-box">
                        <span class="culprit-label">Root Cause Identified</span>
                        <div style="display:flex;align-items:center;justify-content:center;gap:0.75rem">
                            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24"
                                fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path
                                    d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z">
                                </path>
                                <line x1="12" y1="9" x2="12" y2="13"></line>
                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                            </svg>
                            <h3 class="culprit-name" id="suspect-name">Plugin Name</h3>
                        </div>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem">
                        <span style="color:#cbd5e1;font-size:0.875rem">Confidence Score</span>
                        <span style="color:#10b981;font-weight:bold" id="confidence-score">85%</span>
                    </div>
                    <div class="confidence-bar">
                        <div id="confidence-fill" class="confidence-fill"></div>
                    </div>

                    <div style="background:rgba(255,255,255,0.05);padding:1rem;border-radius:0.5rem;margin-top:1.5rem">
                        <p style="margin:0;color:#e2e8f0;line-height:1.6" id="ai-explanation">Explanation goes here...
                        </p>
                    </div>
                </div>
                <div style="margin-top:2rem;display:flex;gap:1rem;justify-content:center">
                    <button class="btn btn-outline" onclick="closeAiModal()">Cancel</button>
                    <button id="apply-fix-btn" class="ai-btn-large"
                        style="background:#10b981;color:white;font-size:1rem;padding:0.75rem 1.5rem">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2">
                            <path
                                d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" />
                        </svg>
                        Fix it for me
                    </button>
                </div>
            </div>

            <div id="ai-success-view" class="hidden" style="text-align:center;padding:2rem">
                <!-- Success Icon with Glow -->
                <div style="display:flex;justify-content:center;margin-bottom:1.5rem">
                    <div
                        style="background:linear-gradient(135deg, rgba(16,185,129,0.2) 0%, rgba(16,185,129,0.1) 100%);border-radius:50%;padding:1.5rem;box-shadow:0 0 40px rgba(16,185,129,0.3)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" viewBox="0 0 24 24" fill="none"
                            stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                            <polyline points="22 4 12 14.01 9 11.01" />
                        </svg>
                    </div>
                </div>

                <!-- Title -->
                <h2 id="success-title" style="color:white;margin-bottom:0.75rem;font-size:1.75rem;font-weight:600">Fixed
                    & Verified!</h2>

                <!-- Subtitle -->
                <p id="success-message"
                    style="color:#94a3b8;margin-bottom:2rem;font-size:0.95rem;max-width:400px;margin-left:auto;margin-right:auto">
                    Plugin disabled and site responded to health check. You are good to go!
                </p>

                <!-- Buttons -->
                <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap">
                    <?php $site_url = sbwp_get_site_url(); ?>
                    <a href="<?php echo $site_url ? $site_url . '/wp-admin/' : '/wp-admin/'; ?>"
                        style="display:inline-flex;align-items:center;gap:0.5rem;background:linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);color:white;padding:0.875rem 1.5rem;border-radius:0.75rem;font-weight:500;text-decoration:none;box-shadow:0 4px 14px rgba(59,130,246,0.4);transition:all 0.2s">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                            <polyline points="9 22 9 12 15 12 15 22" />
                        </svg>
                        Go to Dashboard
                    </a>
                    <button onclick="undoFix()"
                        style="display:inline-flex;align-items:center;gap:0.5rem;background:transparent;color:#94a3b8;padding:0.875rem 1.5rem;border-radius:0.75rem;font-weight:500;border:1px solid #334155;cursor:pointer;transition:all 0.2s">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                            <path d="M3 3v5h5" />
                        </svg>
                        Undo Change
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <header>
            <div class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none"
                    stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                </svg>
                <h1>Recovery Portal</h1>
            </div>
            <span class="badge">STANDALONE MODE</span>
        </header>

        <!-- AI Banner -->
        <div class="ai-banner">
            <div>
                <h2>Site Crashed? Let AI Fix It.</h2>
                <p>Our AI Mechanic retrieves crash logs, identifies the culprit, and fixes it automatically.</p>
            </div>
            <button class="ai-btn-large" onclick="startAiScan()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2">
                    <path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5" />
                    <path d="M8.5 8.5v.01" />
                    <path d="M11.5 8.5v.01" />
                    <path d="M15.5 8.5v.01" />
                </svg>
                Let AI Fix It
            </button>
        </div>

        <div id="status-container"></div>

        <div class="alert">
            <span class="alert-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="text-yellow-500">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z">
                    </path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
            </span>
            <div>
                <h3>Emergency Recovery Mode</h3>
                <p>This portal works independently of WordPress. Disable problematic plugins or themes to recover your
                    site.</p>
            </div>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="showTab('plugins')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    style="margin-right:0.5rem">
                    <path d="M6 7V2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v5" />
                    <path d="M4 7h16a2 2 0 0 1 2 2v6" />
                    <rect width="18" height="7" x="3" y="14" rx="2" />
                    <circle cx="7" cy="18" r="1" />
                    <circle cx="17" cy="18" r="1" />
                </svg>
                Plugins
            </button>
            <button class="tab" onclick="showTab('themes')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    style="margin-right:0.5rem">
                    <circle cx="13.5" cy="6.5" r=".5" />
                    <circle cx="17.5" cy="10.5" r=".5" />
                    <circle cx="8.5" cy="7.5" r=".5" />
                    <circle cx="6.5" cy="12.5" r=".5" />
                    <path
                        d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.109 0-1.42 1.168-2.603 2.602-2.603 1.582 0 2.87-1.33 2.87-3.088 0-5.356-4.135-9.666-9.247-9.387" />
                </svg>
                Themes
            </button>
            <button class="tab" onclick="showTab('logs')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    style="margin-right:0.5rem">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                    <polyline points="14 2 14 8 20 8" />
                    <path d="M16 13H8" />
                    <path d="M16 17H8" />
                    <path d="M10 9H8" />
                </svg>
                Debug Log
            </button>
        </div>

        <div id="plugins-tab">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Installed Plugins</span>
                    <button class="btn btn-outline" onclick="loadPlugins()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <polyline points="1 20 1 14 7 14"></polyline>
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                        </svg>
                        Refresh
                    </button>
                </div>
                <div id="plugins-list" class="plugin-list">
                    <div style="text-align:center;padding:2rem;color:#64748b">
                        <div class="loading"></div>
                        <p style="margin-top:0.5rem">Loading plugins...</p>
                    </div>
                </div>
            </div>
        </div>

        <div id="themes-tab" class="hidden">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Installed Themes</span>
                    <button class="btn btn-outline" onclick="loadThemes()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <polyline points="1 20 1 14 7 14"></polyline>
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                        </svg>
                        Refresh
                    </button>
                </div>
                <div id="themes-list" class="plugin-list">
                    <div style="text-align:center;padding:2rem;color:#64748b">
                        <div class="loading"></div>
                        <p style="margin-top:0.5rem">Loading themes...</p>
                    </div>
                </div>
            </div>
        </div>

        <div id="logs-tab" class="hidden">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Debug Log</span>
                    <div style="display:flex;gap:0.5rem">
                        <button id="explain-btn" type="button" class="btn btn-outline"
                            style="border-color:#8b5cf6;color:#a78bfa">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" style="margin-right:0.5rem">
                                <rect width="18" height="12" x="3" y="6" rx="2" />
                                <path d="M9 10h.01" />
                                <path d="M15 10h.01" />
                                <path d="M12 2v4" />
                                <path d="M12 14v4" />
                                <path d="M12 18h6a2 2 0 0 1 2 2v1" />
                                <path d="M12 18H6a2 2 0 0 1-2 2v1" />
                            </svg>
                            Explain with AI
                        </button>
                        <button class="btn btn-outline" style="border-color:#dc2626;color:#f87171"
                            onclick="clearLogs()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path
                                    d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                </path>
                                <line x1="10" y1="11" x2="10" y2="17"></line>
                                <line x1="14" y1="11" x2="14" y2="17"></line>
                            </svg>
                            Clear Logs
                        </button>
                        <button class="btn btn-outline" onclick="loadDebugLog()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <polyline points="23 4 23 10 17 10"></polyline>
                                <polyline points="1 20 1 14 7 14"></polyline>
                                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                            </svg>
                            Refresh
                        </button>
                    </div>
                </div>
                <div id="debug-ai-explanation" class="hidden"
                    style="background:#2e1065;padding:1rem;border-radius:0.5rem;margin-bottom:1rem;border:1px solid #5b21b6">
                </div>
                <div id="log-content" class="log-viewer">Loading...</div>
            </div>
        </div>

        <footer>
            SafeBackup Recovery Portal v<?php echo SBWP_RECOVERY_VERSION; ?> • Works without WordPress
        </footer>
    </div>

    <script>
        const baseUrl = '<?php echo $base_url; ?>';
        const authParams = '<?php echo $auth_params; ?>';
        const hasAiKey = <?php echo sbwp_get_ai_key() ? 'true' : 'false'; ?>;

        function showTab(tab) {
            document.querySelectorAll('.tabs .tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`[onclick="showTab('${tab}')"]`).classList.add('active');

            document.querySelectorAll('[id$="-tab"]').forEach(t => t.classList.add('hidden'));
            document.getElementById(tab + '-tab').classList.remove('hidden');

            if (tab === 'plugins') loadPlugins();
            if (tab === 'themes') loadThemes();
            if (tab === 'logs') loadDebugLog();
        }

        async function explainError() {
            // Debugging
            console.log('Explain with AI clicked');

            const container = document.getElementById('debug-ai-explanation');
            console.log('Container found:', container);

            if (!container) {
                alert('Error: AI Explanation container not found.');
                return;
            }

            container.classList.remove('hidden');
            container.style.display = 'block'; // Force visibility
            container.innerHTML = '<div style="color:#d8b4fe">Thinking... <div class="loading" style="width:12px;height:12px;display:inline-block;border-width:2px"></div></div>';
            console.log('Container should now be visible');

            try {
                const url = `${baseUrl}?${authParams}&action=explain_error`;
                console.log('Fetching:', url);

                // Add timeout (30 seconds)
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 30000);

                const res = await fetch(url, { signal: controller.signal });
                clearTimeout(timeoutId);

                console.log('Response received, status:', res.status);
                const text = await res.text();
                console.log('Response text:', text.substring(0, 200));

                const data = JSON.parse(text);

                if (!data.success) throw new Error(data.error);

                container.innerHTML = `<strong><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:6px"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/><path d="M5 3v4"/><path d="M19 17v4"/><path d="M3 5h4"/><path d="M17 19h4"/></svg>AI Explanation:</strong><br/>${data.explanation}`;
            } catch (e) {
                console.error('explainError failed:', e);
                if (e.name === 'AbortError') {
                    container.innerHTML = `<span style="color:#f87171">Error: Request timed out (30s). The AI service may be slow or unavailable.</span>`;
                } else {
                    container.innerHTML = `<span style="color:#f87171">Error: ${e.message}</span>`;
                }
            }
        }

        function showStatus(message, type = 'success') {
            const container = document.getElementById('status-container');
            container.innerHTML = `<div class="status-msg ${type}">${message}</div>`;
            setTimeout(() => container.innerHTML = '', 3000);
        }

        const Icons = {
            active: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-green-500"><polyline points="20 6 9 17 4 12"></polyline></svg>`,
            paused: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-yellow-500"><circle cx="12" cy="12" r="10"></circle><line x1="10" y1="15" x2="10" y2="9"></line><line x1="14" y1="15" x2="14" y2="9"></line></svg>`,
            theme: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5"></circle><circle cx="17.5" cy="10.5" r=".5"></circle><circle cx="8.5" cy="7.5" r=".5"></circle><circle cx="6.5" cy="12.5" r=".5"></circle><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.109 0-1.42 1.168-2.603 2.602-2.603 1.582 0 2.87-1.33 2.87-3.088 0-5.356-4.135-9.666-9.247-9.387"></path></svg>`,
            play: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>`,
            pause: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>`,
            check: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`
        };

        async function loadPlugins() {
            const list = document.getElementById('plugins-list');
            list.innerHTML = '<div style="text-align:center;padding:2rem"><div class="loading"></div></div>';

            try {
                const res = await fetch(`${baseUrl}?${authParams}&action=get_plugins`);
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                list.innerHTML = data.plugins.map(p => `
                    <div class="plugin-item ${p.disabled ? 'disabled' : ''}" data-folder="${p.folder}">
                        <div class="plugin-info">
                            <span class="plugin-icon">${p.disabled ? Icons.paused : Icons.active}</span>
                            <div>
                                <div class="plugin-name">${escapeHtml(p.name)} ${p.is_self ? '<span class="badge">THIS PLUGIN</span>' : ''}</div>
                                <div class="plugin-folder">${escapeHtml(p.folder)}</div>
                            </div>
                        </div>
                        ${p.is_self ? '' : `
                            <button class="btn ${p.disabled ? 'btn-success' : 'btn-danger'}" 
                                    onclick="togglePlugin('${p.folder}', '${p.disabled ? 'enable' : 'disable'}')">
                                ${p.disabled ? Icons.play : Icons.pause}
                                <span>${p.disabled ? 'Enable' : 'Disable'}</span>
                            </button>
                        `}
                    </div>
                `).join('');
            } catch (e) {
                list.innerHTML = `<div style="color:#ef4444;padding:1rem">Error: ${e.message}</div>`;
            }
        }

        async function togglePlugin(folder, state) {
            const row = document.querySelector(`.plugin-item[data-folder="${folder}"]`);
            const btn = row ? row.querySelector('button') : null;
            const originalContent = btn ? btn.innerHTML : '';

            // Optimistic UI update (optional, but let's stick to update-on-success for safety, or add a spinner)
            if (btn) {
                btn.disabled = true;
                btn.style.opacity = '0.7';
            }

            try {
                const res = await fetch(`${baseUrl}?${authParams}&action=toggle_plugin`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `folder=${encodeURIComponent(folder)}&state=${state}`
                });
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                showStatus(`Plugin ${state}d successfully!`);

                // In-place update
                if (row && btn) {
                    const isNowDisabled = (state === 'disable');
                    const nextState = isNowDisabled ? 'enable' : 'disable';

                    // Update Row
                    row.classList.toggle('disabled', isNowDisabled);

                    // Update Icon
                    const iconSpan = row.querySelector('.plugin-icon');
                    if (iconSpan) iconSpan.innerHTML = isNowDisabled ? Icons.paused : Icons.active;

                    // Update Button
                    btn.className = `btn ${isNowDisabled ? 'btn-success' : 'btn-danger'}`;
                    btn.innerHTML = `${isNowDisabled ? Icons.play : Icons.pause} <span>${isNowDisabled ? 'Enable' : 'Disable'}</span>`;
                    btn.setAttribute('onclick', `togglePlugin('${folder}', '${nextState}')`);
                }

            } catch (e) {
                showStatus(`Error: ${e.message}`, 'error');
                // Revert
                if (btn) btn.innerHTML = originalContent;
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                }
            }
        }

        async function loadThemes() {
            const list = document.getElementById('themes-list');
            list.innerHTML = '<div style="text-align:center;padding:2rem"><div class="loading"></div></div>';

            try {
                const res = await fetch(`${baseUrl}?${authParams}&action=get_themes`);
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                list.innerHTML = data.themes.map(t => `
                    <div class="plugin-item ${t.disabled ? 'disabled' : ''}" data-folder="${t.folder}">
                        <div class="plugin-info">
                            <span class="plugin-icon">${t.disabled ? Icons.paused : Icons.theme}</span>
                            <div>
                                <div class="plugin-name">${escapeHtml(t.name)}</div>
                                <div class="plugin-folder">${escapeHtml(t.folder)}</div>
                            </div>
                        </div>
                        <button class="btn ${t.disabled ? 'btn-success' : 'btn-danger'}" 
                                onclick="toggleTheme('${t.folder}', '${t.disabled ? 'enable' : 'disable'}')">
                            ${t.disabled ? Icons.play : Icons.pause}
                            <span>${t.disabled ? 'Enable' : 'Disable'}</span>
                        </button>
                    </div>
                `).join('');
            } catch (e) {
                list.innerHTML = `<div style="color:#ef4444;padding:1rem">Error: ${e.message}</div>`;
            }
        }

        async function toggleTheme(folder, state) {
            const row = document.querySelector(`.plugin-item[data-folder="${folder}"]`);
            const btn = row ? row.querySelector('button') : null;
            const originalContent = btn ? btn.innerHTML : '';

            if (btn) {
                btn.disabled = true;
                btn.style.opacity = '0.7';
            }

            try {
                const res = await fetch(`${baseUrl}?${authParams}&action=toggle_theme`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `folder=${encodeURIComponent(folder)}&state=${state}`
                });
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                showStatus(`Theme ${state}d successfully!`);

                // In-place update
                if (row && btn) {
                    const isNowDisabled = (state === 'disable');
                    const nextState = isNowDisabled ? 'enable' : 'disable';

                    // Update Row
                    row.classList.toggle('disabled', isNowDisabled);

                    // Update Icon
                    const iconSpan = row.querySelector('.plugin-icon');
                    if (iconSpan) iconSpan.innerHTML = isNowDisabled ? Icons.paused : Icons.theme;

                    // Update Button
                    btn.className = `btn ${isNowDisabled ? 'btn-success' : 'btn-danger'}`;
                    btn.innerHTML = `${isNowDisabled ? Icons.play : Icons.pause} <span>${isNowDisabled ? 'Enable' : 'Disable'}</span>`;
                    btn.setAttribute('onclick', `toggleTheme('${folder}', '${nextState}')`);
                }
            } catch (e) {
                showStatus(`Error: ${e.message}`, 'error');
                if (btn) btn.innerHTML = originalContent;
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                }
            }
        }

        async function loadDebugLog() {
            const container = document.getElementById('log-content');
            container.innerHTML = '<div class="loading"></div> Loading...';

            try {
                const res = await fetch(`${baseUrl}?${authParams}&action=get_debug_log`);
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                // Highlight errors and warnings, making errors clickable
                let content = escapeHtml(data.content);

                // Replace error patterns with clickable spans (only first unique error)
                const errorPatterns = [
                    /(Fatal error[^\n]*)/gi,
                    /(Parse error[^\n]*)/gi,
                    /(\[.*?\] CRASH:[^\n]*)/gi,
                    /(Uncaught Exception[^\n]*)/gi
                ];

                let errorId = 0;
                const seenErrors = new Set(); // Track unique errors

                errorPatterns.forEach(pattern => {
                    content = content.replace(pattern, (match) => {
                        // Create a normalized key (strip timestamps/line numbers for comparison)
                        const normalizedKey = match.replace(/\[[\d\-:\s]+\]/g, '').trim().substring(0, 100);

                        if (seenErrors.has(normalizedKey)) {
                            // Duplicate - just style it, not clickable
                            return `<span class="error">${match}</span>`;
                        }

                        seenErrors.add(normalizedKey);
                        const id = `error-${errorId++}`;
                        const encoded = btoa(encodeURIComponent(match));
                        return `<span class="error error-line" data-error-id="${id}" data-error="${encoded}" onclick="explainSingleError(this)">${match}</span>`;
                    });
                });

                // Warnings (not clickable)
                content = content.replace(/(Warning[^\n]*)/gi, '<span class="warning">$1</span>');

                container.innerHTML = content || 'No log entries found.';

            } catch (e) {
                container.innerHTML = `<span class="error">Error: ${e.message}</span>`;
            }
        }

        async function explainSingleError(element) {
            const errorId = element.dataset.errorId;
            const errorText = decodeURIComponent(atob(element.dataset.error));

            // Check if explanation already exists
            let existingExplanation = document.getElementById(`explanation-${errorId}`);

            if (existingExplanation) {
                // Toggle off
                existingExplanation.remove();
                element.classList.remove('active');
                return;
            }

            // Remove any other active explanations
            document.querySelectorAll('.error-line.active').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.error-explanation').forEach(el => el.remove());

            // Mark as active
            element.classList.add('active');

            // Create explanation container
            const explanationDiv = document.createElement('div');
            explanationDiv.id = `explanation-${errorId}`;
            explanationDiv.className = 'error-explanation';
            explanationDiv.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#a78bfa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:6px"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/><path d="M5 3v4"/><path d="M19 17v4"/><path d="M3 5h4"/><path d="M17 19h4"/></svg> Analyzing error...';
            element.insertAdjacentElement('afterend', explanationDiv);

            try {
                const res = await fetch(`${baseUrl}?${authParams}&action=explain_single_error`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `error=${encodeURIComponent(errorText)}`
                });
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                explanationDiv.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#a78bfa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:6px"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/><path d="M5 3v4"/><path d="M19 17v4"/><path d="M3 5h4"/><path d="M17 19h4"/></svg> ${data.explanation}`;
            } catch (e) {
                explanationDiv.innerHTML = `<span class="ai-icon">⚠️</span> <span style="color:#f87171">${e.message}</span>`;
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        async function clearLogs() {
            if (!confirm('This will archive your current debug logs. Continue?')) {
                return;
            }

            const container = document.getElementById('log-content');
            container.innerHTML = '<div class="loading"></div> Clearing logs...';

            try {
                const res = await fetch(`${baseUrl}?${authParams}&action=clear_logs`);
                const data = await res.json();

                if (data.success) {
                    showStatus(`${Icons.check} Logs cleared! ${data.cleared.length} log file(s) archived.`, 'success');
                    container.innerHTML = 'Logs cleared. No recent entries.';
                } else {
                    throw new Error(data.error || 'Failed to clear logs');
                }
            } catch (e) {
                container.innerHTML = `<span class="error">Error: ${e.message}</span>`;
            }
        }

        // AI Variables
        let currentAnalysis = null;
        const modal = document.getElementById('ai-modal');
        const views = {
            scan: document.getElementById('ai-scan-view'),
            result: document.getElementById('ai-result-view'),
            success: document.getElementById('ai-success-view')
        };
        const steps = {
            1: document.getElementById('step-1'),
            2: document.getElementById('step-2'),
            3: document.getElementById('step-3')
        };




        // AI Visual Logic
        const SCAN_MESSAGES = [
            "Locating crash artifacts...",
            "Reading flight recorder data...",
            "Parsing PHP stack traces...",
            "Checking debug.log for fatal errors...",
            "Identifying active plugins...",
            "Verifying file permissions..."
        ];

        const ANALYZE_MESSAGES = [
            "Connecting to AI Diagnostic Engine...",
            "Uploading error context...",
            "Analyzing stack trace signature...",
            "Cross-referencing common conflicts...",
            "Identifying root cause...",
            "Formulating fix strategy..."
        ];

        let cycleTimeout = null;

        function transitionText(text, callback) {
            const el = document.getElementById('ai-status-text');
            const currentContent = el.innerText;

            // Don't animate if empty (first run)
            if (!currentContent) {
                el.innerHTML = `<div class="anim-enter">${text}</div>`;
                cycleTimeout = setTimeout(callback, 2000);
                return;
            }

            // Exit animation
            el.innerHTML = `<div class="anim-exit">${currentContent}</div>`;

            // Enter animation after short delay
            setTimeout(() => {
                el.innerHTML = `<div class="anim-enter">${text}</div>`;
                cycleTimeout = setTimeout(callback, 2000);
            }, 300);
        }

        function startStatusCycle(category) {
            clearTimeout(cycleTimeout);
            const messages = category === 'scan' ? SCAN_MESSAGES : ANALYZE_MESSAGES;
            let index = 0;

            function nextMessage() {
                transitionText(messages[index], () => {
                    index = (index + 1) % messages.length;
                    nextMessage();
                });
            }

            // Start immediately
            nextMessage();
        }

        function stopStatusCycle() {
            clearTimeout(cycleTimeout);
        }

        function updateStep(stepNum, status) {
            const el = steps[stepNum];
            if (!el) return;

            el.classList.remove('active', 'completed');

            if (status === 'active') {
                el.classList.add('active');
            } else if (status === 'completed') {
                el.classList.add('completed');
                // Change icon to checkmark
                el.querySelector('.step-icon').innerHTML = Icons.check;
            }
        }

        function startAiScan() {
            modal.classList.add('open');
            switchView('scan');

            // Reset Steps
            Object.values(steps).forEach(s => {
                s.classList.remove('active', 'completed');
                // Reset icons (would be better to store original SVGs, but for now we can just leave them or simplistic reset if needed. 
                // Actually, let's just leave the checkmarks if re-running, or reset them strictly. 
                // For simplicity in this mock, we assume fresh start or just keep checkmarks. 
                // Users usually won't re-run scan immediately after success without reload.)
            });

            updateStep(1, 'active');
            startStatusCycle('scan');

            // 1. Scan
            fetch(`${baseUrl}?${authParams}&action=scan_crash`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.error || 'Scan failed');

                    updateStep(1, 'completed');
                    updateStep(2, 'active');
                    startStatusCycle('analyze');

                    // 2. Analyze
                    const formData = new FormData();
                    formData.append('log', data.log);
                    data.plugins.forEach(p => formData.append('plugins[]', p));

                    if (data.suspects) {
                        for (const [plugin, symbols] of Object.entries(data.suspects)) {
                            symbols.forEach(s => formData.append(`suspects[${plugin}][]`, s));
                        }
                    }

                    return fetch(`${baseUrl}?${authParams}&action=analyze_crash`, {
                        method: 'POST',
                        body: formData
                    });
                })
                .then(r => r.json())
                .then(data => {
                    stopStatusCycle();
                    if (!data.success) throw new Error(data.error || 'Analysis failed');
                    if (!data.analysis) throw new Error('AI returned no analysis');

                    updateStep(2, 'completed');
                    currentAnalysis = data.analysis;

                    // Small delay to let user see the "completed" state
                    setTimeout(() => {
                        showResult(data.analysis);
                    }, 500);
                })
                .catch(err => {
                    stopStatusCycle();
                    statusText.innerText = "Error: " + err.message;
                    statusText.style.color = '#ef4444';
                });
        }

        function showResult(analysis) {
            switchView('result');

            const suspectName = document.getElementById('suspect-name');
            const confidenceScore = document.getElementById('confidence-score');
            const confidenceFill = document.getElementById('confidence-fill');
            const explanation = document.getElementById('ai-explanation');
            const fixBtn = document.getElementById('apply-fix-btn');

            let name = analysis.suspected_plugin_folder || "Unknown Issue";
            let conf = Math.round((analysis.confidence || 0) * 100);

            suspectName.innerText = name;
            confidenceScore.innerText = `${conf}%`;
            explanation.innerText = analysis.explanation || "No explanation provided.";

            // Animate bar
            setTimeout(() => { confidenceFill.style.width = `${conf}%`; }, 100);

            // Configure button
            if (analysis.fix_action === 'disable' && analysis.suspected_plugin_folder) {
                fixBtn.onclick = () => runFix(analysis.suspected_plugin_folder);
                fixBtn.disabled = false;
                fixBtn.style.opacity = 1;
                fixBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg> Fix it for me`;
            } else {
                fixBtn.innerText = "No Automatic Fix Available";
                fixBtn.disabled = true;
                fixBtn.style.opacity = 0.5;
            }
        }

        async function checkSiteHealth() {
            try {
                // Try to hit the login page (or root if preferred)
                const res = await fetch(window.location.origin + '/wp-login.php', { method: 'HEAD' });
                return res.ok;
            } catch (e) {
                return false;
            }
        }

        function runFix(folder) {
            setStep(3);
            const btn = document.getElementById('apply-fix-btn');
            btn.innerHTML = "Applying Fix...";
            btn.disabled = true;

            const formData = new FormData();
            formData.append('folder', folder);

            fetch(`${baseUrl}?${authParams}&action=apply_fix`, {
                method: 'POST',
                body: formData
            })
                .then(r => r.json())
                .then(async data => {
                    if (data.success) {
                        // FIX APPLIED! Now verify...
                        btn.innerHTML = "Verifying Site...";
                        const isUp = await checkSiteHealth();

                        // Update success view based on health check
                        const successTitle = document.getElementById('success-title');
                        const successMsg = document.getElementById('success-message');

                        if (isUp) {
                            successTitle.innerText = "Fixed & Verified!";
                            successMsg.innerText = "Plugin disabled and site responded to health check. You are good to go!";
                        } else {
                            successTitle.innerText = "Plugin Disabled";
                            successMsg.innerText = "We disabled the suspect plugin, but the verification ping failed. You might need to check other plugins or the logs.";
                        }

                        switchView('success');
                    } else {
                        alert('Fix Failed: ' + data.error);
                        btn.innerHTML = "Try Again";
                        btn.disabled = false;
                    }
                });
        }

        function undoFix() {
            if (!confirm("Undo the last AI fix?")) return;

            fetch(`${baseUrl}?${authParams}&action=undo_fix`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert("Change undone! Restored: " + data.restored);
                        closeAiModal();
                        loadPlugins(); // Refresh list
                    } else {
                        alert("Undo failed: " + data.error);
                    }
                });
        }

        function switchView(name) {
            Object.values(views).forEach(el => el.classList.add('hidden'));
            views[name].classList.remove('hidden');
        }

        function setStep(num) {
            Object.values(steps).forEach(el => el.classList.remove('active'));
            if (num >= 1) steps[1].classList.add('active');
            if (num >= 2) steps[2].classList.add('active');
            if (num >= 3) steps[3].classList.add('active');
        }

        function closeAiModal() {
            modal.classList.remove('open');
        }
        // Initialize AI Button State immediately
        const explainBtn = document.getElementById('explain-btn');
        if (explainBtn) {
            // Attach Event Listener
            explainBtn.addEventListener('click', explainError);

            // Check Key
            if (!hasAiKey) {
                explainBtn.disabled = true;
                explainBtn.style.opacity = '0.5';
                explainBtn.style.cursor = 'not-allowed';
                explainBtn.title = 'AI API Key not configured in plugin settings';
                explainBtn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:0.5rem"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    AI Key Missing
                `;
            }
        }

        // Load plugins on start
        loadPlugins();
    </script>
</body>

</html>