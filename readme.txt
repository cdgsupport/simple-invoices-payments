=== Custom Invoice System ===
Contributors: yourname
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
License: GPLv2 or later

Custom user profile page with invoice management and flexible late fee system.

== Description ==

Creates a custom profile page for users to view and pay invoices, with admin functionality to manage invoices and configure late fees.

Features:
* Custom user profile page with invoice list
* Stripe payment integration
* Flexible late fee system (flat, percentage, or progressive)
* Email notifications for late fees
* Admin dashboard for invoice management

== Installation ==

1. Upload 'custom-invoice-system' to the '/wp-content/plugins/' directory
2. Activate the plugin through WordPress admin
3. Add Stripe API keys in WordPress settings
4. Create a page and add shortcode [custom_profile]

== Configuration ==

Stripe Setup:
1. Sign up for Stripe account
2. Get API keys from Stripe Dashboard
3. Add to WordPress:
   - Publishable key: Add to WordPress settings
   - Secret key: Add to WordPress settings

Late Fee Configuration:
1. Go to Invoices in admin menu
2. Enable late fees
3. Configure rules:
   - Flat: Fixed amount
   - Percentage: Based on invoice amount
   - Progressive: Weekly increasing amount

== Changelog ==

= 1.0 =
* Initial release