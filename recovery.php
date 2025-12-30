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

// Key file location (in plugin directory)
$key_file = $plugin_dir . '/.sbwp-recovery-key';
$pin_file = $plugin_dir . '/.sbwp-recovery-pin';

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
    global $plugin_dir;
    $file = $plugin_dir . '/.sbwp-ai-key';
    if (file_exists($file))
        return trim(file_get_contents($file));
    return '';
}

function sbwp_call_openai($error_msg, $key)
{
    $url = 'https://api.openai.com/v1/chat/completions';
    $data = [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a WordPress expert. Explain this PHP error clearly to a non-technical user and suggest a fix.'],
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
            foreach ($dirs as $dir) {
                $basename = basename($dir);
                $is_disabled = substr($basename, -9) === '.disabled';
                $name = $is_disabled ? substr($basename, 0, -9) : $basename;

                // Try to read plugin header
                $display_name = $name;
                $main_file = $dir . '/' . ($is_disabled ? $name : $basename) . '.php';
                if (!file_exists($main_file)) {
                    // Try any PHP file with Plugin Name header
                    $php_files = glob($dir . '/*.php');
                    foreach ($php_files as $pf) {
                        $content = file_get_contents($pf);
                        if (preg_match('/Plugin Name:\s*(.+)/i', $content, $m)) {
                            $display_name = trim($m[1]);
                            break;
                        }
                    }
                } else {
                    $content = file_get_contents($main_file);
                    if (preg_match('/Plugin Name:\s*(.+)/i', $content, $m)) {
                        $display_name = trim($m[1]);
                    }
                }

                $plugins[] = [
                    'folder' => $basename,
                    'name' => $display_name,
                    'slug' => $name,
                    'disabled' => $is_disabled,
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

            $is_disabled = substr($folder, -9) === '.disabled';

            if ($new_state === 'disable' && !$is_disabled) {
                $new_path = $current_path . '.disabled';
                if (rename($current_path, $new_path)) {
                    echo json_encode(['success' => true, 'new_folder' => basename($new_path)]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to disable plugin']);
                }
            } elseif ($new_state === 'enable' && $is_disabled) {
                $new_path = substr($current_path, 0, -9);
                if (rename($current_path, $new_path)) {
                    echo json_encode(['success' => true, 'new_folder' => basename($new_path)]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to enable plugin']);
                }
            } else {
                echo json_encode(['success' => true, 'new_folder' => $folder]);
            }
            exit;

        case 'get_debug_log':
            $log_content = "";

            // 1. Read Flight Recorder Log
            $flight_log = $plugin_dir . '/.sbwp-crash.log';
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
        case 'test':
            // Minimal test endpoint
            echo json_encode(['success' => true, 'test' => 'working']);
            exit;

        case 'scan_crash':
            $log_excerpt = '';
            $debug_info = [];

            // 1. Read Flight Recorder Log
            $flight_log = $plugin_dir . '/.sbwp-crash.log';
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

            // 2. Read WP Debug Log
            $log_path = $wp_content_dir . '/debug.log';
            $debug_info['debug_log_path'] = $log_path;
            $debug_info['debug_log_exists'] = file_exists($log_path);

            if (file_exists($log_path)) {
                $content = @shell_exec('tail -n 30 ' . escapeshellarg($log_path) . ' 2>/dev/null');
                if (empty($content)) {
                    $size = filesize($log_path);
                    $content = @file_get_contents($log_path, false, null, max(0, $size - 10000));
                    if ($content) {
                        $lines = explode("\n", $content);
                        $content = implode("\n", array_slice($lines, -30));
                    }
                }
                if ($content) {
                    $log_excerpt .= "--- WP DEBUG LOG ---\n" . trim($content);
                }
            }

            if (empty(trim($log_excerpt))) {
                $log_excerpt = "No crash logs found. Debug: " . json_encode($debug_info);
            }

            // 3. Get plugin list
            $plugins = [];
            $plugin_dirs_list = @glob($plugins_dir . '/*', GLOB_ONLYDIR);
            if ($plugin_dirs_list) {
                foreach ($plugin_dirs_list as $dir) {
                    $plugins[] = basename($dir);
                }
            }

            echo json_encode([
                'success' => true,
                'log' => $log_excerpt,
                'plugins' => $plugins,
                'suspects' => [],
                'php_version' => PHP_VERSION
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

            $key = sbwp_get_ai_key();
            if (!$key) {
                echo json_encode(['success' => false, 'error' => 'AI Key not configured.']);
                exit;
            }

            $prompt = "You are a WordPress recovery expert.\n";
            $prompt .= "Analyze the error log and identify the plugin causing the crash.\n";
            $prompt .= "Return ONLY valid JSON: { \"suspected_plugin_folder\": \"folder-name\", \"confidence\": 0.95, \"explanation\": \"...\", \"fix_action\": \"disable\" }\n";
            $prompt .= "If unsure, set confidence < 0.5.\n\n";

            // Inject intelligence report if we have suspects
            if (!empty($suspects)) {
                $prompt .= "=== INTELLIGENCE REPORT (HIGH PRIORITY) ===\n";
                $prompt .= "We scanned plugin code and found these matches for symbols in the error log:\n";
                foreach ($suspects as $plugin => $symbols) {
                    $unique_symbols = array_unique((array) $symbols);
                    $prompt .= "- Plugin '$plugin' contains: " . implode(', ', $unique_symbols) . "\n";
                }
                $prompt .= "USE THIS INTELLIGENCE! If a plugin contains the crashing class/namespace, it is almost certainly the culprit.\n\n";
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
            $log_path = $wp_content_dir . '/debug.log';
            if (!file_exists($log_path)) {
                echo json_encode(['success' => false, 'error' => 'No log found']);
                exit;
            }

            // Get last error
            $lines = file($log_path);
            $last_error = '';
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (strpos($lines[$i], 'Fatal error') !== false || strpos($lines[$i], 'Parse error') !== false) {
                    $last_error = $lines[$i];
                    break;
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

            $new_name = $target . '.disabled';
            if (rename($target, $new_name)) {
                // Log for Undo
                $undo_log = $plugin_dir . '/.sbwp-ai-undo-log';
                $entry = json_encode(['original' => $folder, 'renamed' => $folder . '.disabled', 'time' => time()]) . "\n";
                file_put_contents($undo_log, $entry, FILE_APPEND);

                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to rename directory']);
            }
            exit;

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

            $current = $plugins_dir . '/' . $data['renamed'];
            $original = $plugins_dir . '/' . $data['original'];

            if (is_dir($current)) {
                rename($current, $original);
                // Save remaining lines back
                file_put_contents($undo_log, implode("", $lines));
                echo json_encode(['success' => true, 'restored' => $data['original']]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Plugin folder not found for undo']);
            }
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
            color: #ef4444;
        }

        .log-viewer .warning {
            color: #f59e0b;
        }

        .hidden {
            display: none;
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
                <p id="ai-status-text" style="text-align:center;color:#94a3b8;margin-bottom:2rem">Initializing...</p>

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
                <h2 style="text-align:center;color:#a5b4fc">Analysis Complete</h2>
                <div class="diagnosis-card">
                    <div style="display:flex;justify-content:space-between;margin-bottom:0.5rem">
                        <span style="font-weight:bold;color:white;display:flex;align-items:center;gap:0.5rem">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                                fill="none" stroke="#ef4444" stroke-width="2">
                                <path
                                    d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                <line x1="12" y1="9" x2="12" y2="13" />
                                <line x1="12" y1="17" x2="12.01" y2="17" />
                            </svg>
                            <span id="suspect-name">Plugin Name</span>
                        </span>
                        <span style="color:#10b981" id="confidence-score">85% Confidence</span>
                    </div>
                    <div class="confidence-bar">
                        <div id="confidence-fill" class="confidence-fill"></div>
                    </div>
                    <p style="margin-top:1rem;color:#e2e8f0;line-height:1.5" id="ai-explanation">Explanation goes
                        here...</p>
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

            <div id="ai-success-view" class="hidden" style="text-align:center">
                <div style="display:flex;justify-content:center;margin-bottom:1rem">
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none"
                        stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                        <polyline points="22 4 12 14.01 9 11.01" />
                    </svg>
                </div>
                <h2 style="color:white;margin-bottom:1rem">Fixed!</h2>
                <p style="color:#cbd5e1;margin-bottom:2rem">We've disabled the problematic plugin. Your site should be
                    working now.</p>
                <div style="display:flex;gap:1rem;justify-content:center">
                    <a href="/wp-admin" class="btn btn-primary">Go to Dashboard</a>
                    <button class="btn btn-outline" onclick="undoFix()">Undo Change</button>
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
            <span class="alert-icon">⚠️</span>
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
                    <button class="btn btn-outline" onclick="loadPlugins()">↻ Refresh</button>
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
                    <button class="btn btn-outline" onclick="loadThemes()">↻ Refresh</button>
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
                        <button class="btn btn-outline" style="border-color:#8b5cf6;color:#a78bfa"
                            onclick="explainError()">
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
                        <button class="btn btn-outline" onclick="loadDebugLog()">↻ Refresh</button>
                    </div>
                </div>
                <div id="ai-explanation" class="hidden"
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
            const container = document.getElementById('ai-explanation');
            container.classList.remove('hidden');
            container.innerHTML = 'Thinking... ✨';

            try {
                const res = await fetch(`${baseUrl}?${authParams}&action=explain_error`);
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                container.innerHTML = `<strong>🤖 AI Explanation:</strong><br/>${data.explanation}`;
            } catch (e) {
                container.innerHTML = `<span style="color:#f87171">Error: ${e.message}</span>`;
            }
        }

        function showStatus(message, type = 'success') {
            const container = document.getElementById('status-container');
            container.innerHTML = `<div class="status-msg ${type}">${message}</div>`;
            setTimeout(() => container.innerHTML = '', 3000);
        }

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
                            <span class="plugin-icon">${p.disabled ? '⏸️' : '✅'}</span>
                            <div>
                                <div class="plugin-name">${escapeHtml(p.name)} ${p.is_self ? '<span class="badge">THIS PLUGIN</span>' : ''}</div>
                                <div class="plugin-folder">${escapeHtml(p.folder)}</div>
                            </div>
                        </div>
                        ${p.is_self ? '' : `
                            <button class="btn ${p.disabled ? 'btn-success' : 'btn-danger'}" 
                                    onclick="togglePlugin('${p.folder}', '${p.disabled ? 'enable' : 'disable'}')">
                                ${p.disabled ? '▶️ Enable' : '⏸️ Disable'}
                            </button>
                        `}
                    </div>
                `).join('');
            } catch (e) {
                list.innerHTML = `<div style="color:#ef4444;padding:1rem">Error: ${e.message}</div>`;
            }
        }

        async function togglePlugin(folder, state) {
            try {
                const res = await fetch(`${baseUrl}?${authParams}&action=toggle_plugin`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `folder=${encodeURIComponent(folder)}&state=${state}`
                });
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                showStatus(`Plugin ${state}d successfully!`);
                loadPlugins();
            } catch (e) {
                showStatus(`Error: ${e.message}`, 'error');
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
                            <span class="plugin-icon">${t.disabled ? '⏸️' : '🎨'}</span>
                            <div>
                                <div class="plugin-name">${escapeHtml(t.name)}</div>
                                <div class="plugin-folder">${escapeHtml(t.folder)}</div>
                            </div>
                        </div>
                        <button class="btn ${t.disabled ? 'btn-success' : 'btn-danger'}" 
                                onclick="toggleTheme('${t.folder}', '${t.disabled ? 'enable' : 'disable'}')">
                            ${t.disabled ? '▶️ Enable' : '⏸️ Disable'}
                        </button>
                    </div>
                `).join('');
            } catch (e) {
                list.innerHTML = `<div style="color:#ef4444;padding:1rem">Error: ${e.message}</div>`;
            }
        }

        async function toggleTheme(folder, state) {
            try {
                const res = await fetch(`${baseUrl}?${authParams}&action=toggle_theme`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `folder=${encodeURIComponent(folder)}&state=${state}`
                });
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                showStatus(`Theme ${state}d successfully!`);
                loadThemes();
            } catch (e) {
                showStatus(`Error: ${e.message}`, 'error');
            }
        }

        async function loadDebugLog() {
            const container = document.getElementById('log-content');
            container.innerHTML = '<div class="loading"></div> Loading...';

            try {
                const res = await fetch(`${baseUrl}?${authParams}&action=get_debug_log`);
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                // Highlight errors and warnings
                let content = escapeHtml(data.content);
                content = content.replace(/(Fatal error.*)/gi, '<span class="error">$1</span>');
                content = content.replace(/(Parse error.*)/gi, '<span class="error">$1</span>');
                content = content.replace(/(Warning.*)/gi, '<span class="warning">$1</span>');

                container.innerHTML = content || 'No log entries found.';
            } catch (e) {
                container.innerHTML = `<span class="error">Error: ${e.message}</span>`;
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
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
        const statusText = document.getElementById('ai-status-text');

        async function explainError() {
            const container = document.getElementById('ai-explanation');
            container.classList.remove('hidden');
            container.innerHTML = 'Thinking... ✨';

            try {
                const res = await fetch(`${baseUrl}?${authParams}&action=explain_error`);
                const data = await res.json();

                if (!data.success) throw new Error(data.error);

                container.innerHTML = `<strong>🤖 AI Explanation:</strong><br/>${data.explanation}`;
            } catch (e) {
                container.innerHTML = `<span style="color:#f87171">Error: ${e.message}</span>`;
            }
        }

        function startAiScan() {
            modal.classList.add('open');
            switchView('scan');
            setStep(1);
            statusText.innerText = "Reading debug logs...";

            // 1. Scan
            fetch(`${baseUrl}?${authParams}&action=scan_crash`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.error || 'Scan failed');

                    setStep(2);
                    statusText.innerText = "Consulting AI Expert...";

                    // 2. Analyze
                    const formData = new FormData();
                    formData.append('log', data.log);
                    data.plugins.forEach(p => formData.append('plugins[]', p));

                    // Add intelligence if available
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
                    if (!data.success) throw new Error(data.error || 'Analysis failed');
                    if (!data.analysis) throw new Error('AI returned no analysis');

                    currentAnalysis = data.analysis;
                    showResult(data.analysis);
                })
                .catch(err => {
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
            confidenceScore.innerText = `${conf}% Confidence`;
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
                        const successTitle = document.querySelector('#ai-success-view h2');
                        const successMsg = document.querySelector('#ai-success-view p');

                        if (isUp) {
                            successTitle.innerText = "Fixed & Verified! 🟢";
                            successMsg.innerText = "Plugin disabled and site responded to health check. You are good to go!";
                        } else {
                            successTitle.innerText = "Plugin Disabled (Site Still Down?) ⚠️";
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

        // Load plugins on start
        loadPlugins();
    </script>
</body>

</html>