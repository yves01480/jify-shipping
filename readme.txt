=== Jify Shipping ===
Contributors: jifycloud
Tags: shipping, quantity, mixed products, quote, woocommerce
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 3.9.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Advanced quantity-based shipping manager with mixed product quotes and smart cart guidance for WooCommerce.

== Description ==

Jify Shipping is a robust shipping management plugin designed for WooCommerce stores that need granular control over shipping costs based on product quantities and complex mixed-cart scenarios.

It solves the "Mixed Products" shipping problem where certain combinations of items require manual quoting or special handling.

**Key Features:**

*   **Quantity-Based Rules**: Define shipping costs per product or variation based on quantity ranges (e.g., 1-5 items: $100, 6-10 items: $150).
*   **Mixed Product Handling**: Automatically detects when a cart contains a mix of special shipping items and standard items, triggering a "Pending Quote" workflow.
*   **Manual Quote Workflow**: Allows admins to review mixed carts and send a custom shipping quote directly to the customer via email.
*   **Smart Cart Notices**: Displays custom messages in the cart when mixed products are detected, guiding customers on the next steps.
*   **Variation Support**: Set different shipping rules for different product variations.
*   **Checkout Control**: Optionally disable the checkout button until a shipping quote is provided.

== Installation ==

1.  Upload the plugin files to the `/wp-content/plugins/jify-shipping` directory, or install the plugin through the WordPress plugins screen directly.
2.  Activate the plugin through the 'Plugins' screen in WordPress.
3.  Go to Product Data > Jify Shipping tab to configure rules for each product.

== Frequently Asked Questions ==

= Can I set different rules for variations? =
Yes, Jify Shipping fully supports product variations. You can define unique shipping cost rules for each variation.

= How does the mixed product quote work? =
When a customer adds products that are flagged as "Mixed Shipping" along with other items, the checkout can be paused. The admin receives a notification, calculates the custom shipping cost, and updates the order. The customer is then notified to complete payment.

== Changelog ==

= 3.9.0 =
*   Enhanced mixed product quote workflow.
*   Added support for admin notifications.
*   Improved cart validation logic.
