# Jify Shipping

> Quantity-based shipping rules and a manual quote workflow for complex WooCommerce carts.

![License: GPLv2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)
![WordPress 5.8+](https://img.shields.io/badge/WordPress-5.8%2B-21759b)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![Stable](https://img.shields.io/badge/stable-3.9.2-brightgreen)

Jify Shipping is designed for WooCommerce stores whose shipping rules cannot be expressed as a single flat rate. It supports quantity ranges, variation-level configuration, mixed-cart detection, customer guidance, and a pending-quote workflow for orders that require manual handling.

## The Problem

Many stores sell products with very different fulfillment constraints. A single cart may contain standard items, bulky products, made-to-order goods, or combinations that require a human to calculate freight.

Jify Shipping turns that operational exception into an explicit workflow instead of silently returning an incorrect shipping price.

## Key Features

- **Quantity-based rules** — define shipping prices by quantity range for individual products or variations.
- **Variation support** — configure different rules for different product variants.
- **Mixed-cart detection** — identify carts that combine special-shipping and standard products.
- **Pending quote workflow** — pause checkout while an administrator prepares a custom shipping quote.
- **Customer guidance** — show clear notices explaining why a quote is required and what happens next.
- **Checkout control** — optionally disable order submission until a valid quote is available.
- **Administrator diagnostics** — expose cart hash, quote state, and available rates to authorized store managers.
- **Email notification flow** — allow customers to notify the store when a manual quote is needed.

## Example Rule

```text
Product: Industrial Storage Box

1–5 units   → NT$100
6–10 units  → NT$150
11+ units   → Manual quote
```

A cart that mixes this product with another special-shipping item can be placed into the pending-quote flow instead of applying an unreliable automatic total.

## Workflow

```text
Customer adds products
        ↓
Jify Shipping evaluates product and variation rules
        ↓
Standard cart? ── Yes ──→ Return calculated shipping rate
        │
        No
        ↓
Mark cart as pending quote
        ↓
Show customer guidance and notify administrator
        ↓
Administrator records a custom quote
        ↓
WooCommerce rate cache is refreshed
        ↓
Customer completes checkout with the quoted amount
```

## Architecture

The current plugin is implemented as a WooCommerce extension that integrates with:

- Product-data tabs for rule configuration.
- Cart validation and mixed-product detection.
- WooCommerce shipping methods and package-rate filters.
- Session state for pending quotes.
- Cart-hash based quote lookup.
- Checkout button and customer-notification controls.
- Administrator notices and diagnostics.

```text
Product configuration
├── product rules
├── variation rules
└── mixed-shipping flags

Runtime
├── cart evaluator
├── shipping-rate calculator
├── pending-quote state
├── quote lookup by cart hash
└── checkout controls

Operations
├── admin notices
├── customer notification
├── quote management
└── manager-only diagnostics
```

## Installation

1. Upload the plugin to `/wp-content/plugins/jify-shipping`, or install it through the WordPress Plugins screen.
2. Activate **Jify Shipping**.
3. Open a WooCommerce product.
4. Configure rules under **Product Data → Jify Shipping**.
5. Test simple, variable, and mixed-product carts before enabling the workflow in production.

## Verification Checklist

Before deploying to a live store, verify:

- Each configured quantity boundary returns the expected rate.
- Product variations use the intended rule.
- Mixed carts enter the pending-quote state.
- Standard carts can still proceed normally.
- Checkout is blocked only when configured to do so.
- A saved quote replaces the pending state and refreshes the displayed rate.
- Store-manager diagnostics are not visible to ordinary customers.
- Customer and administrator emails reach the expected recipients.

## Current Status

- Stable plugin metadata: `3.9.2`.
- Main plugin header currently reports `3.9.0`; version metadata should be synchronized in a future maintenance release.
- The project is suitable for controlled production use after store-specific checkout and email testing.
- The current implementation intentionally contains both shipping logic and operational workflow code; future refactoring may separate the rule evaluator, quote store, shipping method, and admin UI.

## Known Limitations

- Manual quoting depends on store operations; the plugin cannot guarantee response time from administrators.
- Theme and checkout customization can affect notices and button behavior.
- Complex shipping-plugin combinations may require compatibility testing.
- The quote flow is based on the current cart state; material cart changes should invalidate or refresh the quote.
- No claim is made that the default configuration fits every carrier, jurisdiction, or fulfillment process.

## Contributor Opportunities

Useful contribution areas include:

- Extracting the quantity-range rule evaluator into a testable component.
- Improving automated WooCommerce compatibility tests.
- Adding fixtures for mixed-cart edge cases.
- Separating quote persistence from checkout presentation.
- Expanding localization and email templates.
- Adding structured import and export for shipping rules.

## Related Projects

- [Jify Cloud Website](https://github.com/yves01480/jify-cloud-website)
- [Jify Loyalty](https://github.com/yves01480/jify-loyalty)
- [Jify Discount](https://github.com/yves01480/jify-discount)
- [Jify Taxes](https://github.com/yves01480/jify-taxes)

Full product documentation and demos: <https://jify.cloud>

The canonical WordPress plugin metadata lives in [`readme.txt`](readme.txt).

## Ownership

I designed and implemented the product workflow, WooCommerce integration, shipping-rule behavior, mixed-cart handling, operational diagnostics, and release packaging.

AI coding tools are used in parts of the development workflow. Product decisions, architecture, integration, review, deployment, and acceptance criteria remain my responsibility.

## License

GPL-2.0-or-later — see [`license.txt`](license.txt).
