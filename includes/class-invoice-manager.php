<?php
declare(strict_types=1);

/**
 * Invoice Manager Class
 *
 * Handles all invoice-related functionality including creation, payment processing,
 * and user interface rendering.
 *
 * @package CIS
 * @since 2.0
 */
class CIS_Invoice_Manager
{
  /**
   * Constructor
   */
  public function __construct()
  {
    // Admin hooks
    add_action("admin_menu", [$this, "add_menu_page"]);
    add_action("admin_init", [$this, "handle_invoice_creation"]);

    // AJAX hooks
    add_action("wp_ajax_process_invoice_payment", [$this, "process_payment"]);
    add_action("wp_ajax_confirm_invoice_payment", [$this, "confirm_payment"]);
    add_action("wp_ajax_get_invoice", [$this, "get_invoice"]);
    add_action("wp_ajax_delete_invoice", [$this, "delete_invoice"]);
    add_action("wp_ajax_update_invoice", [$this, "update_invoice"]);

    // Frontend hooks
    add_shortcode("custom_profile", [$this, "render_profile"]);
    add_action("init", [$this, "handle_checkout_return"]);

    // REST API hooks
    add_action("rest_api_init", [$this, "register_webhook_endpoint"]);

    // Initialize database verification
    add_action("init", [$this, "verify_database_tables"]);
  }

