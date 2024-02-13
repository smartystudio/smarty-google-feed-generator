<?php
/**
 * Plugin Name: SM - Google Feed Generator for WooCommerce
 * Plugin URI:  https://smartystudio.net
 * Description: Generates google product and product review feeds for Google Merchant Center.
 * Version:     1.0.0
 * Author:      Smarty Studio | Martin Nestorov
 * Author URI:  https://smartystudio.net
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

if (!function_exists('smarty_feed_generator_add_rewrite_rules')) {
    /**
     * Add rewrite rules for custom endpoints.
     */
    function smarty_feed_generator_add_rewrite_rules() {
        add_rewrite_rule('^smarty-google-feed/?', 'index.php?smarty_google_feed=1', 'top');
        add_rewrite_rule('^smarty-google-reviews-feed/?', 'index.php?smarty_google_reviews_feed=1', 'top');
    }
    add_action('init', 'smarty_feed_generator_add_rewrite_rules');
}

if (!function_exists('smarty_feed_generator_query_vars')) {
    /**
     * Register query vars for custom endpoints.
     */
    function smarty_feed_generator_query_vars($vars) {
        $vars[] = 'smarty_google_feed';
        $vars[] = 'smarty_google_reviews_feed';
        return $vars;
    }
    add_filter('query_vars', 'smarty_feed_generator_query_vars');
}

if (!function_exists('smarty_feed_generator_template_redirect')) {
    /**
     * Handle requests to custom endpoints.
     */
    function smarty_feed_generator_template_redirect() {
        if (get_query_var('smarty_google_feed')) {
            smarty_generate_google_feed();
            exit;
        }
        if (get_query_var('smarty_google_reviews_feed')) {
            smarty_generate_google_reviews_feed();
            exit;
        }
    }
    add_action('template_redirect', 'smarty_feed_generator_template_redirect');
}

if (!function_exists('smarty_regenerate_feed')) {
    function smarty_regenerate_feed() {
        // Feed generation logic, ending with saving the feed to a file or option
        $xml = new SimpleXMLElement('<rss version="2.0"/>');
        
        // Add products or reviews to $xml...

        $feed_content = $xml->asXML();
        // Example: Saving the feed to a file
        file_put_contents(WP_CONTENT_DIR . '/uploads/smarty_google_feed.xml', $feed_content);
    }
}

if (!function_exists('smarty_generate_google_feed')) {
    /**
     * Generates the custom Google product feed.
     */
    function smarty_generate_google_feed() {
        header('Content-Type: application/xml; charset=utf-8');
    
        // Check for a cached version first
        $cached_feed = get_transient('smarty_google_feed');
        if ($cached_feed !== false) {
            echo $cached_feed;
            exit;
        }
    
        if (class_exists('WooCommerce')) {
            $args = array(
                'status' => 'publish',
                'limit' => -1, // Consider performance and execution time limits
                'orderby' => 'date',
                'order' => 'DESC',
            );
    
            $products = wc_get_products($args);
    
            // Initialize the XML structure with <feed> as the root element
            $xml = new SimpleXMLElement('<feed xmlns:g="http://base.google.com/ns/1.0"/>');
    
            foreach ($products as $product) {
                $item = $xml->addChild('item'); // Directly add items to the feed
    
                // Adding the g: namespace for elements related to Google Merchant
                $item->addChild('title', htmlspecialchars($product->get_name()), 'http://base.google.com/ns/1.0');
                $item->addChild('link', get_permalink($product->get_id()), 'http://base.google.com/ns/1.0');
                $item->addChild('description', htmlspecialchars(strip_tags(!empty($product->get_short_description()) ? $product->get_short_description() : $product->get_description())), 'http://base.google.com/ns/1.0');
                $item->addChild('image_link', wp_get_attachment_url($product->get_image_id()), 'http://base.google.com/ns/1.0');
    
                // Additional Images
                $gallery_ids = $product->get_gallery_image_ids();
                foreach ($gallery_ids as $gallery_id) {
                    $item->addChild('additional_image_link', wp_get_attachment_url($gallery_id), 'http://base.google.com/ns/1.0');
                }
    
                // Price
                $item->addChild('price', htmlspecialchars($product->get_price() . ' ' . get_woocommerce_currency()), 'http://base.google.com/ns/1.0');
    
                // Sale Price (if applicable)
                if ($product->is_on_sale() && !empty($product->get_sale_price())) {
                    $item->addChild('sale_price', htmlspecialchars($product->get_sale_price() . ' ' . get_woocommerce_currency()), 'http://base.google.com/ns/1.0');
                }
    
                // Product Type (Category)
                $categories = wp_get_post_terms($product->get_id(), 'product_cat');
                if (!empty($categories) && !is_wp_error($categories)) {
                    $category_names = array_map(function($term) { return $term->name; }, $categories);
                    $item->addChild('product_type', htmlspecialchars(join(' > ', $category_names)), 'http://base.google.com/ns/1.0');
                }
    
                // SKU
                $item->addChild('sku', $product->get_sku(), 'http://base.google.com/ns/1.0');
            }
    
            // Save the feed content and set transient for caching
            $feed_content = $xml->asXML();
            set_transient('smarty_google_feed', $feed_content, 12 * HOUR_IN_SECONDS);
    
            echo $feed_content;
            exit;
        } else {
            echo '<error>WooCommerce is not active.</error>';
            exit;
        }
    }
}

