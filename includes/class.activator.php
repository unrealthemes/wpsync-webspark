<?php

/**
 * Fired during plugin activation
 */

class Webspark_Activator {

    public function activate() {

    	wp_clear_scheduled_hook( 'webspak_product_sync' );
        wp_schedule_event( time(), 'hourly', 'webspak_product_sync' );
    }

}
