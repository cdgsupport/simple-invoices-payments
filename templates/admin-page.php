<?php
/**
 * Improved Admin Page Template with Filters, Search, and Sortable Invoice List
 * Save as: templates/admin-page.php
 */

// Add this temporarily at the top of your admin-page.php template (after <?php tag)
if (defined("WP_DEBUG") && WP_DEBUG) {
  echo '<script>console.log("Admin page loaded, jQuery version:", jQuery.fn.jquery);</script>';
}

if (!defined("ABSPATH")) {
  exit();
}

// Get filter parameters
$status_filter = isset($_GET["status"])
  ? sanitize_text_field($_GET["status"])
  : "all";
$search_query = isset($_GET["search"])
  ? sanitize_text_field($_GET["search"])
  : "";
$current_url = admin_url("admin.php?page=manage-invoices");

// Pagination parameters
$current_page = isset($_GET["paged"]) ? max(1, intval($_GET["paged"])) : 1;
$per_page = 100;
$offset = ($current_page - 1) * $per_page;

// Get invoice counts by status
global $wpdb;
$status_counts = $wpdb->get_results("
    SELECT status, COUNT(*) as count 
    FROM {$wpdb->prefix}custom_invoices 
    GROUP BY status
");

$counts = [
  "all" => 0,
  "active" => 0,
  "pending" => 0,
  "processing" => 0,
  "paid" => 0,
  "cancelled" => 0,
];

foreach ($status_counts as $row) {
  $counts[$row->status] = $row->count;
  $counts["all"] += $row->count;
}

// Active = pending + processing
$counts["active"] = $counts["pending"] + $counts["processing"];
?>

<div class="wrap">
    <input type="hidden" id="invoice_nonce" value="<?php echo wp_create_nonce(
      "invoice_nonce"
    ); ?>">
    <h1 class="wp-heading-inline"><?php _e(
      "Manage Invoices",
      "custom-invoice-system"
    ); ?></h1>
    
    <!-- Search Bar -->
    <div class="invoice-search-box" style="float: right; margin-bottom: 10px;">
        <form method="get" action="">
            <input type="hidden" name="page" value="manage-invoices">
            <input type="hidden" name="status" value="<?php echo esc_attr(
              $status_filter
            ); ?>">
            <input type="search" 
                   name="search" 
                   id="invoice-search" 
                   placeholder="<?php esc_attr_e(
                     "Search by user name or invoice #...",
                     "custom-invoice-system"
                   ); ?>"
                   value="<?php echo esc_attr($search_query); ?>"
                   style="width: 300px;">
            <button type="submit" class="button"><?php _e(
              "Search",
              "custom-invoice-system"
            ); ?></button>
            <?php if ($search_query): ?>
                <a href="<?php echo esc_url(
                  add_query_arg("status", $status_filter, $current_url)
                ); ?>" class="button"><?php _e(
  "Clear",
  "custom-invoice-system"
); ?></a>
            <?php endif; ?>
        </form>
    </div>
    
    <div style="clear: both;"></div>
    
    <!-- Status Filter Tabs -->
    <ul class="subsubsub">
        <li>
            <a href="<?php echo esc_url(
              add_query_arg("status", "active", $current_url)
            ); ?>" 
               class="<?php echo $status_filter === "active"
                 ? "current"
                 : ""; ?>">
                <?php _e("Active", "custom-invoice-system"); ?> 
                <span class="count">(<?php echo $counts["active"]; ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url(
              add_query_arg("status", "pending", $current_url)
            ); ?>" 
               class="<?php echo $status_filter === "pending"
                 ? "current"
                 : ""; ?>">
                <?php _e("Pending", "custom-invoice-system"); ?> 
                <span class="count">(<?php echo $counts["pending"]; ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url(
              add_query_arg("status", "processing", $current_url)
            ); ?>" 
               class="<?php echo $status_filter === "processing"
                 ? "current"
                 : ""; ?>">
                <?php _e("Processing", "custom-invoice-system"); ?> 
                <span class="count">(<?php echo $counts[
                  "processing"
                ]; ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url(
              add_query_arg("status", "paid", $current_url)
            ); ?>" 
               class="<?php echo $status_filter === "paid"
                 ? "current"
                 : ""; ?>">
                <?php _e("Paid", "custom-invoice-system"); ?> 
                <span class="count">(<?php echo $counts["paid"]; ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url(
              add_query_arg("status", "cancelled", $current_url)
            ); ?>" 
               class="<?php echo $status_filter === "cancelled"
                 ? "current"
                 : ""; ?>">
                <?php _e("Cancelled", "custom-invoice-system"); ?> 
                <span class="count">(<?php echo $counts["cancelled"]; ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url(
              add_query_arg("status", "all", $current_url)
            ); ?>" 
               class="<?php echo $status_filter === "all" ? "current" : ""; ?>">
                <?php _e("All", "custom-invoice-system"); ?> 
                <span class="count">(<?php echo $counts["all"]; ?>)</span>
            </a>
        </li>
    </ul>
    
    <br class="clear">
    
    <!-- Create New Invoice (Collapsible) -->
    <div class="card">
        <h2 class="title" style="cursor: pointer;" onclick="toggleSection('create-invoice-section')">
            <?php _e("Create New Invoice", "custom-invoice-system"); ?> 
            <span class="dashicons dashicons-arrow-down" style="float: right;"></span>
        </h2>
        <div id="create-invoice-section" style="display: none;">
            <form method="post" action="">
                <?php wp_nonce_field("create_invoice_nonce"); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="user_id"><?php _e(
                          "Select User",
                          "custom-invoice-system"
                        ); ?></label></th>
                        <td>
                            <select name="user_id" id="user_id" required style="width: 300px;">
                                <option value=""><?php _e(
                                  "Select a user...",
                                  "custom-invoice-system"
                                ); ?></option>
                                <?php
                                $user_count = count_users();
                                $total_users = $user_count["total_users"];
                                $users = get_users([
                                  "fields" => [
                                    "ID",
                                    "display_name",
                                    "user_email",
                                  ],
                                  "number" => 200,
                                  "orderby" => "display_name",
                                  "order" => "ASC",
                                ]);
                                foreach ($users as $user): ?>
                                    <option value="<?php echo esc_attr(
                                      $user->ID
                                    ); ?>">
                                        <?php echo esc_html(
                                          $user->display_name .
                                            " (" .
                                            $user->user_email .
                                            ")"
                                        ); ?>
                                    </option>
                                <?php endforeach;
                                ?>
                                <?php if ($total_users > 200): ?>
                                    <option value="" disabled>
                                        <?php printf(
                                          __(
                                            "— Showing first 200 of %d users —",
                                            "custom-invoice-system"
                                          ),
                                          $total_users
                                        ); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="amount"><?php _e(
                          'Amount ($)',
                          "custom-invoice-system"
                        ); ?></label></th>
                        <td>
                            <input type="number" name="amount" id="amount" step="0.01" min="0.01" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="description"><?php _e(
                          "Invoice Description",
                          "custom-invoice-system"
                        ); ?></label></th>
                        <td>
                            <textarea name="description" id="description" rows="3" style="width: 100%;"></textarea>
                            <p class="description"><?php _e(
                              "Enter details about this invoice",
                              "custom-invoice-system"
                            ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="due_date"><?php _e(
                          "Due Date",
                          "custom-invoice-system"
                        ); ?></label></th>
                        <td>
                            <input type="date" name="due_date" id="due_date" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="frequency"><?php _e(
                          "Frequency",
                          "custom-invoice-system"
                        ); ?></label></th>
                        <td>
                            <select name="frequency" id="create-invoice-frequency">
                                <option value="one-time"><?php _e(
                                  "One-Time Payment",
                                  "custom-invoice-system"
                                ); ?></option>
                                <option value="recurring"><?php _e(
                                  "Recurring",
                                  "custom-invoice-system"
                                ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr class="create-recurring-settings" style="display: none;">
                        <th><label for="recurring_interval"><?php _e(
                          "Recurring Settings",
                          "custom-invoice-system"
                        ); ?></label></th>
                        <td>
                            <input type="number" name="recurring_interval" id="recurring_interval" min="0" value="0" style="width: 60px;">
                            <select name="recurring_unit" id="recurring_unit">
                                <option value=""><?php _e(
                                  "Select Period",
                                  "custom-invoice-system"
                                ); ?></option>
                                <option value="day"><?php _e(
                                  "Day(s)",
                                  "custom-invoice-system"
                                ); ?></option>
                                <option value="week"><?php _e(
                                  "Week(s)",
                                  "custom-invoice-system"
                                ); ?></option>
                                <option value="month"><?php _e(
                                  "Month(s)",
                                  "custom-invoice-system"
                                ); ?></option>
                                <option value="year"><?php _e(
                                  "Year(s)",
                                  "custom-invoice-system"
                                ); ?></option>
                            </select>
                            <p class="description"><?php _e(
                              "How often should this invoice recur?",
                              "custom-invoice-system"
                            ); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="create_invoice" class="button button-primary" value="<?php _e(
                      "Create Invoice",
                      "custom-invoice-system"
                    ); ?>">
                </p>
            </form>
        </div>
    </div>

    <!-- Status Legend -->
    <div class="card">
        <div class="status-legend">
            <span class="legend-item has-tooltip" data-tooltip="Invoice has been created and is awaiting payment.">
                <span class="legend-dot" style="background-color: #996800;"></span>
                <?php _e("Pending", "custom-invoice-system"); ?>
            </span>
            <span class="legend-item has-tooltip" data-tooltip="Payment has been initiated and is being verified (e.g. ACH micro-deposits).">
                <span class="legend-dot" style="background-color: #0073aa;"></span>
                <?php _e("Processing", "custom-invoice-system"); ?>
            </span>
            <span class="legend-item has-tooltip" data-tooltip="Payment has been received and confirmed.">
                <span class="legend-dot" style="background-color: #46b450;"></span>
                <?php _e("Paid", "custom-invoice-system"); ?>
            </span>
            <span class="legend-item has-tooltip" data-tooltip="Invoice has been cancelled and no payment is expected.">
                <span class="legend-dot" style="background-color: #dc3232;"></span>
                <?php _e("Cancelled", "custom-invoice-system"); ?>
            </span>
        </div>
    </div>

    <!-- Existing Invoices -->
    <div class="card">
        <h2><?php _e("Invoice List", "custom-invoice-system"); ?></h2>
        <?php
        // Build query based on filters
        $where_clauses = [];
        $join_clause = "";

        // Status filter
        if ($status_filter === "active") {
          $where_clauses[] = "i.status IN ('pending', 'processing')";
        } elseif ($status_filter !== "all") {
          $where_clauses[] = $wpdb->prepare("i.status = %s", $status_filter);
        }

        // Search filter
        if (!empty($search_query)) {
          $join_clause = "JOIN {$wpdb->users} u ON i.user_id = u.ID";
          $search_like = "%" . $wpdb->esc_like($search_query) . "%";
          $where_clauses[] = $wpdb->prepare(
            "(u.display_name LIKE %s OR u.user_email LIKE %s OR i.invoice_number LIKE %s OR i.description LIKE %s)",
            $search_like,
            $search_like,
            $search_like,
            $search_like
          );
        }

        $where_sql = !empty($where_clauses)
          ? "WHERE " . implode(" AND ", $where_clauses)
          : "";

        // Get total count for pagination
        $total_query =
          "
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}custom_invoices i 
            " .
          ($join_clause ?: "JOIN {$wpdb->users} u ON i.user_id = u.ID") .
          "
            {$where_sql}
        ";

        $total_invoices = $wpdb->get_var($total_query);
        $total_pages = ceil($total_invoices / $per_page);

        // Get invoices with pagination
        $query = "
            SELECT i.*, u.display_name,
                p.payment_date, p.payment_method
            FROM {$wpdb->prefix}custom_invoices i 
            JOIN {$wpdb->users} u ON i.user_id = u.ID
            LEFT JOIN {$wpdb->prefix}custom_invoice_payments p 
                ON p.invoice_id = i.id AND p.status = 'completed'
            {$where_sql}
            ORDER BY i.due_date ASC
            LIMIT {$per_page} OFFSET {$offset}
        ";

        $invoices = $wpdb->get_results($query);

        if ($search_query && empty($invoices)): ?>
            <p><?php printf(
              __('No invoices found for "%s".', "custom-invoice-system"),
              esc_html($search_query)
            ); ?></p>
        <?php

          // Determine frequency display
          // Determine frequency display
          elseif (empty($invoices)): ?>
            <p><?php _e("No invoices found.", "custom-invoice-system"); ?></p>
        <?php else: ?>
            <!-- Pagination Info -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <span class="displaying-num">
                        <?php printf(
                          _n(
                            "%s item",
                            "%s items",
                            $total_invoices,
                            "custom-invoice-system"
                          ),
                          number_format_i18n($total_invoices)
                        ); ?>
                    </span>
                </div>
                <?php if ($total_pages > 1): ?>
                <div class="tablenav-pages">
                    <?php
                    $pagination_args = [
                      "base" => add_query_arg("paged", "%#%"),
                      "format" => "",
                      "prev_text" => __("&laquo;"),
                      "next_text" => __("&raquo;"),
                      "total" => $total_pages,
                      "current" => $current_page,
                      "type" => "plain",
                      "add_args" => [
                        "status" => $status_filter,
                        "search" => $search_query,
                      ],
                    ];
                    echo paginate_links($pagination_args);
                    ?>
                </div>
                <?php endif; ?>
            </div>

            <table class="wp-list-table widefat fixed striped invoices-table sortable-table" id="invoices-table">
                <thead>
                    <tr>
                        <th class="sortable" data-column="invoice_number" data-type="numeric">
                            <?php _e("Invoice #", "custom-invoice-system"); ?> 
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-column="display_name" data-type="string">
                            <?php _e("User", "custom-invoice-system"); ?> 
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-column="amount" data-type="numeric">
                            <?php _e("Amount", "custom-invoice-system"); ?> 
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-column="due_date" data-type="date">
                            <?php _e("Due Date", "custom-invoice-system"); ?> 
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-column="status" data-type="string">
                            <?php _e("Status", "custom-invoice-system"); ?> 
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-column="created_at" data-type="date">
                            <?php _e("Created", "custom-invoice-system"); ?> 
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-column="frequency" data-type="string">
                            <?php _e("Frequency", "custom-invoice-system"); ?> 
                            <span class="sort-indicator"></span>
                        </th>
                        <th><?php _e(
                          "Description",
                          "custom-invoice-system"
                        ); ?></th>
                        <th><?php _e(
                          "Actions",
                          "custom-invoice-system"
                        ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice):

                      $row_class = "";
                      if ($invoice->status === "paid") {
                        $row_class = "invoice-paid";
                      } elseif (
                        $invoice->due_date < date("Y-m-d") &&
                        $invoice->status === "pending"
                      ) {
                        $row_class = "invoice-overdue";
                      }

                      $frequency_display = "";
                      if (
                        $invoice->recurring_interval > 0 &&
                        $invoice->recurring_unit
                      ) {
                        $frequency_display = sprintf(
                          __("Every %d %s", "custom-invoice-system"),
                          $invoice->recurring_interval,
                          $invoice->recurring_unit .
                            ($invoice->recurring_interval > 1 ? "s" : "")
                        );
                      } else {
                        $frequency_display = __(
                          "One-Time",
                          "custom-invoice-system"
                        );
                      }
                      ?>
                        <tr class="<?php echo esc_attr($row_class); ?>">
                            <td data-sort="<?php echo esc_attr(
                              $invoice->invoice_number
                            ); ?>">
                                <?php echo esc_html(
                                  $invoice->invoice_number
                                ); ?>
                            </td>
                            <td data-sort="<?php echo esc_attr(
                              $invoice->display_name
                            ); ?>">
                                <?php echo esc_html($invoice->display_name); ?>
                            </td>
                            <td data-sort="<?php echo esc_attr(
                              $invoice->amount
                            ); ?>">
                                $<?php echo number_format(
                                  $invoice->amount,
                                  2
                                ); ?>
                            </td>
                            <td data-sort="<?php echo esc_attr(
                              $invoice->due_date
                            ); ?>">
                                <?php
                                echo date(
                                  "m/d/Y",
                                  strtotime($invoice->due_date)
                                );
                                if (
                                  $invoice->due_date < date("Y-m-d") &&
                                  $invoice->status === "pending"
                                ) {
                                  echo ' <span class="overdue-indicator">(' .
                                    __("Overdue", "custom-invoice-system") .
                                    ")</span>";
                                }
                                ?>
                            </td>
                            <td data-sort="<?php echo esc_attr(
                              $invoice->status
                            ); ?>">
                                <?php
                                $tooltip = "";
                                if (
                                  $invoice->status === "paid" &&
                                  !empty($invoice->payment_date)
                                ) {
                                  $paid_date = date(
                                    'M j, Y \a\t g:i A',
                                    strtotime($invoice->payment_date)
                                  );
                                  $method = !empty($invoice->payment_method)
                                    ? ucfirst($invoice->payment_method)
                                    : "";
                                  $tooltip = "Paid " . $paid_date;
                                  if ($method) {
                                    $tooltip .= " via " . $method;
                                  }
                                } elseif (
                                  $invoice->status === "pending" &&
                                  $invoice->due_date < date("Y-m-d")
                                ) {
                                  $days_overdue = floor(
                                    (time() - strtotime($invoice->due_date)) /
                                      86400
                                  );
                                  $tooltip =
                                    $days_overdue .
                                    " day" .
                                    ($days_overdue !== 1 ? "s" : "") .
                                    " overdue";
                                }
                                ?>
                                <span class="status-<?php
                                echo esc_attr($invoice->status);
                                echo $tooltip ? " has-tooltip" : "";
                                ?>"
                                    <?php if ($tooltip): ?>
                                        data-tooltip="<?php echo esc_attr(
                                          $tooltip
                                        ); ?>"
                                    <?php endif; ?>>
                                    <?php echo esc_html(
                                      ucfirst($invoice->status)
                                    ); ?>
                                </span>
                            </td>
                            <td data-sort="<?php echo esc_attr(
                              $invoice->created_at
                            ); ?>">
                                <?php echo date(
                                  "m/d/Y",
                                  strtotime($invoice->created_at)
                                ); ?>
                            </td>
                            <td data-sort="<?php echo esc_attr(
                              $frequency_display
                            ); ?>">
                                <?php echo esc_html($frequency_display); ?>
                            </td>
                            <td>
                                <?php
                                $desc = esc_html($invoice->description);
                                echo strlen($desc) > 50
                                  ? substr($desc, 0, 50) . "..."
                                  : $desc;
                                ?>
                            </td>
                            <td>
                                <button class="button edit-invoice" 
                                        data-id="<?php echo esc_attr(
                                          $invoice->id
                                        ); ?>" 
                                        data-nonce="<?php echo wp_create_nonce(
                                          "invoice_nonce"
                                        ); ?>">
                                    <?php _e(
                                      "Edit",
                                      "custom-invoice-system"
                                    ); ?>
                                </button>
                                <button class="button button-link-delete delete-invoice" 
                                        data-id="<?php echo esc_attr(
                                          $invoice->id
                                        ); ?>" 
                                        data-nonce="<?php echo wp_create_nonce(
                                          "invoice_nonce"
                                        ); ?>">
                                    <?php _e(
                                      "Delete",
                                      "custom-invoice-system"
                                    ); ?>
                                </button>
                            </td>
                        </tr>
                    <?php
                    endforeach; ?>
                </tbody>
            </table>

            <!-- Bottom Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php echo paginate_links($pagination_args); ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif;
        ?>
    </div>