  /**
   * Verify database tables exist on init
   *
   * @return void
   */
  public function verify_database_tables(): void
  {
    global $wpdb;

    $invoices_table = $wpdb->prefix . "custom_invoices";
    $invoices_exists =
      $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $invoices_table)) ===
      $invoices_table;

    if (!$invoices_exists) {
      cis_log_error("CIS: Critical - custom_invoices table does not exist");

      // Try to recreate the tables
      if (class_exists("CIS_Database")) {
        CIS_Database::upgrade_database();
      }

      // Add admin notice
      add_action("admin_notices", function () {
        ?>
                <div class="notice notice-error">
                    <p>
                        <strong><?php esc_html_e(
                          "Custom Invoice System Error:",
                          "custom-invoice-system"
                        ); ?></strong>
                        <?php esc_html_e(
                          "Required database tables are missing. Invoice creation will fail until this is resolved.",
                          "custom-invoice-system"
                        ); ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url(
                          admin_url("admin.php?page=manage-invoices")
                        ); ?>" class="button">
                            <?php esc_html_e(
                              "Check Plugin Status",
                              "custom-invoice-system"
                            ); ?>
                        </a>
                    </p>
                </div>
                <?php
      });
    }
  }

  /**
   * Add admin menu page
   *
   * @return void
   */
  public function add_menu_page(): void
  {
    add_menu_page(
      __("Manage Invoices", "custom-invoice-system"),
      __("Invoices", "custom-invoice-system"),
      "manage_options",
      "manage-invoices",
      [$this, "render_invoice_manager_page"],
      "dashicons-money-alt",
      30
    );
  }

  /**
   * Handle the return from Stripe Checkout
   *
   * @return void
   */
  public function handle_checkout_return(): void
  {
    // Only process on pages with our payment_status parameter
    if (!isset($_GET["payment_status"])) {
      return;
    }

    $payment_status = sanitize_text_field($_GET["payment_status"]);

    // Handle successful payment
    if ($payment_status === "success" && isset($_GET["session_id"])) {
      $this->handle_successful_checkout_return();
    } elseif ($payment_status === "canceled") {
      $this->handle_canceled_checkout_return();
    }
  }

  /**
   * Handle successful checkout return
   *
   * @return void
   */
  private function handle_successful_checkout_return(): void
  {
    try {
      $session_id = sanitize_text_field($_GET["session_id"]);

      // Get Stripe API key
      $stripe_secret = get_option("stripe_secret_key");
      if (empty($stripe_secret)) {
        throw new Exception("Stripe not configured");
      }

      // Initialize Stripe
      \Stripe\Stripe::setApiKey($stripe_secret);

      // Retrieve the checkout session
      $session = \Stripe\Checkout\Session::retrieve([
        "id" => $session_id,
        "expand" => ["payment_intent"],
      ]);

      // Get the payment intent
      $payment_intent = $session->payment_intent;

      // Check if payment was successful
      if ($payment_intent->status === "succeeded") {
        // Get the invoice ID from metadata
        $invoice_id = intval($payment_intent->metadata->invoice_id ?? 0);

        if ($invoice_id > 0) {
          // Record the successful payment
          $this->record_successful_payment(
            $invoice_id,
            $payment_intent->id,
            "card"
          );

          // Add a success message
          $this->show_payment_message(
            "success",
            __(
              "Payment successful! Your invoice has been marked as paid.",
              "custom-invoice-system"
            )
          );
        }
      }
    } catch (Exception $e) {
      cis_log_error("Error processing checkout return", [
        "error" => $e->getMessage(),
        "session_id" => $session_id ?? "unknown",
      ]);

      $this->show_payment_message(
        "error",
        __(
          "There was an error processing your payment. Please contact support.",
          "custom-invoice-system"
        )
      );
    }
  }

  /**
   * Handle canceled checkout return
   *
   * @return void
   */
  private function handle_canceled_checkout_return(): void
  {
    $this->show_payment_message(
      "canceled",
      __(
        "Your payment was canceled. If you need assistance, please contact support.",
        "custom-invoice-system"
      )
    );
  }

  /**
   * Display a payment status message via wp_footer
   *
   * @param string $type Message type: success, error, or canceled
   * @param string $message The message text to display
   * @return void
   */
  private function show_payment_message(string $type, string $message): void
  {
    add_action("wp_footer", function () use ($type, $message) {
      // Only output shared CSS once
      static $css_output = false;
      if (!$css_output) {
        $css_output = true; ?>
                <style>
                    .cis-payment-message {
                        position: fixed;
                        top: 20px;
                        right: 20px;
                        padding: 15px 20px;
                        border-radius: 4px;
                        z-index: 9999;
                        animation: cisSlideIn 0.3s ease-out;
                    }
                    .cis-payment-success {
                        background-color: #d4edda;
                        color: #155724;
                        border: 1px solid #c3e6cb;
                    }
                    .cis-payment-error {
                        background-color: #f8d7da;
                        color: #721c24;
                        border: 1px solid #f5c6cb;
                    }
                    .cis-payment-canceled {
                        background-color: #fff3cd;
                        color: #856404;
                        border: 1px solid #ffeaa7;
                    }
                    @keyframes cisSlideIn {
                        from { transform: translateX(100%); opacity: 0; }
                        to { transform: translateX(0); opacity: 1; }
                    }
                </style>
                <script>
                    setTimeout(function() {
                        var msg = document.querySelector('.cis-payment-message');
                        if (msg) msg.style.display = 'none';
                    }, 5000);
                </script>
                <?php
      }
      ?>
            <div class="cis-payment-message cis-payment-<?php echo esc_attr(
              $type
            ); ?>">
                <?php echo esc_html($message); ?>
            </div>
            <?php
    });
  }

  /**
   * Render invoice manager admin page
   *
   * @return void
   */
  public function render_invoice_manager_page(): void
  {
    if (!current_user_can("manage_options")) {
      wp_die(
        esc_html__(
          "You do not have sufficient permissions to access this page.",
          "custom-invoice-system"
        )
      );
    }

    // Display any settings errors
    settings_errors("invoice_creation");

    // Add database status information for debugging
    if (defined("WP_DEBUG") && WP_DEBUG) {
      $this->display_database_status();
    }

    include CIS_PLUGIN_PATH . "templates/admin-page.php";
  }

  /**
   * Display database status for debugging
   *
   * @return void
   */
  private function display_database_status(): void
  {
    if (class_exists("CIS_Database")) {
      $status = CIS_Database::get_table_status(); ?>
            <div class="notice notice-info">
                <h4><?php esc_html_e(
                  "Database Status (Debug Mode)",
                  "custom-invoice-system"
                ); ?></h4>
                <p>
                    <strong><?php esc_html_e(
                      "Invoices Table:",
                      "custom-invoice-system"
                    ); ?></strong>
                    <?php echo $status["invoices_table_exists"]
                      ? '<span style="color: green;">✓ Exists</span>'
                      : '<span style="color: red;">✗ Missing</span>'; ?>
                    <?php if (isset($status["invoices_count"])): ?>
                        (<?php printf(
                          __("%d records", "custom-invoice-system"),
                          $status["invoices_count"]
                        ); ?>)
                    <?php endif; ?>
                </p>
                <p>
                    <strong><?php esc_html_e(
                      "Payments Table:",
                      "custom-invoice-system"
                    ); ?></strong>
                    <?php echo $status["payments_table_exists"]
                      ? '<span style="color: green;">✓ Exists</span>'
                      : '<span style="color: red;">✗ Missing</span>'; ?>
                    <?php if (isset($status["payments_count"])): ?>
                        (<?php printf(
                          __("%d records", "custom-invoice-system"),
                          $status["payments_count"]
                        ); ?>)
                    <?php endif; ?>
                </p>
            </div>
            <?php
    }
  }

  /**
   * Handle invoice creation with improved error handling and logging
   *
   * @return void
   */
  public function handle_invoice_creation(): void
  {
    if (!isset($_POST["create_invoice"])) {
      return;
    }

    cis_log_error("CIS: Invoice creation attempt started", [
      "user_id" => get_current_user_id(),
      "post_data" => array_keys($_POST),
    ]);

    if (!check_admin_referer("create_invoice_nonce")) {
      cis_log_error("CIS: Invoice creation failed - invalid nonce");
      add_settings_error(
        "invoice_creation",
        "error",
        __("Security check failed. Please try again.", "custom-invoice-system"),
        "error"
      );
      return;
    }

    if (!current_user_can("manage_options")) {
      cis_log_error("CIS: Invoice creation failed - insufficient permissions", [
        "user_id" => get_current_user_id(),
      ]);
      add_settings_error(
        "invoice_creation",
        "error",
        __("Insufficient permissions.", "custom-invoice-system"),
        "error"
      );
      return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . "custom_invoices";

    // Critical: Verify table exists before attempting insertion
    $table_exists =
      $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) ===
      $table_name;

    if (!$table_exists) {
      cis_log_error("CIS: Invoice creation failed - table does not exist", [
        "table_name" => $table_name,
      ]);

      // Attempt to create the table
      if (class_exists("CIS_Database")) {
        cis_log_error("CIS: Attempting to create missing table");
        CIS_Database::create_tables();

        // Re-check if table was created
        $table_exists =
          $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) ===
          $table_name;

        if (!$table_exists) {
          add_settings_error(
            "invoice_creation",
            "error",
            __(
              "Critical Error: Database table could not be created. Please contact support.",
              "custom-invoice-system"
            ),
            "error"
          );
          return;
        }
      } else {
        add_settings_error(
          "invoice_creation",
          "error",
          __(
            "Critical Error: Database tables missing and cannot be created.",
            "custom-invoice-system"
          ),
          "error"
        );
        return;
      }
    }

    try {
      // Validate required fields with detailed logging
      $user_id = intval($_POST["user_id"] ?? 0);
      cis_log_error("CIS: Processing invoice creation", [
        "user_id" => $user_id,
        "raw_amount" => $_POST["amount"] ?? "not_set",
        "due_date" => $_POST["due_date"] ?? "not_set",
      ]);

      if ($user_id <= 0) {
        throw new Exception(
          __("Please select a user.", "custom-invoice-system")
        );
      }

      // Verify user exists
      $user = get_user_by("ID", $user_id);
      if (!$user) {
        throw new Exception(
          __("Selected user does not exist.", "custom-invoice-system")
        );
      }

      $amount = $this->sanitize_amount($_POST["amount"] ?? 0);
      $due_date = $this->validate_date($_POST["due_date"] ?? "");

      cis_log_error("CIS: Validated invoice data", [
        "user_id" => $user_id,
        "amount" => $amount,
        "due_date" => $due_date,
      ]);

      // Get next invoice number with database lock to prevent duplicates
      $wpdb->query("START TRANSACTION");

      try {
        $last_invoice = $wpdb->get_var(
          "SELECT MAX(CAST(invoice_number AS UNSIGNED)) 
                     FROM $table_name 
                     WHERE invoice_number REGEXP '^[0-9]+$' 
                     FOR UPDATE"
        );

        $invoice_number = $last_invoice ? intval($last_invoice) + 1 : 1001;

        cis_log_error("CIS: Generated invoice number", [
          "last_invoice" => $last_invoice,
          "new_invoice_number" => $invoice_number,
        ]);

        // Prepare invoice data
        $frequency = sanitize_text_field($_POST["frequency"] ?? "one-time");
        $is_recurring = $frequency === "recurring";

        $data = [
          "user_id" => $user_id,
          "amount" => $amount,
          "description" => sanitize_textarea_field($_POST["description"] ?? ""),
          "due_date" => $due_date,
          "status" => "pending",
          "invoice_number" => strval($invoice_number),
          "created_at" => current_time("mysql"),
          "recurring_unit" => null,
          "recurring_interval" => 0,
          "next_recurring_date" => null,
        ];

        // Add recurring data if applicable
        if ($is_recurring) {
          $recurring_unit = sanitize_text_field($_POST["recurring_unit"] ?? "");
          $recurring_interval = intval($_POST["recurring_interval"] ?? 0);

          if (
            !empty($recurring_unit) &&
            $recurring_interval > 0 &&
            in_array($recurring_unit, ["day", "week", "month", "year"])
          ) {
            $data["recurring_unit"] = $recurring_unit;
            $data["recurring_interval"] = $recurring_interval;

            // Calculate next recurring date
            $data["next_recurring_date"] = $this->calculate_next_date(
              $data["due_date"],
              $recurring_unit,
              $recurring_interval
            );

            cis_log_error("CIS: Added recurring settings", [
              "unit" => $recurring_unit,
              "interval" => $recurring_interval,
              "next_date" => $data["next_recurring_date"],
            ]);
          }
        }

        $format = [
          "%d", // user_id
          "%f", // amount
          "%s", // description
          "%s", // due_date
          "%s", // status
          "%s", // invoice_number
          "%s", // created_at
          "%s", // recurring_unit
          "%d", // recurring_interval
          "%s", // next_recurring_date
        ];

        cis_log_error("CIS: Attempting database insert", [
          "table_name" => $table_name,
          "data_keys" => array_keys($data),
        ]);

        $result = $wpdb->insert($table_name, $data, $format);

        if ($result === false) {
          throw new Exception(
            sprintf(
              __(
                "Failed to create invoice - Database error: %s",
                "custom-invoice-system"
              ),
              $wpdb->last_error
            )
          );
        }

        $new_invoice_id = $wpdb->insert_id;

        if (!$new_invoice_id) {
          throw new Exception(
            __(
              "Invoice created but ID not returned from database.",
              "custom-invoice-system"
            )
          );
        }

        // Commit the transaction
        $wpdb->query("COMMIT");

        cis_log_error("CIS: Invoice created successfully", [
          "invoice_id" => $new_invoice_id,
          "invoice_number" => $invoice_number,
          "user_id" => $user_id,
          "amount" => $amount,
        ]);

        // Trigger invoice created action
        do_action("cis_invoice_created", $new_invoice_id, $user_id);

        add_settings_error(
          "invoice_creation",
          "success",
          sprintf(
            __(
              "Invoice #%s created successfully for %s!",
              "custom-invoice-system"
            ),
            $invoice_number,
            $user->display_name
          ),
          "updated"
        );
      } catch (Exception $e) {
        // Rollback transaction on any error
        $wpdb->query("ROLLBACK");
        throw $e;
      }
    } catch (Exception $e) {
      cis_log_error("CIS: Invoice creation failed", [
        "error" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine(),
        "post_keys" => array_keys($_POST),
      ]);

      add_settings_error(
        "invoice_creation",
        "error",
        $e->getMessage(),
        "error"
      );
    }
  }

  /**
   * Process payment via AJAX
   *
   * @return void
   */
  public function process_payment(): void
  {
    if (!class_exists("\Stripe\Stripe")) {
      wp_send_json_error(
        __("Stripe library not loaded", "custom-invoice-system")
      );
      wp_die();
    }

    if (!is_user_logged_in()) {
      wp_send_json_error(__("Unauthorized access", "custom-invoice-system"));
      wp_die();
    }

    check_ajax_referer("stripe_checkout_payment");

    try {
      if (!isset($_POST["invoice_id"])) {
        throw new Exception(
          __("Required payment data missing", "custom-invoice-system")
        );
      }

      $invoice_id = intval($_POST["invoice_id"]);
      $payment_method = sanitize_text_field($_POST["payment_method"] ?? "card");
      $return_url = esc_url_raw($_POST["return_url"] ?? home_url());

      // Validate payment method
      if (!in_array($payment_method, ["card", "ach"])) {
        $payment_method = "card";
      }

      $stripe_secret = get_option("stripe_secret_key");
      if (empty($stripe_secret)) {
        throw new Exception(
          __("Stripe not configured", "custom-invoice-system")
        );
      }

      global $wpdb;
      $invoice = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT * FROM {$wpdb->prefix}custom_invoices WHERE id = %d",
          $invoice_id
        )
      );

      if (!$invoice) {
        throw new Exception(__("Invoice not found", "custom-invoice-system"));
      }

      $current_user = wp_get_current_user();
      if (
        $invoice->user_id != $current_user->ID &&
        !current_user_can("manage_options")
      ) {
        throw new Exception(
          __("Unauthorized invoice access", "custom-invoice-system")
        );
      }

      if ($invoice->status === "paid") {
        throw new Exception(
          __("Invoice already paid", "custom-invoice-system")
        );
      }

      $user = get_userdata($invoice->user_id);
      if (!$user) {
        throw new Exception(__("User not found", "custom-invoice-system"));
      }

      \Stripe\Stripe::setApiKey($stripe_secret);

      $this->create_checkout_session(
        $invoice,
        $user,
        $return_url,
        $payment_method
      );
    } catch (Exception $e) {
      cis_log_error("Payment processing error", [
        "error" => $e->getMessage(),
        "invoice_id" => $invoice_id ?? "unknown",
      ]);
      wp_send_json_error($e->getMessage());
    }

    wp_die();
  }

  /**
   * Create a Stripe Checkout session for invoice payment
   *
   * @param object $invoice Invoice object
   * @param WP_User $user User object
   * @param string $return_url URL to return to after checkout
   * @param string $payment_method Payment method (card or ach)
   * @return void
   */
  private function create_checkout_session(
    object $invoice,
    WP_User $user,
    string $return_url,
    string $payment_method = "card"
  ): void {
    try {
      // Calculate fee based on payment method
      $fee = 0;

      if ($payment_method === "card") {
        $fee_percentage = floatval(
          get_option("convenience_fee_percentage", "0")
        );
        if ($fee_percentage > 0) {
          $fee = round($invoice->amount * ($fee_percentage / 100), 2);
        }
      } elseif ($payment_method === "ach") {
        $fee = floatval(get_option("ach_fee_amount", "0"));
      }

      $total_amount = $invoice->amount + $fee;

      // Validate amount
      if ($total_amount < 0.5) {
        throw new Exception(
          __('Total amount must be at least $0.50', "custom-invoice-system")
        );
      }

      // Check if ACH payments are enabled
      $enable_ach = get_option("enable_ach_payments") === "1";

      // Set payment methods based on selected method
      if ($payment_method === "card") {
        $payment_method_types = ["card"];
      } elseif ($payment_method === "ach" && $enable_ach) {
        $payment_method_types = ["us_bank_account"];
      } else {
        // Default to card if invalid payment method is provided
        $payment_method_types = ["card"];
      }

      // Create line item description
      $line_item_description = !empty($invoice->description)
        ? $invoice->description
        : sprintf(__("Payment for services rendered", "custom-invoice-system"));

      // Create checkout session
      $session_params = [
        "payment_method_types" => $payment_method_types,
        "customer_email" => $user->user_email,
        "line_items" => [
          [
            "price_data" => [
              "unit_amount" => (int) ($total_amount * 100),
              "currency" => "usd",
              "product_data" => [
                "name" => sprintf(
                  __("Invoice #%s", "custom-invoice-system"),
                  $invoice->invoice_number
                ),
                "description" => $line_item_description,
              ],
            ],
            "quantity" => 1,
          ],
        ],
        "mode" => "payment",
        "success_url" => add_query_arg(
          [
            "payment_status" => "success",
            "session_id" => "{CHECKOUT_SESSION_ID}",
          ],
          $return_url
        ),
        "cancel_url" => add_query_arg(
          ["payment_status" => "canceled"],
          $return_url
        ),
        "metadata" => [
          "invoice_id" => strval($invoice->id),
          "user_id" => strval($user->ID),
          "payment_method" => $payment_method,
          "convenience_fee" => strval($fee),
          "invoice_amount" => strval($invoice->amount),
        ],
      ];

      // Add appearance customization if set
      $business_name = get_option("checkout_name");
      $logo_url = get_option("checkout_logo_url");
      $brand_color = get_option("checkout_color");

      if (!empty($business_name) || !empty($logo_url) || !empty($brand_color)) {
        $checkout_options = [];

        if (!empty($business_name)) {
          $checkout_options["name"] = $business_name;
        }

        if (!empty($logo_url)) {
          $checkout_options["logo"] = $logo_url;
        }

        $session_params["custom_text"] = [
          "submit" => [
            "message" => sprintf(
              __("Pay %s for Invoice #%s", "custom-invoice-system"),
              '$' . number_format($total_amount, 2),
              $invoice->invoice_number
            ),
          ],
        ];
      }

      // Log session creation for debugging
      cis_log_error("Creating Stripe Checkout session", [
        "invoice_id" => $invoice->id,
        "payment_method" => $payment_method,
        "total_amount" => $total_amount,
      ]);

      // Create the session
      $session = \Stripe\Checkout\Session::create($session_params);

      // Mark invoice as processing
      global $wpdb;
      $wpdb->update(
        $wpdb->prefix . "custom_invoices",
        ["status" => "processing"],
        ["id" => $invoice->id],
        ["%s"],
        ["%d"]
      );

      wp_send_json_success([
        "checkout_url" => $session->url,
      ]);
    } catch (\Stripe\Exception\ApiErrorException $e) {
      cis_log_error("Stripe API error", [
        "error" => $e->getMessage(),
        "error_code" => $e->getStripeCode(),
        "http_status" => $e->getHttpStatus(),
      ]);
      wp_send_json_error(
        sprintf(
          __("Stripe API error: %s", "custom-invoice-system"),
          $e->getMessage()
        )
      );
    } catch (Exception $e) {
      cis_log_error("Checkout session creation error", [
        "error" => $e->getMessage(),
      ]);
      wp_send_json_error($e->getMessage());
    }

    wp_die();
  }

  /**
   * Confirm payment via AJAX
   *
   * @return void
   */
  public function confirm_payment(): void
  {
    check_ajax_referer("stripe_checkout_payment", "_ajax_nonce");

    if (!isset($_POST["payment_intent_id"]) || !isset($_POST["invoice_id"])) {
      wp_send_json_error(__("Missing required data", "custom-invoice-system"));
      return;
    }

    try {
      $payment_intent_id = sanitize_text_field($_POST["payment_intent_id"]);
      $invoice_id = intval($_POST["invoice_id"]);

      \Stripe\Stripe::setApiKey(get_option("stripe_secret_key"));
      $intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);

      if ($intent->status === "succeeded") {
        $this->record_successful_payment(
          $invoice_id,
          $payment_intent_id,
          "card"
        );
        wp_send_json_success([
          "message" => __(
            "Payment confirmed successfully",
            "custom-invoice-system"
          ),
        ]);
      } else {
        wp_send_json_error(
          sprintf(
            __(
              "Payment has not succeeded. Status: %s",
              "custom-invoice-system"
            ),
            $intent->status
          )
        );
      }
    } catch (Exception $e) {
      cis_log_error("Payment confirmation error", [
        "error" => $e->getMessage(),
      ]);
      wp_send_json_error(
        sprintf(
          __("Error confirming payment: %s", "custom-invoice-system"),
          $e->getMessage()
        )
      );
    }

    wp_die();
  }

  /**
   * Register webhook endpoint for Stripe events
   *
   * @return void
   */
  public function register_webhook_endpoint(): void
  {
    register_rest_route("cis/v1", "/stripe-webhook", [
      "methods" => "POST",
      "callback" => [$this, "handle_stripe_webhook"],
      "permission_callback" => "__return_true", // Webhooks must be public
      "show_in_index" => false, // Hide from REST API index
    ]);
  }

  /**
   * Handle webhook events from Stripe with signature verification
   *
   * @param WP_REST_Request $request The request object
   * @return WP_REST_Response
   */
  public function handle_stripe_webhook(
    WP_REST_Request $request
  ): WP_REST_Response {
    $payload = $request->get_body();
    $sig_header = $_SERVER["HTTP_STRIPE_SIGNATURE"] ?? "";

    try {
      \Stripe\Stripe::setApiKey(get_option("stripe_secret_key"));
      $webhook_secret = get_option("stripe_webhook_secret");

      // Verify webhook signature
      if (!empty($webhook_secret)) {
        if (empty($sig_header)) {
          throw new Exception("No signature header provided");
        }

        $event = \Stripe\Webhook::constructEvent(
          $payload,
          $sig_header,
          $webhook_secret
        );
      } else {
        // Log warning if webhook secret is not configured
        cis_log_error(
          "WARNING: Stripe webhook secret not configured. This is a security risk!"
        );

        // In production, reject webhooks without signature verification
        if (!defined("WP_DEBUG") || !WP_DEBUG) {
          throw new Exception("Webhook signature verification is required");
        }

        // Parse payload for development only
        $event = json_decode($payload);
        if (json_last_error() !== JSON_ERROR_NONE) {
          throw new Exception("Invalid JSON payload");
        }
      }

      // Prevent replay attacks
      $event_id = $event->id ?? "";
      if (!empty($event_id)) {
        $processed = get_transient("cis_webhook_" . $event_id);
        if ($processed) {
          return new WP_REST_Response(["status" => "already_processed"], 200);
        }
        // Mark as processed (store for 24 hours)
        set_transient("cis_webhook_" . $event_id, true, DAY_IN_SECONDS);
      }

      // Handle the event based on its type
      switch ($event->type) {
        case "checkout.session.completed":
          $this->handle_checkout_completion($event->data->object);
          break;

        case "payment_intent.succeeded":
          $this->handle_payment_success($event->data->object);
          break;

        case "payment_intent.payment_failed":
          $this->handle_payment_failure($event->data->object);
          break;

        default:
          // Log unhandled events for debugging
          cis_log_error("Unhandled webhook event type: " . $event->type);
      }

      return new WP_REST_Response(["status" => "success"], 200);
    } catch (\UnexpectedValueException $e) {
      // Invalid signature
      cis_log_error("Webhook signature verification failed", [
        "error" => $e->getMessage(),
      ]);
      return new WP_REST_Response(["status" => "invalid_signature"], 400);
    } catch (Exception $e) {
      cis_log_error("Webhook error", [
        "error" => $e->getMessage(),
      ]);
      return new WP_REST_Response(
        ["status" => "error", "message" => $e->getMessage()],
        400
      );
    }
  }

  /**
   * Handle checkout completion event
   *
   * @param object $session Stripe checkout session object
   * @return void
   */
  private function handle_checkout_completion(object $session): void
  {
    if (isset($session->metadata->invoice_id)) {
      $invoice_id = intval($session->metadata->invoice_id);
      $payment_intent = $session->payment_intent;

      $this->record_successful_payment($invoice_id, $payment_intent, "card");
    }
  }

  /**
   * Handle successful payment event from webhook
   *
   * @param object $payment_intent Stripe payment intent object
   * @return void
   */
  private function handle_payment_success(object $payment_intent): void
  {
    if (isset($payment_intent->metadata->invoice_id)) {
      $invoice_id = intval($payment_intent->metadata->invoice_id);
      $payment_method = $payment_intent->metadata->payment_method ?? "card";
      $this->record_successful_payment(
        $invoice_id,
        $payment_intent->id,
        $payment_method
      );
    }
  }

  /**
   * Handle failed payment event from webhook
   *
   * @param object $payment_intent Stripe payment intent object
   * @return void
   */
  private function handle_payment_failure(object $payment_intent): void
  {
    if (isset($payment_intent->metadata->invoice_id)) {
      $invoice_id = intval($payment_intent->metadata->invoice_id);

      global $wpdb;

      // Mark the invoice as pending again
      $wpdb->update(
        $wpdb->prefix . "custom_invoices",
        ["status" => "pending"],
        ["id" => $invoice_id],
        ["%s"],
        ["%d"]
      );

      // Update any payment records to failed
      $wpdb->update(
        $wpdb->prefix . "custom_invoice_payments",
        ["status" => "failed"],
        ["invoice_id" => $invoice_id, "transaction_id" => $payment_intent->id],
        ["%s"],
        ["%d", "%s"]
      );

      cis_log_error("Payment failed for invoice", [
        "invoice_id" => $invoice_id,
        "payment_intent" => $payment_intent->id,
      ]);
    }
  }

  /**
   * Record a successful payment
   *
   * @param int $invoice_id Invoice ID
   * @param string $transaction_id Stripe transaction ID
   * @param string $default_payment_method Default payment method
   * @return void
   * @throws Exception If payment recording fails
   */
  private function record_successful_payment(
    int $invoice_id,
    string $transaction_id,
    string $default_payment_method = "card"
  ): void {
    global $wpdb;

    // Prevent concurrent processing of the same transaction
    $lock_key = "cis_payment_lock_" . md5($transaction_id);
    if (get_transient($lock_key)) {
      cis_log_error("Payment already being processed", [
        "transaction_id" => $transaction_id,
      ]);
      return;
    }
    set_transient($lock_key, true, 300); // 5-minute lock

    // Start transaction
    $wpdb->query("START TRANSACTION");

    try {
      // Get invoice details first
      $invoice = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT * FROM {$wpdb->prefix}custom_invoices WHERE id = %d",
          $invoice_id
        )
      );

      if (!$invoice) {
        throw new Exception("Invoice not found during payment recording");
      }

      // Check if payment already exists
      $existing_payment = $wpdb->get_var(
        $wpdb->prepare(
          "SELECT id FROM {$wpdb->prefix}custom_invoice_payments WHERE transaction_id = %s",
          $transaction_id
        )
      );

      if ($existing_payment) {
        // Update the status if payment already exists
        $wpdb->update(
          $wpdb->prefix . "custom_invoice_payments",
          ["status" => "completed"],
          ["transaction_id" => $transaction_id],
          ["%s"],
          ["%s"]
        );

        // Update invoice status
        $wpdb->update(
          $wpdb->prefix . "custom_invoices",
          ["status" => "paid"],
          ["id" => $invoice_id],
          ["%s"],
          ["%d"]
        );

        $wpdb->query("COMMIT");

        // Get payment details for the action hook
        $payment = $wpdb->get_row(
          $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}custom_invoice_payments WHERE transaction_id = %s",
            $transaction_id
          )
        );

        if ($payment) {
          // Trigger success action
          do_action("cis_payment_processed", $invoice_id, [
            "amount" => $payment->amount,
            "invoice_amount" => $payment->amount - $payment->convenience_fee,
            "convenience_fee" => $payment->convenience_fee,
            "payment_date" => current_time("mysql"),
            "transaction_id" => $transaction_id,
          ]);
        }

        return;
      }

      // Try to get payment method and fee from Stripe if available
      $payment_method = $default_payment_method;
      $fee = 0;

      try {
        $stripe_secret = get_option("stripe_secret_key");
        if (!empty($stripe_secret) && class_exists("\Stripe\Stripe")) {
          \Stripe\Stripe::setApiKey($stripe_secret);

          // First try to get it from a PaymentIntent
          try {
            $payment_intent = \Stripe\PaymentIntent::retrieve($transaction_id);
            $payment_method =
              $payment_intent->metadata->payment_method ??
              $default_payment_method;
            $fee = floatval($payment_intent->metadata->convenience_fee ?? 0);
          } catch (\Exception $e) {
            // If that fails, try to get it from Checkout Session
            try {
              $session = \Stripe\Checkout\Session::retrieve([
                "id" => $transaction_id,
                "expand" => ["payment_intent"],
              ]);
              if ($session->payment_intent) {
                $payment_method =
                  $session->metadata->payment_method ?? $default_payment_method;
                $fee = floatval($session->metadata->convenience_fee ?? 0);
                // Use the actual payment intent ID
                $transaction_id =
                  $session->payment_intent->id ?? $transaction_id;
              }
            } catch (\Exception $e2) {
              // If both fail, use the defaults
            }
          }
        }
      } catch (\Exception $e) {
        // If any error occurs, use the defaults
        cis_log_error("Error retrieving payment details from Stripe", [
          "error" => $e->getMessage(),
        ]);
      }

      // Calculate fee if we couldn't get it from Stripe metadata
      if (empty($fee)) {
        if ($payment_method === "card") {
          $fee_percentage = floatval(
            get_option("convenience_fee_percentage", "0")
          );
          if ($fee_percentage > 0) {
            $fee = round($invoice->amount * ($fee_percentage / 100), 2);
          }
        } elseif ($payment_method === "ach") {
          $fee = floatval(get_option("ach_fee_amount", "0"));
        }
      }

      $total_amount = floatval($invoice->amount) + $fee;

      // Record payment in database
      $payment_result = $wpdb->insert(
        $wpdb->prefix . "custom_invoice_payments",
        [
          "invoice_id" => $invoice_id,
          "amount" => $total_amount,
          "convenience_fee" => $fee,
          "payment_method" => $payment_method,
          "transaction_id" => $transaction_id,
          "status" => "completed",
          "payment_date" => current_time("mysql"),
        ],
        ["%d", "%f", "%f", "%s", "%s", "%s", "%s"]
      );

      if ($payment_result === false) {
        throw new Exception("Failed to record payment: " . $wpdb->last_error);
      }

      // Update invoice status
      $update_result = $wpdb->update(
        $wpdb->prefix . "custom_invoices",
        ["status" => "paid"],
        ["id" => $invoice_id],
        ["%s"],
        ["%d"]
      );

      if ($update_result === false) {
        throw new Exception(
          "Failed to update invoice status: " . $wpdb->last_error
        );
      }

      // If we got here, commit the transaction
      $wpdb->query("COMMIT");

      // Trigger success action
      do_action("cis_payment_processed", $invoice_id, [
        "amount" => $total_amount,
        "invoice_amount" => $invoice->amount,
        "convenience_fee" => $fee,
        "payment_date" => current_time("mysql"),
        "transaction_id" => $transaction_id,
      ]);

      cis_log_error("Payment recorded successfully", [
        "invoice_id" => $invoice_id,
        "transaction_id" => $transaction_id,
        "amount" => $total_amount,
      ]);
    } catch (Exception $e) {
      // Something went wrong, rollback the transaction
      $wpdb->query("ROLLBACK");
      cis_log_error("Payment recording failed", [
        "error" => $e->getMessage(),
        "invoice_id" => $invoice_id,
      ]);
      throw $e;
    }
  }

  /**
   * Get invoice via AJAX
   *
   * @return void
   */
  public function get_invoice(): void
  {
    if (!check_ajax_referer("invoice_nonce", "nonce", false)) {
      wp_send_json_error(__("Invalid nonce", "custom-invoice-system"));
      return;
    }

    if (!current_user_can("manage_options")) {
      wp_send_json_error(__("Unauthorized access", "custom-invoice-system"));
      return;
    }

    if (!isset($_POST["invoice_id"])) {
      wp_send_json_error(__("No invoice ID provided", "custom-invoice-system"));
      return;
    }

    global $wpdb;
    $invoice_id = intval($_POST["invoice_id"]);

    $invoice = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}custom_invoices WHERE id = %d",
        $invoice_id
      )
    );

    if (!$invoice) {
      wp_send_json_error(__("Invoice not found", "custom-invoice-system"));
      return;
    }

    wp_send_json_success($invoice);
  }

  /**
   * Delete invoice via AJAX
   *
   * @return void
   */
  public function delete_invoice(): void
  {
    if (!check_ajax_referer("invoice_nonce", "nonce", false)) {
      wp_send_json_error(__("Invalid nonce", "custom-invoice-system"));
      return;
    }

    if (!current_user_can("manage_options")) {
      wp_send_json_error(__("Unauthorized access", "custom-invoice-system"));
      return;
    }

    if (!isset($_POST["invoice_id"])) {
      wp_send_json_error(__("No invoice ID provided", "custom-invoice-system"));
      return;
    }

    global $wpdb;
    $invoice_id = intval($_POST["invoice_id"]);

    // Delete related payments first
    $wpdb->delete(
      $wpdb->prefix . "custom_invoice_payments",
      ["invoice_id" => $invoice_id],
      ["%d"]
    );

    // Delete the invoice
    $result = $wpdb->delete(
      $wpdb->prefix . "custom_invoices",
      ["id" => $invoice_id],
      ["%d"]
    );

    if ($result !== false) {
      wp_send_json_success(
        __("Invoice deleted successfully", "custom-invoice-system")
      );
    } else {
      wp_send_json_error(
        __("Failed to delete invoice", "custom-invoice-system")
      );
    }
  }

  /**
   * Update invoice via AJAX
   *
   * @return void
   */
  public function update_invoice(): void
  {
    if (!check_ajax_referer("invoice_nonce", "nonce", false)) {
      wp_send_json_error(__("Invalid nonce", "custom-invoice-system"));
      return;
    }

    if (!current_user_can("manage_options")) {
      wp_send_json_error(__("Unauthorized access", "custom-invoice-system"));
      return;
    }

    global $wpdb;

    try {
      $invoice_id = intval($_POST["invoice_id"] ?? 0);

      if ($invoice_id <= 0) {
        throw new Exception(__("Invalid invoice ID", "custom-invoice-system"));
      }

      // Check if invoice exists
      $existing_invoice = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT * FROM {$wpdb->prefix}custom_invoices WHERE id = %d",
          $invoice_id
        )
      );

      if (!$existing_invoice) {
        throw new Exception(__("Invoice not found", "custom-invoice-system"));
      }

      // Validate and prepare data
      $amount = $this->sanitize_amount($_POST["amount"] ?? 0);
      $due_date = $this->validate_date($_POST["due_date"] ?? "", true);

      // Prepare update data
      $frequency = sanitize_text_field($_POST["frequency"] ?? "one-time");
      $is_recurring = $frequency === "recurring";

      $data = [
        "amount" => $amount,
        "description" => sanitize_textarea_field($_POST["description"] ?? ""),
        "due_date" => $due_date,
        "status" => sanitize_text_field($_POST["status"] ?? "pending"),
      ];

      // Validate status
      if (
        !in_array($data["status"], [
          "pending",
          "paid",
          "cancelled",
          "processing",
        ])
      ) {
        throw new Exception(__("Invalid status", "custom-invoice-system"));
      }

      // Handle recurring settings
      if ($is_recurring && $data["status"] !== "cancelled") {
        $recurring_unit = sanitize_text_field($_POST["recurring_unit"] ?? "");
        $recurring_interval = intval($_POST["recurring_interval"] ?? 0);

        if (
          !empty($recurring_unit) &&
          $recurring_interval > 0 &&
          in_array($recurring_unit, ["day", "week", "month", "year"])
        ) {
          $data["recurring_unit"] = $recurring_unit;
          $data["recurring_interval"] = $recurring_interval;

          // Calculate next recurring date if not provided
          $next_recurring_date = sanitize_text_field(
            $_POST["next_recurring_date"] ?? ""
          );
          if (empty($next_recurring_date)) {
            $data["next_recurring_date"] = $this->calculate_next_date(
              $data["due_date"],
              $recurring_unit,
              $recurring_interval
            );
          } else {
            $data["next_recurring_date"] = $this->validate_date(
              $next_recurring_date,
              true
            );
          }
        } else {
          // Clear recurring settings if invalid
          $data["recurring_unit"] = null;
          $data["recurring_interval"] = 0;
          $data["next_recurring_date"] = null;
        }
      } else {
        // Clear recurring settings
        $data["recurring_unit"] = null;
        $data["recurring_interval"] = 0;
        $data["next_recurring_date"] = null;
      }

      $result = $wpdb->update($wpdb->prefix . "custom_invoices", $data, [
        "id" => $invoice_id,
      ]);

      if ($result === false) {
        throw new Exception(
          sprintf(
            __("Failed to update invoice: %s", "custom-invoice-system"),
            $wpdb->last_error
          )
        );
      }

      // If status was changed to paid, trigger the payment notification
      if ($data["status"] === "paid" && $existing_invoice->status !== "paid") {
        do_action("cis_payment_processed", $invoice_id, [
          "amount" => $data["amount"],
          "invoice_amount" => $data["amount"],
          "convenience_fee" => 0,
          "payment_date" => current_time("mysql"),
          "transaction_id" => "manual-update-" . time(),
        ]);
      }

      wp_send_json_success(
        __("Invoice updated successfully", "custom-invoice-system")
      );
    } catch (Exception $e) {
      cis_log_error("Failed to update invoice", [
        "error" => $e->getMessage(),
        "invoice_id" => $invoice_id ?? "unknown",
      ]);
      wp_send_json_error($e->getMessage());
    }
  }

  /**
   * Get all invoices for debugging
   *
   * @return array Array of invoice objects with user display names
   */
  public function debug_invoices(): array
  {
    global $wpdb;
    $invoices = $wpdb->get_results("
            SELECT i.*, u.display_name 
            FROM {$wpdb->prefix}custom_invoices i 
            JOIN {$wpdb->users} u ON i.user_id = u.ID 
            ORDER BY i.created_at DESC
        ");
    return $invoices ?: [];
  }

  /**
   * Render user profile shortcode
   *
   * @return string HTML output
   */
  public function render_profile(): string
  {
    if (!is_user_logged_in()) {
      return "<p>" .
        esc_html__(
          "Please log in to view your invoices.",
          "custom-invoice-system"
        ) .
        "</p>";
    }

    // Ensure scripts are loaded (localization handled in cis_enqueue_frontend_scripts)
    wp_enqueue_script("stripe-js");
    wp_enqueue_script("cis-stripe-checkout");

    ob_start();
    include CIS_PLUGIN_PATH . "templates/profile-template.php";
    return ob_get_clean();
  }

  /**
   * Sanitize and validate invoice amount
   *
   * @param mixed $amount Raw amount value
   * @return float Sanitized amount
   * @throws InvalidArgumentException If amount is invalid
   */
  private function sanitize_amount($amount): float
  {
    $amount = floatval($amount);

    if ($amount < 0.01) {
      throw new InvalidArgumentException(
        __('Amount must be at least $0.01', "custom-invoice-system")
      );
    }

    if ($amount > 999999.99) {
      throw new InvalidArgumentException(
        __('Amount cannot exceed $999,999.99', "custom-invoice-system")
      );
    }

    return round($amount, 2);
  }

  /**
   * Validate date format and ensure it's not in the past
   *
   * @param string $date Date string
   * @param bool $allow_past Whether to allow past dates
   * @return string Validated date
   * @throws InvalidArgumentException If date is invalid
   */
  private function validate_date(string $date, bool $allow_past = false): string
  {
    $date_obj = DateTime::createFromFormat("Y-m-d", $date);

    if (!$date_obj || $date_obj->format("Y-m-d") !== $date) {
      throw new InvalidArgumentException(
        __("Invalid date format. Use YYYY-MM-DD", "custom-invoice-system")
      );
    }

    if (!$allow_past && $date_obj < new DateTime("today")) {
      throw new InvalidArgumentException(
        __("Due date cannot be in the past", "custom-invoice-system")
      );
    }

    return $date;
  }

  /**
   * Calculate next date based on unit and interval
   * Delegates to shared utility function cis_calculate_next_date().
   *
   * @param string $current_date Current date in Y-m-d format
   * @param string $unit Time unit (day, week, month, year)
   * @param int $interval Number of units
   * @return string Next date in Y-m-d format
   * @throws Exception If calculation fails
   */
  private function calculate_next_date(
    string $current_date,
    string $unit,
    int $interval
  ): string {
    return cis_calculate_next_date($current_date, $unit, $interval);
  }
}
