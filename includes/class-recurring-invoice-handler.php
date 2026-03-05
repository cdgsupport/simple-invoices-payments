<?php
declare(strict_types=1);

/**
 * Handles recurring invoice generation with improved logic and error handling
 *
 * @package CIS
 * @since 2.0
 */
class CIS_Recurring_Invoice_Handler
{
  /**
   * Constructor
   */
  public function __construct()
  {
    add_action("init", [$this, "setup_cron"]);
    add_action("cis_process_recurring_invoices", [
      $this,
      "process_recurring_invoices",
    ]);
    add_action("wp_ajax_update_recurring_settings", [
      $this,
      "update_recurring_settings",
    ]);

    // Hook into invoice creation to set initial next_recurring_date
    add_action(
      "cis_invoice_created",
      [$this, "set_initial_recurring_date"],
      10,
      2
    );
  }

  /**
   * Setup cron job for recurring invoices
   */
  public function setup_cron(): void
  {
    if (!wp_next_scheduled("cis_process_recurring_invoices")) {
      wp_schedule_event(
        strtotime("tomorrow midnight"),
        "daily",
        "cis_process_recurring_invoices"
      );
    }
  }

  /**
   * Process all due recurring invoices
   */
  public function process_recurring_invoices(): void
  {
    global $wpdb;
    $today = current_time("Y-m-d");

    // Add error logging
    cis_log_error(
      "CIS: Starting recurring invoice processing for date: " . $today
    );

    // Get all invoices that need recurring processing
    $recurring_invoices = $wpdb->get_results(
      $wpdb->prepare(
        "
			SELECT * FROM {$wpdb->prefix}custom_invoices 
			WHERE recurring_interval > 0 
			AND recurring_unit IS NOT NULL 
			AND recurring_unit != ''
			AND (next_recurring_date IS NULL OR next_recurring_date <= %s)
			AND status NOT IN ('cancelled', 'paid')
			ORDER BY id ASC",
        $today
      )
    );

    cis_log_error(
      "CIS: Found " . count($recurring_invoices) . " invoices to process"
    );

    foreach ($recurring_invoices as $invoice) {
      try {
        $this->generate_recurring_invoice($invoice);
      } catch (Exception $e) {
        cis_log_error(
          "CIS: Error generating recurring invoice for ID " .
            $invoice->id .
            ": " .
            $e->getMessage()
        );
      }
    }
  }

