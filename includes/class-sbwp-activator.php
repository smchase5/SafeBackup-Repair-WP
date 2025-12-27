<?php

/**
 * Fired during plugin activation.
 */
class SBWP_Activator
{

    /**
     * Short Description. (use period)
     *
     * Long Description.
     *
     * @since    1.0.0
     */
    public static function activate()
    {
        // Create wp_sb_backups table
        global $wpdb;
        $table_name = $wpdb->prefix . 'sb_backups';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			type varchar(20) NOT NULL,
			storage_location varchar(20) DEFAULT 'local' NOT NULL,
			file_path_local text NOT NULL,
			size_bytes bigint(20) DEFAULT 0 NOT NULL,
			status varchar(20) DEFAULT 'pending' NOT NULL,
			notes text,
			PRIMARY KEY  (id)
		) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

}
