<?php
/**
 * Snippet Name: Webwidely - WooCommerce to Shopify ALL User Export
 * Description: Exports all unique customer contacts (registered users + guest billing emails) to a Shopify-compatible CSV.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add a new menu item under WooCommerce to trigger the export.
 */
function webwidely_add_export_menu() {
    add_submenu_page(
        'woocommerce',
        'Export Customers for Shopify',
        'Export for Shopify',
        'manage_woocommerce',
        'wc-shopify-customer-export',
        'webwidely_shopify_customer_export_page'
    );
}
add_action( 'admin_menu', 'webwidely_add_export_menu', 99 );

/**
 * Render the admin page with the export button and inline JavaScript.
 */
function webwidely_shopify_customer_export_page() {
    ?>
    <div class="wrap">
        <h1>Export Customers for Shopify (includes guest checkouts)</h1>
        <p>Exports unique customer contacts (registered users + guest billing emails). The export runs in batches to avoid timeouts. Do not close this page until it completes.</p>
        <button id="start-export" class="button button-primary">Start Export</button>

        <div id="export-status-container" style="margin-top: 20px; max-width:800px;">
            <div id="export-status-text" style="margin-bottom:8px; color:#333;"></div>
            <div id="ww-progress" style="background:#e6e6e6; border-radius:6px; height:18px; overflow:hidden; display:none;">
                <div id="ww-progress-bar" style="height:100%; width:0%; transition:width 300ms ease; background:#0073aa;"></div>
            </div>
            <div id="export-log" style="margin-top:10px; font-size:13px; color:#666;"></div>
        </div>
    </div>
    <script>
    jQuery(document).ready(function($) {
        var total_customers = 0;
        var csv_data = [];
        var headers = [
            'First Name', 'Last Name', 'Email', 'Accepts Email Marketing', 'Company',
            'Address1', 'Address2', 'City',
            'Province Code', 'Country Code', 'Zip',
            'Phone', 'Accepts SMS Marketing', 'Tags', 'Note', 'Tax Exempt'
        ];

        var webwidely_exporter = {
            ajax_url: '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
            nonce: '<?php echo wp_create_nonce( 'webwidely-export-nonce' ); ?>'
        };

        $('#start-export').on('click', function() {
            $(this).attr('disabled', true).text('Exporting...');

            // Reset progress UI
            csv_data = [];
            csv_data.push(headers);
            total_customers = 0;
            $('#export-log').empty();
            $('#export-status-text').text('Starting export...');
            $('#ww-progress').show();
            $('#ww-progress-bar').css('width', '0%');

            export_customers(0);
        });

        function csvEscape(value) {
            if ( value === null || value === undefined ) return '""';
            var str = String(value);
            str = str.replace(/"/g, '""');
            return '"' + str + '"';
        }

        function updateProgress(offset, total) {
            if ( total <= 0 ) {
                $('#ww-progress-bar').css('width', '100%');
                $('#export-status-text').text('Processing...');
                return;
            }
            var percent = Math.min(100, Math.round((offset / total) * 100));
            $('#ww-progress-bar').css('width', percent + '%');
            $('#export-status-text').text('Exporting ' + offset + ' of ' + total + ' customers... (' + percent + '%)');
        }

        function appendLog(message) {
            var time = new Date().toLocaleTimeString();
            $('#export-log').append('<div>[' + time + '] ' + message + '</div>');
        }

        function export_customers(offset) {
            $.ajax({
                url: webwidely_exporter.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'webwidely_export_customers',
                    nonce: webwidely_exporter.nonce,
                    offset: offset
                },
                success: function(response) {
                    if (response.success) {
                        if (offset === 0) { // Only set total_customers on the first request
                            total_customers = parseInt(response.total_customers, 10) || 0;
                        }

                        response.data.forEach(function(row) {
                            var row_array = headers.map(function(header) {
                                return row.hasOwnProperty(header) ? row[header] : '';
                            });
                            csv_data.push(row_array);
                        });

                        var new_offset = parseInt(response.offset, 10) || 0;
                        updateProgress(new_offset, total_customers);

                        appendLog('Received ' + response.data.length + ' rows (offset now ' + new_offset + ')');

                        if (!response.completed) {
                            // small delay to reduce server load
                            setTimeout(function() { export_customers(new_offset); }, 200);
                        } else {
                            // Ensure progress shows 100%
                            updateProgress(total_customers, total_customers);

                            var csvText = csv_data.map(function(row) {
                                return row.map(csvEscape).join(',');
                            }).join('\n');

                            var bom = '\ufeff'; // UTF-8 BOM
                            var blob = new Blob([bom + csvText], { type: 'text/csv;charset=utf-8;' });
                            var url = URL.createObjectURL(blob);

                            var link = document.createElement('a');
                            link.href = url;
                            link.setAttribute('download', 'woocommerce-customers-' + new Date().toISOString().slice(0,10) + '.csv');
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                            URL.revokeObjectURL(url);

                            $('#export-status-text').text('✅ Export complete!');
                            appendLog('Export completed. Download should start automatically.');
                            $('#start-export').attr('disabled', false).text('Start Export');
                        }
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : (response.data || 'Unknown error');
                        $('#export-status-text').text('❌ An error occurred: ' + msg);
                        appendLog('Error: ' + msg);
                        $('#start-export').attr('disabled', false).text('Start Export');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    var err = textStatus + (errorThrown ? (': ' + errorThrown) : '');
                    $('#export-status-text').text('❌ An AJAX error occurred: ' + err);
                    appendLog('AJAX error: ' + err);
                    $('#start-export').attr('disabled', false).text('Start Export');
                }
            });
        }
    });
    </script>
    <?php
}

/**
 * AJAX handler for the export process.
 */
function webwidely_ajax_export_customers() {
    global $wpdb;

    @set_time_limit( 0 );
    @ini_set( 'memory_limit', '512M' );

    check_ajax_referer( 'webwidely-export-nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
    }

    $batch_size = 500;
    $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;

    // Get all unique emails from both users and orders
    $orders_emails_sql = "SELECT LOWER(pm.meta_value) AS email
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'shop_order'
          AND pm.meta_key = '_billing_email'
          AND p.post_status NOT IN ('auto-draft', 'trash')
          AND pm.meta_value != ''";

    $users_emails_sql = "SELECT LOWER(user_email) AS email
        FROM {$wpdb->users}
        WHERE user_email != ''";

    $union_sql = "($orders_emails_sql) UNION ($users_emails_sql)";

    // Calculate total distinct non-empty emails ONLY ON THE FIRST REQUEST
    if ( $offset === 0 ) {
        $count_sql = "SELECT COUNT(DISTINCT email) FROM (" . $union_sql . ") AS t WHERE email <> ''";
        $total_customers = intval( $wpdb->get_var( $count_sql ) );
    } else {
        // We don't need the total count on subsequent requests
        $total_customers = -1;
    }

    // Fetch the batch of distinct emails (ordered to be deterministic)
    $batch_sql = $wpdb->prepare(
        "SELECT DISTINCT email FROM (" . $union_sql . ") AS t WHERE email <> '' ORDER BY email LIMIT %d OFFSET %d",
        $batch_size,
        $offset
    );
    $emails = $wpdb->get_col( $batch_sql );

    $data = array();

    if ( $emails ) {
        foreach ( $emails as $email ) {
            $email = trim( $email );
            if ( empty( $email ) ) {
                continue;
            }

            // Try to find a WP user first
            $user = get_user_by( 'email', $email );

            if ( $user ) {
                $customer = new WC_Customer( $user->ID );

                // Prefer billing fields from WC customer; if empty, fall back to WP user meta
                $first = $customer->get_billing_first_name() ?: get_user_meta( $user->ID, 'first_name', true );
                $last  = $customer->get_billing_last_name()  ?: get_user_meta( $user->ID, 'last_name', true );
                
                // Handle missing last names - use first name or placeholder if none exists
                if (empty($last)) {
                    if (!empty($first)) {
                        // If we have a first name but no last name, use the first name for both
                        $last = $first;
                    } else {
                        // If we have no name at all, use a placeholder
                        $first = 'Customer';
                        $last = 'Unknown';
                    }
                }
                
                // Handle missing first names
                if (empty($first)) {
                    $first = 'Unknown';
                }

                $row = array(
                    'First Name'                => $first,
                    'Last Name'                 => $last,
                    'Email'                     => $email,
                    'Accepts Email Marketing'   => 'FALSE', // Shopify expects uppercase
                    'Company'                   => $customer->get_billing_company() ?: '',
                    'Address1'                  => $customer->get_billing_address_1() ?: '',
                    'Address2'                  => $customer->get_billing_address_2() ?: '',
                    'City'                      => $customer->get_billing_city() ?: '',
                    'Province Code'             => $customer->get_billing_state() ?: '',
                    'Country Code'              => $customer->get_billing_country() ?: '',
                    'Zip'                       => $customer->get_billing_postcode() ?: '',
                    'Phone'                     => $customer->get_billing_phone() ?: '',
                    'Accepts SMS Marketing'     => 'FALSE', // Shopify expects uppercase
                    'Tags'                      => '',
                    'Note'                      => 'Imported from WooCommerce on ' . date('Y-m-d'),
                    'Tax Exempt'                => 'FALSE' // Shopify expects uppercase
                );
            } else {
                // Guest: get the latest order with this billing email and pull its billing meta
                $order_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE p.post_type = 'shop_order'
                       AND pm.meta_key = '_billing_email'
                       AND LOWER(pm.meta_value) = %s
                       AND p.post_status NOT IN ('auto-draft', 'trash')
                     ORDER BY p.post_date DESC
                     LIMIT 1",
                    $email
                ) );

                // default empty values
                $first = $last = $company = $address1 = $address2 = $city = $state = $country = $postcode = $phone = '';

                if ( $order_id ) {
                    $first    = get_post_meta( $order_id, '_billing_first_name', true );
                    $last     = get_post_meta( $order_id, '_billing_last_name', true );
                    $company  = get_post_meta( $order_id, '_billing_company', true );
                    $address1 = get_post_meta( $order_id, '_billing_address_1', true );
                    $address2 = get_post_meta( $order_id, '_billing_address_2', true );
                    $city     = get_post_meta( $order_id, '_billing_city', true );
                    $state    = get_post_meta( $order_id, '_billing_state', true );
                    $country  = get_post_meta( $order_id, '_billing_country', true );
                    $postcode = get_post_meta( $order_id, '_billing_postcode', true );
                    $phone    = get_post_meta( $order_id, '_billing_phone', true );
                }
                
                // Handle missing last names for guest orders
                if (empty($last)) {
                    if (!empty($first)) {
                        // If we have a first name but no last name, use the first name for both
                        $last = $first;
                    } else {
                        // If we have no name at all, use a placeholder
                        $first = 'Customer';
                        $last = 'Unknown';
                    }
                }
                
                // Handle missing first names for guest orders
                if (empty($first)) {
                    $first = 'Unknown';
                }

                $row = array(
                    'First Name'                => $first,
                    'Last Name'                 => $last,
                    'Email'                     => $email,
                    'Accepts Email Marketing'   => 'FALSE', // Shopify expects uppercase
                    'Company'                   => $company ?: '',
                    'Address1'                  => $address1 ?: '',
                    'Address2'                  => $address2 ?: '',
                    'City'                      => $city ?: '',
                    'Province Code'             => $state ?: '',
                    'Country Code'              => $country ?: '',
                    'Zip'                       => $postcode ?: '',
                    'Phone'                     => $phone ?: '',
                    'Accepts SMS Marketing'     => 'FALSE', // Shopify expects uppercase
                    'Tags'                      => '',
                    'Note'                      => 'Imported from WooCommerce (guest order) on ' . date('Y-m-d'),
                    'Tax Exempt'                => 'FALSE' // Shopify expects uppercase
                );
            }

            $data[] = $row;
        }
    }

    $new_offset = $offset + count( $emails );
    $completed = ( count( $emails ) < $batch_size ); // Check if the batch size is less than the expected size

    $response = array(
        'success'         => true,
        'data'            => $data,
        'offset'          => $new_offset,
        'total_customers' => $total_customers, // This will be -1 on subsequent requests
        'completed'       => $completed
    );

    wp_send_json( $response );
}
add_action( 'wp_ajax_webwidely_export_customers', 'webwidely_ajax_export_customers' );
