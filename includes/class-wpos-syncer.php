<?php

class WPOS_Syncer {

    public function __construct() {
        add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
        add_action( 'wpos_sync_event', [ $this, 'run_sync' ] );

        if ( ! wp_next_scheduled( 'wpos_sync_event' ) ) {
            wp_schedule_event( time(), 'wpos_interval', 'wpos_sync_event' );
        }
    }

    public function add_cron_interval( $schedules ) {
        $schedules['wpos_interval'] = [
            'interval' => defined( 'WPOS_SYNC_INTERVAL' ) ? WPOS_SYNC_INTERVAL : 300,
            'display'  => 'WP Object Sync Interval',
        ];
        return $schedules;
    }

    public function run_sync() {
        global $wpdb;
        $table = $wpdb->prefix . 'object_sync_events';

        $last_id = get_option( 'wpos_last_synced_id', 0 );

        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE id > %d ORDER BY id ASC LIMIT 50",
            $last_id
        ) );

        if ( empty( $events ) ) {
            $this->cleanup_old_events();
            return;
        }

        $s3 = new WPOS_S3_Client();
        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];

        $last_processed_id = $last_id;

        foreach ( $events as $event ) {
            // Skip events originating from this node.
            if ( $event->source_node_id === WPOS_NODE_ID ) {
                $last_processed_id = $event->id;
                continue;
            }

            // Reject paths that could escape the uploads directory.
            if ( ! $this->is_safe_path( $event->file_path ) ) {
                $last_processed_id = $event->id;
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

            $last_processed_id = $event->id;
        }

        // Single DB write instead of one per event.
        if ( $last_processed_id !== $last_id ) {
            update_option( 'wpos_last_synced_id', $last_processed_id );
        }

        $this->cleanup_old_events();
    }

    /**
     * Validate that a relative path cannot escape the uploads directory.
     */
    private function is_safe_path( $path ) {
        if ( empty( $path ) || $path[0] === '/' || strpos( $path, "\0" ) !== false || strpos( $path, '\\' ) !== false ) {
            return false;
        }

        foreach ( explode( '/', $path ) as $segment ) {
            if ( $segment === '..' || $segment === '.' ) {
                return false;
            }
        }

        return true;
    }

    private function download_file( $s3, $remote_path, $local_path ) {
        $dir = dirname( $local_path );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $s3->get_object( $remote_path, $local_path );
    }

    /**
     * Prune events older than the configured retention period.
     */
    private function cleanup_old_events() {
        global $wpdb;
        $table          = $wpdb->prefix . 'object_sync_events';
        $retention_days = defined( 'WPOS_EVENT_RETENTION_DAYS' ) ? WPOS_EVENT_RETENTION_DAYS : 7;

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ) );
    }
}
