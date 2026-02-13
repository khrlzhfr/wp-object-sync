<?php

class WPOS_Activator {
    public static function activate() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'object_sync_events';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            file_path varchar(191) NOT NULL,
            event_type varchar(20) NOT NULL,
            source_node_id varchar(50) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY source_node_id (source_node_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }
}