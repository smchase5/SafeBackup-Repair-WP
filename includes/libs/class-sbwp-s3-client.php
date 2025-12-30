<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight S3 Client for AWS Signature V4.
 * Dependencies: None (uses WP_Http).
 */
class SBWP_S3_Client
{
    private $access_key;
    private $secret_key;
    private $region;
    private $endpoint;

    public function __construct($access_key, $secret_key, $region = 'us-east-1', $endpoint = null)
    {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->region = $region;
        // Check if endpoint is full URL; if not, construct it for AWS
        if ($endpoint) {
            $this->endpoint = rtrim($endpoint, '/');
        } else {
            $this->endpoint = "https://s3.{$this->region}.amazonaws.com";
        }
    }

    /**
     * upload file content
     */
    public function put_object($bucket, $filename, $content, $content_type = 'application/zip')
    {
        $uri = "/{$bucket}/{$filename}";
        $url = "{$this->endpoint}{$uri}";

        $headers = array(
            'Content-Type' => $content_type,
            'x-amz-acl' => 'private',
        );

        $signed_headers = $this->sign_request('PUT', $uri, '', $headers, $content);

        $response = wp_remote_request($url, array(
            'method' => 'PUT',
            'headers' => $signed_headers,
            'body' => $content,
            'timeout' => 300
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return true;
        }

        return new WP_Error('s3_error', 'S3 Upload Failed: ' . $code . ' ' . wp_remote_retrieve_body($response));
    }

    /**
     * Basic check if bucket is accessible
     */
    public function check_bucket($bucket)
    {
        // HEAD request to bucket root or a dummy file check
        // Often HEAD /bucket is used but tricky with endpoints. 
        // We'll trust put_object or just try to list objects (GET /?max-keys=1)

        $uri = "/{$bucket}";
        $query = 'max-keys=1';
        $url = "{$this->endpoint}{$uri}?{$query}";

        $signed_headers = $this->sign_request('GET', $uri, $query, array(), '');

        $response = wp_remote_get($url, array(
            'headers' => $signed_headers
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200)
            return true;

        return new WP_Error('s3_access_denied', "Bucket access failed: $code");
    }

    /**
     * Delete object
     */
    public function delete_object($bucket, $filename)
    {
        $uri = "/{$bucket}/{$filename}";
        $url = "{$this->endpoint}{$uri}";

        $signed_headers = $this->sign_request('DELETE', $uri, '', array(), '');

        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => $signed_headers,
            'timeout' => 300
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        // S3 returns 204 No Content on success
        if ($code >= 200 && $code < 300) {
            return true;
        }

        return new WP_Error('s3_delete_error', "S3 Delete Failed: $code");
    }

    /**
     * List objects in bucket
     * Returns array of ['key' => '...', 'time' => timestamp]
     */
    public function list_objects($bucket, $prefix = '', $limit = 50)
    {
        $uri = "/{$bucket}";
        $query = "list-type=2&prefix=" . urlencode($prefix) . "&max-keys=$limit";
        $url = "{$this->endpoint}{$uri}?{$query}";

        $signed_headers = $this->sign_request('GET', $uri, $query, array(), '');

        $response = wp_remote_get($url, array(
            'headers' => $signed_headers
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('s3_list_error', "S3 List Failed: $code");
        }

        $body = wp_remote_retrieve_body($response);

        // Parse XML response
        $files = array();
        if (function_exists('simplexml_load_string')) {
            $xml = simplexml_load_string($body);
            if ($xml && isset($xml->Contents)) {
                foreach ($xml->Contents as $content) {
                    $files[] = array(
                        'key' => (string) $content->Key,
                        'time' => strtotime((string) $content->LastModified),
                        'size' => (int) $content->Size
                    );
                }
            }
        } else {
            // Fallback for no SimpleXML? (Rare in WP)
            // Regex parsing (Fragile but dependency free)
            preg_match_all('/<Contents>(.*?)<\/Contents>/s', $body, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $block) {
                    preg_match('/<Key>(.*?)<\/Key>/', $block, $key);
                    preg_match('/<LastModified>(.*?)<\/LastModified>/', $block, $date);
                    if (!empty($key[1])) {
                        $files[] = array(
                            'key' => $key[1],
                            'time' => strtotime(isset($date[1]) ? $date[1] : 'now')
                        );
                    }
                }
            }
        }

        return $files;
    }

    private function sign_request($method, $uri, $query_string, $headers, $payload)
    {
        $service = 's3';
        $timestamp = time();
        $date_long = gmdate('Ymd\THis\Z', $timestamp);
        $date_short = gmdate('Ymd', $timestamp);
        $host = parse_url($this->endpoint, PHP_URL_HOST);

        // Standard headers
        $headers['host'] = $host;
        $headers['x-amz-date'] = $date_long;
        $headers['x-amz-content-sha256'] = hash('sha256', $payload);

        // Sort headers
        ksort($headers);
        $canonical_headers = '';
        $signed_headers_list = '';
        foreach ($headers as $key => $value) {
            $lower_key = strtolower($key);
            $canonical_headers .= $lower_key . ':' . trim($value) . "\n";
            $signed_headers_list .= $lower_key . ';';
        }
        $signed_headers_list = rtrim($signed_headers_list, ';');

        // Cannonical Request
        $canonical_request = "$method\n" .
            "$uri\n" .
            "$query_string\n" .
            "$canonical_headers\n" .
            "$signed_headers_list\n" .
            $headers['x-amz-content-sha256'];

        // Credential Scope
        $credential_scope = "$date_short/{$this->region}/$service/aws4_request";

        // String to Sign
        $string_to_sign = "AWS4-HMAC-SHA256\n" .
            "$date_long\n" .
            "$credential_scope\n" .
            hash('sha256', $canonical_request);

        // Sign
        $kSecret = 'AWS4' . $this->secret_key;
        $kDate = hash_hmac('sha256', $date_short, $kSecret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);

        // Authorization Header
        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->access_key}/$credential_scope, SignedHeaders=$signed_headers_list, Signature=$signature";

        return $headers;
    }
}