if (!function_exists('smarty_generate_google_reviews_feed')) {
    /**
     * Generates the custom Google product review feed.
     */
    function smarty_generate_google_reviews_feed() {
        header('Content-Type: application/xml; charset=utf-8');

        $args = array(
            'post_type' => 'product',
            'numberposts' => -1, // Adjust based on performance
        );

        $products = get_posts($args);

        $xml = new SimpleXMLElement('<feed xmlns:g="http://base.google.com/ns/1.0"/>');

        foreach ($products as $product) {
            $reviews = get_comments(array('post_id' => $product->ID));
            foreach ($reviews as $review) {
                if ($review->comment_approved == '1') { // Only show approved reviews
                    $entry = $xml->addChild('entry');
                    $entry->addChild('g:id', htmlspecialchars(get_the_product_sku($product->ID)), 'http://base.google.com/ns/1.0');
                    $entry->addChild('g:title', htmlspecialchars($product->post_title), 'http://base.google.com/ns/1.0');
                    $entry->addChild('g:content', htmlspecialchars($review->comment_content), 'http://base.google.com/ns/1.0');
                    $entry->addChild('g:reviewer', htmlspecialchars($review->comment_author), 'http://base.google.com/ns/1.0');
                    $entry->addChild('g:review_date', date('Y-m-d', strtotime($review->comment_date)), 'http://base.google.com/ns/1.0');
                    $entry->addChild('g:rating', get_comment_meta($review->comment_ID, 'rating', true), 'http://base.google.com/ns/1.0');
                    // Add more fields as required by Google
                }
            }
        }

        echo $xml->asXML();
        exit;
    }
}

if (!function_exists('get_the_product_sku')) {
    /**
     * Helper function to get the product SKU.
     * Adjust if your SKU is stored differently.
     */
    function get_the_product_sku($product_id) {
        return get_post_meta($product_id, '_sku', true);
    }
}

if (!function_exists('smarty_invalidate_feed_cache')) {
    /**
     * Invalidate cache or regenerate feed when a product is created, updated, or deleted.
     */
    function smarty_invalidate_feed_cache($product_id) {
        // Check if the post is a 'product'
        if (get_post_type($product_id) === 'product') {
            // Invalidate cache
            delete_transient('smarty_google_feed');
            // Optionally, regenerate the feed file
            smarty_regenerate_feed();
        }
    }
    add_action('woocommerce_new_product', 'smarty_invalidate_feed_cache');
    add_action('woocommerce_update_product', 'smarty_invalidate_feed_cache');
}

