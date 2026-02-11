<?php

class WPOS_S3_Client {
    private $access_key;
    private $secret_key;
    private $endpoint;
    private $region;
    private $bucket;

    public function __construct() {
        $this->access_key = WPOS_R2_ACCESS_KEY;
        $this->secret_key = WPOS_R2_SECRET_KEY;
        $this->endpoint   = str_replace( ['https://', 'http://'], '', WPOS_R2_ENDPOINT );
        $this->bucket     = WPOS_R2_BUCKET;
        $this->region     = 'auto'; // R2 uses 'auto', S3 uses 'us-east-1' etc.
    }

    public function put_object( $file_path, $content ) {
        return $this->request( 'PUT', $file_path, $content );
    }

    public function get_object( $file_path ) {
        return $this->request( 'GET', $file_path );
    }

    public function delete_object( $file_path ) {
        return $this->request( 'DELETE', $file_path );
    }

    private function request( $method, $path, $content = '' ) {
        $host = $this->bucket . '.' . $this->endpoint;
        $url = 'https://' . $host . '/' . ltrim( $path, '/' );
        
        $headers = [
            'Host' => $host,
            'Date' => gmdate( 'Ymd\THis\Z' ),
            'x-amz-content-sha256' => hash( 'sha256', $content ),
        ];

        // Signature V4 Calculation
        $kSecret = 'AWS4' . $this->secret_key;
        $kDate = hash_hmac( 'sha256', gmdate( 'Ymd' ), $kSecret, true );
        $kRegion = hash_hmac( 'sha256', $this->region, $kDate, true );
        $kService = hash_hmac( 'sha256', 's3', $kRegion, true );
        $kSigning = hash_hmac( 'sha256', 'aws4_request', $kService, true );

        $canonical_uri = '/' . ltrim( $path, '/' );
        $canonical_headers = "host:$host\nx-amz-content-sha256:{$headers['x-amz-content-sha256']}\nx-amz-date:{$headers['Date']}\n";
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
        $payload_hash = $headers['x-amz-content-sha256'];

        $canonical_request = "$method\n$canonical_uri\n\n$canonical_headers\n$signed_headers\n$payload_hash";
        $string_to_sign = "AWS4-HMAC-SHA256\n{$headers['Date']}\n" . gmdate('Ymd') . "/{$this->region}/s3/aws4_request\n" . hash( 'sha256', $canonical_request );
        $signature = hash_hmac( 'sha256', $string_to_sign, $kSigning );

        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->access_key}/" . gmdate('Ymd') . "/{$this->region}/s3/aws4_request, SignedHeaders=$signed_headers, Signature=$signature";

        // Execute cURL
        $ch = curl_init( $url );
        $curl_headers = [];
        foreach ( $headers as $k => $v ) {
            $curl_headers[] = "$k: $v";
        }

        curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        
        if ( $method === 'PUT' ) {
            curl_setopt( $ch, CURLOPT_PUT, true );
            curl_setopt( $ch, CURLOPT_INFILE, $this->string_to_stream( $content ) );
            curl_setopt( $ch, CURLOPT_INFILESIZE, strlen( $content ) );
        } elseif ( $method === 'DELETE' ) {
            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'DELETE' );
        }

        $response = curl_exec( $ch );
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        return $http_code >= 200 && $http_code < 300 ? $response : false;
    }

    private function string_to_stream( $string ) {
        $stream = fopen( 'php://memory', 'r+' );
        fwrite( $stream, $string );
        rewind( $stream );
        return $stream;
    }
}