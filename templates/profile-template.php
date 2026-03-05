<?php
/**
 * Improved Profile Template with Status Filters
 * Save as: templates/profile-template.php
 */

if (!defined("ABSPATH")) {
  exit();
}

$current_user = wp_get_current_user();
if ($current_user->ID === 0) {
  echo "<p>" .
    __("Please log in to view your invoices.", "custom-invoice-system") .
    "</p>";
  return;
}

$convenience_fee = get_option("convenience_fee_percentage", "0");
$ach_fee = get_option("ach_fee_amount", "0");
$enable_ach = get_option("enable_ach_payments") === "1";

// Get filter from URL or default to 'active'
$status_filter = isset($_GET["invoice_status"])
  ? sanitize_text_field($_GET["invoice_status"])
  : "active";
$search_query = isset($_GET["invoice_search"])
  ? sanitize_text_field($_GET["invoice_search"])
  : "";

// Get counts for each status
global $wpdb;
$status_counts = $wpdb->get_results(
  $wpdb->prepare(
    "
    SELECT status, COUNT(*) as count 
    FROM {$wpdb->prefix}custom_invoices 
    WHERE user_id = %d
    GROUP BY status",
    $current_user->ID
  )
);

$counts = [
  "all" => 0,
  "active" => 0,
  "pending" => 0,
  "processing" => 0,
  "paid" => 0,
];

foreach ($status_counts as $row) {
  $counts[$row->status] = $row->count;
  $counts["all"] += $row->count;
}
$counts["active"] = $counts["pending"] + $counts["processing"];
?>

<script>
    // Initialize stripeCheckoutData if it doesn't exist
    window.stripeCheckoutData = window.stripeCheckoutData || {};
    // Explicitly set the payment settings
    window.stripeCheckoutData.convenienceFeePercentage = <?php echo json_encode(
      $convenience_fee
    ); ?>;
    window.stripeCheckoutData.achFeeAmount = <?php echo json_encode(
      $ach_fee
    ); ?>;
    window.stripeCheckoutData.enableACH = <?php echo json_encode(
      $enable_ach
    ); ?>;
</script>

