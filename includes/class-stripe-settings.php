<?php
class CIS_Stripe_Settings {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'register_late_fee_settings'));
    }
    
    public function add_menu() {
        add_submenu_page(
            'manage-invoices',
            'Payment Settings',
            'Payment Settings',
            'manage_options',
            'stripe-settings',
            array($this, 'render_page')
        );
    }
    
    /**
     * Register settings for the Stripe settings page
     */
    public function register_settings() {
        // API Credentials
        register_setting('cis_stripe_settings', 'stripe_publishable_key');
        register_setting('cis_stripe_settings', 'stripe_secret_key');
        register_setting('cis_stripe_settings', 'stripe_webhook_secret');
        
        // Payment Settings
        register_setting('cis_stripe_settings', 'convenience_fee_percentage');
        
        // ACH Settings - Fixed registration
        register_setting('cis_stripe_settings', 'enable_ach_payments');
        register_setting('cis_stripe_settings', 'ach_fee_amount');
        
        // Checkout Settings
        register_setting('cis_stripe_settings', 'checkout_name', array(
            'default' => get_bloginfo('name'),
            'sanitize_callback' => 'sanitize_text_field',
        ));
        
        register_setting('cis_stripe_settings', 'checkout_logo_url', array(
            'sanitize_callback' => 'esc_url_raw',
        ));
        
        register_setting('cis_stripe_settings', 'checkout_color', array(
            'default' => '#0073aa',
            'sanitize_callback' => 'sanitize_hex_color',
        ));
    }

    /**
     * Register Late Fee settings (keeping separate option group)
     */
    public function register_late_fee_settings() {
        register_setting('late_fee_settings', 'late_fee_enabled');
        register_setting('late_fee_settings', 'late_fee_rules');
    }
    
    public function render_page() {
        ?>
        <div class="wrap">
            <h1>Payment Settings</h1>
            
            <!-- Stripe Settings Form -->
            <form method="post" action="options.php" id="stripe-settings-form">
                <?php settings_fields('cis_stripe_settings'); ?>
                
                <h2>Stripe API Credentials</h2>
                <table class="form-table">
                    <tr>
                        <th>Publishable Key</th>
                        <td>
                            <input type="text" name="stripe_publishable_key" 
                                value="<?php echo esc_attr(get_option('stripe_publishable_key')); ?>" 
                                class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Secret Key</th>
                        <td>
                            <input type="password" name="stripe_secret_key" 
                                value="<?php echo esc_attr(get_option('stripe_secret_key')); ?>" 
                                class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Webhook Secret</th>
                        <td>
                            <input type="password" name="stripe_webhook_secret" 
                                value="<?php echo esc_attr(get_option('stripe_webhook_secret')); ?>" 
                                class="regular-text">
                            <p class="description">Used to verify incoming webhooks from Stripe</p>
                        </td>
                    </tr>
                </table>
                
                <h2>Payment Methods</h2>
                <table class="form-table">
                    <tr>
                        <th>Enable ACH Payments</th>
                        <td>
                            <input type="checkbox" name="enable_ach_payments" 
                                   value="1" <?php checked('1', get_option('enable_ach_payments')); ?>>
                            <p class="description">Allow customers to pay with bank account (ACH) payments.<br>
                            Stripe will handle verification automatically.</p>
                        </td>
                    </tr>
                </table>
                
                <h2>Fee Settings</h2>
                <table class="form-table">
                    <tr>
                        <th>Credit Card Fee (%)</th>
                        <td>
                            <input type="number" name="convenience_fee_percentage" 
                                value="<?php echo esc_attr(get_option('convenience_fee_percentage', '0')); ?>" 
                                class="regular-text" 
                                step="0.01" 
                                min="0" 
                                max="100">
                            <p class="description">Enter the percentage that will be added to card payments (e.g., 2.9 for 2.9%)</p>
                        </td>
                    </tr>
                    <tr>
                        <th>ACH Fee (flat amount)</th>
                        <td>
                            <input type="number" name="ach_fee_amount" 
                                value="<?php echo esc_attr(get_option('ach_fee_amount', '0')); ?>" 
                                class="regular-text" 
                                step="0.01" 
                                min="0">
                            <p class="description">Enter the flat amount in dollars to charge for ACH payments (typically $0.75 to $1.00)</p>
                        </td>
                    </tr>
                </table>
                
                <h2>Checkout Appearance</h2>
                <table class="form-table">
                    <tr>
                        <th>Business Name</th>
                        <td>
                            <input type="text" name="checkout_name" 
                                   value="<?php echo esc_attr(get_option('checkout_name', get_bloginfo('name'))); ?>" 
                                   class="regular-text">
                            <p class="description">The name that appears on the Stripe Checkout page</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Logo URL</th>
                        <td>
                            <input type="url" name="checkout_logo_url" 
                                   value="<?php echo esc_attr(get_option('checkout_logo_url')); ?>" 
                                   class="regular-text">
                            <p class="description">Your logo to display on the checkout page (min. 128x128px)</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Brand Color</th>
                        <td>
                            <input type="color" name="checkout_color" 
                                   value="<?php echo esc_attr(get_option('checkout_color', '#0073aa')); ?>">
                            <p class="description">Primary brand color for buttons and elements on the checkout page</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Stripe Settings'); ?>
            </form>

            <hr style="margin: 40px 0;">

            <!-- Late Fee Settings Form -->
            <form method="post" action="options.php" id="late-fee-settings-form">
                <?php settings_fields('late_fee_settings'); ?>
                
                <h2>Late Fee Settings</h2>
                <table class="form-table">
                    <tr>
                        <td class="enable-fees-row">
                            <input type="checkbox" name="late_fee_enabled" id="late_fee_enabled" value="1" 
                                <?php checked(1, get_option('late_fee_enabled'), true); ?>>
                            <label for="late_fee_enabled">Enable Late Fees</label>
                        </td>
                    </tr>
                </table>
                
                <div id="late-fee-rules">
                    <h3>Late Fee Rules</h3>
                    <?php 
                    $rules = get_option('late_fee_rules', array());
                    if (empty($rules)) {
                        $rules = array(array(
                            'days' => 30,
                            'type' => 'flat',
                            'amount' => 30,
                            'max_amount' => 0
                        ));
                    }
                    foreach ($rules as $index => $rule): 
                    ?>
                    <div class="late-fee-rule">
                        <h4>Rule #<?php echo ($index + 1); ?></h4>
                        <button type="button" class="button remove-rule">Remove Rule</button>
                        <table class="form-table">
                            <tr>
                                <th>Days Overdue</th>
                                <td>
                                    <input type="number" name="late_fee_rules[<?php echo $index; ?>][days]" 
                                        value="<?php echo esc_attr($rule['days']); ?>" min="1" required>
                                </td>
                            </tr>
                            <tr>
                                <th>Fee Type</th>
                                <td>
                                    <select name="late_fee_rules[<?php echo $index; ?>][type]" class="fee-type-select">
                                        <option value="flat" <?php selected($rule['type'], 'flat'); ?>>Flat Fee</option>
                                        <option value="percentage" <?php selected($rule['type'], 'percentage'); ?>>Percentage</option>
                                        <option value="progressive" <?php selected($rule['type'], 'progressive'); ?>>Progressive</option>
                                    </select>
                                    <div class="fee-type-description">
                                        <p class="description flat-desc" style="display: none;">A one-time fixed fee applied after the specified number of days.</p>
                                        <p class="description percentage-desc" style="display: none;">A one-time percentage of the original invoice amount applied after the specified number of days.</p>
                                        <p class="description progressive-desc" style="display: none;">A fee that increases monthly until the maximum amount is reached (if set). Starts after the specified number of days.</p>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th class="amount-label">Amount</th>
                                <td>
                                    <input type="number" name="late_fee_rules[<?php echo $index; ?>][amount]" 
                                        value="<?php echo esc_attr($rule['amount']); ?>" step="0.01" min="0" required>
                                    <span class="amount-type"></span>
                                    <p class="description amount-description"></p>
                                </td>
                            </tr>
                            <tr class="max-amount-row">
                                <th>Maximum Amount</th>
                                <td>
                                    <input type="number" name="late_fee_rules[<?php echo $index; ?>][max_amount]" 
                                        value="<?php echo esc_attr($rule['max_amount']); ?>" step="0.01" min="0">
                                    <p class="description">Maximum total fee (0 for no limit)</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="fee-controls">
                    <button type="button" class="button add-rule">Add New Rule</button>
                </div>
                
                <?php submit_button('Save Late Fee Settings'); ?>
            </form>
        </div>
        <?php
    }
}