<?php

/**
 * Fired during plugin deactivation
 */

class Webspark_Deactivator {

	public static function deactivate() {

		wp_clear_scheduled_hook( 'webspak_product_sync' );
		wp_clear_scheduled_hook( 'webspark_upload_image' );
	}

}