<div class="custom-profile-container">
    <div class="profile-header">
        <h1><?php _e("My Invoices", "custom-invoice-system"); ?></h1>
        
        <!-- Search Bar -->
        <div class="invoice-search-wrapper">
            <form method="get" action="" class="invoice-search-form">
                <input type="hidden" name="invoice_status" value="<?php echo esc_attr(
                  $status_filter
                ); ?>">
                <div class="search-box">
                    <input type="search" 
                           name="invoice_search" 
                           placeholder="<?php esc_attr_e(
                             "Search invoices...",
                             "custom-invoice-system"
                           ); ?>"
                           value="<?php echo esc_attr($search_query); ?>"
                           class="search-input">
                    <button type="submit" class="search-button">
                        <span class="dashicons dashicons-search"></span>
                    </button>
                    <?php if ($search_query): ?>
                        <a href="?invoice_status=<?php echo esc_attr(
                          $status_filter
                        ); ?>" class="clear-search">
                            <?php _e("Clear", "custom-invoice-system"); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Status Filter Tabs -->
    <div class="invoice-status-tabs">
        <a href="?invoice_status=active" 
           class="status-tab <?php echo $status_filter === "active"
             ? "active"
             : ""; ?>">
            <?php _e("Active", "custom-invoice-system"); ?> 
            <span class="count"><?php echo $counts["active"]; ?></span>
        </a>
        <a href="?invoice_status=pending" 
           class="status-tab <?php echo $status_filter === "pending"
             ? "active"
             : ""; ?>">
            <?php _e("Pending", "custom-invoice-system"); ?> 
            <span class="count"><?php echo $counts["pending"]; ?></span>
        </a>
        <a href="?invoice_status=processing" 
           class="status-tab <?php echo $status_filter === "processing"
             ? "active"
             : ""; ?>">
            <?php _e("Processing", "custom-invoice-system"); ?> 
            <span class="count"><?php echo $counts["processing"]; ?></span>
        </a>
        <a href="?invoice_status=paid" 
           class="status-tab <?php echo $status_filter === "paid"
             ? "active"
             : ""; ?>">
            <?php _e("Paid", "custom-invoice-system"); ?> 
            <span class="count"><?php echo $counts["paid"]; ?></span>
        </a>
        <a href="?invoice_status=all" 
           class="status-tab <?php echo $status_filter === "all"
             ? "active"
             : ""; ?>">
            <?php _e("All", "custom-invoice-system"); ?> 
            <span class="count"><?php echo $counts["all"]; ?></span>
        </a>
    </div>
    
    <div class="invoices-section">
        <?php
        // Pagination
        $invoices_per_page = 25;
        $current_page = isset($_GET["inv_page"])
          ? max(1, intval($_GET["inv_page"]))
          : 1;
        $offset = ($current_page - 1) * $invoices_per_page;

        // Build query based on filters
        $query_args = ["user_id = %d"];
        $query_values = [$current_user->ID];

        // Apply status filter
        if ($status_filter === "active") {
          $query_args[] = "status IN ('pending', 'processing')";
        } elseif ($status_filter !== "all") {
          $query_args[] = "status = %s";
          $query_values[] = $status_filter;
        }

        // Apply search filter
        if (!empty($search_query)) {
          $query_args[] = "(invoice_number LIKE %s OR description LIKE %s)";
          $search_like = "%" . $wpdb->esc_like($search_query) . "%";
          $query_values[] = $search_like;
          $query_values[] = $search_like;
        }

        $where_clause = implode(" AND ", $query_args);

        // Get total count for pagination
        $total_invoices = $wpdb->get_var(
          $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}custom_invoices 
            WHERE {$where_clause}",
            $query_values
          )
        );
        $total_pages = max(1, ceil($total_invoices / $invoices_per_page));

        $invoices = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}custom_invoices 
            WHERE {$where_clause}
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d",
            array_merge($query_values, [$invoices_per_page, $offset])
          )
        );

        if ($invoices): ?>
            <div class="table-responsive">
                <table class="invoices-table">
                    <thead>
                        <tr>
                            <th><?php _e(
                              "Invoice #",
                              "custom-invoice-system"
                            ); ?></th>
                            <th><?php _e(
                              "Amount",
                              "custom-invoice-system"
                            ); ?></th>
                            <th><?php _e(
                              "Due Date",
                              "custom-invoice-system"
                            ); ?></th>
                            <th><?php _e(
                              "Status",
                              "custom-invoice-system"
                            ); ?></th>
                            <th><?php _e(
                              "Description",
                              "custom-invoice-system"
                            ); ?></th>
                            <th><?php _e(
                              "Action",
                              "custom-invoice-system"
                            ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice):
                          $is_overdue =
                            $invoice->due_date < date("Y-m-d") &&
                            $invoice->status === "pending"; ?>
                            <tr class="<?php echo $is_overdue
                              ? "invoice-overdue"
                              : ""; ?>">
                                <td data-label="<?php _e(
                                  "Invoice #",
                                  "custom-invoice-system"
                                ); ?>">
                                    <?php echo esc_html(
                                      $invoice->invoice_number
                                    ); ?>
                                </td>
                                <td data-label="<?php _e(
                                  "Amount",
                                  "custom-invoice-system"
                                ); ?>">
                                    $<?php echo number_format(
                                      $invoice->amount,
                                      2
                                    ); ?>
                                </td>
                                <td data-label="<?php _e(
                                  "Due Date",
                                  "custom-invoice-system"
                                ); ?>">
                                    <?php
                                    echo date(
                                      "M d, Y",
                                      strtotime($invoice->due_date)
                                    );
                                    if ($is_overdue) {
                                      echo ' <span class="overdue-label">' .
                                        __(
                                          "(Overdue)",
                                          "custom-invoice-system"
                                        ) .
                                        "</span>";
                                    }
                                    ?>
                                </td>
                                <td data-label="<?php _e(
                                  "Status",
                                  "custom-invoice-system"
                                ); ?>">
                                    <?php if (
                                      $invoice->status === "processing"
                                    ): ?>
                                        <span class="status-badge processing"><?php _e(
                                          "Processing",
                                          "custom-invoice-system"
                                        ); ?></span>
                                    <?php elseif (
                                      $invoice->status === "paid"
                                    ): ?>
                                        <span class="status-badge paid"><?php _e(
                                          "Paid",
                                          "custom-invoice-system"
                                        ); ?></span>
                                    <?php else: ?>
                                        <span class="status-badge <?php echo esc_attr(
                                          $invoice->status
                                        ); ?>">
                                            <?php echo esc_html(
                                              ucfirst($invoice->status)
                                            ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="<?php _e(
                                  "Description",
                                  "custom-invoice-system"
                                ); ?>">
                                    <?php echo esc_html(
                                      $invoice->description
                                    ); ?>
                                </td>
                                <td data-label="<?php _e(
                                  "Action",
                                  "custom-invoice-system"
                                ); ?>">
                                    <?php if (
                                      $invoice->status !== "paid" &&
                                      $invoice->status !== "processing"
                                    ): ?>
                                        <button class="pay-invoice-btn cdg_button" 
                                                data-invoice-id="<?php echo esc_attr(
                                                  $invoice->id
                                                ); ?>"
                                                data-amount="<?php echo esc_attr(
                                                  $invoice->amount
                                                ); ?>">
                                            <?php _e(
                                              "Pay Now",
                                              "custom-invoice-system"
                                            ); ?>
                                        </button>
                                    <?php elseif (
                                      $invoice->status === "processing"
                                    ): ?>
                                        <span class="payment-pending"><?php _e(
                                          "Payment in Progress",
                                          "custom-invoice-system"
                                        ); ?></span>
                                    <?php else: ?>
                                        <span class="payment-complete">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <?php _e(
                                              "Paid",
                                              "custom-invoice-system"
                                            ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php
                        endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($total_pages > 1): ?>
                <div class="invoice-pagination">
                    <?php
                    $base_url = add_query_arg(
                      [
                        "invoice_status" => $status_filter,
                        "invoice_search" => $search_query,
                      ],
                      get_permalink()
                    );

                    if ($current_page > 1): ?>
                        <a href="<?php echo esc_url(
                          add_query_arg(
                            "inv_page",
                            $current_page - 1,
                            $base_url
                          )
                        ); ?>" class="page-link">&laquo; <?php _e(
  "Previous",
  "custom-invoice-system"
); ?></a>
                    <?php endif;
                    ?>
                    
                    <span class="page-info">
                        <?php printf(
                          __("Page %d of %d", "custom-invoice-system"),
                          $current_page,
                          $total_pages
                        ); ?>
                    </span>
                    
                    <?php if ($current_page < $total_pages): ?>
                        <a href="<?php echo esc_url(
                          add_query_arg(
                            "inv_page",
                            $current_page + 1,
                            $base_url
                          )
                        ); ?>" class="page-link"><?php _e(
  "Next",
  "custom-invoice-system"
); ?> &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($status_filter === "active" && $counts["paid"] > 0): ?>
                <div class="view-paid-notice">
                    <p><?php printf(
                      __(
                        'You have %d paid invoice(s). <a href="?invoice_status=paid">View paid invoices</a>',
                        "custom-invoice-system"
                      ),
                      $counts["paid"]
                    ); ?></p>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <div class="no-invoices-message">
                <?php if ($search_query): ?>
                    <p><?php printf(
                      __(
                        'No invoices found matching "%s".',
                        "custom-invoice-system"
                      ),
                      esc_html($search_query)
                    ); ?></p>
                    <p><a href="?invoice_status=<?php echo esc_attr(
                      $status_filter
                    ); ?>" class="button">
                        <?php _e("Clear Search", "custom-invoice-system"); ?>
                    </a></p>
                <?php elseif ($status_filter === "paid"): ?>
                    <p><?php _e(
                      "You have no paid invoices.",
                      "custom-invoice-system"
                    ); ?></p>
                    <p><a href="?invoice_status=active" class="button">
                        <?php _e(
                          "View Active Invoices",
                          "custom-invoice-system"
                        ); ?>
                    </a></p>
                <?php else: ?>
                    <p><?php _e(
                      "No invoices found.",
                      "custom-invoice-system"
                    ); ?></p>
                <?php endif; ?>
            </div>
        <?php endif;
        ?>
    </div>
    
    <!-- Payment Modal (unchanged) -->
    <div id="payment-modal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3><?php _e("Pay Invoice", "custom-invoice-system"); ?></h3>
            <div id="payment-details"></div>
            
            <!-- Payment Method Selection -->
            <div class="payment-method-selection">
                <h4><?php _e(
                  "Select Payment Method",
                  "custom-invoice-system"
                ); ?></h4>
                <div class="payment-options">
                    <label class="payment-option">
                        <input type="radio" name="payment_method" value="card" checked>
                        <span class="payment-label"><?php _e(
                          "Credit/Debit Card",
                          "custom-invoice-system"
                        ); ?></span>
                        <span class="payment-description">
                            <?php if ($convenience_fee > 0): ?>
                                (<?php echo esc_html(
                                  $convenience_fee
                                ); ?>% <?php _e(
  "convenience fee",
  "custom-invoice-system"
); ?>)
                            <?php else: ?>
                                (<?php _e(
                                  "No additional fee",
                                  "custom-invoice-system"
                                ); ?>)
                            <?php endif; ?>
                        </span>
                    </label>
                    
                    <?php if (get_option("enable_ach_payments") === "1"): ?>
                    <label class="payment-option">
                        <input type="radio" name="payment_method" value="ach">
                        <span class="payment-label"><?php _e(
                          "Bank Account (ACH)",
                          "custom-invoice-system"
                        ); ?></span>
                        <span class="payment-description">
                            <?php
                            $ach_fee = get_option("ach_fee_amount", "0");
                            if ($ach_fee > 0): ?>
                                ($<?php echo esc_html($ach_fee); ?> <?php _e(
   "service fee",
   "custom-invoice-system"
 ); ?>)
                            <?php else: ?>
                                (<?php _e(
                                  "No additional fee",
                                  "custom-invoice-system"
                                ); ?>)
                            <?php endif;
                            ?>
                        </span>
                    </label>
                    <?php endif; ?>
                </div>
            </div>
            
            <form id="payment-form">
                <div id="payment-message" role="alert"></div>
                <button type="submit" id="pay-button"><?php _e(
                  "Proceed to Checkout",
                  "custom-invoice-system"
                ); ?></button>
            </form>
        </div>
    </div>
