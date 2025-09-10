<?php
// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Plugin Name: Webwidely - Export Unused Product Images
 * Description: Download all image files from the WordPress Media Library that are NOT used in WooCommerce products.
 * Version: 1.0.0
 * Author: Abdul Rauf (Webwidely)
 * Author URI: https://webwidely.com/
 */

// Add a submenu under the Media menu in the WordPress admin dashboard
add_action('admin_menu', 'webwidely_add_unused_images_export_page');

function webwidely_add_unused_images_export_page() {
    add_submenu_page(
        'upload.php', // Parent slug for Media Library
        'Export Unused Product Images',
        'Export Unused Product Images',
        'manage_options',
        'webwidely-export-unused-images',
        'webwidely_export_unused_images_admin_page'
    );
}

// Admin page content to display the download button
function webwidely_export_unused_images_admin_page() {
    ?>
    <div class="wrap">
        <h2>Export Unused Product Images</h2>
        <p>This will create a ZIP file containing all images from your Media Library that are NOT used in WooCommerce products.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="webwidely_export_unused_images">
            <?php submit_button('Download Unused Images'); ?>
        </form>
    </div>
    <?php
}

// Handle the download action
add_action('admin_post_webwidely_export_unused_images', 'webwidely_export_unused_images_files');

function webwidely_export_unused_images_files() {
    // Security check
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to perform this action.');
    }

    // Clean any previous output
    while (ob_get_level()) {
        ob_end_clean();
    }

    ignore_user_abort(true);
    set_time_limit(0);

    // Get all image attachments
    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => -1,
        'fields'         => 'ids'
    );

    $all_images = get_posts($args);

    if (empty($all_images)) {
        wp_die('No images found in the Media Library.');
    }

    // Get all WooCommerce product IDs
    $products = get_posts(array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids'
    ));

    $used_image_ids = array();

    foreach ($products as $product_id) {
        // Featured image
        $featured_image_id = get_post_thumbnail_id($product_id);
        if ($featured_image_id) {
            $used_image_ids[] = $featured_image_id;
        }

        // Gallery images
        $gallery_image_ids = get_post_meta($product_id, '_product_image_gallery', true);
        if (!empty($gallery_image_ids)) {
            $gallery_ids_array = array_map('intval', explode(',', $gallery_image_ids));
            $used_image_ids = array_merge($used_image_ids, $gallery_ids_array);
        }
    }

    $used_image_ids = array_unique($used_image_ids);

    // Get unused images
    $unused_images = array_diff($all_images, $used_image_ids);

    if (empty($unused_images)) {
        wp_die('No unused images found.');
    }

    // Create temporary ZIP file
    $zip = new ZipArchive();
    $zip_file = tempnam(sys_get_temp_dir(), 'unused_images_export') . '.zip';

    if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        wp_die('Could not create ZIP file. Please check if ZipArchive is enabled.');
    }

    // Add unused images to ZIP
    foreach ($unused_images as $image_id) {
        $file_path = get_attached_file($image_id);
        if ($file_path && file_exists($file_path)) {
            $zip->addFile($file_path, basename($file_path));
        }
    }

    $zip->close();

    // Send headers for download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="unused-product-images.zip"');
    header('Content-Length: ' . filesize($zip_file));
    header('Pragma: no-cache');
    header('Expires: 0');
    flush();

    readfile($zip_file);

    unlink($zip_file);
    exit;
}
