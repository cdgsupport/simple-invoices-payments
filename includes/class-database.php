<?php
declare(strict_types=1);

/**
 * Database management class for Simple Invoices & Payments
 *
 * @package CIS
 * @since 2.0
 */
class CIS_Database
{
  /**
   * Create database tables
   *
   * @return bool True on success, false on failure
   */
  public static function create_tables(): bool
  {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . "wp-admin/includes/upgrade.php";

    $success = true;

    // Create custom_invoices table (removed IF NOT EXISTS)
    $invoices_sql = "CREATE TABLE {$wpdb->prefix}custom_invoices (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            invoice_number varchar(50) NOT NULL,
            amount decimal(10,2) NOT NULL,
            status varchar(20) NOT NULL,
            description text,
            due_date date NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            recurring_unit varchar(20) DEFAULT NULL,
            recurring_interval int DEFAULT NULL,
            original_invoice_id mediumint(9) DEFAULT NULL,
            recurring_sequence int DEFAULT 0,
            next_recurring_date date DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY due_date (due_date),
            KEY invoice_number (invoice_number)
        ) $charset_collate;";

    $result = dbDelta($invoices_sql);
    cis_log_error("CIS: dbDelta result for invoices table", $result);

    // Verify invoices table was created
    $invoices_table_exists = $wpdb->get_var(
      $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->prefix . "custom_invoices")
    );

    if (!$invoices_table_exists) {
      cis_log_error("CIS: Failed to create custom_invoices table");
      $success = false;
    } else {
      cis_log_error("CIS: Successfully created/verified custom_invoices table");
    }

    // Create separate payments table for payment records
    $payments_sql = "CREATE TABLE {$wpdb->prefix}custom_invoice_payments (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            invoice_id mediumint(9) NOT NULL,
            amount decimal(10,2) NOT NULL,
            convenience_fee decimal(10,2) DEFAULT 0.00,
            payment_method varchar(50) NOT NULL,
            transaction_id varchar(255) NOT NULL,
            status varchar(20) NOT NULL,
            payment_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY invoice_id (invoice_id),
            KEY transaction_id (transaction_id),
            KEY status (status)
        ) $charset_collate;";

    $result = dbDelta($payments_sql);
    cis_log_error("CIS: dbDelta result for payments table", $result);

    // Verify payments table was created
    $payments_table_exists = $wpdb->get_var(
      $wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $wpdb->prefix . "custom_invoice_payments"
      )
    );

    if (!$payments_table_exists) {
      cis_log_error("CIS: Failed to create custom_invoice_payments table");
      $success = false;
    } else {
      cis_log_error(
        "CIS: Successfully created/verified custom_invoice_payments table"
      );
    }

    return $success;
  }

  /**
   * Upgrade database schema and verify table existence
   *
   * @return bool True on success, false on failure
   */
  public static function upgrade_database(): bool
  {
    global $wpdb;

    cis_log_error("CIS: Starting database upgrade/verification");

    // Check if main invoices table exists
    $invoices_table = $wpdb->prefix . "custom_invoices";
    $invoices_exists =
      $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $invoices_table)) ===
      $invoices_table;

    // Check if payments table exists
    $payments_table = $wpdb->prefix . "custom_invoice_payments";
    $payments_exists =
      $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $payments_table)) ===
      $payments_table;

    cis_log_error("CIS: Table existence check", [
      "invoices_table_exists" => $invoices_exists,
      "payments_table_exists" => $payments_exists,
    ]);

    // Create missing tables
    if (!$invoices_exists || !$payments_exists) {
      cis_log_error("CIS: Creating missing database tables");
      $create_result = self::create_tables();

      if (!$create_result) {
        cis_log_error("CIS: Failed to create missing tables");
        return false;
      }

      // Re-check after creation
      $invoices_exists =
        $wpdb->get_var(
          $wpdb->prepare("SHOW TABLES LIKE %s", $invoices_table)
        ) === $invoices_table;

      $payments_exists =
        $wpdb->get_var(
          $wpdb->prepare("SHOW TABLES LIKE %s", $payments_table)
        ) === $payments_table;

      cis_log_error("CIS: Post-creation table check", [
        "invoices_table_exists" => $invoices_exists,
        "payments_table_exists" => $payments_exists,
      ]);
    }

    // Verify table structure and add missing columns
    if ($payments_exists) {
      self::upgrade_payments_table_structure();
    }

    if ($invoices_exists) {
      self::upgrade_invoices_table_structure();
    }

    // Final verification
    $final_check = $invoices_exists && $payments_exists;
    cis_log_error("CIS: Database upgrade completed", [
      "success" => $final_check,
    ]);

    return $final_check;
  }

  /**
   * Upgrade payments table structure
   *
   * @return void
   */
  private static function upgrade_payments_table_structure(): void
  {
    global $wpdb;
    $payments_table = $wpdb->prefix . "custom_invoice_payments";

    // Check if convenience_fee column exists
    $convenience_fee_exists = $wpdb->get_results(
      $wpdb->prepare(
        "SHOW COLUMNS FROM {$payments_table} LIKE %s",
        "convenience_fee"
      )
    );

    if (empty($convenience_fee_exists)) {
      $result = $wpdb->query(
        "ALTER TABLE {$payments_table} 
                 ADD COLUMN convenience_fee decimal(10,2) DEFAULT 0.00 
                 AFTER amount"
      );

      if ($result !== false) {
        cis_log_error("CIS: Added convenience_fee column to payments table");
      } else {
        cis_log_error(
          "CIS: Failed to add convenience_fee column",
          $wpdb->last_error
        );
      }
    }
  }

  /**
   * Upgrade invoices table structure
   *
   * @return void
   */
  private static function upgrade_invoices_table_structure(): void
  {
    global $wpdb;
    $invoices_table = $wpdb->prefix . "custom_invoices";

    // Get current table structure
    $columns = $wpdb->get_results("SHOW COLUMNS FROM {$invoices_table}");
    $existing_columns = array_column($columns, "Field");

    // Define required columns that might be missing in older versions
    $required_columns = [
      "recurring_unit" => "ALTER TABLE {$invoices_table} ADD COLUMN recurring_unit varchar(20) DEFAULT NULL",
      "recurring_interval" => "ALTER TABLE {$invoices_table} ADD COLUMN recurring_interval int DEFAULT NULL",
      "original_invoice_id" => "ALTER TABLE {$invoices_table} ADD COLUMN original_invoice_id mediumint(9) DEFAULT NULL",
      "recurring_sequence" => "ALTER TABLE {$invoices_table} ADD COLUMN recurring_sequence int DEFAULT 0",
      "next_recurring_date" => "ALTER TABLE {$invoices_table} ADD COLUMN next_recurring_date date DEFAULT NULL",
    ];

    foreach ($required_columns as $column => $alter_sql) {
      if (!in_array($column, $existing_columns)) {
        $result = $wpdb->query($alter_sql);

        if ($result !== false) {
          cis_log_error("CIS: Added {$column} column to invoices table");
        } else {
          cis_log_error(
            "CIS: Failed to add {$column} column",
            $wpdb->last_error
          );
        }
      }
    }

    // Add indexes if they don't exist
    self::ensure_table_indexes();
  }

  /**
   * Ensure proper indexes exist on tables
   *
   * @return void
   */
  private static function ensure_table_indexes(): void
  {
    global $wpdb;

    // Define indexes for invoices table
    $invoices_indexes = [
      "user_id" => "ALTER TABLE {$wpdb->prefix}custom_invoices ADD INDEX user_id (user_id)",
      "status" => "ALTER TABLE {$wpdb->prefix}custom_invoices ADD INDEX status (status)",
      "due_date" => "ALTER TABLE {$wpdb->prefix}custom_invoices ADD INDEX due_date (due_date)",
      "invoice_number" => "ALTER TABLE {$wpdb->prefix}custom_invoices ADD INDEX invoice_number (invoice_number)",
    ];

    // Define indexes for payments table
    $payments_indexes = [
      "invoice_id" => "ALTER TABLE {$wpdb->prefix}custom_invoice_payments ADD INDEX invoice_id (invoice_id)",
      "transaction_id" => "ALTER TABLE {$wpdb->prefix}custom_invoice_payments ADD INDEX transaction_id (transaction_id)",
      "status" => "ALTER TABLE {$wpdb->prefix}custom_invoice_payments ADD INDEX status (status)",
    ];

    $all_indexes = array_merge($invoices_indexes, $payments_indexes);

    foreach ($all_indexes as $index_name => $index_sql) {
      // Check if index exists (this will suppress errors if index already exists)
      $wpdb->query($index_sql);
    }
  }

  /**
   * Get table status information for debugging
   *
   * @return array Table status information
   */
  public static function get_table_status(): array
  {
    global $wpdb;

    $status = [];

    // Check invoices table
    $invoices_table = $wpdb->prefix . "custom_invoices";
    $status["invoices_table_exists"] =
      $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $invoices_table)) ===
      $invoices_table;

    if ($status["invoices_table_exists"]) {
      $status["invoices_count"] = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$invoices_table}"
      );
      $status["invoices_structure"] = $wpdb->get_results(
        "SHOW COLUMNS FROM {$invoices_table}"
      );
    }

    // Check payments table
    $payments_table = $wpdb->prefix . "custom_invoice_payments";
    $status["payments_table_exists"] =
      $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $payments_table)) ===
      $payments_table;

    if ($status["payments_table_exists"]) {
      $status["payments_count"] = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$payments_table}"
      );
      $status["payments_structure"] = $wpdb->get_results(
        "SHOW COLUMNS FROM {$payments_table}"
      );
    }

    return $status;
  }

  /**
   * Constructor - Run database upgrade only when plugin version changes
   */
  public function __construct()
  {
    $stored_version = get_option("cis_db_version", "0");

    if (version_compare($stored_version, CIS_PLUGIN_VERSION, "<")) {
      $upgrade_result = self::upgrade_database();

      if ($upgrade_result) {
        update_option("cis_db_version", CIS_PLUGIN_VERSION);
      } else {
        cis_log_error(
          "CIS: Database initialization failed - tables may not exist"
        );

        // Add admin notice for database issues
        add_action("admin_notices", function () {
          ?>
                    <div class="notice notice-error">
                        <p>
                            <?php esc_html_e(
                              "Simple Invoices & Payments: Database tables could not be created or verified. Please check your database permissions and error logs.",
                              "custom-invoice-system"
                            ); ?>
                        </p>
                    </div>
                    <?php
        });
      }
    }
  }
}