</div>

<style>
/* Header and search styling */
.profile-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 20px;
}

.profile-header h1 {
    margin: 0;
}

.invoice-search-wrapper {
    flex-grow: 1;
    max-width: 400px;
}

.invoice-search-form {
    margin: 0;
}

.search-box {
    display: flex;
    align-items: center;
    position: relative;
}

.search-input {
    flex: 1;
    padding: 8px 40px 8px 12px;
    border: 1px solid rgba(72,61,3,.2);
    border-radius: 4px;
    font-size: 14px;
}

.search-button {
    position: absolute;
    right: 2px;
    background: none;
    border: none;
    padding: 8px;
    cursor: pointer;
    color: #666;
}

.search-button:hover {
    color: #333;
}

.clear-search {
    margin-left: 10px;
    text-decoration: none;
    color: #666;
    font-size: 14px;
}

/* Status tabs styling */
.invoice-status-tabs {
    display: flex;
    gap: 0;
    margin-bottom: 20px;
    border-bottom: 2px solid rgba(72,61,3,0.1);
    flex-wrap: wrap;
}
a.status-tab
 {
    font-size: 14px;
}

.status-tab {
    padding: 10px 20px;
    text-decoration: none;
    color: #666;
    border-bottom: 3px solid transparent;
    transition: all 0.3s ease;
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
}

