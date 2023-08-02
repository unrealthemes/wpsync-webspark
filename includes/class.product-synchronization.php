<?php

class Webspark_Product_Sync {

    const SERVICE_URL = 'https://wp.webspark.dev/wp-api/products';

    public function __construct() {

        if ( ! wp_next_scheduled( 'webspak_product_sync' ) ) {
            wp_schedule_event( time(), 'hourly', 'webspak_product_sync' );
        }
         
        add_action( 'webspak_product_sync', [$this, 'sync_handler'] );

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
        // $img_id = $this->upload_file_by_url( $data['picture'] );
        // $product->set_image_id( $img_id );

        $product->save();

        return $product->get_id();
    }

    public function update_product($data, $product) {

        // [sku] => 0f93bbb6-b1b1-4d01-adf6-76e19fffa2a2
        // [name] => Small Steel Chicken
        // [description] => The automobile layout consists of a front-engine design, with transaxle-type transmissions mounted at the rear of the engine and four wheel drive
        // [price] => $858.00
        // [picture] => https://loremflickr.com/640/480/abstract
        // [in_stock] => 377
        
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
        // $img_id = $this->upload_file_by_url( $data['picture'] );
        // $product->set_image_id( $img_id );

        $product->save();

        return $product->get_id();
    }

    public function upload_file_by_url( $image_url ) {  

        // it allows us to use download_url() and wp_handle_sideload() functions
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
    
        // download to temp dir
        $temp_file = download_url( $image_url );   
    
        if ( is_wp_error( $temp_file ) ) {
            return false;
        }
    
        // move the temp file into the uploads directory
        $file = array(
            'name'     => basename( $image_url ),
            'type'     => mime_content_type( $temp_file ),
            'tmp_name' => $temp_file,
            'size'     => filesize( $temp_file ),
        );
        $sideload = wp_handle_sideload(
            $file,
            array(
                'test_form'   => false // no needs to check 'action' parameter
            )
        );
    
        if ( ! empty( $sideload[ 'error' ] ) ) {
            // you may return error message if you want
            return false;
        }
    
        // it is time to add our uploaded image into WordPress media library
        $attachment_id = wp_insert_attachment(
            array(
                'guid'           => $sideload[ 'url' ],
                'post_mime_type' => $sideload[ 'type' ],
                'post_title'     => basename( $sideload[ 'file' ] ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ),
            $sideload[ 'file' ]
        );
    
        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return false;
        }
    
        // update medatata, regenerate image sizes
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
    
        wp_update_attachment_metadata(
            $attachment_id,
            wp_generate_attachment_metadata( $attachment_id, $sideload[ 'file' ] )
        );
    
        return $attachment_id;
    
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
        curl_setopt_array(
            $curl, 
            array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                    'Cookie: PHPSESSID=60a852838457d96a235d4ffc52762cfb'
                ),
            )
        );
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

}
