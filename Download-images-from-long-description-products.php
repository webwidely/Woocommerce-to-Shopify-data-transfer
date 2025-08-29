
// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Webwidely: Download all images from WooCommerce product descriptions.
 * Version: 1.0.0
 * Author: Abdul Rauf (Webwidely)
 * Author URI: https://webwidely.com/
 */

// Add a submenu under the Media menu in the WordPress admin dashboard
add_action('admin_menu', 'webwidely_add_images_export_page');

function webwidely_add_images_export_page() {
    add_submenu_page(
        'upload.php', // Parent slug for Media Library
        'Export Images from Products',
        'Export Product Images',
        'manage_options', // User capability required to view this page
        'webwidely-export-product-images',
        'webwidely_export_images_admin_page'
    );
}

// Admin page content to display the download button
function webwidely_export_images_admin_page() {
    ?>
    <div class="wrap">
        <h2>Export All Images from Product Descriptions</h2>
        <p>This will create a ZIP file containing all images used in the long descriptions of your WooCommerce products.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="webwidely_export_product_images">
            <?php submit_button('Download All Product Images'); ?>
        </form>
    </div>
    <?php
}

// Handle the download action for images
add_action('admin_post_webwidely_export_product_images', 'webwidely_export_product_images_files');

function webwidely_export_product_images_files() {
    // Security check to ensure the user has permission
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to perform this action.');
    }

    // Get all published WooCommerce products
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => -1, // Get all products
        'post_status'    => 'publish',
    );
    $products = new WP_Query($args);

    if (!$products->have_posts()) {
        wp_die('No WooCommerce products found.');
    }

    $image_urls = array();
    $upload_dir = wp_get_upload_dir();
    $base_url   = $upload_dir['baseurl'];

    // Loop through each product and extract image URLs from the long description
    while ($products->have_posts()) {
        $products->the_post();
        $product_id = get_the_ID();
        $product_desc = get_the_content();

        if (empty($product_desc)) {
            continue;
        }

        // Use a regular expression to find all image URLs
        preg_match_all('/<img[^>]+src="([^"]+)"/', $product_desc, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                // Ensure the URL is for an image in the local media library
                if (strpos($url, $base_url) !== false) {
                    $image_urls[] = $url;
                }
            }
        }
    }
    wp_reset_postdata();

    // Remove duplicate URLs
    $unique_image_urls = array_unique($image_urls);

    if (empty($unique_image_urls)) {
        wp_die('No images found in any product descriptions.');
    }

    // Create a temporary ZIP file
    $zip = new ZipArchive();
    $zip_file = tempnam(sys_get_temp_dir(), 'product_images_export') . '.zip';

    if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        wp_die('Could not create ZIP file. Please check if ZipArchive is enabled on your server.');
    }

    // Add each unique image file to the ZIP archive
    foreach ($unique_image_urls as $url) {
        $file_path = str_replace($base_url, $upload_dir['basedir'], $url);
        if (file_exists($file_path)) {
            // Add file to ZIP with its original filename
            $zip->addFile($file_path, basename($file_path));
        }
    }

    $zip->close();

    // Clear any buffered output to prevent corruption
    if (ob_get_length()) {
        ob_end_clean();
    }

    // Send the ZIP file to the user for download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="woocommerce-product-images.zip"');
    header('Content-Length: ' . filesize($zip_file));
    flush();
    readfile($zip_file);

    // Clean up the temporary ZIP file
    unlink($zip_file);
    exit;
}