.status-tab:hover {
    color: #333;
    background-color: rgba(72,61,3,0.1);
}

.status-tab.active {
    color: #0f4131;
    border-bottom-color: #0f4131;
    font-weight: 600;
}

.status-tab .count {
    background: #e0e0e0;
    color: #666;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: normal;
}

.status-tab.active .count {
    background: #0073aa;
    color: white;
}

/* Invoice table improvements */
.invoice-overdue {
    background-color: #fff5f5 !important;
}

.overdue-label {
    color: #dc3232;
    font-weight: bold;
    font-size: 12px;
}

.payment-complete {
    color: #46b450;
    display: flex;
    align-items: center;
    gap: 4px;
}

.view-paid-notice {
    margin-top: 16px;
    padding: 16px;
    background: rgba(72,61,3,0.1);
    border-radius: 4px;
    text-align: center;
}
.view-paid-notice p
 {
    font-size: 16px!important;
}

.no-invoices-message {
    text-align: center;
    padding: 40px;
    background: #f8f8f8;
    border-radius: 4px;
}

.no-invoices-message .button {
    margin-top: 10px;
}

/* Keep all existing styles below... */
.payment-method-selection {
    margin: 20px 0 10px 0;
}

.payment-options {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 0px;
}

.payment-option {
    display: flex;
    align-items: center;
    padding: 10px;
    border: 1px solid rgba(72,61,3,.2);
    border-radius: 4px;
    cursor: pointer;
    display: flex;
    gap: 0px;
}

