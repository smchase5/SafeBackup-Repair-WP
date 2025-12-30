<?php

if (!defined('ABSPATH')) {
    exit;
}

class SBWP_Google_Drive_Provider implements SBWP_Cloud_Provider
{
    private $token_option = 'sbwp_gdrive_token';
    private $creds_option = 'sbwp_gdrive_creds';

    public function get_id()
    {
        return 'gdrive';
    }

    public function get_name()
    {
        return 'Google Drive';
    }

    public function is_connected()
    {
        $token = get_option($this->token_option);
        return !empty($token) && !empty($token['access_token']);
    }

    /**
     * Get the Authorization URL for the consent screen.
     */
    public function get_auth_url($client_id, $client_secret, $callback_url)
    {
        // Generate and store state nonce for security
        $state = wp_create_nonce('sbwp_gdrive_auth');
        set_transient('sbwp_gdrive_auth_state', $state, 600); // 10 minutes

        $base = 'https://accounts.google.com/o/oauth2/v2/auth';
        $params = array(
            'client_id' => $client_id,
            'redirect_uri' => $callback_url,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email',
            'access_type' => 'offline',
            'prompt' => 'consent', // Force to ensure we get a refresh_token
            'state' => $state
        );
        return add_query_arg($params, $base);
    }

    /**
     * Authenticate handling the OAuth callback.
     */
    public function authenticate($code, $client_id, $client_secret, $callback_url)
    {
        // Manager verifies state before calling this.

        $url = 'https://oauth2.googleapis.com/token';
        $body = array(
            'code' => $code,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => $callback_url,
            'grant_type' => 'authorization_code'
        );

        $response = wp_remote_post($url, array('body' => $body));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || isset($data['error'])) {
            return new WP_Error('auth_failed', isset($data['error_description']) ? $data['error_description'] : 'Authentication failed');
        }

        // Store Tokens
        // $data usually contains: access_token, refresh_token, expires_in, scope, token_type
        $data['created_at'] = time(); // Track when we got it
        update_option($this->token_option, $data);

        // Save Client Creds (if not using constants)
        if (!defined('SBWP_GDRIVE_CLIENT_ID')) {
            update_option($this->creds_option, array(
                'client_id' => $client_id,
                'client_secret' => $client_secret
            ));
        }

        // Fetch user info immediately to confirm identity
        $this->refresh_user_info();

