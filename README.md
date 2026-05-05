# Jify Shipping

> Advanced quantity-based shipping manager with mixed product quotes for WooCommerce.

![License: GPLv2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)
![WordPress 5.8+](https://img.shields.io/badge/WordPress-5.8%2B-21759b)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![Stable](https://img.shields.io/badge/stable-3.9.2-brightgreen)

A WooCommerce plugin for stores that need granular shipping cost rules based on product quantities and complex mixed-cart scenarios — including a "Pending Quote" workflow for items that require manual quoting.

## Key Features

- **Quantity-based rules** — per product / variation, by quantity range (e.g. 1–5 items: $100, 6–10 items: $150)
- **Mixed product handling** — auto-detect special items and trigger a pending-quote workflow
- **Manual quote workflow** — admin reviews mixed carts and sends a custom shipping quote by email
- **Smart cart notices** — guide customers when mixed products are detected
- **Variation support** — different shipping rules per product variation
- **Checkout control** — optionally block checkout until a quote is provided

## Installation

1. Upload to `/wp-content/plugins/jify-shipping`, or install via the Plugins screen.
2. Activate from the **Plugins** screen.
3. Configure under **Product Data → Jify Shipping** for each product.

## Documentation

Full feature docs and demo: <https://jify.cloud>

The canonical plugin metadata for WordPress.org lives in [`readme.txt`](readme.txt).

## License

GPL-2.0-or-later — see [`license.txt`](license.txt).
