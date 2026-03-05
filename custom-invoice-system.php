<?php
declare(strict_types=1);

/**
 * Plugin Name: Simple Invoices & Payments
 * Description: Custom user profile with invoice and payment functionality using Stripe Checkout
 * Version: 2.4.8
 * Author: Crawford Design Group
 * License: GPL v2 or later
 * Text Domain: custom-invoice-system
 */

// Prevent direct access
if (!defined("ABSPATH")) {
  exit();
}

// Define plugin constants
if (!defined("CIS_PLUGIN_PATH")) {
  define("CIS_PLUGIN_PATH", plugin_dir_path(__FILE__));
}
if (!defined("CIS_PLUGIN_URL")) {
  define("CIS_PLUGIN_URL", plugin_dir_url(__FILE__));
}
if (!defined("CIS_PLUGIN_VERSION")) {
  define("CIS_PLUGIN_VERSION", "2.4.8");
}

/**
 * Load required libraries and check dependencies
 *
 * @return void
 */
function cis_load_dependencies(): void
{
  // Check for Stripe library
  $stripe_autoload = CIS_PLUGIN_PATH . "includes/stripe-php/init.php";
  if (file_exists($stripe_autoload)) {
    require_once $stripe_autoload;
  } else {
    add_action("admin_notices", function () use ($stripe_autoload) {
      ?>
            <div class="notice notice-error">
                <p><?php printf(
                  esc_html__(
                    "Simple Invoices & Payments: Stripe PHP library not found at: %s",
                    "custom-invoice-system"
                  ),
                  "<code>" . esc_html($stripe_autoload) . "</code>"
                ); ?></p>
            </div>
            <?php
    });
    return;
  }

  // Load core plugin files
  $required_files = [
    "includes/class-database.php",
    "includes/class-invoice-manager.php",
    "includes/class-late-fees.php",
    "includes/class-stripe-settings.php",
    "includes/class-recurring-invoice-handler.php",
    "includes/class-email-templates.php",
    "includes/class-email-manager.php",
    "includes/class-email-settings.php",
  ];

  foreach ($required_files as $file) {
    $file_path = dirname(__FILE__) . "/" . $file;
    if (file_exists($file_path)) {
      require_once $file_path;
    } else {
      add_action("admin_notices", function () use ($file) {
        ?>
                <div class="notice notice-error">
                    <p><?php printf(
                      esc_html__(
                        "Simple Invoices & Payments: Required file not found: %s",
                        "custom-invoice-system"
                      ),
                      "<code>" . esc_html($file) . "</code>"
                    ); ?></p>
                </div>
                <?php
      });
    }
  }
}

/**
 * Plugin activation hook
 *
 * @return void
 */
function cis_activate(): void
{
  // Load dependencies first
  cis_load_dependencies();

  // Create SSL cache directory
  $ssl_cache_dir = WP_CONTENT_DIR . "/cache/cis-ssl/";
  if (!file_exists($ssl_cache_dir)) {
    wp_mkdir_p($ssl_cache_dir);
  }

  if (class_exists("CIS_Database")) {
    CIS_Database::create_tables();
    CIS_Database::upgrade_database();

    // Set up daily cron events
    if (!wp_next_scheduled("check_late_fees_daily")) {
      wp_schedule_event(time(), "daily", "check_late_fees_daily");
    }

    if (!wp_next_scheduled("cis_process_recurring_invoices")) {
      wp_schedule_event(
        strtotime("tomorrow midnight"),
        "daily",
        "cis_process_recurring_invoices"
      );
    }

    // Set default options
    cis_set_default_options();

    // Flush rewrite rules for REST API endpoints
    flush_rewrite_rules();
  }
}
register_activation_hook(__FILE__, "cis_activate");

/**
 * Plugin deactivation hook
 *
 * @return void
 */
function cis_deactivate(): void
{
  // Clear scheduled hooks
  wp_clear_scheduled_hook("check_late_fees_daily");
  wp_clear_scheduled_hook("cis_process_recurring_invoices");
  wp_clear_scheduled_hook("check_due_date_reminders");

  // Flush rewrite rules
  flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, "cis_deactivate");

/**
 * Set default plugin options
 *
 * @return void
 */
