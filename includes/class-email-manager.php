<?php
class CIS_Email_Manager
{
  private $templates;

  public function __construct()
  {
    $this->templates = new CIS_Email_Templates();

    // Hook into invoice creation
    add_action(
      "cis_invoice_created",
      [$this, "send_new_invoice_notification"],
      10,
      2
    );

    // Hook into payment processing
    add_action(
      "cis_payment_processed",
      [$this, "send_payment_confirmation"],
      10,
      2
    );

    // Hook into late fee application
    add_action(
      "cis_late_fee_applied",
      [$this, "send_late_fee_notification"],
      10,
      2
    );

    // Schedule due date reminders
    add_action("init", [$this, "schedule_reminder_check"]);
    add_action("check_due_date_reminders", [
      $this,
      "process_due_date_reminders",
    ]);
  }

  public function schedule_reminder_check()
  {
    if (!wp_next_scheduled("check_due_date_reminders")) {
      wp_schedule_event(
        strtotime("today midnight"),
        "daily",
        "check_due_date_reminders"
      );
    }
  }

  public function send_new_invoice_notification($invoice_id, $user_id)
  {
    if (!$this->templates->is_notification_enabled("new_invoice")) {
      return;
    }

    $invoice = $this->get_invoice_data($invoice_id);
    if (!$invoice) {
      return;
    }

    $user = get_userdata($user_id);
    if (!$user) {
      return;
    }

    $variables = [
      "user_name" => $user->display_name,
      "invoice_number" => $invoice->invoice_number,
      "amount" => number_format($invoice->amount, 2),
      "due_date" => date("F j, Y", strtotime($invoice->due_date)),
      "description" => $invoice->description,
    ];

    $template = $this->templates->get_template("new_invoice");
    $content = $this->templates->parse_template($template, $variables);
    $formatted_content = $this->templates->wrap_template_with_styles($content);

    $this->send_email(
      $user->user_email,
      "New Invoice Created - #" . $invoice->invoice_number,
      $formatted_content
    );
  }

  public function send_payment_confirmation($invoice_id, $payment_data)
  {
    $invoice = $this->get_invoice_data($invoice_id);
    if (!$invoice) {
      return;
    }

    $user = get_userdata($invoice->user_id);
    if (!$user) {
      return;
    }

    // Send customer notification
    if ($this->templates->is_notification_enabled("payment_received")) {
      $variables = [
        "user_name" => $user->display_name,
        "invoice_number" => $invoice->invoice_number,
        "amount" => number_format($payment_data["amount"], 2),
        "payment_date" => date("F j, Y"),
        "description" => $invoice->description,
      ];

      $template = $this->templates->get_template("payment_received");
      $content = $this->templates->parse_template($template, $variables);
      $formatted_content = $this->templates->wrap_template_with_styles(
        $content
      );

      $this->send_email(
        $user->user_email,
        "Payment Confirmation - Invoice #" . $invoice->invoice_number,
        $formatted_content
      );
    }

    // Send admin notification
    if ($this->templates->is_notification_enabled("admin_payment")) {
      $admin_variables = [
        "user_name" => $user->display_name,
        "user_email" => $user->user_email,
        "invoice_number" => $invoice->invoice_number,
        "amount" => number_format($payment_data["amount"], 2),
        "invoice_amount" => number_format($payment_data["invoice_amount"], 2),
        "convenience_fee" => number_format($payment_data["convenience_fee"], 2),
        "payment_date" => date("F j, Y"),
        "description" => $invoice->description,
        "transaction_id" => isset($payment_data["transaction_id"])
          ? $payment_data["transaction_id"]
          : "N/A",
      ];

      $admin_template = $this->templates->get_template("admin_payment");
      $admin_content = $this->templates->parse_template(
        $admin_template,
        $admin_variables
      );
      $formatted_admin_content = $this->templates->wrap_template_with_styles(
        $admin_content
      );

      // Get admin email
      $admin_email = get_option("admin_email");

      $this->send_email(
        $admin_email,
        "Payment Received - Invoice #" . $invoice->invoice_number,
        $formatted_admin_content
      );
    }
  }

  public function process_due_date_reminders()
  {
    global $wpdb;
    $reminder_days = [14, 7, 3];

    foreach ($reminder_days as $days) {
      if (!$this->templates->is_notification_enabled("due_soon_" . $days)) {
        continue;
      }

      $due_date = date("Y-m-d", strtotime("+{$days} days"));

      $invoices = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT * FROM {$wpdb->prefix}custom_invoices 
				WHERE due_date = %s 
				AND status = 'pending'",
          $due_date
        )
      );

      foreach ($invoices as $invoice) {
        $this->send_due_date_reminder($invoice, $days);
      }
    }
  }

  public function send_due_date_reminder($invoice, $days_until_due)
  {
    $user = get_userdata($invoice->user_id);
    if (!$user) {
      return;
    }

    $variables = [
      "user_name" => $user->display_name,
      "invoice_number" => $invoice->invoice_number,
      "amount" => number_format($invoice->amount, 2),
      "due_date" => date("F j, Y", strtotime($invoice->due_date)),
      "days_until_due" => $days_until_due,
      "description" => $invoice->description,
    ];

    $template = $this->templates->get_template("due_soon");
    $content = $this->templates->parse_template($template, $variables);
    $formatted_content = $this->templates->wrap_template_with_styles($content);

    $this->send_email(
      $user->user_email,
      "Payment Reminder - Invoice #" . $invoice->invoice_number,
      $formatted_content
    );
  }

  public function send_late_fee_notification($invoice_id, $late_fee_data)
  {
    if (!$this->templates->is_notification_enabled("late_fee")) {
      return;
    }

    $invoice = $this->get_invoice_data($invoice_id);
    if (!$invoice) {
      return;
    }

    $user = get_userdata($invoice->user_id);
    if (!$user) {
      return;
    }

    $variables = [
      "user_name" => $user->display_name,
      "invoice_number" => $invoice->invoice_number,
      "original_amount" => number_format($invoice->amount, 2),
      "late_fee_amount" => number_format($late_fee_data["fee_amount"], 2),
      "total_amount" => number_format(
        $invoice->amount + $late_fee_data["fee_amount"],
        2
      ),
      "original_due_date" => date("F j, Y", strtotime($invoice->due_date)),
    ];

    $template = $this->templates->get_template("late_fee");
    $content = $this->templates->parse_template($template, $variables);
    $formatted_content = $this->templates->wrap_template_with_styles($content);

    $this->send_email(
      $user->user_email,
      "Late Fee Notice - Invoice #" . $invoice->invoice_number,
      $formatted_content
    );
  }

  private function send_email($to, $subject, $message)
  {
    $headers = [
      "Content-Type: text/html; charset=UTF-8",
      "From: " .
      $this->templates->get_sender_name() .
      " <" .
      get_option("admin_email") .
      ">",
    ];

    wp_mail($to, $subject, $message, $headers);
  }

  private function get_invoice_data($invoice_id)
  {
    global $wpdb;
    return $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}custom_invoices WHERE id = %d",
        $invoice_id
      )
    );
  }
}
