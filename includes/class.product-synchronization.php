<?php
ini_set('max_execution_time', '6000'); //10 часов
set_time_limit(0);

class Webspark_Product_Sync {

    const SERVICE_URL = 'https://wp.webspark.dev/wp-api/products';

    const INTERVAL = 'every_15_minutes';

    public function __construct() {

        if ( ! wp_next_scheduled( 'webspark_product_sync' ) ) {
            wp_schedule_event( time(), 'hourly', 'webspark_product_sync' );
        }
         
        add_action( 'webspark_product_sync', [$this, 'sync_handler'] );
        add_filter( 'cron_schedules', [$this, 'add_cron_recurrence_interval'] );
        add_action( 'webspark_upload_image', [$this, 'upload_image_handler'], 10, 1 );
    }

    public function sync_handler() {

        $data = $this->curl_get_products( self::SERVICE_URL );
        $products = $data['data'];

        if ( $data['error'] ) {
            error_log( $data['message'] ); 
            return false;
        }

        if ( ! $products ) {
            return false;
        }

        $new_ids = [];
        foreach ( $products as $product ) {
            // check if SKU already exists
            $wc_product = $this->get_product_by_sku($product['sku']);

            if ( $wc_product ) {
                $new_ids[] = $this->update_product($product, $wc_product);
            } else {
                $new_ids[] = $this->create_product($product);
            }
        }

        $this->delete_products($new_ids);
        $this->set_shedule_upload_image(1);
    }

