<?php
/**
 * Plugin Name: Jify Shipping
 * Description: Quantity-based shipping cost manager with dynamic UI, variation support, min-max range logic, smart cart guidance, and mixed products handling. Forces separate checkout.
 * Version: 3.9.0
 * Author: jify cloud
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

class Jify_Shipping_Upgrade {

    const OPTION_MIXED_MESSAGE = 'jify_shipping_mixed_products_message';
    const OPTION_PENDING_ORDERS = 'jify_shipping_mixed_pending_orders';
    const OPTION_MIXED_QUOTES = 'jify_shipping_mixed_quotes';
    const OPTION_NOTIFY_EMAIL = 'jify_shipping_notify_email';
    const MAX_PENDING_ENTRIES = 10;

    private static $pending_clear_notice = false;
    private static $mail_from = null;
    private static $mail_from_name = null;

    public static function init() {
        load_plugin_textdomain( 'jify-shipping', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        // UI
        add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_tab' ) );
        add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'add_product_data_panel' ) );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_tab_data' ) );

        // Logic
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'cart_validation' ), 10, 4 );
        add_action( 'woocommerce_shipping_init', array( __CLASS__, 'shipping_method_init' ) );
        add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'add_shipping_method' ) );
        add_filter( 'woocommerce_package_rates', array( __CLASS__, 'maybe_override_mixed_quote' ), 9999, 2 );
        add_filter( 'woocommerce_shipping_rate_label', array( __CLASS__, 'filter_shipping_rate_label' ), 9999, 2 );
        add_filter( 'woocommerce_shipping_rate_cost', array( __CLASS__, 'filter_shipping_rate_cost' ), 9999, 2 );
        add_filter( 'woocommerce_shipping_rate_cache_key', array( __CLASS__, 'add_quote_to_rate_cache_key' ), 10, 2 );
        add_action( 'woocommerce_before_cart', array( __CLASS__, 'refresh_mixed_pending_flag' ) );
        add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'refresh_mixed_pending_flag' ) );
        add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'render_cart_mixed_message' ) );
        add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'render_cart_mixed_message' ) );
        add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'render_cart_hash_debug' ) );
        add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'render_cart_hash_debug' ) );
        add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'render_cart_rates_debug' ) );
        add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'render_cart_rates_debug' ) );
        add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'render_cart_debug_summary' ) );
        add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'render_cart_debug_summary' ) );
        add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'render_checkout_notify_button' ) );
        add_filter( 'woocommerce_order_button_html', array( __CLASS__, 'maybe_disable_order_button' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_clear_pending_orders' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render_admin_pending_notice' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        add_action( 'wp', array( __CLASS__, 'override_proceed_to_checkout_button' ) );
        add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'custom_order_button_text' ) );
        add_filter( 'gettext', array( __CLASS__, 'force_translate_strings' ), 20, 3 );

        // AJAX (front-end checkout needs nopriv handlers)
        add_action( 'wp_ajax_jify_shipping_notify_admin', array( __CLASS__, 'handle_notify_admin' ) );
        add_action( 'wp_ajax_nopriv_jify_shipping_notify_admin', array( __CLASS__, 'handle_notify_admin' ) );
        add_action( 'wp_ajax_jify_shipping_get_quote', array( __CLASS__, 'handle_get_quote' ) );
        add_action( 'wp_ajax_nopriv_jify_shipping_get_quote', array( __CLASS__, 'handle_get_quote' ) );
    }

    public static function force_translate_strings( $translated_text, $text, $domain ) {
        switch ( $text ) {
            case 'Customer information':
                return '顧客資訊';
            case 'Have a coupon?':
                return '有優惠代碼嗎？';
            case 'Payment':
                return '付款方式';
            case 'Apply Store Credits Discounts':
                return '使用商店購物金折抵';
            case 'Click here to enter your code':
                return '點此輸入優惠碼';
            case 'If you have a coupon code, please apply it below.':
                return '如果您有優惠碼，請在下方輸入。';
        }
        return $translated_text;
    }

    public static function custom_order_button_text( $text ) {
        return __( '送出訂單', 'jify-shipping' );
    }
    
    public static function handle_empty_cart_request() {
        if ( isset( $_GET['jify_empty_cart'] ) && $_GET['jify_empty_cart'] == '1' ) {
            WC()->cart->empty_cart();
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }
    }

    public static function refresh_mixed_pending_flag() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
            return;
        }
        if ( self::has_mixed_jify_products() ) {
            $message = self::get_cart_mixed_message();
            WC()->session->set( 'jify_shipping_mixed_pending', array(
                'message' => $message,
                'hash' => self::get_current_cart_hash(),
            ) );
            self::maybe_refresh_quote_shipping_cache();
        } else {
            WC()->session->__unset( 'jify_shipping_mixed_pending' );
            WC()->session->__unset( 'jify_shipping_quote_applied' );
        }
    }

    public static function render_cart_mixed_message() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }
        if ( ! self::has_mixed_jify_products() ) {
            return;
        }
        $message = self::get_cart_mixed_message();
        if ( empty( $message ) ) {
            return;
        }
        printf(
            '<tr class="jify-mixed-products-notice"><td colspan="2"><div class="woocommerce-info">%s<br><small>%s</small></div></td></tr>',
            esc_html( $message ),
            esc_html__( '此訂單暫存中，客服人員將提供混合商品運費報價後再完成。', 'jify-shipping' )
        );
    }

    public static function render_cart_hash_debug() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $cart_hash = self::get_current_cart_hash();
        if ( $cart_hash === '' ) {
            return;
        }
        $quote = self::get_mixed_quote_for_cart( $cart_hash );
        $is_mixed = self::has_mixed_jify_products();
        printf(
            '<tr class="jify-cart-hash-debug"><td colspan="2"><small><strong>%s</strong> <code>%s</code> | <strong>%s</strong> %s | <strong>%s</strong> %s</small></td></tr>',
            esc_html__( 'Cart Hash (admin only):', 'jify-shipping' ),
            esc_html( $cart_hash ),
            esc_html__( 'Quote:', 'jify-shipping' ),
            $quote !== '' ? esc_html( $quote ) : '&mdash;',
            esc_html__( 'Mixed:', 'jify-shipping' ),
            $is_mixed ? esc_html__( 'yes', 'jify-shipping' ) : esc_html__( 'no', 'jify-shipping' )
        );
        ?>
        <script>
        (function() {
            var storageKey = 'jify_shipping_hide_debug';
            var hideDebug = function() {
                var rows = document.querySelectorAll('.jify-cart-hash-debug, .jify-cart-rates-debug');
                rows.forEach(function(row) {
                    row.style.display = 'none';
                });
            };
            var showDebug = function() {
                var rows = document.querySelectorAll('.jify-cart-hash-debug, .jify-cart-rates-debug');
                rows.forEach(function(row) {
                    row.style.display = '';
                });
            };
            var applyDebugVisibility = function() {
                if (window.localStorage && localStorage.getItem(storageKey) === '1') {
                    hideDebug();
                } else {
                    showDebug();
                }
            };
            applyDebugVisibility();
            window.addEventListener('storage', function(e) {
                if (e && e.key === storageKey) {
                    applyDebugVisibility();
                }
            });
        })();
        </script>
        <?php
    }

    public static function render_cart_rates_debug() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( ! function_exists( 'WC' ) || ! WC()->shipping ) {
            return;
        }
        $packages = WC()->shipping()->get_packages();
        if ( empty( $packages ) || empty( $packages[0]['rates'] ) ) {
            return;
        }
        $lines = array();
        foreach ( $packages[0]['rates'] as $rate_id => $rate ) {
            if ( ! is_object( $rate ) ) {
                continue;
            }
            $mixed_flag = self::get_rate_meta_flag( $rate, 'mixed_products' ) ? 'yes' : 'no';
            $method_id = method_exists( $rate, 'get_method_id' ) ? $rate->get_method_id() : '';
            $lines[] = sprintf(
                '%s | %s | %s | mixed:%s | method:%s',
                $rate_id,
                $rate->get_label(),
                wc_price( $rate->get_cost() ),
                $mixed_flag,
                $method_id !== '' ? $method_id : '-'
            );
        }
        if ( empty( $lines ) ) {
            return;
        }
        $chosen = '';
        if ( function_exists( 'WC' ) && WC()->session ) {
            $chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
            $chosen = isset( $chosen_methods[0] ) ? $chosen_methods[0] : '';
        }
        printf(
            '<tr class="jify-cart-rates-debug"><td colspan="2"><small><strong>%s</strong><br>%s</small></td></tr>',
            esc_html__( 'Shipping Rates (admin only):', 'jify-shipping' ),
            esc_html( implode( "\n", $lines ) . ( $chosen !== '' ? "\nChosen: " . $chosen : '' ) )
        );
    }

    public static function render_cart_debug_summary() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }
        $cart = WC()->cart;
        $items = array();
        foreach ( $cart->get_cart() as $item ) {
            $name = isset( $item['data'] ) ? $item['data']->get_name() : '';
            $qty = isset( $item['quantity'] ) ? intval( $item['quantity'] ) : 0;
            $line_total = isset( $item['line_total'] ) ? floatval( $item['line_total'] ) : 0;
            $items[] = sprintf( '%s x %d | %s', $name, $qty, wc_price( $line_total ) );
        }
        $fees = array();
        foreach ( $cart->get_fees() as $fee ) {
            $fees[] = sprintf( '%s: %s', $fee->name, wc_price( $fee->amount ) );
        }
        $subtotal = wc_price( $cart->get_subtotal() );
        $discount = wc_price( $cart->get_discount_total() );
        $shipping = wc_price( $cart->get_shipping_total() );
        $tax = wc_price( $cart->get_total_tax() );
        $total = wc_price( $cart->get_total( 'edit' ) );
        $summary = array(
            'Items' => implode( '; ', $items ),
            'Fees' => empty( $fees ) ? '-' : implode( '; ', $fees ),
            'Subtotal' => $subtotal,
            'Discount' => $discount,
            'Shipping' => $shipping,
            '稅金' => $tax,
            'Total' => $total,
        );
        $lines = array();
        foreach ( $summary as $label => $value ) {
            $lines[] = $label . ': ' . $value;
        }
        printf(
            '<tr class="jify-cart-summary-debug"><td colspan="2"><small><strong>%s</strong><br>%s</small></td></tr>',
            esc_html__( 'Cart Summary (admin only):', 'jify-shipping' ),
            esc_html( implode( "\n", $lines ) )
        );
    }

    public static function override_proceed_to_checkout_button() {
        remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
        add_action( 'woocommerce_proceed_to_checkout', array( __CLASS__, 'render_proceed_to_checkout_button' ), 20 );
    }

    public static function render_proceed_to_checkout_button() {
        $button_text = self::has_mixed_jify_products()
            ? __( '前往下一頁', 'jify-shipping' )
            : __( 'Proceed to checkout', 'woocommerce' );
        $class = wc_wp_theme_get_element_class_name( 'button' );
        $class = $class ? ' ' . $class : '';
        printf(
            '<a href="%s" class="checkout-button button alt wc-forward%s">%s</a>',
            esc_url( wc_get_checkout_url() ),
            esc_attr( $class ),
            esc_html( $button_text )
        );
    }

    public static function maybe_override_mixed_quote( $rates, $package ) {
        if ( empty( $rates ) ) {
            return $rates;
        }
        if ( ! self::has_mixed_jify_products() ) {
            return $rates;
        }
        $quote = self::get_mixed_quote_for_cart();
        if ( $quote === '' ) {
            return $rates;
        }
        $quote_value = floatval( $quote );
        $jify_rate_id = '';
        foreach ( $rates as $rate_id => $rate ) {
            if ( ! is_object( $rate ) ) {
                continue;
            }
            $method_id = method_exists( $rate, 'get_method_id' ) ? $rate->get_method_id() : '';
            $is_jify_method = $method_id === 'jify_shipping' || strpos( (string) $rate_id, 'jify_shipping' ) === 0;
            $is_mixed_rate = self::get_rate_meta_flag( $rate, 'mixed_products' );
            if ( empty( $is_mixed_rate ) && ! $is_jify_method ) {
                continue;
            }
            $title = self::strip_price_from_title( self::get_jify_rate_title( $rate ) );
            $rate->set_cost( $quote_value );
            $rate->set_label( $title );
            $rate->add_meta_data( 'quoted', true, true );
            $rates[ $rate_id ] = $rate;
            if ( $is_jify_method ) {
                $jify_rate_id = $rate_id;
            }
        }
        if ( $jify_rate_id !== '' ) {
            $rates = array( $jify_rate_id => $rates[ $jify_rate_id ] );
        }
        if ( $jify_rate_id !== '' && function_exists( 'WC' ) && WC()->session ) {
            $chosen = WC()->session->get( 'chosen_shipping_methods', array() );
            $package_key = isset( $package['package_id'] ) ? absint( $package['package_id'] ) : 0;
            if ( ! isset( $chosen[ $package_key ] ) || $chosen[ $package_key ] !== $jify_rate_id ) {
                $chosen[ $package_key ] = $jify_rate_id;
                WC()->session->set( 'chosen_shipping_methods', $chosen );
            }
            $counts = WC()->session->get( 'shipping_method_counts', array() );
            $counts[ $package_key ] = count( $rates );
            WC()->session->set( 'shipping_method_counts', $counts );
        }
        return $rates;
    }

    public static function filter_shipping_rate_label( $label, $rate ) {
        $quote = self::get_mixed_quote_for_cart();
        if ( $quote === '' || ! self::has_mixed_jify_products() ) {
            return $label;
        }
        if ( ! self::is_jify_rate( $rate ) ) {
            return $label;
        }
        return self::strip_price_from_title( self::get_jify_rate_title( $rate ) );
    }

    public static function filter_shipping_rate_cost( $cost, $rate ) {
        $quote = self::get_mixed_quote_for_cart();
        if ( $quote === '' || ! self::has_mixed_jify_products() ) {
            return $cost;
        }
        if ( ! self::is_jify_rate( $rate ) ) {
            return $cost;
        }
        return floatval( $quote );
    }

    public static function add_quote_to_rate_cache_key( $cache_key, $package ) {
        if ( ! self::has_mixed_jify_products() ) {
            return $cache_key;
        }
        $quote = self::get_mixed_quote_for_cart();
        if ( $quote !== '' ) {
            return $cache_key . ':q:' . $quote;
        }
        $message = self::get_cart_mixed_message();
        if ( $message !== '' ) {
            return $cache_key . ':m:' . md5( $message );
        }
        return $cache_key;
    }

    private static function get_rate_meta_flag( $rate, $key ) {
        if ( ! is_object( $rate ) ) {
            return false;
        }
        if ( method_exists( $rate, 'get_meta' ) ) {
            return (bool) $rate->get_meta( $key, true );
        }
        if ( method_exists( $rate, 'get_meta_data' ) ) {
            $meta_items = $rate->get_meta_data();
            foreach ( $meta_items as $meta_item ) {
                if ( is_object( $meta_item ) && method_exists( $meta_item, 'get_data' ) ) {
                    $data = $meta_item->get_data();
                    if ( isset( $data['key'] ) && $data['key'] === $key ) {
                        return ! empty( $data['value'] );
                    }
                }
            }
        }
        return false;
    }

    private static function is_jify_rate( $rate ) {
        if ( ! is_object( $rate ) ) {
            return false;
        }
        $method_id = method_exists( $rate, 'get_method_id' ) ? $rate->get_method_id() : '';
        if ( $method_id === 'jify_shipping' ) {
            return true;
        }
        $rate_id = method_exists( $rate, 'get_id' ) ? $rate->get_id() : '';
        return $rate_id !== '' && strpos( $rate_id, 'jify_shipping' ) === 0;
    }

    private static function get_jify_rate_title( $rate ) {
        $default_title = __( 'Jify Shipping', 'jify-shipping' );
        if ( ! is_object( $rate ) ) {
            return $default_title;
        }
        $instance_id = method_exists( $rate, 'get_instance_id' ) ? $rate->get_instance_id() : 0;
        if ( $instance_id && class_exists( 'WC_Shipping_Zones' ) ) {
            $method = WC_Shipping_Zones::get_shipping_method( $instance_id );
            if ( $method && isset( $method->title ) && $method->title !== '' ) {
                return $method->title;
            }
        }
        return $default_title;
    }

    private static function strip_price_from_title( $title ) {
        if ( ! is_string( $title ) || $title === '' ) {
            return $title;
        }
        $stripped = preg_replace( '/\s*[-–—]\s*[^0-9]*[0-9][0-9,\.]*\s*$/', '', $title );
        return $stripped !== null ? $stripped : $title;
    }

    private static function cart_has_non_jify_products() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            $variation_id = isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
            $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $is_jify = false;
            if ( $variation_id && get_post_meta( $variation_id, '_jify_shipping_enabled', true ) === 'yes' ) {
                $is_jify = true;
            } elseif ( $product_id && get_post_meta( $product_id, '_jify_shipping_enabled', true ) === 'yes' ) {
                $is_jify = true;
            }
            if ( $is_jify ) {
                continue;
            }
            $product = isset( $item['data'] ) ? $item['data'] : null;
            if ( $product && method_exists( $product, 'needs_shipping' ) && ! $product->needs_shipping() ) {
                continue;
            }
            return true;
        }
        return false;
    }

    private static function get_single_jify_cart_context() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return array();
        }
        $jify_ids = self::get_cart_jify_product_ids();
        if ( count( $jify_ids ) !== 1 ) {
            return array();
        }
        if ( self::cart_has_non_jify_products() ) {
            return array();
        }
        $total_qty = 0;
        foreach ( WC()->cart->get_cart() as $item ) {
            $variation_id = isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
            $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $is_jify = false;
            if ( $variation_id && get_post_meta( $variation_id, '_jify_shipping_enabled', true ) === 'yes' ) {
                $is_jify = true;
            } elseif ( $product_id && get_post_meta( $product_id, '_jify_shipping_enabled', true ) === 'yes' ) {
                $is_jify = true;
            }
            if ( $is_jify ) {
                $total_qty += isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
            }
        }
        return array(
            'product_id' => $jify_ids[0],
            'quantity' => $total_qty,
        );
    }

    private static function is_quantity_outside_rules() {
        $context = self::get_single_jify_cart_context();
        if ( empty( $context ) ) {
            return false;
        }
        $rules_str = get_post_meta( $context['product_id'], '_jify_shipping_costs', true );
        if ( empty( $rules_str ) ) {
            return false;
        }
        $qty = absint( $context['quantity'] );
        $rules_pairs = explode( '|', $rules_str );
        foreach ( $rules_pairs as $pair ) {
            $parts = explode( ':', $pair );
            if ( count( $parts ) !== 2 ) {
                continue;
            }
            $qty_def = trim( $parts[0] );
            if ( $qty_def === '' ) {
                continue;
            }
            if ( strpos( $qty_def, '-' ) !== false ) {
                $range = explode( '-', $qty_def );
                $min = intval( $range[0] );
                $max = intval( $range[1] );
                if ( $qty >= $min && $qty <= $max ) {
                    return false;
                }
            } else {
                if ( intval( $qty_def ) === $qty ) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function normalize_notify_emails( $value ) {
        if ( is_array( $value ) ) {
            $candidates = $value;
        } else {
            $raw = trim( (string) $value );
            if ( '' === $raw ) {
                return array();
            }
            $candidates = preg_split( '/[,;]+/', $raw );
        }
        $emails = array();
        foreach ( $candidates as $candidate ) {
            $candidate = sanitize_email( trim( $candidate ) );
            if ( $candidate ) {
                $emails[] = $candidate;
            }
        }
        return array_values( array_unique( $emails ) );
    }

    private static function get_notify_emails() {
        $value = get_option( self::OPTION_NOTIFY_EMAIL, '' );
        return self::normalize_notify_emails( $value );
    }

    private static function send_admin_notify_email( $cart_hash, $entry ) {
        $notify_emails = self::get_notify_emails();
        if ( empty( $notify_emails ) ) {
            return;
        }
        $items = isset( $entry['items'] ) && is_array( $entry['items'] ) ? implode( ', ', $entry['items'] ) : '';
        $subject = __( 'Jify Shipping: New Mixed Order Notification', 'jify-shipping' );
        $message = sprintf(
            "Cart Hash: %s\nCustomer: %s\nPhone: %s\nEmail: %s\nItems: %s\nTime: %s\n",
            $cart_hash,
            isset( $entry['customer_name'] ) ? $entry['customer_name'] : '',
            isset( $entry['customer_phone'] ) ? $entry['customer_phone'] : '',
            isset( $entry['customer_email'] ) ? $entry['customer_email'] : '',
            $items,
            isset( $entry['timestamp'] ) ? $entry['timestamp'] : ''
        );
        self::$mail_from = get_option( 'woocommerce_email_from_address', '' );
        self::$mail_from_name = get_option( 'woocommerce_email_from_name', '' );
        add_filter( 'wp_mail_from', array( __CLASS__, 'filter_wp_mail_from' ) );
        add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_wp_mail_from_name' ) );
        wp_mail( $notify_emails, $subject, $message );
        remove_filter( 'wp_mail_from', array( __CLASS__, 'filter_wp_mail_from' ) );
        remove_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_wp_mail_from_name' ) );
        self::$mail_from = null;
        self::$mail_from_name = null;
    }

    private static function send_customer_quote_email( $cart_hash, $entry, $amount ) {
        $to_email = isset( $entry['customer_email'] ) ? sanitize_email( $entry['customer_email'] ) : '';
        if ( ! is_email( $to_email ) ) {
            return false;
        }

        $customer_name = isset( $entry['customer_name'] ) ? $entry['customer_name'] : '';
        $items_str = isset( $entry['items'] ) && is_array( $entry['items'] ) ? implode( ', ', $entry['items'] ) : '';
        
        $subject = sprintf( __( '您的訂單運費報價已完成 - %s', 'jify-shipping' ), get_bloginfo( 'name' ) );
        
        $message = sprintf(
            "親愛的 %s 您好，\n\n感謝您的詢問。您在我們網站挑選的商品運費已經報價完成：\n\n" .
            "商品內容：%s\n" .
            "報價金額：NT$ %s\n\n" .
            "您可以回到購物車頁面，系統將會自動更新運費，您可以繼續完成結帳手續。\n\n" .
            "網站連結：%s\n\n" .
            "祝您購物愉快！",
            $customer_name,
            $items_str,
            wc_format_decimal( $amount ),
            wc_get_cart_url()
        );

        self::$mail_from = get_option( 'woocommerce_email_from_address', '' );
        self::$mail_from_name = get_option( 'woocommerce_email_from_name', '' );
        add_filter( 'wp_mail_from', array( __CLASS__, 'filter_wp_mail_from' ) );
        add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_wp_mail_from_name' ) );
        
        $sent = wp_mail( $to_email, $subject, $message );
        
        remove_filter( 'wp_mail_from', array( __CLASS__, 'filter_wp_mail_from' ) );
        remove_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_wp_mail_from_name' ) );
        self::$mail_from = null;
        self::$mail_from_name = null;

        return $sent;
    }

    public static function filter_wp_mail_from( $from_email ) {
        return self::$mail_from !== '' ? self::$mail_from : $from_email;
    }

    public static function filter_wp_mail_from_name( $from_name ) {
        return self::$mail_from_name !== '' ? self::$mail_from_name : $from_name;
    }

    private static function maybe_refresh_quote_shipping_cache() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
            return;
        }
        $quote = self::get_mixed_quote_for_cart();
        if ( $quote === '' ) {
            return;
        }
        $cart_hash = self::get_current_cart_hash();
        if ( $cart_hash === '' ) {
            return;
        }
        $state = $cart_hash . '|' . $quote;
        $applied = WC()->session->get( 'jify_shipping_quote_applied' );
        if ( $applied === $state ) {
            return;
        }
        WC()->session->set( 'jify_shipping_quote_applied', $state );
        WC()->session->set( 'shipping_for_package_0', null );
        WC()->session->set( 'shipping_method_counts', null );
        WC()->session->set( 'chosen_shipping_methods', null );
        if ( class_exists( 'WC_Cache_Helper' ) ) {
            WC_Cache_Helper::get_transient_version( 'shipping', true );
        }
        WC()->cart->calculate_shipping();
    }

    public static function render_checkout_notify_button() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }
        if ( ! self::has_mixed_jify_products() ) {
            return;
        }
        if ( ! self::is_jify_shipping_selected() ) {
            return;
        }

        $quote = self::get_mixed_quote_for_cart();
        $has_quote = $quote !== '';
        $mixed_message = self::get_cart_mixed_message();
        if ( $mixed_message === '' ) {
            $mixed_message = __( '此訂單需人工報價，請先通知小編。', 'jify-shipping' );
        }
        ?>
        <div class="jify-mixed-notify" style="margin: 10px 0; padding: 12px; border: 1px solid #ccd0d4; background: #f8f8f8;">
            <?php if ( $has_quote ) : ?>
                <strong><?php esc_html_e( '運費已更新，請重新檢查金額後送出訂單。', 'jify-shipping' ); ?></strong>
            <?php else : ?>
                <strong><?php echo esc_html( $mixed_message ); ?></strong>
                <button type="button" class="button" id="jify-notify-admin" style="margin-left:10px;"><?php esc_html_e( '通知小編', 'jify-shipping' ); ?></button>
                <span id="jify-notify-status" style="margin-left:8px;"></span>
            <?php endif; ?>
        </div>
        <script>
        jQuery(function($) {
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var notifyNonce = <?php echo wp_json_encode( wp_create_nonce( 'jify_shipping_notify' ) ); ?>;
            var quoteNonce = <?php echo wp_json_encode( wp_create_nonce( 'jify_shipping_quote' ) ); ?>;

            function getCheckoutField(selector) {
                var $el = $(selector);
                return $el.length ? $el.val() : '';
            }

            function pollQuote() {
                $.post(ajaxUrl, {
                    action: 'jify_shipping_get_quote',
                    nonce: quoteNonce
                }).done(function(resp) {
                    if (resp && resp.success && resp.data && resp.data.quote !== '') {
                        $('#jify-notify-admin').prop('disabled', true);
                        $('#jify-notify-status').text('<?php echo esc_js( __( '已收到報價，正在更新運費...', 'jify-shipping' ) ); ?>');
                        $(document.body).trigger('update_checkout');
                    }
                });
            }

            $('#jify-notify-admin').on('click', function() {
                var name = $.trim(getCheckoutField('#billing_last_name') + ' ' + getCheckoutField('#billing_first_name'));
                var phone = getCheckoutField('#billing_phone');
                var email = getCheckoutField('#billing_email');
                if (!name || !phone || !email) {
                    $('#jify-notify-status').text('<?php echo esc_js( __( '請先填寫姓名、電話與 Email。', 'jify-shipping' ) ); ?>');
                    return;
                }
                $('#jify-notify-admin').prop('disabled', true);
                $('#jify-notify-status').text('<?php echo esc_js( __( '送出中...', 'jify-shipping' ) ); ?>');
                $.post(ajaxUrl, {
                    action: 'jify_shipping_notify_admin',
                    nonce: notifyNonce,
                    name: name,
                    phone: phone,
                    email: email
                }).done(function(resp) {
                    if (resp && resp.success) {
                        $('#jify-notify-status').text('<?php echo esc_js( __( '已通知小編，等待報價...', 'jify-shipping' ) ); ?>');
                    } else {
                        $('#jify-notify-admin').prop('disabled', false);
                        $('#jify-notify-status').text('<?php echo esc_js( __( '通知失敗，請稍後再試。', 'jify-shipping' ) ); ?>');
                    }
                }).fail(function() {
                    $('#jify-notify-admin').prop('disabled', false);
                    $('#jify-notify-status').text('<?php echo esc_js( __( '通知失敗，請稍後再試。', 'jify-shipping' ) ); ?>');
                });
            });

            <?php if ( $has_quote ) : ?>
            $(document.body).trigger('update_checkout');
            <?php else : ?>
            setInterval(pollQuote, 5000);
            <?php endif; ?>
        });
        </script>
        <?php
    }

    public static function maybe_disable_order_button( $html ) {
        if ( self::should_block_checkout() ) {
            $text = apply_filters( 'woocommerce_order_button_text', __( '送出訂單', 'jify-shipping' ) );
            return '<button type="submit" class="button alt jify-order-disabled" disabled="disabled" style="opacity:0.5; cursor:not-allowed;">' . esc_html( $text ) . '</button>';
        }
        return $html;
    }

    public static function get_cart_jify_product_ids() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return array();
        }

        $ids = array();
        foreach ( WC()->cart->get_cart() as $item ) {
            $variation_id = isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
            $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            if ( $variation_id && get_post_meta( $variation_id, '_jify_shipping_enabled', true ) === 'yes' ) {
                $ids[] = $variation_id;
            } elseif ( $product_id && get_post_meta( $product_id, '_jify_shipping_enabled', true ) === 'yes' ) {
                $ids[] = $product_id;
            }
        }

        return array_values( array_unique( $ids ) );
    }

    public static function has_mixed_jify_products() {
        $ids = self::get_cart_jify_product_ids();
        if ( empty( $ids ) ) {
            return false;
        }
        $has_non_jify = self::cart_has_non_jify_products();
        if ( count( $ids ) > 1 || $has_non_jify ) {
            return true;
        }
        return self::is_quantity_outside_rules();
    }

    public static function get_cart_mixed_message() {
        if ( ! self::has_mixed_jify_products() ) {
            return '';
        }
        $ids = self::get_cart_jify_product_ids();

        $message = get_post_meta( $ids[0], '_jify_shipping_mixed_products_message', true );
        if ( empty( $message ) ) {
            $message = get_option( self::OPTION_MIXED_MESSAGE, '' );
        }
        if ( empty( $message ) ) {
            $message = __( 'Please contact us for shipping quote for mixed products', 'jify-shipping' );
        }

        return $message;
    }

    public static function maybe_clear_pending_orders() {
        if ( ! is_admin() || empty( $_GET['clear_jify_shipping_pending'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        self::clear_pending_orders();
        self::$pending_clear_notice = true;
    }

    public static function render_admin_pending_notice() {
        if ( ! is_admin() ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->id !== 'woocommerce_page_wc-settings' ) {
            return;
        }
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
        $section = isset( $_GET['section'] ) ? sanitize_key( $_GET['section'] ) : '';
        if ( $tab !== 'shipping' || $section !== 'jify_shipping' ) {
            return;
        }

        if ( self::$pending_clear_notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Jify Shipping 暫存混合商品訂單已清除。', 'jify-shipping' ) . '</p></div>';
        }

        $pending = self::get_pending_orders();
        if ( empty( $pending ) ) {
            return;
        }

        echo '<div class="notice notice-warning">';
        echo '<p>' . esc_html__( '目前有 Jify Shipping 的混合商品訂單暫存等待人工報價。', 'jify-shipping' ) . '</p>';
        echo '<ul>';
        foreach ( $pending as $entry ) {
            $products = ! empty( $entry['products'] ) && is_array( $entry['products'] ) ? $entry['products'] : array();
            $product_list = ! empty( $products ) ? implode( ',', array_slice( $products, 0, 3 ) ) : '';
            printf(
                '<li><strong>%s</strong>: %s <small>(%s)</small></li>',
                esc_html( $entry['timestamp'] ),
                esc_html( $entry['message'] ),
                esc_html( $product_list )
            );
        }
        echo '</ul>';
        $clear_url = esc_url( add_query_arg( 'clear_jify_shipping_pending', '1' ) );
        printf(
            '<p><a class="button" href="%s">%s</a></p>',
            $clear_url,
            esc_html__( '已處理，清除暫存訂單', 'jify-shipping' )
        );
        echo '</div>';
    }

    public static function record_mixed_pending_order( $mixed_message, $product_ids ) {
        if ( empty( $mixed_message ) ) {
            return;
        }

        $cart_hash = self::get_current_cart_hash();
        if ( empty( $cart_hash ) ) {
            $cart_hash = wp_hash( maybe_serialize( $product_ids ) . microtime( true ) );
        }

        $pending = self::get_pending_orders();
        $existing = isset( $pending[ $cart_hash ] ) && is_array( $pending[ $cart_hash ] ) ? $pending[ $cart_hash ] : array();
        $pending[ $cart_hash ] = array_merge( $existing, array(
            'timestamp' => current_time( 'mysql' ),
            'message' => $mixed_message,
            'products' => $product_ids,
            'items' => self::get_cart_items_snapshot(),
            'notified' => isset( $existing['notified'] ) ? $existing['notified'] : false,
        ) );
        while ( count( $pending ) > self::MAX_PENDING_ENTRIES ) {
            reset( $pending );
            unset( $pending[ key( $pending ) ] );
        }
        update_option( self::OPTION_PENDING_ORDERS, $pending );

        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'jify_shipping_mixed_pending', array(
                'message' => $mixed_message,
                'hash' => $cart_hash,
                'products' => $product_ids,
            ) );
        }
    }

    public static function get_pending_orders() {
        $pending = get_option( self::OPTION_PENDING_ORDERS, array() );
        if ( ! is_array( $pending ) ) {
            return array();
        }
        return $pending;
    }

    public static function get_cart_items_snapshot() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return array();
        }
        $items = array();
        foreach ( WC()->cart->get_cart() as $item ) {
            $name = isset( $item['data'] ) ? $item['data']->get_name() : '';
            $qty = isset( $item['quantity'] ) ? intval( $item['quantity'] ) : 0;
            if ( $name !== '' ) {
                $items[] = sprintf( '%s x %d', $name, $qty );
            }
        }
        return $items;
    }

    public static function get_mixed_quote_for_cart( $cart_hash = '' ) {
        if ( $cart_hash === '' ) {
            $cart_hash = self::get_current_cart_hash();
        }
        if ( $cart_hash === '' ) {
            return '';
        }
        $quotes = get_option( self::OPTION_MIXED_QUOTES, array() );
        if ( ! is_array( $quotes ) || ! isset( $quotes[ $cart_hash ] ) ) {
            return '';
        }
        $amount = $quotes[ $cart_hash ];
        return is_numeric( $amount ) ? $amount : '';
    }

    public static function should_block_checkout() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }
        if ( ! self::has_mixed_jify_products() ) {
            return false;
        }
        if ( ! self::is_jify_shipping_selected() ) {
            return false;
        }
        return self::get_mixed_quote_for_cart() === '';
    }

    private static function is_jify_shipping_selected() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return false;
        }
        $chosen = WC()->session->get( 'chosen_shipping_methods' );
        if ( empty( $chosen ) || ! is_array( $chosen ) ) {
            return false;
        }
        foreach ( $chosen as $method ) {
            if ( is_string( $method ) && strpos( $method, 'jify_shipping' ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    public static function handle_notify_admin() {
        if ( ! check_ajax_referer( 'jify_shipping_notify', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'invalid_nonce' ), 403 );
        }
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_send_json_error( array( 'message' => 'cart_unavailable' ), 400 );
        }
        if ( ! self::has_mixed_jify_products() ) {
            wp_send_json_error( array( 'message' => 'not_mixed_cart' ), 400 );
        }

        $cart_hash = self::get_current_cart_hash();
        if ( empty( $cart_hash ) ) {
            wp_send_json_error( array( 'message' => 'missing_cart_hash' ), 400 );
        }

        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

        $pending = self::get_pending_orders();
        if ( ! isset( $pending[ $cart_hash ] ) ) {
            $pending[ $cart_hash ] = array(
                'timestamp' => current_time( 'mysql' ),
                'message' => self::get_cart_mixed_message(),
                'products' => self::get_cart_jify_product_ids(),
            );
        }
        $pending[ $cart_hash ]['customer_name'] = $name;
        $pending[ $cart_hash ]['customer_phone'] = $phone;
        $pending[ $cart_hash ]['customer_email'] = $email;
        $pending[ $cart_hash ]['items'] = self::get_cart_items_snapshot();
        $pending[ $cart_hash ]['notified'] = true;
        $pending[ $cart_hash ]['superseded'] = false;

        $identifier = $email !== '' ? $email : $phone;
        if ( $identifier !== '' ) {
            foreach ( $pending as $hash => $entry ) {
                if ( $hash === $cart_hash ) {
                    continue;
                }
                if ( empty( $entry['notified'] ) ) {
                    continue;
                }
                $entry_email = isset( $entry['customer_email'] ) ? $entry['customer_email'] : '';
                $entry_phone = isset( $entry['customer_phone'] ) ? $entry['customer_phone'] : '';
                if ( $entry_email === $identifier || $entry_phone === $identifier ) {
                    $pending[ $hash ]['superseded'] = true;
                    $pending[ $hash ]['superseded_by'] = $cart_hash;
                    $pending[ $hash ]['superseded_at'] = current_time( 'mysql' );
                }
            }
        }

        update_option( self::OPTION_PENDING_ORDERS, $pending );

        self::send_admin_notify_email( $cart_hash, $pending[ $cart_hash ] );

        wp_send_json_success();
    }

    public static function handle_get_quote() {
        if ( ! check_ajax_referer( 'jify_shipping_quote', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'invalid_nonce' ), 403 );
        }
        $quote = self::get_mixed_quote_for_cart();
        wp_send_json_success( array( 'quote' => $quote ) );
    }

    public static function add_admin_menu() {
        add_menu_page(
            __( 'Jify Setting', 'jify-shipping' ),
            __( 'Jify Setting', 'jify-shipping' ),
            'manage_woocommerce',
            'jify-setting',
            array( __CLASS__, 'render_admin_page' ),
            'dashicons-admin-generic',
            58
        );

        add_submenu_page(
            'jify-setting',
            __( '通知管理', 'jify-shipping' ),
            __( '通知管理', 'jify-shipping' ),
            'manage_woocommerce',
            'jify-setting',
            array( __CLASS__, 'render_admin_page' )
        );

        add_submenu_page(
            'jify-setting',
            __( 'Jify Shipping', 'jify-shipping' ),
            __( 'Jify Shipping', 'jify-shipping' ),
            'manage_woocommerce',
            'jify-setting-shipping',
            array( __CLASS__, 'render_jify_shipping_batch_page' )
        );

        add_submenu_page(
            'jify-setting',
            __( 'Jify Discount', 'jify-shipping' ),
            __( 'Jify Discount', 'jify-shipping' ),
            'manage_woocommerce',
            'jify-setting-discount',
            array( __CLASS__, 'render_jify_discount_batch_page' )
        );

        add_submenu_page(
            'jify-setting',
            __( 'Jify 稅金', 'jify-shipping' ),
            __( 'Jify 稅金', 'jify-shipping' ),
            'manage_woocommerce',
            'jify-setting-taxes',
            array( __CLASS__, 'render_jify_tax_batch_page' )
        );
    }

    public static function render_jify_shipping_batch_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        
        // Save Surcharge Handler
        if ( isset( $_POST['jify_surcharge_submit'] ) ) {
            check_admin_referer( 'jify_surcharge_save', 'jify_surcharge_nonce' );
            update_option( 'jify_shipping_surcharge_enabled', isset( $_POST['surcharge_enabled'] ) ? 'yes' : 'no' );
            update_option( 'jify_shipping_surcharge_start_date', sanitize_text_field( $_POST['surcharge_start'] ) );
            update_option( 'jify_shipping_surcharge_end_date', sanitize_text_field( $_POST['surcharge_end'] ) );
            update_option( 'jify_shipping_surcharge_amount', sanitize_text_field( $_POST['surcharge_amount'] ) );
            update_option( 'jify_shipping_surcharge_desc', sanitize_text_field( $_POST['surcharge_desc'] ) );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Seasonal surcharge settings updated.', 'jify-shipping' ) . '</p></div>';
        }

        // Save Batch Handler
        if ( isset( $_POST['jify_shipping_batch_submit'] ) ) {
            check_admin_referer( 'jify_shipping_batch_save', 'jify_shipping_batch_nonce' );
            $ids = isset( $_POST['product_ids'] ) ? $_POST['product_ids'] : array();
            foreach ( $ids as $id ) {
                $enabled = isset( $_POST['enabled'][$id] ) ? 'yes' : 'no';
                update_post_meta( $id, '_jify_shipping_enabled', $enabled );
                
                if ( isset( $_POST['rules'][$id] ) ) {
                    // Convert newlines to pipes and clean up
                    $rules_raw = wp_unslash( $_POST['rules'][$id] );
                    $rules_array = array_filter( array_map( 'trim', explode( "\n", $rules_raw ) ) );
                    update_post_meta( $id, '_jify_shipping_costs', implode( '|', $rules_array ) );
                }
                if ( isset( $_POST['mixed_msg'][$id] ) ) {
                    update_post_meta( $id, '_jify_shipping_mixed_products_message', sanitize_textarea_field( $_POST['mixed_msg'][$id] ) );
                }
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Shipping settings updated.', 'jify-shipping' ) . '</p></div>';
        }

        // Query Enabled Products
        $args = array(
            'post_type' => array('product', 'product_variation'),
            'posts_per_page' => -1,
            'meta_query' => array(
                array( 'key' => '_jify_shipping_enabled', 'value' => 'yes' )
            )
        );
        $query = new WP_Query( $args );
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Jify Shipping Management', 'jify-shipping' ); ?></h1>

            <!-- Global Surcharge Section -->
            <div class="postbox" style="margin-top:20px; border:1px solid #ccd0d4; background:#fff;">
                <h2 style="padding:8px 12px; margin:0; border-bottom:1px solid #ccd0d4; font-size:14px;"><?php esc_html_e( 'Seasonal Surcharge Configuration', 'jify-shipping' ); ?></h2>
                <div class="inside" style="padding:12px;">
                    <form method="post">
                        <?php wp_nonce_field( 'jify_surcharge_save', 'jify_surcharge_nonce' ); ?>
                        <table class="form-table" style="margin-top:0;">
                            <tr>
                                <th style="width:200px; padding:10px 0;"><?php esc_html_e( 'Enable Surcharge', 'jify-shipping' ); ?></th>
                                <td><input type="checkbox" name="surcharge_enabled" value="yes" <?php checked( get_option('jify_shipping_surcharge_enabled'), 'yes' ); ?>></td>
                            </tr>
                            <tr>
                                <th style="padding:10px 0;"><?php esc_html_e( 'Date Range (Start - End)', 'jify-shipping' ); ?></th>
                                <td>
                                    <input type="text" name="surcharge_start" value="<?php echo esc_attr(get_option('jify_shipping_surcharge_start_date')); ?>" placeholder="YYYY-MM-DD" style="width:120px;"> 
                                    - 
                                    <input type="text" name="surcharge_end" value="<?php echo esc_attr(get_option('jify_shipping_surcharge_end_date')); ?>" placeholder="YYYY-MM-DD" style="width:120px;">
                                    <p class="description"><?php esc_html_e( 'Format: YYYY-MM-DD (e.g. 2025-02-01)', 'jify-shipping' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:10px 0;"><?php esc_html_e( 'Surcharge Amount ($)', 'jify-shipping' ); ?></th>
                                <td><input type="number" step="0.01" name="surcharge_amount" value="<?php echo esc_attr(get_option('jify_shipping_surcharge_amount')); ?>" placeholder="10"></td>
                            </tr>
                            <tr>
                                <th style="padding:10px 0;"><?php esc_html_e( 'Label Description', 'jify-shipping' ); ?></th>
                                <td>
                                    <input type="text" name="surcharge_desc" value="<?php echo esc_attr(get_option('jify_shipping_surcharge_desc')); ?>" placeholder="<?php esc_attr_e( 'e.g. Holiday Surcharge', 'jify-shipping' ); ?>" class="regular-text">
                                    <p class="description"><?php esc_html_e( 'Appended to shipping method title, e.g. Jify Shipping (Holiday Surcharge)', 'jify-shipping' ); ?></p>
                                </td>
                            </tr>
                        </table>
                        <p style="margin-bottom:0;"><button type="submit" class="button" name="jify_surcharge_submit" value="1"><?php esc_html_e( 'Save Surcharge Settings', 'jify-shipping' ); ?></button></p>
                    </form>
                </div>
            </div>

            <hr style="margin:20px 0;">

            <p><?php esc_html_e( 'Manage all products with Jify Shipping enabled.', 'jify-shipping' ); ?></p>
            <form method="post">
                <?php wp_nonce_field( 'jify_shipping_batch_save', 'jify_shipping_batch_nonce' ); ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:25%;">Product</th>
                            <th style="width:8%;">Enabled</th>
                            <th>Shipping Rules (One per line)</th>
                            <th>Mixed Message</th>
                            <th style="width:8%;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( $query->have_posts() ) : while ( $query->have_posts() ) : $query->the_post(); 
                        $id = get_the_ID();
                        $product = wc_get_product( $id );
                        if ( ! $product ) continue;
                        $rules = get_post_meta( $id, '_jify_shipping_costs', true );
                        $display_rules = str_replace('|', "\n", $rules);
                        $msg = get_post_meta( $id, '_jify_shipping_mixed_products_message', true );
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $product->get_formatted_name() ); ?></strong>
                                <input type="hidden" name="product_ids[]" value="<?php echo esc_attr( $id ); ?>">
                            </td>
                            <td>
                                <input type="checkbox" name="enabled[<?php echo $id; ?>]" value="yes" checked>
                            </td>
                            <td>
                                <textarea name="rules[<?php echo $id; ?>]" rows="3" style="width:100%; font-family:monospace;"><?php echo esc_textarea( $display_rules ); ?></textarea>
                                <small style="color:#666;">Format: <code>Qty:Cost</code> (e.g., <code>1-5:200</code>)</small>
                            </td>
                            <td>
                                <textarea name="mixed_msg[<?php echo $id; ?>]" rows="2" style="width:100%;"><?php echo esc_textarea( $msg ); ?></textarea>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $id ) ); ?>" target="_blank" class="button">Edit</a>
                            </td>
                        </tr>
                    <?php endwhile; else : ?>
                        <tr><td colspan="5"><?php esc_html_e( 'No enabled products found.', 'jify-shipping' ); ?></td></tr>
                    <?php endif; wp_reset_postdata(); ?>
                    </tbody>
                </table>
                <p><button type="submit" class="button button-primary" name="jify_shipping_batch_submit" value="1"><?php esc_html_e( 'Save Changes', 'jify-shipping' ); ?></button></p>
            </form>
        </div>
        <?php
    }

    public static function render_jify_discount_batch_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;

        if ( isset( $_POST['jify_discount_batch_submit'] ) ) {
            check_admin_referer( 'jify_discount_batch_save', 'jify_discount_batch_nonce' );
            $ids = isset( $_POST['product_ids'] ) ? $_POST['product_ids'] : array();
            foreach ( $ids as $id ) {
                $enabled = isset( $_POST['enabled'][$id] ) ? 'yes' : 'no';
                update_post_meta( $id, '_jify_discount_enabled', $enabled );
                
                if ( isset( $_POST['rules'][$id] ) ) {
                    $rules_raw = wp_unslash( $_POST['rules'][$id] );
                    $rules_array = array_filter( array_map( 'trim', explode( "\n", $rules_raw ) ) );
                    update_post_meta( $id, '_jify_discount_rules', implode( '|', $rules_array ) );
                }
                if ( isset( $_POST['start'][$id] ) ) update_post_meta( $id, '_jify_discount_start_date', sanitize_text_field( $_POST['start'][$id] ) );
                if ( isset( $_POST['end'][$id] ) ) update_post_meta( $id, '_jify_discount_end_date', sanitize_text_field( $_POST['end'][$id] ) );
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Discount settings updated.', 'jify-shipping' ) . '</p></div>';
        }

        $args = array(
            'post_type' => array('product', 'product_variation'),
            'posts_per_page' => -1,
            'meta_query' => array( array( 'key' => '_jify_discount_enabled', 'value' => 'yes' ) )
        );
        $query = new WP_Query( $args );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Jify Discount Management', 'jify-shipping' ); ?></h1>
            <form method="post">
                <?php wp_nonce_field( 'jify_discount_batch_save', 'jify_discount_batch_nonce' ); ?>
                <table class="widefat striped">
                    <thead><tr><th style="width:25%;">Product</th><th style="width:8%;">Enabled</th><th>Discount Rules (One per line)</th><th style="width:12%;">Start Date</th><th style="width:12%;">End Date</th><th style="width:8%;">Action</th></tr></thead>
                    <tbody>
                    <?php if ( $query->have_posts() ) : while ( $query->have_posts() ) : $query->the_post(); 
                        $id = get_the_ID();
                        $product = wc_get_product( $id );
                        if ( ! $product ) continue;
                        $rules = get_post_meta( $id, '_jify_discount_rules', true );
                        $display_rules = str_replace('|', "\n", $rules);
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html( $product->get_formatted_name() ); ?></strong><input type="hidden" name="product_ids[]" value="<?php echo esc_attr( $id ); ?>"></td>
                            <td><input type="checkbox" name="enabled[<?php echo $id; ?>]" value="yes" checked></td>
                            <td>
                                <textarea name="rules[<?php echo $id; ?>]" rows="3" style="width:100%; font-family:monospace;"><?php echo esc_textarea( $display_rules ); ?></textarea>
                                <small style="color:#666;">Format: <code>Threshold:Value:Type</code> (e.g., <code>1000:10:percent</code>)</small>
                            </td>
                            <td><input type="text" name="start[<?php echo $id; ?>]" value="<?php echo esc_attr( get_post_meta( $id, '_jify_discount_start_date', true ) ); ?>" placeholder="YYYY-MM-DD" style="width:100%;"></td>
                            <td><input type="text" name="end[<?php echo $id; ?>]" value="<?php echo esc_attr( get_post_meta( $id, '_jify_discount_end_date', true ) ); ?>" placeholder="YYYY-MM-DD" style="width:100%;"></td>
                            <td><a href="<?php echo esc_url( get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $id ) ); ?>" target="_blank" class="button">Edit</a></td>
                        </tr>
                    <?php endwhile; else : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'No enabled products found.', 'jify-shipping' ); ?></td></tr>
                    <?php endif; wp_reset_postdata(); ?>
                    </tbody>
                </table>
                <p><button type="submit" class="button button-primary" name="jify_discount_batch_submit" value="1"><?php esc_html_e( 'Save Changes', 'jify-shipping' ); ?></button></p>
            </form>
        </div>
        <?php
    }

    public static function render_jify_tax_batch_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;

        if ( isset( $_POST['jify_tax_batch_submit'] ) ) {
            check_admin_referer( 'jify_tax_batch_save', 'jify_tax_batch_nonce' );
            $ids = isset( $_POST['product_ids'] ) ? $_POST['product_ids'] : array();
            foreach ( $ids as $id ) {
                $enabled = isset( $_POST['enabled'][$id] ) ? 'yes' : 'no';
                update_post_meta( $id, '_jify_tax_enabled', $enabled );
                
                if ( isset( $_POST['rate'][$id] ) ) update_post_meta( $id, '_jify_tax_rate', sanitize_text_field( $_POST['rate'][$id] ) );
                update_post_meta( $id, '_jify_tax_inc_shipping', isset( $_POST['inc_ship'][$id] ) ? 'yes' : 'no' );
                update_post_meta( $id, '_jify_tax_deduct_discount', isset( $_POST['deduct_disc'][$id] ) ? 'yes' : 'no' );
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Tax settings updated.', 'jify-shipping' ) . '</p></div>';
        }

        $args = array(
            'post_type' => array('product', 'product_variation'),
            'posts_per_page' => -1,
            'meta_query' => array( array( 'key' => '_jify_tax_enabled', 'value' => 'yes' ) )
        );
        $query = new WP_Query( $args );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Jify Tax Management', 'jify-shipping' ); ?></h1>
            <form method="post">
                <?php wp_nonce_field( 'jify_tax_batch_save', 'jify_tax_batch_nonce' ); ?>
                <table class="widefat striped">
                    <thead><tr><th>Product</th><th>Enabled</th><th>Rate (%)</th><th>Inc Shipping</th><th>Deduct Discount</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if ( $query->have_posts() ) : while ( $query->have_posts() ) : $query->the_post(); 
                        $id = get_the_ID();
                        $product = wc_get_product( $id );
                        if ( ! $product ) continue;
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html( $product->get_formatted_name() ); ?></strong><input type="hidden" name="product_ids[]" value="<?php echo esc_attr( $id ); ?>"></td>
                            <td><input type="checkbox" name="enabled[<?php echo $id; ?>]" value="yes" checked></td>
                            <td><input type="number" step="0.01" name="rate[<?php echo $id; ?>]" value="<?php echo esc_attr( get_post_meta( $id, '_jify_tax_rate', true ) ); ?>" style="width:60px;">%</td>
                            <td><input type="checkbox" name="inc_ship[<?php echo $id; ?>]" value="yes" <?php checked( get_post_meta( $id, '_jify_tax_inc_shipping', true ), 'yes' ); ?>></td>
                            <td><input type="checkbox" name="deduct_disc[<?php echo $id; ?>]" value="yes" <?php checked( get_post_meta( $id, '_jify_tax_deduct_discount', true ), 'yes' ); ?>></td>
                            <td><a href="<?php echo esc_url( get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $id ) ); ?>" target="_blank" class="button">Edit</a></td>
                        </tr>
                    <?php endwhile; else : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'No enabled products found.', 'jify-shipping' ); ?></td></tr>
                    <?php endif; wp_reset_postdata(); ?>
                    </tbody>
                </table>
                <p><button type="submit" class="button button-primary" name="jify_tax_batch_submit" value="1"><?php esc_html_e( 'Save Changes', 'jify-shipping' ); ?></button></p>
            </form>
        </div>
        <?php
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( isset( $_POST['jify_shipping_message_submit'] ) ) {
            check_admin_referer( 'jify_shipping_message_save', 'jify_shipping_message_nonce' );
            $message = isset( $_POST['mixed_products_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mixed_products_message'] ) ) : '';
            update_option( self::OPTION_MIXED_MESSAGE, $message );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '通知訊息已更新。', 'jify-shipping' ) . '</p></div>';
        }

        if ( isset( $_POST['jify_shipping_notify_email_submit'] ) ) {
            check_admin_referer( 'jify_shipping_notify_email_save', 'jify_shipping_notify_email_nonce' );
            $notify_input = isset( $_POST['notify_email'] ) ? wp_unslash( $_POST['notify_email'] ) : '';
            $notify_emails = self::normalize_notify_emails( $notify_input );
            update_option( self::OPTION_NOTIFY_EMAIL, $notify_emails );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '通知 Email 已更新。', 'jify-shipping' ) . '</p></div>';
        }

        if ( isset( $_POST['jify_shipping_quote_submit'] ) ) {
            check_admin_referer( 'jify_shipping_quote_save', 'jify_shipping_quote_nonce' );
            $cart_hash = isset( $_POST['cart_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_hash'] ) ) : '';
            $amount = isset( $_POST['shipping_amount'] ) ? wc_format_decimal( wp_unslash( $_POST['shipping_amount'] ) ) : '';
            if ( $cart_hash !== '' && $amount !== '' ) {
                $quotes = get_option( self::OPTION_MIXED_QUOTES, array() );
                if ( ! is_array( $quotes ) ) {
                    $quotes = array();
                }
                $quotes[ $cart_hash ] = $amount;
                update_option( self::OPTION_MIXED_QUOTES, $quotes );

                $pending = self::get_pending_orders();
                if ( isset( $pending[ $cart_hash ] ) ) {
                    self::send_customer_quote_email( $cart_hash, $pending[ $cart_hash ], $amount );
                }

                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '運費已更新並已寄出通知信。', 'jify-shipping' ) . '</p></div>';
            }
        }

        if ( isset( $_POST['jify_shipping_pending_delete'] ) ) {
            check_admin_referer( 'jify_shipping_pending_delete', 'jify_shipping_pending_delete_nonce' );
            $cart_hash = isset( $_POST['cart_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_hash'] ) ) : '';
            if ( $cart_hash !== '' ) {
                $pending = self::get_pending_orders();
                if ( isset( $pending[ $cart_hash ] ) ) {
                    unset( $pending[ $cart_hash ] );
                    update_option( self::OPTION_PENDING_ORDERS, $pending );
                }
                $quotes = get_option( self::OPTION_MIXED_QUOTES, array() );
                if ( is_array( $quotes ) && isset( $quotes[ $cart_hash ] ) ) {
                    unset( $quotes[ $cart_hash ] );
                    update_option( self::OPTION_MIXED_QUOTES, $quotes );
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '通知已刪除。', 'jify-shipping' ) . '</p></div>';
            }
        }

        if ( isset( $_POST['jify_shipping_test_email_submit'] ) ) {
            check_admin_referer( 'jify_shipping_test_email', 'jify_shipping_test_email_nonce' );
            $to_email = isset( $_POST['test_email_to'] ) ? sanitize_email( wp_unslash( $_POST['test_email_to'] ) ) : '';
            if ( $to_email !== '' && is_email( $to_email ) ) {
                $subject = __( 'Jify Shipping Test Email', 'jify-shipping' );
                $body = __( 'This is a test email from Jify Shipping.', 'jify-shipping' );
                $sent = wp_mail( $to_email, $subject, $body );
                if ( $sent ) {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '測試信已送出。', 'jify-shipping' ) . '</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( '測試信寄送失敗，請檢查 SMTP 設定。', 'jify-shipping' ) . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( '請輸入有效的 Email。', 'jify-shipping' ) . '</p></div>';
            }
        }

        $pending = self::get_pending_orders();
        $quotes = get_option( self::OPTION_MIXED_QUOTES, array() );
        if ( ! is_array( $quotes ) ) {
            $quotes = array();
        }
        $current_message = get_option( self::OPTION_MIXED_MESSAGE, 'Please contact us for shipping quote for mixed products' );
        $notify_emails = self::get_notify_emails();
        $current_cart_hash = self::get_current_cart_hash();
        $fluent_settings = get_option( 'fluentmail-settings', array() );
        $erp_smtp_settings = get_option( 'erp_settings_erp-email_smtp', array() );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Jify Setting', 'jify-shipping' ); ?></h1>
            <h2><?php esc_html_e( 'Dashboard', 'jify-shipping' ); ?></h2>
            <form method="post" style="margin: 10px 0 20px 0;">
                <?php wp_nonce_field( 'jify_shipping_notify_email_save', 'jify_shipping_notify_email_nonce' ); ?>
                <label for="notify_email" style="display:block; font-weight:600; margin-bottom:6px;">
                    <?php esc_html_e( 'Notify Email (逗號分隔)', 'jify-shipping' ); ?>
                </label>
                <input type="text" id="notify_email" name="notify_email" style="width:100%; max-width:400px;" value="<?php echo esc_attr( implode( ', ', $notify_emails ) ); ?>" placeholder="you@example.com, someone@example.com">
                <p style="margin-top:6px;">
                    <button type="submit" class="button button-primary" name="jify_shipping_notify_email_submit" value="1"><?php esc_html_e( '儲存', 'jify-shipping' ); ?></button>
                </p>
            </form>
            <div style="margin: 10px 0 20px 0; padding: 12px; border: 1px solid #ccd0d4; background: #f8f8f8;">
                <strong><?php esc_html_e( 'SMTP Status', 'jify-shipping' ); ?></strong>
                <ul style="margin: 8px 0 0 18px;">
                    <li>
                        <?php esc_html_e( 'FluentSMTP:', 'jify-shipping' ); ?>
                        <?php
                        $fluent_provider = isset( $fluent_settings['connection']['provider'] ) ? $fluent_settings['connection']['provider'] : '';
                        $fluent_enabled = ! empty( $fluent_provider );
                        echo $fluent_enabled ? esc_html( $fluent_provider ) : esc_html__( 'not configured', 'jify-shipping' );
                        ?>
                    </li>
                    <li>
                        <?php esc_html_e( 'ERP SMTP:', 'jify-shipping' ); ?>
                        <?php
                        $erp_enabled = isset( $erp_smtp_settings['enable_smtp'] ) && $erp_smtp_settings['enable_smtp'] === 'yes';
                        echo $erp_enabled ? esc_html__( 'enabled', 'jify-shipping' ) : esc_html__( 'disabled', 'jify-shipping' );
                        ?>
                    </li>
                </ul>
                <form method="post" style="margin-top:10px;">
                    <?php wp_nonce_field( 'jify_shipping_test_email', 'jify_shipping_test_email_nonce' ); ?>
                    <label for="test_email_to" style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e( 'Test Email To:', 'jify-shipping' ); ?></label>
                    <input type="email" id="test_email_to" name="test_email_to" style="width:100%; max-width:400px;" placeholder="you@example.com">
                    <button type="submit" class="button" name="jify_shipping_test_email_submit" value="1" style="margin-top:6px;"><?php esc_html_e( '寄送測試信', 'jify-shipping' ); ?></button>
                </form>
            </div>
            <h2><?php esc_html_e( '通知管理', 'jify-shipping' ); ?>
                <button
                    type="button"
                    class="button jify-hide-debug"
                    style="margin-left:8px;"
                    data-hide-label="<?php echo esc_attr__( 'Hide debug', 'jify-shipping' ); ?>"
                    data-show-label="<?php echo esc_attr__( 'Show debug', 'jify-shipping' ); ?>"
                ><?php esc_html_e( 'Hide debug', 'jify-shipping' ); ?></button>
            </h2>
            <form method="post" style="margin: 10px 0 20px 0;">
                <?php wp_nonce_field( 'jify_shipping_message_save', 'jify_shipping_message_nonce' ); ?>
                <label for="mixed_products_message" style="display:block; font-weight:600; margin-bottom:6px;">
                    <?php esc_html_e( 'Mixed Products Message', 'jify-shipping' ); ?>
                </label>
                <textarea id="mixed_products_message" name="mixed_products_message" rows="2" style="width:100%; max-width:700px;"><?php echo esc_textarea( $current_message ); ?></textarea>
                <p style="margin-top:6px;">
                    <button type="submit" class="button button-primary" name="jify_shipping_message_submit" value="1"><?php esc_html_e( '儲存', 'jify-shipping' ); ?></button>
                </p>
            </form>
            <p class="jify-admin-debug" style="margin: 6px 0 10px 0;">
                <strong><?php esc_html_e( 'Current Cart Hash:', 'jify-shipping' ); ?></strong>
                <code><?php echo $current_cart_hash !== '' ? esc_html( $current_cart_hash ) : esc_html__( 'N/A (no cart in admin)', 'jify-shipping' ); ?></code>
            </p>
            <script>
            (function() {
                var storageKey = 'jify_shipping_hide_debug';
                var hideAdminDebug = function() {
                    var rows = document.querySelectorAll('.jify-admin-debug');
                    rows.forEach(function(row) {
                        row.style.display = 'none';
                    });
                };
                var showAdminDebug = function() {
                    var rows = document.querySelectorAll('.jify-admin-debug');
                    rows.forEach(function(row) {
                        row.style.display = '';
                    });
                };
                var updateButtonLabel = function(isHidden) {
                    var buttons = document.querySelectorAll('.jify-hide-debug');
                    buttons.forEach(function(btn) {
                        var hideLabel = btn.getAttribute('data-hide-label') || 'Hide debug';
                        var showLabel = btn.getAttribute('data-show-label') || 'Show debug';
                        btn.textContent = isHidden ? showLabel : hideLabel;
                    });
                };
                var applyAdminDebugVisibility = function() {
                    var isHidden = window.localStorage && localStorage.getItem(storageKey) === '1';
                    if (isHidden) {
                        hideAdminDebug();
                    } else {
                        showAdminDebug();
                    }
                    updateButtonLabel(isHidden);
                };
                applyAdminDebugVisibility();
                document.addEventListener('click', function(e) {
                    if (!e.target || !e.target.classList || !e.target.classList.contains('jify-hide-debug')) {
                        return;
                    }
                    e.preventDefault();
                    if (window.localStorage) {
                        var isHidden = localStorage.getItem(storageKey) === '1';
                        localStorage.setItem(storageKey, isHidden ? '0' : '1');
                    }
                    applyAdminDebugVisibility();
                });
            })();
            </script>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( '時間', 'jify-shipping' ); ?></th>
                        <th><?php esc_html_e( '客戶姓名', 'jify-shipping' ); ?></th>
                        <th><?php esc_html_e( '電話', 'jify-shipping' ); ?></th>
                        <th><?php esc_html_e( '商品清單', 'jify-shipping' ); ?></th>
                        <th class="jify-admin-debug"><?php esc_html_e( 'Cart Hash', 'jify-shipping' ); ?></th>
                        <th><?php esc_html_e( '已報價', 'jify-shipping' ); ?></th>
                        <th><?php esc_html_e( '狀態', 'jify-shipping' ); ?></th>
                        <th><?php esc_html_e( '填入運費', 'jify-shipping' ); ?></th>
                        <th><?php esc_html_e( '刪除', 'jify-shipping' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $has_rows = false;
                foreach ( $pending as $hash => $entry ) {
                    if ( empty( $entry['notified'] ) ) {
                        continue;
                    }
                    $has_rows = true;
                    $items = isset( $entry['items'] ) && is_array( $entry['items'] ) ? implode( ', ', $entry['items'] ) : '';
                    ?>
                    <tr>
                        <td><?php echo esc_html( $entry['timestamp'] ); ?></td>
                        <td><?php echo esc_html( isset( $entry['customer_name'] ) ? $entry['customer_name'] : '' ); ?></td>
                        <td><?php echo esc_html( isset( $entry['customer_phone'] ) ? $entry['customer_phone'] : '' ); ?></td>
                        <td><?php echo esc_html( $items ); ?></td>
                        <td class="jify-admin-debug"><?php echo esc_html( $hash ); ?></td>
                        <td>
                            <?php
                            $quote_amount = isset( $quotes[ $hash ] ) ? $quotes[ $hash ] : '';
                            echo $quote_amount !== '' ? esc_html( $quote_amount ) : '&mdash;';
                            ?>
                        </td>
                        <td>
                            <?php if ( ! empty( $entry['superseded'] ) ) : ?>
                                <?php
                                $superseded_by = isset( $entry['superseded_by'] ) ? $entry['superseded_by'] : '';
                                $status_label = __( '已更新', 'jify-shipping' );
                                ?>
                                <span style="color:#a00;"><?php echo esc_html( $status_label ); ?></span>
                                <?php if ( $superseded_by !== '' ) : ?>
                                    <br><small><?php echo esc_html( $superseded_by ); ?></small>
                                <?php endif; ?>
                            <?php else : ?>
                                <span style="color:#135e96;"><?php esc_html_e( '待處理', 'jify-shipping' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post">
                                <?php wp_nonce_field( 'jify_shipping_quote_save', 'jify_shipping_quote_nonce' ); ?>
                                <input type="hidden" name="cart_hash" value="<?php echo esc_attr( $hash ); ?>">
                                <input type="number" step="0.01" name="shipping_amount" style="width:120px;" <?php echo ! empty( $entry['superseded'] ) ? 'disabled' : ''; ?>>
                                <button type="submit" class="button button-primary" name="jify_shipping_quote_submit" value="1" <?php echo ! empty( $entry['superseded'] ) ? 'disabled' : ''; ?>><?php esc_html_e( '送出', 'jify-shipping' ); ?></button>
                            </form>
                        </td>
                        <td>
                            <form method="post" onsubmit="return confirm('<?php echo esc_js( __( '確定要刪除這筆通知嗎？', 'jify-shipping' ) ); ?>');">
                                <?php wp_nonce_field( 'jify_shipping_pending_delete', 'jify_shipping_pending_delete_nonce' ); ?>
                                <input type="hidden" name="cart_hash" value="<?php echo esc_attr( $hash ); ?>">
                                <button type="submit" class="button" name="jify_shipping_pending_delete" value="1" aria-label="<?php esc_attr_e( '刪除通知', 'jify-shipping' ); ?>">×</button>
                            </form>
                        </td>
                    </tr>
                    <?php
                }
                if ( ! $has_rows ) {
                    echo '<tr><td colspan="9">' . esc_html__( '目前沒有通知紀錄。', 'jify-shipping' ) . '</td></tr>';
                }
                ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function clear_pending_orders() {
        update_option( self::OPTION_PENDING_ORDERS, array() );
    }

    public static function get_current_cart_hash() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return '';
        }
        return WC()->cart->get_cart_hash();
    }

    public static function add_product_tab( $tabs ) {
        $tabs['jify_shipping'] = array(
            'label'    => __( 'Jify Shipping', 'jify-shipping' ),
            'target'   => 'jify_shipping_product_data',
            'class'    => array( 'show_if_simple', 'show_if_variable' ),
            'priority' => 60,
        );
        return $tabs;
    }

    public static function add_product_data_panel() {
        global $post;
        $product = wc_get_product( $post->ID );
        if ( ! $product ) return;

        // Prepare list of items to configure
        $products_to_configure = array();
        
        // Optimization: For variable products, we hide the "Main Product" section to avoid redundancy
        if ( ! $product->is_type( 'variable' ) ) {
            $products_to_configure[] = array('id' => $post->ID, 'name' => __('Main Product / Default', 'jify-shipping'));
        }

        if ( $product->is_type( 'variable' ) ) {
            $variations = $product->get_available_variations();
            foreach ( $variations as $variation ) {
                $var_obj = wc_get_product($variation['variation_id']);
                $products_to_configure[] = array(
                    'id' => $variation['variation_id'],
                    'name' => strip_tags( $var_obj->get_formatted_name() )
                );
            }
        }

        echo '<div id="jify_shipping_product_data" class="panel woocommerce_options_panel hidden">';
        echo '<div id="jify-shipping-app">';

        foreach ( $products_to_configure as $item ) {
            $id = $item['id'];
            $name = $item['name'];
            
            $is_enabled = get_post_meta( $id, '_jify_shipping_enabled', true );
            $rules_str = get_post_meta( $id, '_jify_shipping_costs', true ); 
            $mixed_products_message = get_post_meta( $id, '_jify_shipping_mixed_products_message', true );
            
            // Parse existing rules into array for the UI
            $rules = array();
            if ( ! empty( $rules_str ) ) {
                $pairs = explode( '|', $rules_str );
                foreach ( $pairs as $pair ) {
                    $parts = explode( ':', $pair );
                    if ( count( $parts ) === 2 ) $rules[] = array( 'qty' => $parts[0], 'cost' => $parts[1] );
                }
            }
            ?>
            <div class="jify-product-row" style="border: 1px solid #ccd0d4; padding: 15px; margin: 10px 10px 20px 10px; background: #fff;">
                <h3 style="margin-top:0; border-bottom: 1px solid #eee; padding-bottom: 10px; font-size: 14px;">
                    <?php echo esc_html( $name ); ?> <span style="font-size: 11px; color: #a7aaad;">(ID: <?php echo $id; ?>)</span>
                </h3>
                
                <div class="jify-ship-toggles" style="padding:0; margin: 10px 0;">
                    <p class="form-field" style="padding:0; margin: 0;">
                        <label style="float:none; display:inline-block; font-weight:600;">
                            <input type="checkbox" name="jify_ship_enabled[<?php echo $id; ?>]" value="yes" <?php checked( $is_enabled, 'yes' ); ?> class="jify-enable-toggle" style="float:none; margin-right:5px;">
                            <?php esc_html_e( 'Enable Jify Shipping (Forces Separate Checkout)', 'jify-shipping' ); ?>
                        </label>
                    </p>
                </div>

                <div class="jify-ship-container" style="<?php echo $is_enabled === 'yes' ? '' : 'display:none;'; ?>;">
                    <div style="margin-bottom:15px; padding:15px; background:#f5f5f5; border-left:4px solid #9aa0a6;">
                        <p style="margin:0 0 6px 0; font-weight:700; color:#333; font-size:13px;"><?php esc_html_e('Message (Mixed Products):', 'jify-shipping'); ?></p>
                        <textarea id="jify_ship_mixed_message_<?php echo $id; ?>" name="jify_ship_mixed_message[<?php echo $id; ?>]" rows="2" style="width:100%; max-width:600px; box-sizing:border-box; display:block; margin:0;" placeholder="<?php esc_attr_e('e.g., Please contact us for shipping quote for mixed products', 'jify-shipping'); ?>"><?php echo esc_textarea($mixed_products_message); ?></textarea>
                        <small style="color:#666; display:block; margin-top:4px;"><?php esc_html_e('Shown when cart contains this product + other Jify products', 'jify-shipping'); ?></small>
                    </div>
                    
                    <p class="description" style="margin-bottom:10px;"><?php esc_html_e('Define shipping costs per quantity or range (e.g. "1" or "1-5").', 'jify-shipping'); ?></p>
                    <table class="widefat" style="border:none; background: #f9f9f9;">
                        <thead>
                            <tr>
                                <th style="padding-left:10px;">Qty / Range (e.g. 1-5)</th>
                                <th>Shipping Cost ($)</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="jify-ship-rules-list">
                            <?php foreach ( $rules as $rule ) : ?>
                            <tr>
                                <td style="padding-left:10px;">
                                    <input type="text" class="short" name="jify_ship_qty[<?php echo $id; ?>][]" value="<?php echo esc_attr($rule['qty']); ?>" placeholder="1-5">
                                </td>
                                <td>
                                    <input type="number" step="0.01" class="short" name="jify_ship_cost[<?php echo $id; ?>][]" value="<?php echo esc_attr($rule['cost']); ?>" placeholder="100">
                                </td>
                                <td><button type="button" class="button remove-row">x</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin-top:10px;"><button type="button" class="button add-jify-ship-rule" data-id="<?php echo $id; ?>">+ Add Rule</button></p>
                </div>
            </div>
            <?php
        }
        echo '</div>'; // End app container
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Toggle visibility
            $('#jify-shipping-app').on('change', '.jify-enable-toggle', function() {
                var $row = $(this).closest('.jify-product-row');
                var isEnabled = $(this).is(':checked');
                $row.find('.jify-ship-container').toggle(isEnabled);
            });

            // Add Rule
            $('#jify-shipping-app').on('click', '.add-jify-ship-rule', function() {
                var id = $(this).data('id');
                var row = `<tr>
                    <td style="padding-left:10px;"><input type="text" class="short" name="jify_ship_qty[${id}][]" placeholder="e.g. 1-5"></td>
                    <td><input type="number" step="0.01" class="short" name="jify_ship_cost[${id}][]" placeholder="Cost"></td>
                    <td><button type="button" class="button remove-row">x</button></td>
                </tr>`;
                $(this).closest('.jify-product-row').find('tbody').append(row);
            });

            // Remove Rule
            $('#jify-shipping-app').on('click', '.remove-row', function() { 
                $(this).closest('tr').remove(); 
            });
        });
        </script>
        <?php
        echo '</div>';
    }

    public static function save_tab_data( $post_id ) {
        // Collect all IDs from POST data to handle variations
        $all_ids = array();
        if ( isset( $_POST['jify_ship_enabled'] ) ) $all_ids = array_keys( $_POST['jify_ship_enabled'] );
        if ( isset( $_POST['jify_ship_qty'] ) ) $all_ids = array_merge( $all_ids, array_keys( $_POST['jify_ship_qty'] ) );
        if ( isset( $_POST['jify_ship_mixed_message'] ) ) $all_ids = array_merge( $all_ids, array_keys( $_POST['jify_ship_mixed_message'] ) );
        $all_ids = array_unique( $all_ids );

        foreach ( $all_ids as $id ) {
            // Save Enabled Status
            $enabled = isset( $_POST['jify_ship_enabled'][$id] ) ? 'yes' : 'no';
            update_post_meta( $id, '_jify_shipping_enabled', $enabled );
            
            if ( isset( $_POST['jify_ship_mixed_message'][$id] ) ) {
                update_post_meta( $id, '_jify_shipping_mixed_products_message', sanitize_textarea_field( $_POST['jify_ship_mixed_message'][$id] ) );
            }

            // Save Rules (Serialize array back to string format for logic compatibility)
            $rule_str = '';
            if ( isset( $_POST['jify_ship_qty'][$id] ) && isset( $_POST['jify_ship_cost'][$id] ) ) {
                $qtys = $_POST['jify_ship_qty'][$id]; 
                $costs = $_POST['jify_ship_cost'][$id]; 
                $pairs = array();
                for ( $i = 0; $i < count( $qtys ); $i++ ) {
                    if ( ! empty( $qtys[$i] ) && is_numeric( $costs[$i] ) ) {
                        $pairs[] = sanitize_text_field($qtys[$i]) . ':' . sanitize_text_field($costs[$i]);
                    }
                }
                $rule_str = implode( '|', $pairs );
            }
            update_post_meta( $id, '_jify_shipping_costs', $rule_str );
        }
    }

    public static function cart_validation( $passed, $product_id, $quantity, $variation_id = 0 ) {
        return $passed;
    }

    public static function add_shipping_method( $methods ) {
        $methods['jify_shipping'] = 'WC_Jify_Shipping_v3';
        return $methods;
    }

    public static function shipping_method_init() {
        // Class defined outside to prevent redeclaration errors
    }
}

Jify_Shipping_Upgrade::init();

// === Shipping Method Class (Defined Outside) ===

if ( ! class_exists( 'WC_Jify_Shipping_v3' ) ) {
    add_action( 'woocommerce_shipping_init', 'jify_shipping_v3_class_define' );
    
    function jify_shipping_v3_class_define() {
        if ( ! class_exists( 'WC_Shipping_Method' ) ) return;
        
        class WC_Jify_Shipping_v3 extends WC_Shipping_Method {
            public function __construct( $instance_id = 0 ) {
                parent::__construct( $instance_id );
                $this->id = 'jify_shipping';
                $this->method_title = __( 'Jify Shipping', 'jify-shipping' );
                $this->method_description = __( 'Quantity-based shipping with mixed-product quote flow', 'jify-shipping' );
                // Support shipping zones and instance settings
                $this->supports = array( 
                    'shipping-zones',
                    'instance-settings',
                    'instance-settings-modal'
                ); 
                $this->init();
            }

            public function init() {
                $this->init_form_fields();
                $this->init_settings();
                $this->title = $this->get_option( 'title' );
                $this->enabled = $this->get_option( 'enabled' );
                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }

            public function process_admin_options() {
                $result = parent::process_admin_options();
                return $result;
            }

            public function init_form_fields() {
                $this->instance_form_fields = array(
                    'enabled' => array( 'title' => 'Enable', 'type' => 'checkbox', 'default' => 'yes' ),
                    'title'   => array( 'title' => 'Title', 'type' => 'text', 'default' => 'Jify Shipping' ),
                );
            }

            public function calculate_shipping( $package = array() ) {
                $total_qty = 0;
                $jify_product_ids = array();
                $has_non_jify = false;
                
                // Collect all Jify product IDs in cart
                foreach( $package['contents'] as $item ) {
            $total_qty += $item['quantity'];
            $vid = $item['variation_id'];
            $pid = $item['product_id'];
            $is_jify_item = false;
            
            // Check if this item has Jify shipping enabled
            if ( get_post_meta( $vid, '_jify_shipping_enabled', true ) === 'yes' ) {
                $jify_product_ids[] = $vid;
                $is_jify_item = true;
            } elseif ( get_post_meta( $pid, '_jify_shipping_enabled', true ) === 'yes' ) {
                $jify_product_ids[] = $pid;
                $is_jify_item = true;
            }

            if ( ! $is_jify_item ) {
                $product = isset( $item['data'] ) ? $item['data'] : null;
                if ( ! $product || ( method_exists( $product, 'needs_shipping' ) && $product->needs_shipping() ) ) {
                    $has_non_jify = true;
                }
            }
        }
        
        // Remove duplicates
        $jify_product_ids = array_unique( $jify_product_ids );
        
        // No Jify products in cart
        if ( empty( $jify_product_ids ) ) return;
        
        // Mixed scenario: multiple Jify or Jify + non-Jify
        if ( count( $jify_product_ids ) > 1 || $has_non_jify ) {
            $quote = Jify_Shipping_Upgrade::get_mixed_quote_for_cart();
            if ( $quote !== '' ) {
                $label = $this->title ? $this->title : __( 'Jify Shipping', 'jify-shipping' );
                $this->add_rate( array(
                    'id'    => $this->get_rate_id(),
                            'label' => sprintf( __( '%s - %s', 'jify-shipping' ), $label, wc_price( floatval( $quote ) ) ),
                            'cost'  => floatval( $quote ),
                            'meta_data' => array( 'mixed_products' => true, 'quoted' => true )
                        ));
                        return;
                    }

                    $mixed_message = Jify_Shipping_Upgrade::get_cart_mixed_message();
                    if ( empty( $mixed_message ) ) {
                        $mixed_message = __( 'Please contact us for shipping quote for mixed products', 'jify-shipping' );
                    }
                    
                    Jify_Shipping_Upgrade::record_mixed_pending_order( $mixed_message, $jify_product_ids );
                    
                    $this->add_rate( array(
                        'id'    => $this->get_rate_id(),
                        'label' => $mixed_message,
                        'cost'  => 0,
                        'meta_data' => array( 'mixed_products' => true )
                    ));
                    return;
                }
                
                // Single Jify product - use normal calculation
                $target_product_id = $jify_product_ids[0];

                $rules_str = get_post_meta( $target_product_id, '_jify_shipping_costs', true );
                if ( empty( $rules_str ) ) return;

                $cost = 0;
                $found_match = false;
                $rules_pairs = explode( '|', $rules_str );
                
                // Logic: Find the first matching rule
                foreach ( $rules_pairs as $pair ) {
                    $parts = explode( ':', $pair );
                    if ( count( $parts ) !== 2 ) continue;
                    
                    $qty_def = trim($parts[0]);
                    $rule_cost = floatval(trim($parts[1]));

                    if ( strpos( $qty_def, '-' ) !== false ) {
                        // Range Match (e.g. "1-5")
                        $range = explode( '-', $qty_def );
                        $min = intval($range[0]);
                        $max = intval($range[1]);
                        if ( $total_qty >= $min && $total_qty <= $max ) {
                            $cost = $rule_cost;
                            $found_match = true;
                            break;
                        }
                    } else {
                        // Exact Match (e.g. "10")
                        if ( intval($qty_def) == $total_qty ) {
                            $cost = $rule_cost;
                            $found_match = true;
                            break;
                        }
                    }
                }
                
                // If no matching rule found, fall back to mixed-quote flow
                if ( ! $found_match ) {
                    $mixed_message = Jify_Shipping_Upgrade::get_cart_mixed_message();
                    if ( empty( $mixed_message ) ) {
                        $mixed_message = __( 'Please contact us for shipping quote for mixed products', 'jify-shipping' );
                    }
                    Jify_Shipping_Upgrade::record_mixed_pending_order( $mixed_message, $jify_product_ids );
                    $this->add_rate( array(
                        'id'    => $this->get_rate_id(),
                        'label' => $mixed_message,
                        'cost'  => 0,
                        'meta_data' => array( 'mixed_products' => true, 'quantity_exceeds_rules' => true )
                    ));
                    return;
                }

                // Check for Seasonal Surcharge (Global Setting)
                $final_label = $this->title;
                if ( get_option('jify_shipping_surcharge_enabled') === 'yes' ) {
                    $now = current_time('Y-m-d');
                    $start = get_option('jify_shipping_surcharge_start_date');
                    $end = get_option('jify_shipping_surcharge_end_date');
                    
                    $in_range = true;
                    if ( ! empty( $start ) && $now < $start ) $in_range = false;
                    if ( ! empty( $end ) && $now > $end ) $in_range = false;

                    if ( $in_range ) {
                        $surcharge = floatval( get_option('jify_shipping_surcharge_amount') );
                        $desc = get_option('jify_shipping_surcharge_desc');
                        if ( $surcharge > 0 ) $cost += $surcharge;
                        if ( ! empty( $desc ) ) $final_label .= ' (' . $desc . ')';
                    }
                }

                $this->add_rate( array(
                    'id'    => $this->get_rate_id(),
                    'label' => $final_label,
                    'cost'  => $cost
                ));
            }
        }
    }
}