</div>

<style>
/* Status colors */
.status-pending { color: #996800; font-weight: bold; }
.status-processing { color: #0073aa; font-weight: bold; }
.status-paid { color: #46b450; font-weight: bold; }
.status-cancelled { color: #dc3232; font-weight: bold; }

/* Row highlighting */
.invoice-paid { opacity: 0.7; }
.invoice-overdue { background-color: #fff5f5 !important; }
.overdue-indicator { color: #dc3232; font-weight: bold; }

/* Collapsible sections */
.card .title { margin-bottom: 0; padding: 10px 0; }
.card .title:hover { background-color: #f5f5f5; }
.dashicons-arrow-down { transition: transform 0.3s; }
.section-open .dashicons-arrow-down { transform: rotate(180deg); }

/* Search box styling */
.invoice-search-box { margin-top: 10px; }
#invoice-search { padding: 5px 10px; }

/* Tab styling */
.subsubsub { margin-bottom: 20px; }
.subsubsub .current { font-weight: bold; color: #000; }

/* Sortable table styles */
.sortable-table th.sortable {
    cursor: pointer;
    user-select: none;
    position: relative;
    padding-right: 20px;
}

.sortable-table th.sortable:hover {
    background-color: #f0f0f1;
}

.sort-indicator {
    position: absolute;
    right: 6px;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
    font-size: 12px;
}

.sortable-table th.sort-asc .sort-indicator::before {
    content: '↑';
    color: #2271b1;
}

.sortable-table th.sort-desc .sort-indicator::before {
    content: '↓';
    color: #2271b1;
}

.sortable-table th.sortable:not(.sort-asc):not(.sort-desc) .sort-indicator::before {
    content: '↕';
    color: #999;
}

/* Pagination styling */
.tablenav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 10px 0;
    padding: 0;
}

.tablenav .displaying-num {
    color: #646970;
    font-style: italic;
}

.tablenav-pages {
    display: flex;
    align-items: center;
}

.tablenav-pages a,
.tablenav-pages span {
    display: inline-block;
    padding: 3px 5px;
    margin: 0 1px;
    text-decoration: none;
    border: 1px solid transparent;
}

.tablenav-pages a:hover {
    border-color: #8c8f94;
    background-color: #f6f7f7;
}

.tablenav-pages .current {
    background-color: #2271b1;
    color: #fff;
    border-color: #2271b1;
}
</style>

<script>
function toggleSection(sectionId) {
    const section = document.getElementById(sectionId);
    const card = section.closest('.card');
    
    if (section.style.display === 'none') {
        section.style.display = 'block';
        card.classList.add('section-open');
    } else {
        section.style.display = 'none';
        card.classList.remove('section-open');
    }
}

// Auto-open create invoice section if there are errors
<?php if (settings_errors("invoice_creation")): ?>
document.addEventListener('DOMContentLoaded', function() {
    toggleSection('create-invoice-section');
});
<?php endif; ?>
</script>