function cis_set_default_options(): void
{
  // Set default convenience fee if not set
  if (get_option("convenience_fee_percentage") === false) {
    update_option("convenience_fee_percentage", "0");
  }

  // Set default ACH fee if not set
  if (get_option("ach_fee_amount") === false) {
    update_option("ach_fee_amount", "0");
  }

  // Set default checkout settings
  if (get_option("checkout_name") === false) {
    update_option("checkout_name", get_bloginfo("name"));
  }

  if (get_option("checkout_color") === false) {
    update_option("checkout_color", "#0073aa");
  }
}

/**
 * Enhanced initialization with SSL certificate handling
 *
 * @return void
 */
function cis_init(): void
{
  // Load text domain for translations
  load_plugin_textdomain(
    "custom-invoice-system",
    false,
    dirname(plugin_basename(__FILE__)) . "/languages"
  );

  // Initialize Stripe with proper SSL handling
  $stripe_key = get_option("stripe_secret_key");
  if (!empty($stripe_key) && class_exists("\Stripe\Stripe")) {
    try {
      \Stripe\Stripe::setApiKey($stripe_key);

      // Set API version for compatibility
      \Stripe\Stripe::setApiVersion("2023-10-16");

      // Enhanced SSL certificate handling
      cis_configure_stripe_ssl();

      // Set app info for better debugging in Stripe dashboard
      \Stripe\Stripe::setAppInfo(
        "Simple Invoices & Payments",
        CIS_PLUGIN_VERSION,
        site_url()
      );
    } catch (Exception $e) {
      cis_log_error("CIS Error initializing Stripe: " . $e->getMessage());
      add_action("admin_notices", function () use ($e) {
        ?>
                <div class="notice notice-error">
                    <p><?php printf(
                      esc_html__(
                        "Simple Invoices & Payments: Error initializing Stripe: %s",
                        "custom-invoice-system"
                      ),
                      esc_html($e->getMessage())
                    ); ?></p>
                    <p><?php printf(
                      esc_html__(
                        "Please check your %s or contact support.",
                        "custom-invoice-system"
                      ),
                      '<a href="' .
                        admin_url("admin.php?page=stripe-settings") .
                        '">Stripe settings</a>'
                    ); ?></p>
                </div>
                <?php
      });
    }
  }

  // Instantiate core plugin classes
  if (class_exists("CIS_Database")) {
    new CIS_Database();
  }
  if (class_exists("CIS_Invoice_Manager")) {
    new CIS_Invoice_Manager();
  }
  if (class_exists("CIS_Late_Fees")) {
    new CIS_Late_Fees();
  }
  if (class_exists("CIS_Stripe_Settings")) {
    new CIS_Stripe_Settings();
  }
  if (class_exists("CIS_Recurring_Invoice_Handler")) {
    new CIS_Recurring_Invoice_Handler();
  }

  // Initialize email-related classes
  if (class_exists("CIS_Email_Templates")) {
    new CIS_Email_Templates();
  }
  if (class_exists("CIS_Email_Manager")) {
    new CIS_Email_Manager();
  }
  if (class_exists("CIS_Email_Settings")) {
    new CIS_Email_Settings();
  }
}

/**
 * Configure SSL certificates for Stripe API calls
 *
 * @return void
 */
function cis_configure_stripe_ssl(): void
{
  try {
    // Try to get and set the proper CA bundle path
    $ca_bundle_path = cis_get_ca_bundle_path();

    if ($ca_bundle_path && file_exists($ca_bundle_path)) {
      \Stripe\Stripe::setCABundlePath($ca_bundle_path);
      cis_log_error("CIS: Using CA bundle: " . $ca_bundle_path);
    } else {
      // Fallback: Use WordPress HTTP API wrapper for Stripe requests
      cis_log_error(
        "CIS: CA bundle not found, using WordPress HTTP API wrapper"
      );
      cis_setup_wordpress_http_wrapper();
    }

    // Enable SSL verification (this is the default, but being explicit)
    \Stripe\Stripe::setVerifySslCerts(true);
  } catch (Exception $e) {
    cis_log_error("CIS SSL Configuration Error: " . $e->getMessage());

    // In development, we can be more lenient with SSL
    if (defined("WP_DEBUG") && WP_DEBUG && defined("CIS_ALLOW_INSECURE_SSL")) {
      cis_log_error("CIS: Warning - SSL verification disabled for development");
      \Stripe\Stripe::setVerifySslCerts(false);
    } else {
      // In production, use WordPress HTTP API wrapper as fallback
      cis_log_error(
        "CIS: Falling back to WordPress HTTP API wrapper due to SSL configuration issues"
      );
      cis_setup_wordpress_http_wrapper();
    }
  }
}

