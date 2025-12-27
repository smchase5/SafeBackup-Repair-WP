<?php

/**
 * Fired during plugin deactivation.
 */
class SBWP_Deactivator
{

    /**
     * Short Description. (use period)
     *
     * Long Description.
     *
     * @since    1.0.0
     */
    public static function deactivate()
    {
        // Optional: Clean up cron jobs or temp options.
        // Usually we don't drop tables on deactivation to preserve user data.
    }

}