  /**
   * Generate a new recurring invoice
   *
   * @param object $original_invoice The source invoice object
   * @throws Exception If invoice generation fails
   */
  private function generate_recurring_invoice(object $original_invoice): void
  {
    global $wpdb;

    // If this is the first recurring invoice, set the base date
    if (empty($original_invoice->next_recurring_date)) {
      $base_date = $original_invoice->due_date;
    } else {
      $base_date = $original_invoice->next_recurring_date;
    }

    // Calculate the next invoice number with sequence
    $sequence = intval($original_invoice->recurring_sequence) + 1;
    $original_id =
      $original_invoice->original_invoice_id ?: $original_invoice->id;

    // Get the base invoice number (without sequence)
    $base_invoice_number = $this->get_base_invoice_number(
      $original_invoice->invoice_number
    );
    $new_invoice_number = $base_invoice_number . "-" . $sequence;

    // Check if this invoice number already exists
    $exists = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}custom_invoices WHERE invoice_number = %s",
        $new_invoice_number
      )
    );

    if ($exists > 0) {
      cis_log_error(
        "CIS: Invoice number " .
          $new_invoice_number .
          " already exists, skipping"
      );
      // Update the next recurring date anyway to prevent stuck invoices
      $this->update_next_recurring_date($original_invoice);
      return;
    }

    // Calculate the new due date
    $new_due_date = $this->calculate_next_date(
      $base_date,
      $original_invoice->recurring_unit,
      intval($original_invoice->recurring_interval)
    );

    // Calculate the next recurring date (for the following invoice)
    $next_recurring_date = $this->calculate_next_date(
      $new_due_date,
      $original_invoice->recurring_unit,
      intval($original_invoice->recurring_interval)
    );

    // Prepare new invoice data
    $new_invoice_data = [
      "user_id" => $original_invoice->user_id,
      "invoice_number" => $new_invoice_number,
      "amount" => $original_invoice->amount,
      "status" => "pending",
      "description" => $original_invoice->description,
      "due_date" => $new_due_date,
      "created_at" => current_time("mysql"),
      "recurring_unit" => $original_invoice->recurring_unit,
      "recurring_interval" => $original_invoice->recurring_interval,
      "original_invoice_id" => $original_id,
      "recurring_sequence" => $sequence,
      "next_recurring_date" => $next_recurring_date,
    ];

    // Insert new invoice
    $result = $wpdb->insert(
      $wpdb->prefix . "custom_invoices",
      $new_invoice_data,
      ["%d", "%s", "%f", "%s", "%s", "%s", "%s", "%s", "%d", "%d", "%d", "%s"]
    );

    if ($result === false) {
      throw new Exception(
        "Failed to insert recurring invoice: " . $wpdb->last_error
      );
    }

    $new_invoice_id = $wpdb->insert_id;

    // Update the original invoice's tracking fields
    $update_result = $wpdb->update(
      $wpdb->prefix . "custom_invoices",
      [
        "next_recurring_date" => $next_recurring_date,
        "recurring_sequence" => $sequence,
      ],
      ["id" => $original_invoice->id],
      ["%s", "%d"],
      ["%d"]
    );

    if ($update_result === false) {
      cis_log_error(
        "CIS: Failed to update original invoice tracking: " . $wpdb->last_error
      );
    }

    // Trigger actions
    do_action(
      "cis_recurring_invoice_generated",
      $new_invoice_id,
      $original_invoice->id
    );
    do_action(
      "cis_invoice_created",
      $new_invoice_id,
      $original_invoice->user_id
    );

    cis_log_error(
      "CIS: Successfully generated recurring invoice #" . $new_invoice_number
    );
  }

  /**
   * Get base invoice number without sequence
   *
   * @param string $invoice_number Full invoice number
   * @return string Base invoice number
   */
  private function get_base_invoice_number(string $invoice_number): string
  {
    // Remove any existing sequence suffix
    $parts = explode("-", $invoice_number);
    if (count($parts) > 1 && is_numeric(end($parts))) {
      array_pop($parts);
    }
    return implode("-", $parts);
  }

  /**
   * Calculate next date based on unit and interval
   * Delegates to shared utility function cis_calculate_next_date().
   *
   * @param string $current_date Current date in Y-m-d format
   * @param string $unit Time unit (day, week, month, year)
   * @param int $interval Number of units
   * @return string Next date in Y-m-d format
   */
  private function calculate_next_date(
    string $current_date,
    string $unit,
    int $interval
  ): string {
    return cis_calculate_next_date($current_date, $unit, $interval);
  }

  /**
   * Update just the next recurring date for an invoice
   *
   * @param object $invoice Invoice object
   */
  private function update_next_recurring_date(object $invoice): void
  {
    global $wpdb;

    $next_date = $this->calculate_next_date(
      $invoice->next_recurring_date ?: $invoice->due_date,
      $invoice->recurring_unit,
      intval($invoice->recurring_interval)
    );

    $wpdb->update(
      $wpdb->prefix . "custom_invoices",
      ["next_recurring_date" => $next_date],
      ["id" => $invoice->id],
      ["%s"],
      ["%d"]
    );
  }

  /**
   * Set initial recurring date when invoice is created
   *
   * @param int $invoice_id Invoice ID
   * @param int $user_id User ID
   */
  public function set_initial_recurring_date(
    int $invoice_id,
    int $user_id
  ): void {
    global $wpdb;

    $invoice = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}custom_invoices WHERE id = %d",
        $invoice_id
      )
    );

    if (
      !$invoice ||
      empty($invoice->recurring_unit) ||
      $invoice->recurring_interval <= 0
    ) {
      return;
    }

    // Only set if not already set
    if (!empty($invoice->next_recurring_date)) {
      return;
    }

    // Calculate next recurring date from due date
    $next_date = $this->calculate_next_date(
      $invoice->due_date,
      $invoice->recurring_unit,
      intval($invoice->recurring_interval)
    );

    $wpdb->update(
      $wpdb->prefix . "custom_invoices",
      ["next_recurring_date" => $next_date],
      ["id" => $invoice_id],
      ["%s"],
      ["%d"]
    );

    cis_log_error(
      "CIS: Set initial recurring date for invoice #" .
        $invoice->invoice_number .
        " to " .
        $next_date
    );
  }

  /**
   * Update recurring settings via AJAX
   */
  public function update_recurring_settings(): void
  {
    check_ajax_referer("invoice_nonce", "nonce");

    if (!current_user_can("manage_options")) {
      wp_send_json_error("Unauthorized access");
      return;
    }

    global $wpdb;

    $invoice_id = intval($_POST["invoice_id"] ?? 0);
    $recurring_unit = sanitize_text_field($_POST["recurring_unit"] ?? "");
    $recurring_interval = intval($_POST["recurring_interval"] ?? 0);
    $next_recurring_date = sanitize_text_field(
      $_POST["next_recurring_date"] ?? ""
    );

    // Validate inputs
    if ($invoice_id <= 0) {
      wp_send_json_error("Invalid invoice ID");
      return;
    }

    // If clearing recurring settings
    if (empty($recurring_unit) || $recurring_interval <= 0) {
      $result = $wpdb->update(
        $wpdb->prefix . "custom_invoices",
        [
          "recurring_unit" => null,
          "recurring_interval" => 0,
          "next_recurring_date" => null,
        ],
        ["id" => $invoice_id],
        ["%s", "%d", "%s"],
        ["%d"]
      );
    } else {
      // Validate unit
      if (!in_array($recurring_unit, ["day", "week", "month", "year"])) {
        wp_send_json_error("Invalid recurring unit");
        return;
      }

      // Auto-calculate next recurring date if not provided
      if (empty($next_recurring_date)) {
        $invoice = $wpdb->get_row(
          $wpdb->prepare(
            "SELECT due_date FROM {$wpdb->prefix}custom_invoices WHERE id = %d",
            $invoice_id
          )
        );

        if ($invoice) {
          $next_recurring_date = $this->calculate_next_date(
            $invoice->due_date,
            $recurring_unit,
            $recurring_interval
          );
        }
      }

      $result = $wpdb->update(
        $wpdb->prefix . "custom_invoices",
        [
          "recurring_unit" => $recurring_unit,
          "recurring_interval" => $recurring_interval,
          "next_recurring_date" => $next_recurring_date,
        ],
        ["id" => $invoice_id],
        ["%s", "%d", "%s"],
        ["%d"]
      );
    }

    if ($result !== false) {
      wp_send_json_success("Recurring settings updated");
    } else {
      wp_send_json_error(
        "Failed to update recurring settings: " . $wpdb->last_error
      );
    }
  }
}