/**
 * Get the appropriate CA bundle path
 *
 * @return string|null Path to CA bundle file or null if not found
 */
function cis_get_ca_bundle_path(): ?string
{
  // Check multiple possible locations for CA bundle
  $possible_paths = [
    // Stripe's default path
    CIS_PLUGIN_PATH . "includes/stripe-php/data/ca-certificates.crt",

    // WordPress core certificates
    ABSPATH . "wp-includes/certificates/ca-bundle.crt",

    // System paths (including the one we downloaded)
    "/etc/ssl/certs/cacert.pem",
    "/etc/ssl/certs/ca-certificates.crt",
    "/etc/ssl/certs/ca-bundle.crt",
    "/etc/pki/tls/certs/ca-bundle.crt",
    "/usr/local/share/certs/ca-root-nss.crt",

    // PHP curl.cainfo setting
    ini_get("curl.cainfo"),
    ini_get("openssl.cafile"),

    // Download and cache from curl.se
    cis_get_cached_ca_bundle(),
  ];

  foreach ($possible_paths as $path) {
    if (!empty($path) && file_exists($path) && is_readable($path)) {
      return $path;
    }
  }

  return null;
}

/**
 * Download and cache CA bundle from curl.se
 *
 * @return string|null Path to cached CA bundle or null on failure
 */
function cis_get_cached_ca_bundle(): ?string
{
  $cache_dir = WP_CONTENT_DIR . "/cache/cis-ssl/";
  $ca_bundle_file = $cache_dir . "cacert.pem";

  // Check if cached file exists and is recent (less than 30 days old)
  if (
    file_exists($ca_bundle_file) &&
    time() - filemtime($ca_bundle_file) < 30 * DAY_IN_SECONDS
  ) {
    return $ca_bundle_file;
  }

  try {
    // Create cache directory if it doesn't exist
    if (!file_exists($cache_dir)) {
      wp_mkdir_p($cache_dir);
    }

    // Download CA bundle using WordPress HTTP API
    $response = wp_remote_get("https://curl.se/ca/cacert.pem", [
      "timeout" => 30,
      "sslverify" => false, // We need to download the certs first!
    ]);

    if (is_wp_error($response)) {
      cis_log_error(
        "CIS: Failed to download CA bundle: " . $response->get_error_message()
      );
      return null;
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
      cis_log_error("CIS: Empty CA bundle downloaded");
      return null;
    }

    // Verify the content looks like a certificate bundle
    if (strpos($body, "-----BEGIN CERTIFICATE-----") === false) {
      cis_log_error("CIS: Downloaded CA bundle appears invalid");
      return null;
    }

    // Save to cache
    $result = file_put_contents($ca_bundle_file, $body, LOCK_EX);
    if ($result === false) {
      cis_log_error("CIS: Failed to save CA bundle to cache");
      return null;
    }

    cis_log_error("CIS: Successfully downloaded and cached CA bundle");
    return $ca_bundle_file;
  } catch (Exception $e) {
    cis_log_error("CIS: Error downloading CA bundle: " . $e->getMessage());
    return null;
  }
}

/**
 * Set up WordPress HTTP API wrapper for Stripe requests
 * This is a fallback when CA bundle is not available
 *
 * @return void
 */
function cis_setup_wordpress_http_wrapper(): void
{
  // Only create the class if Stripe interface is available
  if (!interface_exists("\Stripe\HttpClient\ClientInterface")) {
    cis_log_error(
      "CIS: Stripe ClientInterface not available for WordPress HTTP wrapper"
    );
    return;
  }

  // Define the WordPress HTTP Client class dynamically
  if (!class_exists("CIS_WordPress_HTTP_Client")) {
    cis_define_wordpress_http_client();
  }

  // Override Stripe's HTTP client to use WordPress's HTTP API
  \Stripe\Stripe::setHttpClient(new CIS_WordPress_HTTP_Client());
}

