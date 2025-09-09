<?php
// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Plugin Name: Webwidely - Export Media Library PDFs
 * Description: Download all PDF files from the WordPress Media Library in a single ZIP.
 * Version: 1.0.1
 * Author: Abdul Rauf (Webwidely)
 * Author URI: https://webwidely.com/
 */

// Add a submenu under the Media menu in the WordPress admin dashboard
add_action('admin_menu', 'webwidely_add_pdfs_export_page');

function webwidely_add_pdfs_export_page() {
    add_submenu_page(
        'upload.php', // Parent slug for Media Library
        'Export PDFs from Media Library',
        'Export PDFs',
        'manage_options', // User capability required to view this page
        'webwidely-export-pdfs',
        'webwidely_export_pdfs_admin_page'
    );
}

// Admin page content to display the download button
function webwidely_export_pdfs_admin_page() {
    ?>
    <div class="wrap">
        <h2>Export All PDFs from Media Library</h2>
        <p>This will create a ZIP file containing all PDF files uploaded to your WordPress media library.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="webwidely_export_pdfs">
            <?php submit_button('Download All PDFs'); ?>
        </form>
    </div>
    <?php
}

// Handle the download action for PDFs
add_action('admin_post_webwidely_export_pdfs', 'webwidely_export_pdfs_files');

function webwidely_export_pdfs_files() {
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

    // Query all PDF attachments
    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => 'application/pdf',
        'posts_per_page' => -1,
    );

    $pdfs = get_posts($args);

    if (empty($pdfs)) {
        wp_die('No PDF files found in the Media Library.');
    }

    // Create temporary ZIP file
    $zip = new ZipArchive();
    $zip_file = tempnam(sys_get_temp_dir(), 'media_pdfs_export') . '.zip';

    if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        wp_die('Could not create ZIP file. Please check if ZipArchive is enabled.');
    }

    // Add PDFs to ZIP
    foreach ($pdfs as $pdf) {
        $file_path = get_attached_file($pdf->ID);
        if ($file_path && file_exists($file_path)) {
            $zip->addFile($file_path, basename($file_path));
        }
    }

    $zip->close();

    // Send headers
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="media-library-pdfs.zip"');
    header('Content-Length: ' . filesize($zip_file));
    header('Pragma: no-cache');
    header('Expires: 0');
    flush();

    // Output file
    readfile($zip_file);

    // Clean up
    unlink($zip_file);
    exit;
}
