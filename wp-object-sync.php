<?php
/*
 * Plugin Name:       WP Object Sync
 * Description:       Lightweight sync of media uploads to S3-based object storage.
 * Version:           0.0.1
 * Author:            Khairil Zhafri
 * Author URI:        https://khrlzh.fr/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

// Define plugin paths
define( 'WPOS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Check for required configuration constants
if ( ! defined( 'WPOS_NODE_ID' ) || ! defined( 'WPOS_S3_BUCKET' ) || ! defined( 'WPOS_S3_ACCESS_KEY' ) || ! defined( 'WPOS_S3_SECRET_KEY' ) || ! defined( 'WPOS_S3_ENDPOINT' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="error"><p>' . esc_html( 'WP Object Sync: Missing required configuration constants in wp-config.php.' ) . '</p></div>';
    } );
    return;
}

// Include required files
require_once WPOS_PLUGIN_DIR . 'includes/class-wpos-activator.php';
require_once WPOS_PLUGIN_DIR . 'includes/class-wpos-s3-client.php';
require_once WPOS_PLUGIN_DIR . 'includes/class-wpos-uploader.php';
require_once WPOS_PLUGIN_DIR . 'includes/class-wpos-syncer.php';

// Activation Hook
register_activation_hook( __FILE__, [ 'WPOS_Activator', 'activate' ] );

// Deactivation Hook — unschedule cron so it doesn't fire after removal.
register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'wpos_sync_event' );
} );

// Initialize the Uploader (Listens for new files)
new WPOS_Uploader();

// Initialize the Syncer (Handles cron jobs)
new WPOS_Syncer();