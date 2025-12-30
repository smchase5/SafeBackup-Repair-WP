<?php

if (!class_exists('SBWP_AI_Settings_REST')) {
    class SBWP_AI_Settings_REST
    {
        private $api_key_option = 'sbwp_openai_api_key';

        public function init()
        {
            // Only register routes in base plugin if Pro is NOT active
            // Pro has its own AI routes in class-sbwp-pro-loader.php
            if (!apply_filters('sbwp_is_pro_active', false)) {
                add_action('rest_api_init', array($this, 'register_routes'));
            }
        }

        public function register_routes()
        {
            register_rest_route('sbwp/v1', '/settings/ai', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_settings'),
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ));

            register_rest_route('sbwp/v1', '/settings/ai', array(
                'methods' => 'POST',
                'callback' => array($this, 'save_settings'),
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ));

            register_rest_route('sbwp/v1', '/ai/humanize-report', array(
                'methods' => 'POST',
                'callback' => array($this, 'humanize_report'),
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ));
        }

        public function get_settings()
        {
            $key = get_option($this->api_key_option, '');
            return rest_ensure_response(array(
                'is_configured' => !empty($key),
                'masked_key' => !empty($key) ? substr($key, 0, 3) . '...' . substr($key, -4) : ''
            ));
        }

        public function save_settings($request)
        {
            $params = $request->get_json_params();
            $key = sanitize_text_field($params['api_key']);

            if (empty($key)) {
                delete_option($this->api_key_option);
                $this->sync_key_to_file('');
            } else {
                update_option($this->api_key_option, $key);
                $this->sync_key_to_file($key);
            }

            return $this->get_settings();
        }

        private function sync_key_to_file($key)
        {
            $file = SBWP_PLUGIN_DIR . '.sbwp-ai-key';
            if (empty($key)) {
                if (file_exists($file))
                    @unlink($file);
            } else {
                file_put_contents($file, $key);
                @chmod($file, 0600);
            }
        }

        public function humanize_report($request)
        {
            $params = $request->get_json_params();
            $session_id = intval($params['session_id']);
            // Fetch session data logic here if needed...
            // For now, implement a generic explainer or mock it if session not found.

            $key = get_option($this->api_key_option);
            if (!$key)
                return new WP_Error('no_key', 'OpenAI API Key not configured');

            // ... Implementation for report humanization ...
            // Keeping it simple for this task as the focus is on Recovery Portal
            return rest_ensure_response(array('summary' => 'AI Summary Placeholder'));
        }
    }
}