/**
 * Define WordPress HTTP Client class after Stripe library is loaded
 *
 * @return void
 */
function cis_define_wordpress_http_client(): void
{
  // Only define if the interface exists
  if (!interface_exists("\Stripe\HttpClient\ClientInterface")) {
    return;
  }

  // Define the class
  eval('
    class CIS_WordPress_HTTP_Client implements \Stripe\HttpClient\ClientInterface {
        
        private $timeout = 80;
        
        public function request($method, $absUrl, $headers, $params, $hasFile) {
            $method = strtolower($method);
            
            // Prepare arguments for wp_remote_request
            $args = array(
                "method" => strtoupper($method),
                "timeout" => $this->timeout,
                "headers" => $headers,
                "sslverify" => true, // WordPress handles SSL verification properly
            );
            
            // Handle different HTTP methods
            if ($method === "post") {
                if ($hasFile) {
                    // For file uploads, we need to handle multipart data
                    $args["body"] = $this->formatMultipartParams($params);
                } else {
                    $args["body"] = http_build_query($params);
                }
            } elseif ($method === "get" && !empty($params)) {
                $absUrl = $absUrl . "?" . http_build_query($params);
            }
            
            // Make the request using WordPress HTTP API
            $response = wp_remote_request($absUrl, $args);
            
            if (is_wp_error($response)) {
                throw new \Stripe\Exception\ApiConnectionException(
                    "WordPress HTTP Error: " . $response->get_error_message()
                );
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $response_headers = wp_remote_retrieve_headers($response);
            
            return array($response_body, $response_code, $response_headers);
        }
        
        /**
         * Format multipart parameters (simplified implementation)
         * 
         * @param array $params Parameters to format
         * @return string Formatted multipart data
         */
        private function formatMultipartParams($params) {
            // For file uploads, we would need more sophisticated handling
            // For now, fall back to regular encoding
            return http_build_query($params);
        }
    }
    ');
}

/**
 * Test Stripe connectivity and SSL configuration
 *
 * @return array Test results
 */
function cis_test_stripe_connection(): array
{
  $results = [];

  try {
    $stripe_key = get_option("stripe_secret_key");
    if (empty($stripe_key)) {
      $results["error"] = "Stripe secret key not configured";
      return $results;
    }

    \Stripe\Stripe::setApiKey($stripe_key);

    // Test SSL configuration
    $ca_bundle_path = cis_get_ca_bundle_path();
    if ($ca_bundle_path) {
      \Stripe\Stripe::setCABundlePath($ca_bundle_path);
      $results["ca_bundle"] = $ca_bundle_path;
    } else {
      $results["ca_bundle"] = "Using WordPress HTTP API wrapper";
      cis_setup_wordpress_http_wrapper();
    }

    // Test TLS version support
    try {
      \Stripe\Balance::retrieve();
      $results["tls_test"] = "TLS 1.2+ supported - Connection successful";
      $results["success"] = true;
    } catch (\Stripe\Exception\ApiConnectionException $e) {
      $results["tls_test"] = "Connection failed: " . $e->getMessage();
      $results["success"] = false;
    }
  } catch (Exception $e) {
    $results["error"] = $e->getMessage();
    $results["success"] = false;
  }

  return $results;
}

/**
 * Load plugin dependencies and initialize
 *
 * @return void
 */
function cis_plugins_loaded(): void
{
  cis_load_dependencies();
  cis_init();
}
add_action("plugins_loaded", "cis_plugins_loaded");

/**
 * Enqueue admin scripts and styles
 *
 * @param string $hook Current admin page hook
 * @return void
 */
function cis_enqueue_admin_scripts(string $hook): void
{
  // Only load on our plugin pages - Updated to include stripe-settings
  if (
    strpos($hook, "manage-invoices") === false &&
    strpos($hook, "stripe-settings") === false &&
    strpos($hook, "invoice-email-settings") === false
  ) {
    return;
  }

  // Enqueue admin styles
  wp_enqueue_style(
    "cis-admin-style",
    CIS_PLUGIN_URL . "assets/css/admin-style.css",
    [],
    CIS_PLUGIN_VERSION
  );

  // Enqueue admin scripts
  wp_enqueue_script("jquery");
  wp_enqueue_script(
    "cis-admin-script",
    CIS_PLUGIN_URL . "assets/js/admin-script.js",
    ["jquery"],
    CIS_PLUGIN_VERSION,
    true
  );

  // Localize script with data
  wp_localize_script("cis-admin-script", "cisAdminData", [
    "ajaxUrl" => admin_url("admin-ajax.php"),
    "security" => wp_create_nonce("cis_admin_nonce"),
    "strings" => [
      "confirmDelete" => __(
        "Are you sure you want to delete this invoice? This action cannot be undone.",
        "custom-invoice-system"
      ),
      "processingText" => __("Processing...", "custom-invoice-system"),
      "errorGeneric" => __(
        "An error occurred. Please try again.",
        "custom-invoice-system"
      ),
    ],
  ]);
}
add_action("admin_enqueue_scripts", "cis_enqueue_admin_scripts");

/**
 * Enqueue frontend scripts and styles
 *
 * @return void
 */
function cis_enqueue_frontend_scripts(): void
{
  if (!is_page()) {
    return;
  }

  global $post;

  // Check if the page contains our shortcode
  if (!has_shortcode($post->post_content, "custom_profile")) {
    return;
  }

  // Enqueue Stripe.js
  wp_enqueue_script(
    "stripe-js",
    "https://js.stripe.com/v3/",
    [],
    null,
    false // Load in header for better performance
  );

  // Enqueue our Stripe integration script
  wp_enqueue_script(
    "cis-stripe-checkout",
    CIS_PLUGIN_URL . "assets/js/stripe-checkout.js",
    ["jquery", "stripe-js"],
    CIS_PLUGIN_VERSION,
    false
  );

  // Get payment settings
  $convenience_fee = get_option("convenience_fee_percentage", "0");
  $ach_fee = get_option("ach_fee_amount", "0");
  $enable_ach = get_option("enable_ach_payments") === "1";

  // Localize script with Stripe data
  wp_localize_script("cis-stripe-checkout", "stripeCheckoutData", [
    "publishableKey" => get_option("stripe_publishable_key"),
    "ajaxUrl" => admin_url("admin-ajax.php"),
    "nonce" => wp_create_nonce("stripe_checkout_payment"),
    "convenienceFeePercentage" => $convenience_fee,
    "achFeeAmount" => $ach_fee,
    "enableACH" => $enable_ach,
    "debug" => defined("WP_DEBUG") && WP_DEBUG,
    "strings" => [
      "processingPayment" => __(
        "Processing payment...",
        "custom-invoice-system"
      ),
      "paymentError" => __("Payment error:", "custom-invoice-system"),
      "unexpectedError" => __(
        "An unexpected error occurred",
        "custom-invoice-system"
      ),
    ],
  ]);
}
add_action("wp_enqueue_scripts", "cis_enqueue_frontend_scripts");

/**
 * Enhanced error logging with more context
 *
 * @param string $message Error message to log
 * @param mixed $data Optional data to log
 * @return void
 */
function cis_log_error(string $message, $data = null): void
{
  if (defined("WP_DEBUG_LOG") && WP_DEBUG_LOG) {
    $log_message = "CIS Plugin: " . $message;
    if ($data !== null) {
      $log_message .= " | Data: " . print_r($data, true);
    }

    // Also log system info for SSL errors
    if (
      strpos($message, "SSL") !== false ||
      strpos($message, "certificate") !== false
    ) {
      $log_message .= " | PHP Version: " . phpversion();
      $log_message .=
        " | cURL Version: " .
        (function_exists("curl_version") ? curl_version()["version"] : "N/A");
      $log_message .=
        " | OpenSSL Version: " .
        (defined("OPENSSL_VERSION_TEXT") ? OPENSSL_VERSION_TEXT : "N/A");
    }

    error_log($log_message);
  }
}

/**
 * Check if SSL verification should be enforced
 *
 * @return bool
 */
function cis_should_verify_ssl(): bool
{
  // Always verify SSL in production
  if (!defined("WP_DEBUG") || !WP_DEBUG) {
    return true;
  }

  // Allow disabling SSL verification only in local development
  if (defined("CIS_LOCAL_DEVELOPMENT") && CIS_LOCAL_DEVELOPMENT === true) {
    return false;
  }

  return true;
}

/**
 * Get plugin version
 *
 * @return string
 */
function cis_get_version(): string
{
  return CIS_PLUGIN_VERSION;
}

/**
 * Calculate next date based on unit and interval
 * Shared utility used by Invoice Manager and Recurring Invoice Handler.
 *
 * @param string $current_date Current date in Y-m-d format
 * @param string $unit Time unit (day, week, month, year)
 * @param int $interval Number of units
 * @return string Next date in Y-m-d format
 * @throws Exception If unit is invalid
 */
function cis_calculate_next_date(
  string $current_date,
  string $unit,
  int $interval
): string {
  $date = new DateTime($current_date);

  switch ($unit) {
    case "day":
      $date->modify("+{$interval} days");
      break;
    case "week":
      $date->modify("+{$interval} weeks");
      break;
    case "month":
      $date->modify("+{$interval} months");
      break;
    case "year":
      $date->modify("+{$interval} years");
      break;
    default:
      throw new Exception("Invalid recurring unit: " . $unit);
  }

  return $date->format("Y-m-d");
}

/**
 * Add SSL test to admin menu (for debugging)
 */
add_action("admin_menu", function () {
  if (defined("WP_DEBUG") && WP_DEBUG) {
    add_submenu_page(
      "manage-invoices",
      "SSL Test",
      "SSL Test",
      "manage_options",
      "cis-ssl-test",
      function () {
        echo '<div class="wrap">';
        echo "<h1>Stripe SSL Connection Test</h1>";

        if (isset($_POST["run_test"])) {
          if (
            !wp_verify_nonce($_POST["_cis_ssl_nonce"] ?? "", "cis_ssl_test")
          ) {
            echo '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
          } else {
            $results = cis_test_stripe_connection();
            echo '<div class="notice notice-' .
              ($results["success"] ? "success" : "error") .
              '">';
            echo "<h3>Test Results:</h3>";
            foreach ($results as $key => $value) {
              echo "<p><strong>" .
                esc_html($key) .
                ":</strong> " .
                esc_html($value) .
                "</p>";
            }
            echo "</div>";
          }
        }

        echo '<form method="post">';
        wp_nonce_field("cis_ssl_test", "_cis_ssl_nonce");
        echo "<p>This will test your Stripe SSL configuration and connection.</p>";
        submit_button("Run SSL Test", "primary", "run_test");
        echo "</form>";
        echo "</div>";
      }
    );
  }
});

/**
 * Plugin uninstall hook
 * This should be in a separate uninstall.php file for production
 */
register_uninstall_hook(__FILE__, "cis_uninstall");

/**
 * Clean up plugin data on uninstall
 *
 * @return void
 */
function cis_uninstall(): void
{
  if (!defined("WP_UNINSTALL_PLUGIN")) {
    return;
  }

  // Only delete data if option is set
  if (get_option("cis_delete_data_on_uninstall") === "yes") {
    global $wpdb;

    // Drop custom tables
    $tables = [
      $wpdb->prefix . "custom_invoices",
      $wpdb->prefix . "custom_invoice_payments",
    ];

    foreach ($tables as $table) {
      $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

    // Delete options
    $options = [
      "stripe_publishable_key",
      "stripe_secret_key",
      "stripe_webhook_secret",
      "convenience_fee_percentage",
      "ach_fee_amount",
      "enable_ach_payments",
      "checkout_name",
      "checkout_logo_url",
      "checkout_color",
      "late_fee_enabled",
      "late_fee_rules",
      "cis_email_options",
      "cis_recurring_migration_v2_completed",
      "cis_delete_data_on_uninstall",
    ];

    foreach ($options as $option) {
      delete_option($option);
    }

    // Clear scheduled hooks
    wp_clear_scheduled_hook("check_late_fees_daily");
    wp_clear_scheduled_hook("cis_process_recurring_invoices");
    wp_clear_scheduled_hook("check_due_date_reminders");

    // Clean up SSL cache directory
    $ssl_cache_dir = WP_CONTENT_DIR . "/cache/cis-ssl/";
    if (file_exists($ssl_cache_dir)) {
      $files = glob($ssl_cache_dir . "*");
      foreach ($files as $file) {
        if (is_file($file)) {
          unlink($file);
        }
      }
      rmdir($ssl_cache_dir);
    }
  }
}
