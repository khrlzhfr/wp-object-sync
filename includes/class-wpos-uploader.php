<?php

class WPOS_Uploader {

    public function __construct() {
        // Hook for new uploads (runs after metadata is generated, so thumbnails exist)
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'handle_upload' ], 10, 2 );
        
        // Hook for deletions
        add_action( 'delete_attachment', [ $this, 'handle_delete' ] );
    }

    /**
     * upload main file and all sizes to R2
     */
    public function handle_upload( $metadata, $attachment_id ) {
        $s3 = new WPOS_S3_Client();
        $upload_dir = wp_upload_dir();
        
        // 1. Upload the main file
        $file_path = $metadata['file']; // e.g., 2024/02/image.jpg
        $full_path = $upload_dir['basedir'] . '/' . $file_path;
        
        if ( file_exists( $full_path ) ) {
            $this->upload_file( $s3, $full_path, $file_path );
        }

        // 2. Upload all intermediate sizes (thumbnails)
        if ( isset( $metadata['sizes'] ) ) {
            $dirname = dirname( $file_path );
            foreach ( $metadata['sizes'] as $size ) {
                $thumb_file = $size['file'];
                // Reconstruct relative path for thumbnail: 2024/02/image-150x150.jpg
                $thumb_relative_path = ( $dirname === '.' ? '' : $dirname . '/' ) . $thumb_file;
                $thumb_full_path = $upload_dir['basedir'] . '/' . $thumb_relative_path;

                if ( file_exists( $thumb_full_path ) ) {
                    $this->upload_file( $s3, $thumb_full_path, $thumb_relative_path );
                }
            }
        }

        return $metadata;
    }

    /**
     * Delete files from R2 when deleted from Media Library
     */
    public function handle_delete( $post_id ) {
        $s3 = new WPOS_S3_Client();
        $metadata = wp_get_attachment_metadata( $post_id );
        
        if ( ! $metadata || empty( $metadata['file'] ) ) {
            return;
        }

        // Delete main file
        $file_path = $metadata['file'];
        $this->delete_file( $s3, $file_path );

        // Delete sizes
        if ( isset( $metadata['sizes'] ) ) {
            $dirname = dirname( $file_path );
            foreach ( $metadata['sizes'] as $size ) {
                $thumb_relative_path = ( $dirname === '.' ? '' : $dirname . '/' ) . $size['file'];
                $this->delete_file( $s3, $thumb_relative_path );
            }
        }
    }

    private function upload_file( $s3, $full_path, $relative_path ) {
        $content = file_get_contents( $full_path );
        if ( $content === false ) return;

        // Push to R2
        $s3->put_object( $relative_path, $content );

        // Log to DB
        $this->log_event( $relative_path, 'upload' );
    }

    private function delete_file( $s3, $relative_path ) {
        // Delete from R2
        $s3->delete_object( $relative_path );

        // Log to DB
        $this->log_event( $relative_path, 'delete' );
    }

    private function log_event( $path, $type ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'object_sync_events';
        
        $wpdb->insert( 
            $table_name, 
            [
                'file_path' => $path,
                'event_type' => $type,
                'source_node_id' => WPOS_NODE_ID,
                'created_at' => current_time( 'mysql' )
            ]
        );
    }
}