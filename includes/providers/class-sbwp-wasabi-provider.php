<?php
if (!defined('ABSPATH'))
    exit;

require_once 'abstract-sbwp-s3-provider.php';

class SBWP_Wasabi_Provider extends SBWP_S3_Base_Provider
{
    public function get_id()
    {
        return 'wasabi';
    }
    public function get_name()
    {
        return 'Wasabi';
    }

    protected function get_endpoint($region)
    {
        // Wasabi endpoint format: https://s3.us-east-1.wasabisys.com
        // Default region 'us-east-1' often doesn't need region in URL for some SDKs, 
        // but for SigV4 we need exact endpoint.
        if ($region === 'us-east-1')
            return 'https://s3.wasabisys.com';
        return "https://s3.{$region}.wasabisys.com";
    }
}