.payment-option:hover {
    background-color: #f8f8f8;
}

.payment-option input {
    margin-right: 10px;
}

.payment-label {
    font-weight: bold;
    margin-right: 6px;
}

.payment-description {
    color: #666;
    font-size: 0.9em;
}
.custom-profile-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.status-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
}

.status-badge.paid {
    background-color: #d4edda;
    color: #155724;
}

.status-badge.pending {
    background-color: #fff3cd;
    color: #856404;
}

.status-badge.processing {
    background-color: #d1ecf1;
    color: #0c5460;
}

.payment-pending {
    color: #0c5460;
    font-size: 14px;
    font-style: italic;
}

table.invoices-table {
    border: none;
}
.fee-breakdown {
    margin: 10px 0;
    padding: 15px;
    background: #fff9ed;
    border-radius: 2px;
    border: 1px solid rgba(72,61,3,.2);
}

.fee-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0px;
    padding: 5px 0;
}
.fee-row span {
    font-size: 1rem;
}

.fee-row.total {
    border-top: 1px solid rgba(72,61,3,.2);
    margin-top: 10px;
    padding-top: 10px;
    font-weight: bold;
}
.invoices-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    border: none;
}
.entry-content table:not(.variations) {
    border: none;
}
.invoices-table th,
.invoices-table td {
    padding: 10px;
    border: 1px solid rgba(72,61,3,0.2);
    text-align: left;
}

.invoices-table th {
    background-color: #f5f5f5;
}

.entry-content thead th, 
.entry-content tr th {
    line-height: 1em;
    background-color: #fff9ed;
    color: #0f4131;
}

.entry-content tr td {
    border-top: none;
    padding: 10px 24px;
}

.entry-content table {
    font-size: 14px;
}

