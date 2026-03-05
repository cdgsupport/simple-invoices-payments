<?php
class CIS_Late_Fees
{
  public function __construct()
  {
    add_action("wp", [$this, "schedule_check"]);
    add_action("check_late_fees_daily", [$this, "check_and_apply_fees"]);
  }

  public function schedule_check()
  {
    if (!wp_next_scheduled("check_late_fees_daily")) {
      wp_schedule_event(time(), "daily", "check_late_fees_daily");
    }
  }

  public function check_and_apply_fees()
  {
    if (get_option("late_fee_enabled") !== "1") {
      return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . "custom_invoices";

    $unpaid_invoices = $wpdb->get_results("
            SELECT * FROM $table_name 
            WHERE status = 'pending' 
            AND due_date < CURDATE()
        ");

    $late_fee_rules = get_option("late_fee_rules", []);
    if (empty($late_fee_rules)) {
      return;
    }

    foreach ($unpaid_invoices as $invoice) {
      $this->process_late_fee($invoice, $late_fee_rules);
    }
  }

  private function process_late_fee($invoice, $rules)
  {
    global $wpdb;
    $days_overdue =
      (strtotime("now") - strtotime($invoice->due_date)) / (60 * 60 * 24);

    // Check for existing recent late fees
    $existing_late_fees = $wpdb->get_results(
      $wpdb->prepare(
        "
            SELECT * FROM {$wpdb->prefix}custom_invoices 
            WHERE invoice_number LIKE %s 
            AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)",
        "LATE-FEE-" . $invoice->invoice_number . "%"
      )
    );

    if (!empty($existing_late_fees)) {
      return;
    }

    foreach ($rules as $rule) {
      if ($days_overdue >= $rule["days"]) {
        $fee = $this->calculate_fee($invoice, $rule);
        if ($fee > 0) {
          $this->create_late_fee_invoice($invoice, $fee, $rule);
        }
        break;
      }
    }
  }

  private function calculate_fee($invoice, $rule)
  {
    switch ($rule["type"]) {
      case "flat":
        return floatval($rule["amount"]);

      case "percentage":
        return $invoice->amount * (floatval($rule["amount"]) / 100);

      case "progressive":
        $progression_months = floor(
          (strtotime("now") - strtotime($invoice->due_date)) /
            (60 * 60 * 24 * 30)
        );
        $fee = floatval($rule["amount"]) * $progression_months;
        if ($rule["max_amount"] > 0) {
          $fee = min($fee, $rule["max_amount"]);
        }
        return $fee;

      default:
        return 0;
    }
  }

  /**
   * Get a human-readable description for the late fee
   *
   * @param array $rule The late fee rule configuration
   * @return string Fee description
   */
  private function get_fee_description($rule)
  {
    switch ($rule["type"]) {
      case "flat":
        return sprintf(
          'Late fee - flat charge of $%s',
          number_format(floatval($rule["amount"]), 2)
        );
      case "percentage":
        return sprintf(
          "Late fee - %s%% of original invoice",
          floatval($rule["amount"])
        );
      case "progressive":
        $desc = sprintf(
          'Late fee - progressive charge of $%s/month',
          number_format(floatval($rule["amount"]), 2)
        );
        if (!empty($rule["max_amount"]) && $rule["max_amount"] > 0) {
          $desc .= sprintf(
            ' (max $%s)',
            number_format(floatval($rule["max_amount"]), 2)
          );
        }
        return $desc;
      default:
        return "Late fee charge";
    }
  }

  private function create_late_fee_invoice($original_invoice, $amount, $rule)
  {
    global $wpdb;

    $description = $this->get_fee_description($rule);

    // Single insert operation
    $result = $wpdb->insert(
      $wpdb->prefix . "custom_invoices",
      [
        "user_id" => $original_invoice->user_id,
        "invoice_number" => "LATE-FEE-" . $original_invoice->invoice_number,
        "amount" => $amount,
        "status" => "pending",
        "due_date" => date("Y-m-d"),
        "description" => $description,
      ],
      ["%d", "%s", "%f", "%s", "%s", "%s"]
    );

    if ($result !== false) {
      do_action("cis_late_fee_applied", $original_invoice->id, [
        "fee_amount" => $amount,
        "fee_type" => $rule["type"],
      ]);
    }
  }
}
