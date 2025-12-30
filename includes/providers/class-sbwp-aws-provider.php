<?php
if (!defined('ABSPATH'))
    exit;

require_once 'abstract-sbwp-s3-provider.php';

class SBWP_AWS_Provider extends SBWP_S3_Base_Provider
{
    public function get_id()
    {
        return 'aws';
    }
    public function get_name()
    {
        return 'AWS S3';
    }

    protected function get_endpoint($region)
    {
        return "https://s3.{$region}.amazonaws.com";
    }
}
