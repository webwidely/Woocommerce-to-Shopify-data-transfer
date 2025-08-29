<?php
/**
 * Plugin Name: Webwidely - WooCommerce to Shopify Customer Export
 * Description: Exports WooCommerce customer data in a Shopify-compatible CSV format.
 * Author: Abdul Rauf (Webwidely)
 * Version: 1.0.3
 *
 * This code is provided as a complete, self-contained solution.
 * It's recommended to place this in a custom plugin for easy management.
 * Alternatively, you can add the content of this file (excluding the initial
 * multi-line comment block and plugin header) to your theme's functions.php file.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add a new menu item under WooCommerce to trigger the export.
 * This is a safer and more user-friendly method than a direct URL link.
 */
function webwidely_add_export_menu() {
    add_submenu_page(
        'woocommerce',
        'Export Customers for Shopify',
        'Export for Shopify',
        'manage_options',
        'wc-shopify-customer-export',
        'webwidely_shopify_customer_export_page'
    );
}
add_action( 'admin_menu', 'webwidely_add_export_menu', 99 );

/**
 * Render the admin page content with the export button.
 */
function webwidely_shopify_customer_export_page() {
    ?>
    <div class="wrap">
        <h1>Export Customers for Shopify</h1>
        <p>Click the button below to generate a CSV file of your WooCommerce customers. This file is formatted for direct import into Shopify.</p>
        <form method="post">
            <input type="hidden" name="wc_shopify_customer_export_nonce" value="<?php echo wp_create_nonce( 'wc-shopify-customer-export-nonce' ); ?>">
            <input type="submit" name="webwidely_export_button" class="button button-primary" value="Download Shopify Customer CSV">
        </form>
    </div>
    <?php
}

/**
 * Handle the export process when the button is clicked.
 */
function webwidely_handle_export_request() {
    // Check if the export button was clicked and the nonce is valid.
    if ( isset( $_POST['webwidely_export_button'] ) && isset( $_POST['wc_shopify_customer_export_nonce'] ) ) {
        if ( ! wp_verify_nonce( $_POST['wc_shopify_customer_export_nonce'], 'wc-shopify-customer-export-nonce' ) ) {
            wp_die( 'Security check failed. Please go back and try again.' );
        }

        // Get all customer users. 'customer' is the default WooCommerce role.
        $customer_query = new WP_User_Query( array(
            'role' => 'customer',
            'orderby' => 'registered',
            'order' => 'ASC',
            'fields' => 'all_with_meta'
        ) );
        $customers = $customer_query->get_results();

        // Shopify requires specific column headers for a successful import.
        $headers = array(
            'First Name',
            'Last Name',
            'Email',
            'Accepts Email Marketing',
            'Default Address Company',
            'Default Address Address1',
            'Default Address Address2',
            'Default Address City',
            'Default Address Province Code',
            'Default Address Country Code',
            'Default Address Zip',
            'Default Address Phone',
            'Accepts SMS Marketing',
            'Tags',
            'Note',
            'Tax Exempt'
        );

        // Set up the CSV file and headers for download.
        $filename = 'woocommerce-customers-' . date( 'Y-m-d_H-i-s' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, $headers );

        // Loop through each customer and add their data to the CSV.
        if ( $customers ) {
            foreach ( $customers as $customer_data ) {
                $customer = new WC_Customer( $customer_data->ID );

                $row = array(
                    'First Name' => $customer->get_billing_first_name(),
                    'Last Name' => $customer->get_billing_last_name(),
                    'Email' => $customer->get_billing_email(),
                    'Accepts Email Marketing' => 'false',
                    'Default Address Company' => $customer->get_billing_company(),
                    'Default Address Address1' => $customer->get_billing_address_1(),
                    'Default Address Address2' => $customer->get_billing_address_2(),
                    'Default Address City' => $customer->get_billing_city(),
                    'Default Address Province Code' => $customer->get_billing_state(),
                    'Default Address Country Code' => $customer->get_billing_country(),
                    'Default Address Zip' => $customer->get_billing_postcode(),
                    'Default Address Phone' => $customer->get_billing_phone(),
                    'Accepts SMS Marketing' => 'false',
                    'Tags' => '',
                    'Note' => 'Imported from WooCommerce on ' . date('Y-m-d'),
                    'Tax Exempt' => 'false'
                );
                fputcsv( $output, $row );
            }
        }

        // Close the file pointer.
        fclose( $output );
        exit;
    }
}
add_action( 'admin_init', 'webwidely_handle_export_request' );
