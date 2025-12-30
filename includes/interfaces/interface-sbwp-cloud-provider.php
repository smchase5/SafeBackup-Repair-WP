<?php

if (!defined('ABSPATH')) {
    exit;
}

interface SBWP_Cloud_Provider
{
    /**
     * Get unique provider ID (e.g., 'gdrive')
     */
    public function get_id();

    /**
     * Get display name
     */
    public function get_name();

    /**
     * Check if connected
     */
    public function is_connected();

    /**
     * Get authorization URL for user
     */
    public function get_auth_url($client_id, $client_secret, $callback_url);

    /**
     * Exchange auth code for tokens
     */
    public function authenticate($code, $client_id, $client_secret, $callback_url);

    /**
     * Disconnect and clear tokens
     */
    public function disconnect();

    /**
     * Upload a backup file
     * @param string $file_path Absolute path to local file
     * @param string $file_name Name to save as
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function upload_backup($file_path, $file_name);

    /**
     * Get user info (email/name) associated with the account
     */
    public function get_user_info();
    /**
     * List recent backups from the provider
     * @param int $limit Number of backups to retrieve
     * @return array|WP_Error Array of ['id' => '...', 'name' => '...', 'created' => timestamp]
     */
    public function list_backups($limit = 10);

    /**
     * Delete a specific backup file
     * @param string $file_id The ID or Path of the file to delete
     * @return bool|WP_Error
     */
    public function delete_backup($file_id);
}