/* Responsive styles */
@media screen and (max-width: 768px) {
    .profile-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .invoice-search-wrapper {
        max-width: 100%;
    }
    
    .invoice-status-tabs {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0 -20px;
        padding: 0 20px;
    }
    
    .status-tab {
        white-space: nowrap;
        flex-shrink: 0;
    }
    
    .invoices-table {
        border: 0;
    }
    
    .invoices-table thead {
        display: none;
    }
    
    .invoices-table tr {
        margin-bottom: 20px;
        display: block;
        border: 1px solid rgba(72,61,3,0.2);
        border-radius: 4px;
    }
    
    .invoices-table td {
        display: block;
        text-align: right;
        padding: 12px;
        position: relative;
        border: none;
        border-bottom: 1px solid rgba(72,61,3,0.2);
    }
    
    .invoices-table td:last-child {
        border-bottom: 0;
    }
    
    .invoices-table td::before {
        content: attr(data-label);
        position: absolute;
        left: 12px;
        width: 45%;
        text-align: left;
        font-weight: bold;
    }
    
    .invoices-table td:last-child {
        text-align: center;
        padding-top: 15px;
    }
    
    .pay-invoice-btn {
        width: 100%;
        padding: 10px;
        margin-top: 5px;
    }
}

/* Tablet specific adjustments */
@media screen and (min-width: 769px) and (max-width: 1024px) {
    .invoices-table {
        font-size: 14px;
    }
    
    .invoices-table th,
    .invoices-table td {
        padding: 8px;
    }
    
    .pay-invoice-btn {
        padding: 4px 8px;
        font-size: 13px;
    }
}

/* Modal styles */
.payment-label {
    font-size: .7rem;
}
.payment-description {
    font-size: .6rem;
}
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    position: relative;
    background-color: #fff9ed !important;
    margin: auto !important;
    padding: 20px;
    border: 1px solid rgba(72,61,3,0.2);
    width: 80%;
    max-width: 500px;
    border-radius: 4px;
    top: 50%;
    transform: translateY(-50%);
}

.close {
    position: absolute;
    right: 20px;
    top: 10px;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

#payment-form {
    margin-top: 20px;
}

#payment-message {
    color: #dc3545;
    margin-bottom: 10px;
    padding: 10px;
    border-radius: 4px;
    background-color: #f8d7da;
    display: none;
}

#payment-message.visible {
    display: block;
}

.pay-invoice-btn {
    background-color: #0073aa;
    color: white;
    border: none;
    padding: 5px 10px;
    border-radius: 3px;
    cursor: pointer;
}

.pay-invoice-btn:hover {
    background-color: #006291;
}

#payment-form button[type="submit"] {
    width: 100%;
    padding: 10px;
    background-color: #0073aa;
    color: white;
    border: none;
    border-radius: 4px;
    font-size: 16px;
    cursor: pointer;
    margin-top: 20px;
}

#payment-form button[type="submit"]:hover {
    background-color: #005a87;
}

#payment-details p {
    font-size: .6em;
    font-weight: 500;
    font-style: italic;
}

/* Responsive modal */
@media screen and (max-width: 768px) {
    .invoices-table td:empty {
        display: none;
    }
    .invoices-table td:last-child {
        padding-bottom:30px;
    }
    .cdg_button {
        margin-top: 24px;
        padding-top: 16px !important;
        padding-bottom: 16px !important;
    }
    .modal-content {
        width: 95%;
        margin: 10% auto;
        padding: 15px;
    }
    
    #payment-form {
        margin-top: 15px;
    }
    .payment-label {
        margin-right:12px;
        line-height: 100%;
    }
}

/* Pagination */
.invoice-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 15px;
    margin: 20px 0;
    padding: 10px 0;
}

.invoice-pagination .page-link {
    padding: 8px 16px;
    border: 1px solid rgba(72,61,3,0.2);
    border-radius: 4px;
    text-decoration: none;
    color: #0f4131;
    transition: background-color 0.2s;
}

.invoice-pagination .page-link:hover {
    background-color: rgba(72,61,3,0.1);
}

.invoice-pagination .page-info {
    color: #666;
    font-size: 14px;
}
</style>