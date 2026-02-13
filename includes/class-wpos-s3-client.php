<?php

class WPOS_S3_Client {
    private $access_key;
    private $secret_key;
    private $endpoint;
    private $region;
    private $bucket;

    public function __construct() {
        $this->access_key = WPOS_S3_ACCESS_KEY;
        $this->secret_key = WPOS_S3_SECRET_KEY;
        $this->endpoint   = str_replace( [ 'https://', 'http://' ], '', WPOS_S3_ENDPOINT );
        $this->bucket     = WPOS_S3_BUCKET;
        $this->region     = defined( 'WPOS_S3_REGION' ) ? WPOS_S3_REGION : 'auto';
    }

    /**
     * Upload a local file to the bucket.
     */
    public function put_object( $local_path, $remote_path, $content_type = '' ) {
        $content = file_get_contents( $local_path );
        if ( $content === false ) {
            return false;
        }
        return $this->request( 'PUT', $remote_path, $content, $content_type );
    }

    /**
     * Download an object. When $save_to is provided the response body is
     * streamed straight to that file path (avoids holding it in memory).
     */
    public function get_object( $remote_path, $save_to = '' ) {
        return $this->request( 'GET', $remote_path, '', '', $save_to );
    }

    public function delete_object( $remote_path ) {
        return $this->request( 'DELETE', $remote_path );
    }

    private function request( $method, $path, $content = '', $content_type = '', $save_to = '' ) {
        // Capture both date formats once to avoid a midnight race condition.
        $date_short = gmdate( 'Ymd' );
        $date_long  = gmdate( 'Ymd\THis\Z' );

        $host = $this->bucket . '.' . $this->endpoint;

        // URI-encode each path segment individually.
        $encoded_path = '/' . implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $path, '/' ) ) ) );
        $url          = 'https://' . $host . $encoded_path;

        $payload_hash = hash( 'sha256', $content );

        // Build the headers that will be signed (lowercase keys, sorted).
        $sign_headers = [
            'host'                 => $host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date'           => $date_long,
        ];

        if ( $content_type ) {
            $sign_headers['content-type'] = $content_type;
        }

        ksort( $sign_headers );

        $signed_headers_str = implode( ';', array_keys( $sign_headers ) );

        $canonical_headers = '';
        foreach ( $sign_headers as $k => $v ) {
            $canonical_headers .= $k . ':' . trim( $v ) . "\n";
        }

        // AWS Signature V4
        $scope             = "$date_short/{$this->region}/s3/aws4_request";
        $canonical_request = "$method\n$encoded_path\n\n$canonical_headers\n$signed_headers_str\n$payload_hash";
        $string_to_sign    = "AWS4-HMAC-SHA256\n$date_long\n$scope\n" . hash( 'sha256', $canonical_request );

        $k_date    = hash_hmac( 'sha256', $date_short, 'AWS4' . $this->secret_key, true );
        $k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
        $k_service = hash_hmac( 'sha256', 's3', $k_region, true );
        $k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        $signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

        // Request headers sent over the wire (Host is derived from the URL by
        // the WordPress HTTP transport, so we omit it here to avoid duplicates).
        $request_headers = [
            'x-amz-date'           => $date_long,
            'x-amz-content-sha256' => $payload_hash,
            'Authorization'        => "AWS4-HMAC-SHA256 Credential={$this->access_key}/$scope, SignedHeaders=$signed_headers_str, Signature=$signature",
        ];

        if ( $content_type ) {
            $request_headers['Content-Type'] = $content_type;
        }

        $args = [
            'method'  => $method,
            'headers' => $request_headers,
            'timeout' => 30,
        ];

        if ( $method === 'PUT' ) {
            $args['body'] = $content;
        }

        if ( $save_to ) {
            $args['stream']   = true;
            $args['filename'] = $save_to;
        }

        $response  = wp_remote_request( $url, $args );
        $is_error  = is_wp_error( $response );
        $http_code = $is_error ? 0 : wp_remote_retrieve_response_code( $response );
        $success   = ! $is_error && $http_code >= 200 && $http_code < 300;

        // When streaming to a file, clean up on failure so we don't leave
        // a partial / error-body file on disk.
        if ( $save_to && ! $success && file_exists( $save_to ) ) {
            @unlink( $save_to );
        }

        if ( ! $success ) {
            return false;
        }

        return $save_to ? true : wp_remote_retrieve_body( $response );
    }
}
