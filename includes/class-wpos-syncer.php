<?php

class WPOS_Syncer {

    public function __construct() {
        // Register the cron interval if not exists
        add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
        
        // Hook the cron event
        add_action( 'wpos_sync_event', [ $this, 'run_sync' ] );

        // Schedule it if not scheduled
        if ( ! wp_next_scheduled( 'wpos_sync_event' ) ) {
            wp_schedule_event( time(), 'wpos_interval', 'wpos_sync_event' );
        }
    }

    public function add_cron_interval( $schedules ) {
        $schedules['wpos_interval'] = [
            'interval' => defined('WPOS_SYNC_INTERVAL') ? WPOS_SYNC_INTERVAL : 300,
            'display'  => 'WP Object Sync Interval'
        ];
        return $schedules;
    }

    public function run_sync() {
        global $wpdb;
        $table = $wpdb->prefix . 'object_sync_events';
        
        // Get the last ID we processed
        $last_id = get_option( 'wpos_last_synced_id', 0 );

        // Fetch new events
        $events = $wpdb->get_results( $wpdb->prepare( 
            "SELECT * FROM $table WHERE id > %d ORDER BY id ASC LIMIT 50", 
            $last_id 
        ) );

        if ( empty( $events ) ) {
            return;
        }

        $s3 = new WPOS_S3_Client();
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        foreach ( $events as $event ) {
            // Skip if we were the ones who did this action
            if ( $event->source_node_id === WPOS_NODE_ID ) {
                update_option( 'wpos_last_synced_id', $event->id );
                continue;
            }

            $local_path = $base_dir . '/' . $event->file_path;

            if ( $event->event_type === 'upload' ) {
                $this->download_file( $s3, $event->file_path, $local_path );
            } elseif ( $event->event_type === 'delete' ) {
                if ( file_exists( $local_path ) ) {
                    unlink( $local_path );
                }
            }

            // Update pointer
            update_option( 'wpos_last_synced_id', $event->id );
        }
    }

    private function download_file( $s3, $remote_path, $local_path ) {
        // Ensure directory exists
        $dir = dirname( $local_path );
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }

        // Fetch from R2
        $content = $s3->get_object( $remote_path );

        if ( $content ) {
            file_put_contents( $local_path, $content );
        }
    }
}