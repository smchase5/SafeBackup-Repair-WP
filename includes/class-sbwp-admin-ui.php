<?php

/**
 * The admin-specific functionality of the plugin.
 */
class SBWP_Admin_UI
{

    /**
     * Register the stylesheets for the admin area.
     */
    public function enqueue_styles()
    {
        // Enqueue the compiled CSS from Vite
        $css_file = plugin_dir_url(dirname(__FILE__)) . 'assets/compiled/assets/main.css';
        // Note: Vite manifest logic will be needed for hashed filenames in production, 
        // but for dev/simple setup we can target the output or use a helper. 
        // For now, let's assume a static name or update this after build.
        // Actually, let's check for the file in the manifest if possible, but simplest 
        // is to enqueue the main css found in assets/compiled. 

        // For this phase, we'll try to enqueue 'style.css' if generated, 
        // otherwise rely on JS importing CSS in dev mode (Vite typically handles this).
        // In production build, Vite emits CSS files.

        // We will look for a generated CSS file in the build directory.
        // This is a placeholder; real implementation needs to parse manifest.json
    }

    /**
     * Register the JavaScript for the admin area.
     */
    public function enqueue_scripts()
    {
        $manifest_path = plugin_dir_path(dirname(__FILE__)) . 'assets/compiled/.vite/manifest.json';
        $script_url = SBWP_PLUGIN_URL . 'assets/compiled/assets/main.js'; // Fallback

        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path), true);
            $entry = $manifest['src/main.tsx'] ?? null;

            if ($entry) {
                $script_url = SBWP_PLUGIN_URL . 'assets/compiled/' . $entry['file'];

                // Enqueue CSS from Entry
                if (isset($entry['css'])) {
                    foreach ($entry['css'] as $css) {
                        wp_enqueue_style('sbwp-style-' . md5($css), SBWP_PLUGIN_URL . 'assets/compiled/' . $css, array(), SBWP_VERSION);
                    }
                }

                // Enqueue CSS from Imports (Chunks)
                if (isset($entry['imports'])) {
                    foreach ($entry['imports'] as $import_key) {
                        if (isset($manifest[$import_key]['css'])) {
                            foreach ($manifest[$import_key]['css'] as $css) {
                                wp_enqueue_style('sbwp-style-' . md5($css), SBWP_PLUGIN_URL . 'assets/compiled/' . $css, array(), SBWP_VERSION);
                            }
                        }
                    }
                }
            }
        }

        // Enqueue the main script
        wp_enqueue_script('sbwp-admin-script', $script_url, array(), SBWP_VERSION, true);

        // Localize script for React to use
        wp_localize_script('sbwp-admin-script', 'sbwpData', array(
            'root' => '#sbwp-admin-root',
            'nonce' => wp_create_nonce('wp_rest'),
            'restUrl' => get_rest_url(null, 'sbwp/v1'),
            'isPro' => apply_filters('sbwp_is_pro_active', false),
        ));
    }

    /**
     * Register the administration menu.
     */
    public function add_plugin_admin_menu()
    {
        add_menu_page(
            'SafeBackup & Repair',
            'SafeBackup',
            'manage_options',
            'safebackup-repair-wp',
            array($this, 'display_plugin_admin_page'),
            'dashicons-shield',
            6
        );
    }

    /**
     * Render the admin page (React root).
     */
    public function display_plugin_admin_page()
    {
        echo '<div id="sbwp-admin-root">Loading SafeBackup...</div>';
    }

    public function run()
    {
        add_action('admin_menu', array($this, 'add_plugin_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        add_action('rest_api_init', function () {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sbwp-rest-api.php';
            $api = new SBWP_REST_API();
            $api->register_routes();
        });

        add_filter('script_loader_tag', function ($tag, $handle, $src) {
            if ($handle !== 'sbwp-admin-script') {
                return $tag;
            }
            return '<script type="module" src="' . esc_url($src) . '"></script>';
        }, 10, 3);
    }
}
