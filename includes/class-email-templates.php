<?php
class CIS_Email_Templates {
	private $option_name = 'cis_email_options';

	public function __construct() {
		// Set default templates if they don't exist
		add_action('admin_init', array($this, 'maybe_set_default_templates'));
	}

	public function maybe_set_default_templates() {
		$options = get_option($this->option_name);
		if (!$options) {
			$this->set_default_templates();
		}
	}

	private function set_default_templates() {
		$default_options = array(
			'sender_name' => get_bloginfo('name'),
			'enable_new_invoice' => 1,
			'enable_payment_received' => 1,
			'enable_due_soon_14' => 1,
			'enable_due_soon_7' => 1,
			'enable_due_soon_3' => 1,
			'enable_late_fee' => 1,
			'template_new_invoice' => $this->get_default_new_invoice_template(),
			'template_payment_received' => $this->get_default_payment_template(),
			'template_due_soon' => $this->get_default_due_soon_template(),
			'template_late_fee' => $this->get_default_late_fee_template(),
			'email_styles' => $this->get_default_styles()
		);

		update_option($this->option_name, $default_options);
	}

	public function get_template($template_type) {
		$options = get_option($this->option_name);
		$template_key = 'template_' . $template_type;
		
		if (isset($options[$template_key])) {
			return $options[$template_key];
		}
		
		// Return default template if saved template not found
		$method_name = 'get_default_' . $template_type . '_template';
		if (method_exists($this, $method_name)) {
			return $this->$method_name();
		}
		
		return '';
	}

	public function get_styles() {
		$options = get_option($this->option_name);
		return isset($options['email_styles']) ? $options['email_styles'] : $this->get_default_styles();
	}

	public function is_notification_enabled($type) {
		$options = get_option($this->option_name);
		return isset($options['enable_' . $type]) && $options['enable_' . $type] == 1;
	}

	public function get_sender_name() {
		$options = get_option($this->option_name);
		return isset($options['sender_name']) ? $options['sender_name'] : get_bloginfo('name');
	}

	private function get_default_new_invoice_template() {
		return '
<div class="invoice-header">
	<h2>New Invoice Created</h2>
</div>
<div class="invoice-details">
	<p>Dear {user_name},</p>
	<p>A new invoice has been created for your account:</p>
	<p><strong>Invoice Number:</strong> {invoice_number}</p>
	<p><strong>Amount:</strong> <span class="amount">${amount}</span></p>
	<p><strong>Due Date:</strong> <span class="due-date">{due_date}</span></p>
	<p><strong>Description:</strong> {description}</p>
</div>
<div class="footer">
	<p>Thank you for your business!</p>
	<p>If you have any questions, please don\'t hesitate to contact us.</p>
</div>';
	}

	private function get_default_payment_template() {
		return '
<div class="invoice-header">
	<h2>Payment Received</h2>
</div>
<div class="invoice-details">
	<p>Dear {user_name},</p>
	<p>We have received your payment for invoice #{invoice_number}.</p>
	<p><strong>Amount Paid:</strong> <span class="amount">${amount}</span></p>
	<p><strong>Payment Date:</strong> {payment_date}</p>
	<p>Thank you for your payment!</p>
</div>
<div class="footer">
	<p>This is your payment confirmation. Please keep it for your records.</p>
	<p>If you have any questions about this payment, please contact us.</p>
</div>';
	}

	private function get_default_due_soon_template() {
		return '
<div class="invoice-header">
	<h2>Invoice Payment Reminder</h2>
</div>
<div class="invoice-details">
	<p>Dear {user_name},</p>
	<p>This is a reminder that invoice #{invoice_number} is due in {days_until_due} days.</p>
	<p><strong>Amount Due:</strong> <span class="amount">${amount}</span></p>
	<p><strong>Due Date:</strong> <span class="due-date">{due_date}</span></p>
	<p><strong>Description:</strong> {description}</p>
	<p>Please ensure timely payment to avoid any late fees.</p>
</div>
<div class="footer">
	<p>If you have already made the payment, please disregard this reminder.</p>
	<p>For any questions about this invoice, please contact us.</p>
</div>';
	}

	private function get_default_late_fee_template() {
		return '
<div class="invoice-header">
	<h2>Late Fee Notice</h2>
</div>
<div class="invoice-details">
	<p>Dear {user_name},</p>
	<p>A late fee has been applied to invoice #{invoice_number} due to delayed payment.</p>
	<p><strong>Original Amount:</strong> ${original_amount}</p>
	<p><strong>Late Fee Amount:</strong> <span class="amount">${late_fee_amount}</span></p>
	<p><strong>Total Amount Due:</strong> <span class="amount">${total_amount}</span></p>
	<p><strong>Original Due Date:</strong> <span class="due-date">{original_due_date}</span></p>
	<p>Please make the payment as soon as possible to avoid additional fees.</p>
</div>
<div class="footer">
	<p>If you have any questions about this late fee or need to discuss payment arrangements, please contact us immediately.</p>
</div>';
	}
	private function get_default_admin_payment_template() {
		return '
	<div class="invoice-header">
		<h2>Payment Received Notification</h2>
	</div>
	<div class="invoice-details">
		<p>A payment has been received for invoice #{invoice_number}.</p>
		<p><strong>User:</strong> {user_name} ({user_email})</p>
		<p><strong>Amount Paid:</strong> <span class="amount">${amount}</span></p>
		<p><strong>Original Amount:</strong> ${invoice_amount}</p>
		<p><strong>Convenience Fee:</strong> ${convenience_fee}</p>
		<p><strong>Payment Date:</strong> {payment_date}</p>
		<p><strong>Description:</strong> {description}</p>
		<p><strong>Transaction ID:</strong> {transaction_id}</p>
	</div>';
	}

	private function get_default_styles() {
		return '
body {
	font-family: Arial, sans-serif;
	line-height: 1.6;
	color: #333333;
	margin: 0;
	padding: 20px;
}
.invoice-header {
	background-color: #f8f9fa;
	padding: 20px;
	margin-bottom: 20px;
	border-bottom: 2px solid #dee2e6;
}
.invoice-header h2 {
	margin: 0;
	color: #004085;
}
.invoice-details {
	background-color: #ffffff;
	padding: 20px;
	margin-bottom: 20px;
}
.amount {
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
}
strong {
	color: #495057;
}';
	}

	public function parse_template($template_content, $variables) {
		foreach ($variables as $key => $value) {
			$template_content = str_replace('{' . $key . '}', $value, $template_content);
		}
		return $template_content;
	}

	public function wrap_template_with_styles($template_content) {
		$styles = $this->get_styles();
		return '
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<style>
		' . $styles . '
	</style>
</head>
<body>
	' . $template_content . '
</body>
</html>';
	}
}