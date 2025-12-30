<?php
if (!defined('ABSPATH'))
    exit;

require_once 'abstract-sbwp-s3-provider.php';

class SBWP_DO_Spaces_Provider extends SBWP_S3_Base_Provider
{
    public function get_id()
    {
        return 'do_spaces';
    }
    public function get_name()
    {
        return 'DigitalOcean Spaces';
    }

    protected function get_endpoint($region)
    {
        // DO Spaces endpoint format: https://nyc3.digitaloceanspaces.com
        // Region is usually 'nyc3', 'ams3' etc.
        return "https://{$region}.digitaloceanspaces.com";
    }
}