        return true;
    }

    public function disconnect()
    {
        // Optional: Revoke token on Google side?
        $token = $this->get_valid_token();
        if ($token) {
            wp_remote_get('https://accounts.google.com/o/oauth2/revoke?token=' . $token);
        }

        delete_option($this->token_option);
        delete_option($this->creds_option);
        delete_option('sbwp_gdrive_user_info');
        return true;
    }

    public function get_user_info()
    {
        return get_option('sbwp_gdrive_user_info', array());
    }

    /**
     * Helper to get a valid access token, performing refresh if needed.
     */
    private function get_valid_token()
    {
        $token_data = get_option($this->token_option);
        if (!$token_data || empty($token_data['access_token'])) {
            return false;
        }

        // Check Expiry (expires_in is usually 3600s)
        $expires_in = isset($token_data['expires_in']) ? (int) $token_data['expires_in'] : 3600;
        $created = isset($token_data['created_at']) ? (int) $token_data['created_at'] : 0;

        // Refresh 5 minutes before expiry
        if ((time() - $created) > ($expires_in - 300)) {
            $refreshed = $this->refresh_token();
            if ($refreshed) {
                return $refreshed;
            }
            // If refresh failed, fall through to try existing token (maybe clock skew) or fail
        }

        return $token_data['access_token'];
    }

    private function refresh_token()
    {
        $token_data = get_option($this->token_option);

        // Get Creds (Option or Constant)
        $client_id = defined('SBWP_GDRIVE_CLIENT_ID') ? SBWP_GDRIVE_CLIENT_ID : '';
        $client_secret = defined('SBWP_GDRIVE_CLIENT_SECRET') ? SBWP_GDRIVE_CLIENT_SECRET : '';

        if (!$client_id) {
            $creds = get_option($this->creds_option);
            $client_id = isset($creds['client_id']) ? $creds['client_id'] : '';
            $client_secret = isset($creds['client_secret']) ? $creds['client_secret'] : '';
        }

        if (empty($token_data['refresh_token']) || empty($client_id)) {
            return false;
        }

        $url = 'https://oauth2.googleapis.com/token';
        $body = array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $token_data['refresh_token'],
            'grant_type' => 'refresh_token'
        );

        $response = wp_remote_post($url, array('body' => $body));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            error_log('SBWP Google Refresh Failed: ' . print_r(wp_remote_retrieve_body($response), true));
            return false;
        }

        $new_data = json_decode(wp_remote_retrieve_body($response), true);

        // Update stored data
        $token_data['access_token'] = $new_data['access_token'];
        $token_data['expires_in'] = $new_data['expires_in'];
        $token_data['created_at'] = time();

        // Sometimes a new refresh token is returned
        if (!empty($new_data['refresh_token'])) {
            $token_data['refresh_token'] = $new_data['refresh_token'];
        }

        update_option($this->token_option, $token_data);

        return $token_data['access_token'];
    }

    private function refresh_user_info()
    {
        $token = $this->get_valid_token();
        if (!$token)
            return;

        $response = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', array(
            'headers' => array('Authorization' => 'Bearer ' . $token)
        ));

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            update_option('sbwp_gdrive_user_info', $data);
        }
    }

    public function upload_backup($file_path, $file_name)
    {
        $token = $this->get_valid_token();
        if (!$token) {
            // Try explicit refresh
            $token = $this->refresh_token();
            if (!$token) {
                return new WP_Error('not_connected', 'Google Drive disconnected. Please reconnect.');
            }
        }

        // 1. Ensure Folder Structure
        $folder_id = $this->ensure_folder_structure($token);
        if (is_wp_error($folder_id))
            return $folder_id;

        // 2. Upload
        $metadata = array(
            'name' => $file_name,
            'parents' => array($folder_id)
        );

        $boundary = 'sbwp_boundary_' . md5(time());
        $body = "--$boundary\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= json_encode($metadata) . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: application/zip\r\n\r\n"; // Assuming zip
        $body .= file_get_contents($file_path) . "\r\n";
        $body .= "--$boundary--";

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'multipart/related; boundary=' . $boundary,
                'Content-Length' => strlen($body)
            ),
            'body' => $body,
            'timeout' => 300
        );

        $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart';
        $response = wp_remote_post($url, $args);

        // Handle 401 specifically
        if (wp_remote_retrieve_response_code($response) === 401) {
            $token = $this->refresh_token();
            if ($token) {
                $args['headers']['Authorization'] = 'Bearer ' . $token;
                $response = wp_remote_post($url, $args);
            }
        }

        if (is_wp_error($response))
            return $response;

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('upload_failed', "Google Drive Upload Error: $code");
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['id'];
    }

    private function ensure_folder_structure($token)
    {
        $root_name = 'SafeBackup';
        $site_name = get_bloginfo('name');

        // Root
        $root_id = $this->find_folder($root_name, 'root', $token);
        if (!$root_id)
            $root_id = $this->create_folder($root_name, null, $token);
        if (is_wp_error($root_id))
            return $root_id;

        // Site
        $site_id = $this->find_folder($site_name, $root_id, $token);
        if (!$site_id)
            $site_id = $this->create_folder($site_name, $root_id, $token);

        return $site_id;
    }

    private function find_folder($name, $parent_id, $token)
    {
        $query = "mimeType='application/vnd.google-apps.folder' and name='" . addslashes($name) . "' and trashed=false";
        if ($parent_id !== 'root')
            $query .= " and '$parent_id' in parents";

        $url = 'https://www.googleapis.com/drive/v3/files?q=' . urlencode($query);
        $response = wp_remote_get($url, array('headers' => array('Authorization' => 'Bearer ' . $token)));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200)
            return false;
        $data = json_decode(wp_remote_retrieve_body($response), true);

        return !empty($data['files']) ? $data['files'][0]['id'] : false;
    }

    private function create_folder($name, $parent_id, $token)
    {
        $metadata = array('name' => $name, 'mimeType' => 'application/vnd.google-apps.folder');
        if ($parent_id)
            $metadata['parents'] = array($parent_id);

        $response = wp_remote_post('https://www.googleapis.com/drive/v3/files', array(
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'),
            'body' => json_encode($metadata)
        ));

        if (is_wp_error($response))
            return $response;
        $data = json_decode(wp_remote_retrieve_body($response), true);

        return isset($data['id']) ? $data['id'] : new WP_Error('create_folder_fail', 'Failed to create folder');
    }

    public function list_backups($limit = 10)
    {
        $token = $this->get_valid_token();
        if (!$token)
            $token = $this->refresh_token();
        if (!$token)
            return new WP_Error('not_connected', 'Google Drive disconnected');

        // Find Site Folder ID
        $folder_id = $this->ensure_folder_structure($token);
        if (is_wp_error($folder_id))
            return $folder_id;

        $query = "mimeType!='application/vnd.google-apps.folder' and '$folder_id' in parents and trashed=false";
        $url = "https://www.googleapis.com/drive/v3/files?q=" . urlencode($query) . "&orderBy=createdTime desc&pageSize=$limit&fields=files(id,name,createdTime,size)";

        $response = wp_remote_get($url, array('headers' => array('Authorization' => 'Bearer ' . $token)));

        if (is_wp_error($response))
            return $response;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['files']))
            return array();

        $backups = array();
        foreach ($data['files'] as $file) {
            $backups[] = array(
                'id' => $file['id'],
                'name' => $file['name'],
                'created' => strtotime($file['createdTime']),
                'size' => isset($file['size']) ? (int) $file['size'] : 0
            );
        }

        return $backups;
    }

    public function delete_backup($file_id)
    {
        $token = $this->get_valid_token();
        if (!$token)
            $token = $this->refresh_token();
        if (!$token)
            return new WP_Error('not_connected', 'Google Drive disconnected');

        $url = "https://www.googleapis.com/drive/v3/files/$file_id";
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array('Authorization' => 'Bearer ' . $token)
        ));

        if (is_wp_error($response))
            return $response;

        $code = wp_remote_retrieve_response_code($response);
        // 204 No Content success
        if ($code >= 200 && $code < 300)
            return true;

        return new WP_Error('gdrive_delete_fail', "Delete failed: $code");
    }
}