if (!function_exists('smarty_invalidate_feed_cache_on_delete')) {
    function smarty_invalidate_feed_cache_on_delete($post_id) {
        if (get_post_type($post_id) === 'product') {
            // Invalidate cache
            delete_transient('smarty_google_feed');
            // Optionally, regenerate the feed file
            smarty_regenerate_feed();
        }
    }
    add_action('before_delete_post', 'smarty_invalidate_feed_cache_on_delete');
}

if (!function_exists('smarty_invalidate_review_feed_cache')) {
    /**
     * Invalidate cache or regenerate review feed when reviews are added, updated, or deleted.
     */
    function smarty_invalidate_review_feed_cache($comment_id, $comment_approved = '') {
        $comment = get_comment($comment_id);
        $post_id = $comment->comment_post_ID;
        
        // Check if the comment is for a 'product' and approved
        if (get_post_type($post_id) === 'product' && ($comment_approved == 1 || $comment_approved == 'approve')) {
            // Invalidate cache
            delete_transient('smarty_google_reviews_feed');
            // Optionally, regenerate the feed file
            smarty_regenerate_google_reviews_feed();
        }
    }
    add_action('comment_post', 'smarty_invalidate_review_feed_cache', 10, 2);
    add_action('edit_comment', 'smarty_invalidate_review_feed_cache');
    add_action('deleted_comment', 'smarty_invalidate_review_feed_cache');
    add_action('wp_set_comment_status', 'smarty_invalidate_review_feed_cache');
}

if (!function_exists('smarty_regenerate_product_feed')) {
    /**
     * Regenerates the feed and saves it to a transient or a file.
     */
	function smarty_regenerate_product_feed() {
		$products = wc_get_products(array(
			'status' => 'publish',
			'limit' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
		));

		$xml = new SimpleXMLElement('<feed xmlns:g="http://base.google.com/ns/1.0"/>');
		// Add feed details and loop through products to add them to the feed...

		foreach ($products as $product) {
			$item = $xml->addChild('item');
			// Populate $item with product details...
		}

		$feed_content = $xml->asXML();

		// Save the generated feed to a transient or a file
		set_transient('smarty_google_product_feed', $feed_content, 12 * HOUR_IN_SECONDS);
		// or
		file_put_contents(WP_CONTENT_DIR . '/uploads/smarty_google_product_feed.xml', $feed_content);
	}
}

// Call this function to regenerate the feed
//smarty_regenerate_product_feed();

if (!function_exists('smarty_handle_product_change')) {
    /**
     * Hook into product changes.
     */
    function smarty_handle_product_change($post_id) {
        if (get_post_type($post_id) == 'product') {
            smarty_regenerate_product_feed(); // Regenerate the feed
	    }
    }
	add_action('save_post_product', 'smarty_handle_product_change');
	add_action('deleted_post', 'smarty_handle_product_change');
}

if (!function_exists('smarty_feed_generator_activate')) {
    /**
     * Activation hook to flush rewrite rules and schedule feed regeneration on plugin activation.
     */
    function smarty_feed_generator_activate() {
        // Add rewrite rules
        smarty_feed_generator_add_rewrite_rules();

        // Flush rewrite rules to ensure custom endpoints are registered
        flush_rewrite_rules();

        // Schedule the feed regeneration event if it's not already scheduled
        if (!wp_next_scheduled('smarty_generate_google_feed_event')) {
            wp_schedule_event(time(), 'twicedaily', 'smarty_generate_google_feed_event');
        }
    }
    register_activation_hook(__FILE__, 'smarty_feed_generator_activate');
}

if (!function_exists('smarty_feed_generator_deactivate')) {
    /**
     * Deactivation hook to flush rewrite rules and clear scheduled feed regeneration on plugin deactivation.
     */
    function smarty_feed_generator_deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();

        // Clear scheduled feed regeneration event
        $timestamp = wp_next_scheduled('smarty_generate_google_feed_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'smarty_generate_google_feed_event');
        }
    }
    register_deactivation_hook(__FILE__, 'smarty_feed_generator_deactivate');
}

