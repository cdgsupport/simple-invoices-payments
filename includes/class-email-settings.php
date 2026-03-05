<?php
class CIS_Email_Settings {
	private $option_group = 'cis_email_settings';
	private $option_name = 'cis_email_options';

	public function __construct() {
		add_action('admin_menu', array($this, 'add_menu_page'));
		add_action('admin_init', array($this, 'register_settings'));
	}

	public function add_menu_page() {
		add_submenu_page(
			'manage-invoices',
			'Email Settings',
			'Email Settings',
			'manage_options',
			'invoice-email-settings',
			array($this, 'render_settings_page')
		);
	}

	public function register_settings() {
		register_setting($this->option_group, $this->option_name);

		// General Email Settings Section
		add_settings_section(
			'cis_email_general',
			'General Email Settings',
			array($this, 'render_general_section'),
			'invoice-email-settings'
		);

		// Sender Name Field
		add_settings_field(
			'sender_name',
			'Sender Name',
			array($this, 'render_text_field'),
			'invoice-email-settings',
			'cis_email_general',
			array(
				'label_for' => 'sender_name',
				'field_name' => $this->option_name . '[sender_name]',
				'description' => 'Name that will appear as the sender of invoice emails'
			)
		);

		// Notification Controls Section
		add_settings_section(
			'cis_email_notifications',
			'Email Notifications',
			array($this, 'render_notifications_section'),
			'invoice-email-settings'
		);

		// Enable/Disable Fields
		$notification_types = array(
			'new_invoice' => 'New Invoice Notifications',
			'payment_received' => 'Payment Received Notifications',
			'due_soon_14' => '14 Days Due Reminder',
			'due_soon_7' => '7 Days Due Reminder',
			'due_soon_3' => '3 Days Due Reminder',
			'late_fee' => 'Late Fee Notifications',
			'admin_payment' => 'Admin Payment Notifications'
		);

		foreach ($notification_types as $key => $label) {
			add_settings_field(
				"enable_{$key}",
				$label,
				array($this, 'render_checkbox_field'),
				'invoice-email-settings',
				'cis_email_notifications',
				array(
					'label_for' => "enable_{$key}",
					'field_name' => $this->option_name . "[enable_{$key}]",
					'description' => "Enable {$label}"
				)
			);
		}

		// Email Template Settings Section
		add_settings_section(
			'cis_email_templates',
			'Email Templates',
			array($this, 'render_templates_section'),
			'invoice-email-settings'
		);

		// Template Fields
		$templates = array(
			'new_invoice' => 'New Invoice Email Template',
			'payment_received' => 'Payment Received Email Template',
			'due_soon' => 'Due Soon Reminder Template',
			'late_fee' => 'Late Fee Notification Template',
			'admin_payment' => 'Admin Payment Notification Template'
		);

		foreach ($templates as $key => $label) {
			add_settings_field(
				"template_{$key}",
				$label,
				array($this, 'render_template_field'),
				'invoice-email-settings',
				'cis_email_templates',
				array(
					'label_for' => "template_{$key}",
					'field_name' => $this->option_name . "[template_{$key}]",
					'description' => "Template for {$label}. Available variables: {invoice_number}, {amount}, {due_date}, {description}, {user_name}"
				)
			);
		}

		// Email Styling Section
		add_settings_section(
			'cis_email_styling',
			'Email Styling',
			array($this, 'render_styling_section'),
			'invoice-email-settings'
		);

		// Style Fields
		add_settings_field(
			'email_styles',
			'Global Email Styles',
			array($this, 'render_styles_field'),
			'invoice-email-settings',
			'cis_email_styling',
			array(
				'label_for' => 'email_styles',
				'field_name' => $this->option_name . '[email_styles]',
				'description' => 'CSS styles to be applied to all invoice emails'
			)
		);
	}

	public function render_settings_page() {
		if (!current_user_can('manage_options')) {
			wp_die('You do not have sufficient permissions to access this page.');
		}
		?>
		<div class="wrap">
			<h1>Invoice Email Settings</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields($this->option_group);
				do_settings_sections('invoice-email-settings');
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_general_section() {
		echo '<p>Configure general email settings for invoice notifications.</p>';
	}

	public function render_notifications_section() {
		echo '<p>Enable or disable different types of email notifications.</p>';
	}

	public function render_templates_section() {
		echo '<p>Customize email templates for different notification types. Use the available variables in your templates.</p>';
	}

	public function render_styling_section() {
		echo '<p>Configure the styling that will be applied to all invoice emails.</p>';
	}

	public function render_text_field($args) {
		$options = get_option($this->option_name);
		$field_id = $args['label_for'];
		$field_name = $args['field_name'];
		$value = isset($options[$field_id]) ? $options[$field_id] : '';
		?>
		<input type="text" 
			   id="<?php echo esc_attr($field_id); ?>" 
			   name="<?php echo esc_attr($field_name); ?>" 
			   value="<?php echo esc_attr($value); ?>" 
			   class="regular-text">
		<p class="description"><?php echo esc_html($args['description']); ?></p>
		<?php
	}

	public function render_checkbox_field($args) {
		$options = get_option($this->option_name);
		$field_id = $args['label_for'];
		$field_name = $args['field_name'];
		$checked = isset($options[$field_id]) ? $options[$field_id] : 0;
		?>
		<input type="checkbox" 
			   id="<?php echo esc_attr($field_id); ?>" 
			   name="<?php echo esc_attr($field_name); ?>" 
			   value="1" 
			   <?php checked(1, $checked); ?>>
		<p class="description"><?php echo esc_html($args['description']); ?></p>
		<?php
	}

	public function render_template_field($args) {
		$options = get_option($this->option_name);
		$field_id = $args['label_for'];
		$field_name = $args['field_name'];
		$value = isset($options[$field_id]) ? $options[$field_id] : '';
		?>
		<textarea id="<?php echo esc_attr($field_id); ?>" 
				  name="<?php echo esc_attr($field_name); ?>" 
				  rows="10" 
				  class="large-text code"><?php echo esc_textarea($value); ?></textarea>
		<p class="description"><?php echo esc_html($args['description']); ?></p>
		<?php
	}

	public function render_styles_field($args) {
		$options = get_option($this->option_name);
		$field_id = $args['label_for'];
		$field_name = $args['field_name'];
		$value = isset($options[$field_id]) ? $options[$field_id] : $this->get_default_styles();
		?>
		<textarea id="<?php echo esc_attr($field_id); ?>" 
				  name="<?php echo esc_attr($field_name); ?>" 
				  rows="10" 
				  class="large-text code"><?php echo esc_textarea($value); ?></textarea>
		<p class="description"><?php echo esc_html($args['description']); ?></p>
		<?php
	}

	private function get_default_styles() {
		return '
body {
	font-family: Arial, sans-serif;
	line-height: 1.6;
	color: #333333;
}
.invoice-header {
	background-color: #f8f9fa;
	padding: 20px;
	margin-bottom: 20px;
}
.invoice-details {
	margin-bottom: 20px;
}
.amount {
	font-size: 24px;
	color: #28a745;
	font-weight: bold;
}
.due-date {
	color: #dc3545;
	font-weight: bold;
}
.footer {
	margin-top: 30px;
	padding-top: 20px;
	border-top: 1px solid #dee2e6;
	font-size: 12px;
	color: #6c757d;
}';
	}
}