    public function delete_products($new_ids) {

        if ( ! $new_ids ) {
            return false;
        }

        $delete_ids = [];
        $args = [
            'post_type' => 'product', 
            'posts_per_page' => -1,
            'post__not_in' => $new_ids,
        ];
        $query = new WP_Query( $args ); 

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $delete_ids[] = get_the_ID();
            }
        }
        wp_reset_postdata();

        foreach ( $delete_ids as $delete_id ) {
            // $this->delete_product( $delete_id );
            wp_delete_post($delete_id, true);
        }

        return;
    }

    public function create_product($data) {

        $product = new WC_Product_Simple();
        $product->set_sku( $data['sku'] ); 
        $product->set_name( $data['name'] ); 

        if ( $data['price'] ) {
            $price = substr($data['price'], 1);
            $product->set_regular_price( $price ); 
        }
        $product->set_manage_stock( true ); 
        $product->set_stock_status( 'instock' );

        $product->set_description( $data['description'] ); // or description
        $product->set_stock_quantity( $data['in_stock'] );
        // save media
        add_post_meta( $product->get_id(), '_picture', $data['picture'] );
        // $this->set_shedule_upload_image($product->get_id(), $data['picture']);
        // $file_name = $this->get_file_name( $data['name'] );
        // $img_id = $this->upload_image( $data['picture'], $file_name );
        // $product->set_image_id( $img_id );

        $product->save();

        return $product->get_id();
    }

    public function update_product($data, $product) {
        
        if ( $product->get_name() != $data['name'] ) {
            $product->set_name( $data['name'] ); 
        }

        if ( $product->get_regular_price() != $data['price'] ) {
            $price = substr($data['price'], 1);
            $product->set_regular_price( $price ); 
        }

        if ( $product->get_description() != $data['description'] ) {
            $product->set_description( $data['description'] ); // or description
        }

        if ( $data['in_stock'] ) {
            $product->set_manage_stock( true ); 
            $product->set_stock_status( 'instock' );
        }

        if ( $product->get_stock_quantity() != $data['in_stock'] ) {
            $product->set_stock_quantity( $data['in_stock'] );
        }
        // save media
        update_post_meta( $product->get_id(), '_picture', $data['picture'] );
        // $this->set_shedule_upload_image($product->get_id(), $data['picture']);
        // $file_name = $this->get_file_name( $data['name'] );
        // $img_id = $this->upload_image( $data['picture'], $file_name );
        // $product->set_image_id( $img_id );

        $product->save();

        return $product->get_id();
    }

    public function upload_image($image_url, $filename) {

        $upload_dir = wp_upload_dir();
        // $image_data = file_get_contents( $image_url );
        $image_data = $this->curl_get_method( $image_url );
        // $filename = basename( $image_url );

        if ( wp_mkdir_p( $upload_dir['path'] ) ) {
            $file = $upload_dir['path'] . '/' . $filename;
        }
        else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }

        file_put_contents( $file, $image_data );

        $wp_filetype = wp_check_filetype( $filename, null );
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name( $filename ),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        $attach_id = wp_insert_attachment( $attachment, $file );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        $attach_data = wp_generate_attachment_metadata( $attach_id, $file );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        return $attach_id;
    }

    public function curl_get_products($url) {

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
    
    public function curl_get_method($url) {

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Cookie: PHPSESSID=8acfdd19df7176d772bd80f5ba6e5fc8'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        return $response;
    }

    function get_product_by_sku( $sku ) {

        global $wpdb;
    
        $product_id = $wpdb->get_var( 
            $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $sku ) 
        );
    
        if ( $product_id ) {
            return new WC_Product( $product_id );
        }
    
        return null;
    }

    function delete_product($id, $force = FALSE) {

        $product = wc_get_product($id);

        if(empty($product))
            return new WP_Error(999, sprintf(__('No %s is associated with #%d', 'woocommerce'), 'product', $id));

        // If we're forcing, then delete permanently.
        if ($force) {

            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $child_id) {
                    $child = wc_get_product($child_id);
                    $child->delete(true);
                }
            } elseif ($product->is_type('grouped')) {
                foreach ($product->get_children() as $child_id) {
                    $child = wc_get_product($child_id);
                    $child->set_parent_id(0);
                    $child->save();
                }
            }

            $product->delete(true);
            $result = $product->get_id() > 0 ? false : true;
        } else {
            $product->delete();
            $result = 'trash' === $product->get_status();
        }

        if (!$result) {
            return new WP_Error(999, sprintf(__('This %s cannot be deleted', 'woocommerce'), 'product'));
        }

        // Delete parent product transients.
        if ($parent_id = wp_get_post_parent_id($id)) {
            wc_delete_product_transients($parent_id);
        }

        return true;
    }

    public function get_file_name($product_name) {

        $prepare_name = preg_replace('/\s+/', '', $product_name);

        return $prepare_name . '.jpg';
    }

    public function upload_image_handler($page) {

        $args = [
            'post_type' => 'product', 
            'paged' => $page,
        ];
        $query = new WP_Query( $args ); 

        if ( ! $query->have_posts() ) {
            wp_clear_scheduled_hook( 'webspark_upload_image', [$page] );
            wp_clear_scheduled_hook( 'webspark_upload_image' );
            return false;
        }

        while ( $query->have_posts() ) {
            $query->the_post();
            $product = wc_get_product( $query->post->ID );

            if ( ! $product ) {
                continue;
            }

            if ( $product->get_image_id() ) {
                continue;
            }
    
            $file_name = $this->get_file_name( $product->get_name() ); 
            $img = get_post_meta( $product->get_id(), '_picture', true );
            $img_id = $this->upload_image( $img, $file_name );
    
            // if ( $img_id && $product->get_image_id() ) {
            //     wp_delete_attachment( $product->get_image_id(), true );
            // }
            
            if ( $img_id ) {
                $product->set_image_id( $img_id );
                $product->save();
            }

        }
        wp_reset_postdata();
        $page++;
        $this->set_shedule_upload_image($page);
    }

    public function set_shedule_upload_image($page) {

        wp_clear_scheduled_hook( 'webspark_upload_image', [$page-1] );
        wp_schedule_event( time(), self::INTERVAL, 'webspark_upload_image', [$page]);
    }

    public function add_cron_recurrence_interval( $schedules ) {
 
        $schedules[ self::INTERVAL ] = [
            'interval'  => 900,
            'display'   => __( 'Every 15 Minutes' )
        ];
         
        return $schedules;
    }

}
