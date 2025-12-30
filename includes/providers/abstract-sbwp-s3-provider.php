<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once SBWP_PLUGIN_DIR . 'includes/libs/class-sbwp-s3-client.php';

abstract class SBWP_S3_Base_Provider implements SBWP_Cloud_Provider
{
    protected $option_name;

    abstract public function get_id();
    abstract public function get_name();

    // Some providers trigger specific endpoint logic
    abstract protected function get_endpoint($region);

    public function __construct()
    {
        $this->option_name = 'sbwp_' . $this->get_id() . '_creds';
    }

    public function is_connected()
    {
        $creds = get_option($this->option_name);
        return !empty($creds['access_key']) && !empty($creds['secret_key']) && !empty($creds['bucket']);
    }

    public function get_user_info()
    {
        $creds = get_option($this->option_name);
        if ($this->is_connected()) {
            return array('email' => $creds['bucket'] . ' (' . $creds['region'] . ')');
        }
        return array();
    }

    public function disconnect()
    {
        delete_option($this->option_name);
        return true;
    }

    /**
     * Connect is just saving credentials for S3
     */
    public function connect($params)
    {
        $access_key = sanitize_text_field($params['access_key']);
        $secret_key = sanitize_text_field($params['secret_key']);
        $bucket = sanitize_text_field($params['bucket']);
        $region = sanitize_text_field($params['region']);
        $endpoint_override = isset($params['endpoint']) ? sanitize_text_field($params['endpoint']) : '';

        if (empty($access_key) || empty($secret_key) || empty($bucket)) {
            return new WP_Error('missing_fields', 'Access Key, Secret Key and Bucket are required.');
        }

        // Test Connection
        $client = new SBWP_S3_Client($access_key, $secret_key, $region, $endpoint_override ?: $this->get_endpoint($region));
        $test = $client->check_bucket($bucket);

        if (is_wp_error($test)) {
            return $test;
        }

        update_option($this->option_name, array(
            'access_key' => $access_key,
            'secret_key' => $secret_key,
            'bucket' => $bucket,
            'region' => $region,
            'endpoint' => $endpoint_override
        ));

        return true;
    }

    public function upload_backup($file_path, $file_name)
    {
        $creds = get_option($this->option_name);
        if (!$creds)
            return new WP_Error('not_connected', 'Not connected');

        $endpoint = !empty($creds['endpoint']) ? $creds['endpoint'] : $this->get_endpoint($creds['region']);
        $client = new SBWP_S3_Client($creds['access_key'], $creds['secret_key'], $creds['region'], $endpoint);

        $content = file_get_contents($file_path);

        // Folder structure: SafeBackup/SiteName/filename
        $site_name = sanitize_title(get_bloginfo('name'));
        $s3_path = "SafeBackup/{$site_name}/{$file_name}";

        return $client->put_object($creds['bucket'], $s3_path, $content);
    }

    // Adding the missing interface methods if strictly enforced,
    // although our Interface might change to direct-connect style for S3?
    public function get_auth_url($client_id, $client_secret, $callback_url)
    {
        return '';
    }
    public function authenticate($code, $client_id, $client_secret, $callback_url)
    {
        return true;
    }
    public function list_backups($limit = 10)
    {
        $client = $this->get_client();
        if (is_wp_error($client))
            return $client;

        $creds = get_option($this->option_name);
        $site_name = sanitize_title(get_bloginfo('name'));
        $prefix = "SafeBackup/{$site_name}/";

        $files = $client->list_objects($creds['bucket'], $prefix, $limit);

        if (is_wp_error($files))
            return $files;

        // Map S3 keys to standard format
        $backups = array();
        foreach ($files as $file) {
            // Skip folders
            if (substr($file['key'], -1) === '/')
                continue;

            $backups[] = array(
                'id' => $file['key'], // For S3, ID is the Key
                'name' => basename($file['key']),
                'created' => $file['time'],
                'size' => isset($file['size']) ? $file['size'] : 0
            );
        }

        // Sort by created desc (optional, S3 usually returns alphabetical)
        usort($backups, function ($a, $b) {
            return $b['created'] - $a['created'];
        });

        return array_slice($backups, 0, $limit);
    }

    public function delete_backup($file_id)
    {
        $client = $this->get_client();
        if (is_wp_error($client))
            return $client;

        $creds = get_option($this->option_name);
        return $client->delete_object($creds['bucket'], $file_id);
    }

    private function get_client()
    {
        $creds = get_option($this->option_name);
        if (!$creds)
            return new WP_Error('not_connected', 'Not connected');

        $endpoint = !empty($creds['endpoint']) ? $creds['endpoint'] : $this->get_endpoint($creds['region']);
        return new SBWP_S3_Client($creds['access_key'], $creds['secret_key'], $creds['region'], $endpoint);
    }
}
