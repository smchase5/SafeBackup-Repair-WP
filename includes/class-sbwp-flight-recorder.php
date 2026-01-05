<?php

/**
 * Flight Recorder
 * 
 * Silently monitors for fatal errors and logs them to a custom file.
 * This ensures we have crash data even if WP_DEBUG is off.
 */

if (!class_exists('SBWP_Flight_Recorder')) {
    class SBWP_Flight_Recorder
    {
        private $log_file;

        public function init()
        {
            // Use secure uploads directory for crash log
            $upload_dir = wp_upload_dir();
            $secure_dir = $upload_dir['basedir'] . '/sbwp-secure';

            // Ensure directory exists
            if (!file_exists($secure_dir)) {
                wp_mkdir_p($secure_dir);
                file_put_contents($secure_dir . '/.htaccess', "Order deny,allow\nDeny from all");
                file_put_contents($secure_dir . '/index.php', '<?php // Silence is golden.');
            }

            $this->log_file = $secure_dir . '/crash.log';
            register_shutdown_function(array($this, 'handle_shutdown'));
            set_exception_handler(array($this, 'handle_exception'));
        }

        public function handle_exception($exception)
        {
            $error = array(
                'type' => E_ERROR,
                'message' => 'Uncaught Exception: ' . $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            );
            $this->log_crash($error);
        }

        public function handle_shutdown()
        {
            $error = error_get_last();

            // Broaden capture: Fatal, Parse, Recoverable, User Error, and Critical Uncaughts
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_RECOVERABLE_ERROR, E_USER_ERROR])) {
                $this->log_crash($error);
            }
        }

        private function log_crash($error)
        {
            // Don't log if file is huge (> 5MB)
            if (file_exists($this->log_file) && filesize($this->log_file) > 5 * 1024 * 1024) {
                return; // Rotate logic could go here, for now just stop
            }

            $time = date('Y-m-d H:i:s');
            $message = strip_tags($error['message']); // Clean HTML
            $file = $error['file'];
            $line = $error['line'];

            $log_entry = "[$time] CRASH: $message in $file:$line\n";

            // Append to our private flight recorder log
            file_put_contents($this->log_file, $log_entry, FILE_APPEND);

            // Ensure it's secure (though typically it's .htaccess protected or similar)
            if (!file_exists($this->log_file) || (fileperms($this->log_file) & 0777) !== 0600) {
                @chmod($this->log_file, 0600);
            }

            // Send Alert if enabled
            $this->send_alert($error);
        }

        private function send_alert($error)
        {
            $settings = get_option('sbwp_settings', array());
            if (empty($settings['alerts_enabled']) || empty($settings['alert_email'])) {
                return;
            }

            // Throttle: 1 email per hour (store throttle in same secure dir as log)
            $upload_dir = wp_upload_dir();
            $secure_dir = $upload_dir['basedir'] . '/sbwp-secure';
            $alert_file = $secure_dir . '/last-alert';
            $last_alert = file_exists($alert_file) ? (int) file_get_contents($alert_file) : 0;

            if ((time() - $last_alert) < 3600) {
                return; // Too soon
            }

            // Retrieve Recovery URL with key token (from secure location)
            $key_file = $secure_dir . '/recovery-key';
            $recovery_key = file_exists($key_file) ? trim(file_get_contents($key_file)) : '';

            // Build full recovery URL
            $recovery_url = content_url() . '/plugins/SafeBackup-Repair-WP/recovery.php';
            if ($recovery_key) {
                $recovery_url .= '?key=' . $recovery_key;
            }

            $subject = '🚨 Website Crash Detected!';
            $body = "A fatal error has crashed your website.\n\n";
            $body .= "Error: " . strip_tags($error['message']) . "\n";
            $body .= "File: " . $error['file'] . ":" . $error['line'] . "\n\n";
            $body .= "Click here to access the Recovery Portal:\n";
            $body .= $recovery_url . "\n\n";
            $body .= "You will need your Recovery PIN to log in.\n";
            $body .= "(This alert is throttled to once per hour)";

            $sent = false;
            if (function_exists('wp_mail')) {
                $sent = wp_mail($settings['alert_email'], $subject, $body);
            } else {
                // Fallback for very early crashes
                $sent = @mail($settings['alert_email'], $subject, $body, "From: WordPress <wordpress@" . $_SERVER['HTTP_HOST'] . ">");
            }

            if ($sent) {
                file_put_contents($alert_file, time());
            }
        }
    }
}
