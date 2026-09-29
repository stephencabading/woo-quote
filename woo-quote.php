<?php
/**
 * Plugin Name: Woo Quote System
 * Description: Cart-style quote system with WooCommerce orders.
 * Version: 2.0.0
 * Author: Stephen Cabading
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Woo_Quote_System {

    public function __construct() {

        // Frontend
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'woocommerce_after_add_to_cart_button', [ $this, 'add_to_quote_button' ] );
        add_filter( 'woocommerce_loop_add_to_cart_link', [ $this, 'add_to_quote_link' ], 10, 2 );
        add_filter( 'wp_nav_menu_items', [ $this, 'add_quote_link' ], 10, 2 );

        // Backend
        add_action( 'init', [ $this, 'register_quote_statuses' ] );
        add_filter( 'wc_order_statuses', [ $this, 'add_quote_statuses' ] );
        add_filter( 'woocommerce_can_reduce_order_stock', [ $this, 'can_reduce_order_stock' ], 10, 2 );
        add_action( 'woocommerce_order_status_new-quote-request', [ $this, 'optimized_quote_notifications' ], 10, 2 );
        add_filter( 'woocommerce_get_order_item_totals', [ $this, 'quote_item_totals' ], 10, 3 );
        add_action( 'process_quote_background_cron', [ $this, 'process_quote_background' ], 10, 3 );

        // Shortcode
        add_shortcode( 'woo_quote_form', [ $this, 'render_quote_form' ] );

        // AJAX
        add_action( 'wp_ajax_woo_submit_quote', [ $this, 'submit_quote' ] );
        add_action( 'wp_ajax_nopriv_woo_submit_quote', [ $this, 'submit_quote' ] );
    }

    public function enqueue_assets() {
        if ( ! class_exists( 'WooCommerce' ) ) return;

        wp_enqueue_script(
            'woo-quote-js',
            plugin_dir_url( __FILE__ ) . 'assets/scripts.js',
            [ 'jquery' ],
            '1.5.6',
            true
        );

        wp_enqueue_style(
            'woo-quote-css',
            plugin_dir_url( __FILE__ ) . 'assets/style.css',
            [],
            '1.3.5'
        );

        wp_localize_script( 'woo-quote-js', 'wooQuote', [
            'ajax'      => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'woo_quote' ),
            'logged_in' => is_user_logged_in(),
        ] );
    }

    /**
     * Add quote link to menu
     */
    public function add_quote_link( $items, $args ) {
        if ( $args->theme_location !== 'main_menu' ) {
            return $items;
        }

        $items .= '<li class="menu-item woo-quote-menu"><a href="/quote-request">Request a Quote<span class="woo-quote-count">0</span></a></li>';

        return $items;
    }

    /**
     * Register quote statuses
     */
    public function register_quote_statuses() {
        $statuses = [
            'wc-new-quote-request' => 'New Quote Request',
            'wc-on-hold-quote'     => 'On Hold Quote',
            'wc-accepted-quote'    => 'Accepted Quote',
            'wc-rejected-quote'    => 'Rejected Quote',
            'wc-rejected-by-customer' => 'Rejected by Customer',
            'wc-expired-quote'     => 'Expired Quote',
        ];

        foreach ( $statuses as $status => $label ) {

            register_post_status( $status, [
                'label'                     => $label,
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    "$label <span class='count'>(%s)</span>",
                    "$label <span class='count'>(%s)</span>"
                ),
            ] );
        }
    }

    /**
     * Add quote statuses to order statuses
     */
    public function add_quote_statuses( $statuses ) {
        $new = [];

        foreach ( $statuses as $key => $label ) {
            $new[ $key ] = $label;

            if ( 'wc-pending' === $key ) {
                $new['wc-new-quote-request']     = 'New Quote Request';
                $new['wc-on-hold-quote']         = 'On Hold Quote';
                $new['wc-accepted-quote']        = 'Accepted Quote';
                $new['wc-rejected-quote']        = 'Rejected Quote';
                $new['wc-rejected-by-customer']  = 'Rejected by Customer';
                $new['wc-expired-quote']         = 'Expired Quote';
            }
        }

        return $new;
    }

    /**
     * Prevent stock reduction for quote orders
     */
    public function can_reduce_order_stock( $reduce, $order ) {
        $quote_statuses = [
            'new-quote-request',
            'on-hold-quote',
            'accepted-quote',
            'rejected-quote',
            'rejected-by-customer',
            'expired-quote',
        ];

        return in_array( $order->get_status(), $quote_statuses, true )
            ? false
            : $reduce;
    }

    /**
     * Add to Quote button
     */
    public function add_to_quote_button() {
        global $product;

        if ( ! $product ) return;

        $product_id     = $product->get_id();
        $product_name   = $product->get_name();
        $variation_id   = '';
        $variation_name = '';
        $is_variable    = $product->is_type( 'variable' );

        if ( $is_variable ) {
            $default_attributes = $product->get_default_attributes();

            if ( ! empty( $default_attributes ) ) {
                $matching_attributes = array();
                foreach ( $default_attributes as $attribute => $value ) {
                    $matching_attributes[ 'attribute_' . $attribute ] = $value;
                }

                $data_store = WC_Data_Store::load( 'product' );
                $found_id   = $data_store->find_matching_product_variation( $product, $matching_attributes );

                if ( $found_id > 0 ) {
                    $variation_id  = $found_id;
                    $variation_obj = wc_get_product( $found_id );
                    
                    if ( $variation_obj ) {
                        $full_name      = $variation_obj->get_name();
                        $variation_name = str_replace( $product_name . ' - ', '', $full_name );
                    }
                }
            }
        }

        echo '<button
            type="button"
            class="woo-add-to-quote button"
            data-product-id="'   . esc_attr( $product_id ) . '"
            data-variation-id="' . esc_attr( $variation_id ) . '"
            data-product-name="' . esc_attr( $product_name ) . '"
            data-variation-name="'. esc_attr( $variation_name ) . '"
            data-is-variable="'  . ( $is_variable ? 'yes' : 'no' ) . '"
            data-product-image="' . esc_url( wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ) . '"
            data-product-price="' . esc_attr( wc_get_price_to_display( $variation_id ? wc_get_product($variation_id) : $product ) ) . '">
            Add to Quote
        </button>';
    }

    /** 
     * Add quote link to loop
    */
    public function add_to_quote_link( $html, $product ) {
        if ( ! $product ) return;

        $product_id     = $product->get_id();
        $product_name   = $product->get_name();
        $variation_id   = '';
        $variation_name = '';
        $is_variable    = $product->is_type( 'variable' );

        if ( $is_variable ) {
            $default_attributes = $product->get_default_attributes();

            if ( ! empty( $default_attributes ) ) {
                $matching_attributes = array();
                foreach ( $default_attributes as $attribute => $value ) {
                    $matching_attributes[ 'attribute_' . $attribute ] = $value;
                }

                $data_store = WC_Data_Store::load( 'product' );
                $found_id   = $data_store->find_matching_product_variation( $product, $matching_attributes );

                if ( $found_id > 0 ) {
                    $variation_id  = $found_id;
                    $variation_obj = wc_get_product( $found_id );
                    
                    if ( $variation_obj ) {
                        $full_name      = $variation_obj->get_name();
                        $variation_name = str_replace( $product_name . ' - ', '', $full_name );
                    }
                }
            }
        }

        $quote_btn = '<a 
            href="#" 
            class="woo-add-to-quote button alt"
            data-product-id="'   . esc_attr( $product_id ) . '"
            data-variation-id="' . esc_attr( $variation_id ) . '"
            data-product-name="' . esc_attr( $product_name ) . '"
            data-variation-name="'. esc_attr( $variation_name ) . '"
            data-is-variable="'  . ( $is_variable ? 'yes' : 'no' ) . '"
            data-product-image="' . esc_url( wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ) . '"
            data-product-price="' . esc_attr( wc_get_price_to_display( $variation_id ? wc_get_product($variation_id) : $product ) ) . '">
            Add to Quote
        </a>';
        return $html . $quote_btn;
    }

    /**
     * Quote page shortcode
     */
    public function render_quote_form() {
        $states = WC()->countries->get_states( 'AU' );

        echo '<div class="woocommerce woo-quote-wrapper row">

            <div class="quote-table col-md-6 border p-4 order-md-last d-none">
                <table class="shop_table responsive cart woocommerce-cart-form__contents woo-quote-table">
                    <thead>
                        <tr>
                            <th class="product-thumbnail">Image</th>
                            <th class="product-name">Product</th>
                            <th class="product-price">Price</th>
                            <th class="product-quantity">Quantity</th>
                            <th class="product-subtotal">Subtotal</th>
                            <th class="product-remove"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="customer-details col-md-6 pe-md-4 d-none">
                <form class="woocommerce-checkout woo-quote-form">
                    <h3>Contact details</h3>
                    
                    <div class="woocommerce-billing-fields__field-wrapper">

                        <p class="form-row form-row-first validate-required" id="billing_first_name_field">
                            <label for="first_name">First name <abbr class="required" title="required">*</abbr></label>
                            <span class="woocommerce-input-wrapper">
                                <input type="text" class="input-text" name="first_name" id="first_name" placeholder="First name" required>
                            </span>
                        </p>

                        <p class="form-row form-row-last validate-required" id="billing_last_name_field">
                            <label for="last_name">Last name <abbr class="required" title="required">*</abbr></label>
                            <span class="woocommerce-input-wrapper">
                                <input type="text" class="input-text" name="last_name" id="last_name" placeholder="Last name" required>
                            </span>
                        </p>

                        <p class="form-row form-row-first validate-required validate-email" id="billing_email_field">
                            <label for="email">Email address <abbr class="required" title="required">*</abbr></label>
                            <span class="woocommerce-input-wrapper">
                                <input type="email" class="input-text" name="email" id="email" placeholder="Email" required>
                            </span>
                        </p>

                        <p class="form-row form-row-last" id="billing_company_field">
                            <label for="company">Company / Hospital</label>
                            <span class="woocommerce-input-wrapper">
                                <input type="text" class="input-text" name="company" id="company" placeholder="Company / Hospital">
                            </span>
                        </p>

                        <p class="form-row form-row-first validate-required" id="billing_address_1_field">
                            <label for="address_1">Street address <abbr class="required" title="required">*</abbr></label>
                            <span class="woocommerce-input-wrapper">
                                <input type="text" class="input-text" name="address_1" id="address_1" placeholder="House number and street name" required>
                            </span>
                        </p>

                        <p class="form-row form-row-last" id="billing_address_2_field">
                            <label for="address_2">&nbsp;</label>
                            <span class="woocommerce-input-wrapper">
                                <input type="text" class="input-text" name="address_2" id="address_2" placeholder="Apartment, suite, unit, etc. (optional)">
                            </span>
                        </p>

                        <p class="form-row form-row-first validate-required" id="billing_city_field">
                            <label for="city">Suburb <abbr class="required" title="required">*</abbr></label>
                            <span class="woocommerce-input-wrapper">
                                <input type="text" class="input-text" name="city" id="city" placeholder="Suburb" required>
                            </span>
                        </p>

                        <p class="form-row form-row-last validate-required" id="billing_postcode_field">
                            <label for="postcode">Postcode <abbr class="required" title="required">*</abbr></label>
                            <span class="woocommerce-input-wrapper">
                                <input type="text" class="input-text" name="postcode" id="postcode" placeholder="Postcode" required>
                            </span>
                        </p>

                        <p class="form-row form-row-first address-field validate-state" id="billing_state_field">
                            <label for="state">State <abbr class="required" title="required">*</abbr></label>
                            <span class="woocommerce-input-wrapper">
                                <select name="state" id="state" class="state_select" style="padding: 13px 12px;" required>
                                    <option value="">Select a state&hellip;</option>';
                                    foreach ( $states as $code => $label ) {
                                        echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>';
                                    }
                    echo '      </select>
                            </span>
                        </p>

                        <p class="form-row form-row-last validate-required" id="billing_phone_field">
                            <label for="phone">Phone <abbr class="required" title="required">*</abbr></label>
                            <span class="woocommerce-input-wrapper">
                                <input type="text" class="input-text" name="phone" id="phone" placeholder="Phone" required>
                            </span>
                        </p>

                        <p class="form-row form-row-wide" id="order_comments_field">
                            <label for="note">Additional Notes</label>
                            <span class="woocommerce-input-wrapper">
                                <textarea name="note" class="input-text" id="note" placeholder="Notes about your quote request" rows="2" cols="5"></textarea>
                            </span>
                        </p>

                    </div>

                    <div class="form-row">
                        <button type="submit" class="button alt pull-right" name="woocommerce_checkout_place_order" id="place_order">Submit Quote Request</button>
                    </div>
                </form>
            </div>
        </div>';
    }

    /**
     * Create WooCommerce order
     */
    public function submit_quote() {

        check_ajax_referer( 'woo_quote', 'nonce' );
        
        add_filter( 'woocommerce_defer_emails', '__return_true' );
        add_filter( 'woocommerce_is_checkout', '__return_false' );

        $items = json_decode( stripslashes( $_POST['items'] ), true );

        if ( empty( $items ) ) {
            wp_send_json_error( [ 'message' => 'Quote is empty.' ] );
        }

        $order_id = wp_insert_post([
            'post_type'   => 'shop_order',
            'post_status' => 'wc-pending',
            'post_title'  => 'Quote – ' . current_time('mysql'),
            'post_author' => get_current_user_id(),
        ], true);

        if ( is_wp_error( $order_id ) ) {
            wp_send_json_error([ 'message' => 'Order creation failed.' ]);
        }

        try {
            wp_schedule_single_event(
                time() + 1,
                'process_quote_background_cron',
                [ $order_id, $items, $_POST ]
            );
        } catch (Exception $e) {
            error_log( 'Error scheduling process_quote_background_cron: ' . $e->getMessage() );
        }

        wp_send_json_success([
            'message'  => 'Quote submitted successfully.',
            'order_id' => $order_id,
        ]);
    }

    /**
     * Process quote
     */
    public function process_quote_background( $order_id, $items, $post_data ) {
        
        $stored_hooks = $this->suspend_order_hooks();

        $order = wc_get_order( $order_id );

        foreach ( $items as $item ) {

            if ( ! empty( $item['variation_id'] ) ) {
                $product = wc_get_product( $item['variation_id'] );
            } else {
                $product = wc_get_product( $item['product_id'] );
            }

            if ( $product ) {
                $order->add_product( $product, $item['qty'] );
            }
        }
        
        $order->set_address([
            'first_name' => sanitize_text_field( $post_data['first_name'] ),
            'last_name'  => sanitize_text_field( $post_data['last_name'] ),
            'email'      => sanitize_email( $post_data['email'] ),
            'company'    => sanitize_text_field( $post_data['company'] ),
            'state'      => sanitize_text_field( $post_data['state'] ),
            'city'       => sanitize_text_field( $post_data['city'] ),
            'postcode'   => sanitize_text_field( $post_data['postcode'] ),
            'address_1'  => sanitize_text_field( $post_data['address_1'] ),
            'address_2'  => sanitize_text_field( $post_data['address_2'] ),
            'country'    => 'AU',
        ], 'billing' );

        $order->set_billing_phone( sanitize_text_field( $post_data['phone'] ) );

        if ( ! empty( $post_data['note'] ) ) {
            $order->set_customer_note( sanitize_textarea_field( $post_data['note'] ) );
        }

        $this->restore_order_hooks( $stored_hooks );

        $order->update_status( 'new-quote-request' );
        $order->update_meta_data( '_is_quote', 'yes' );
        $order->save();
    }

    /**
     * Optimized quote notifications
     */
    public function optimized_quote_notifications( $order_id, $order ) {

        if ( get_post_meta( $order_id, '_quote_email_sent', true ) ) {
            return;
        }

        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }

        $mailer = WC()->mailer();

        $new_order_settings = $mailer->emails['WC_Email_New_Order'];
        $admin_recipients = $new_order_settings->get_recipient();

        ob_start();
        wc_get_template( 'emails/email-order-details.php', [
            'order'           => $order,
            'sent_to_admin'   => false,
            'plain_text'      => false,
            'email'           => null,
            'sent_to_customer'=> true,
        ] );
        wc_get_template( 'emails/email-order-notes.php', [
            'order'           => $order,
            'sent_to_admin'   => false,
            'plain_text'      => false,
            'email'           => null,
        ] );
        wc_get_template( 'emails/email-addresses.php', [
            'order'           => $order,
            'sent_to_admin'   => false,
            'plain_text'      => false,
            'email'           => null,
        ] );
        $order_details_html = ob_get_clean();

        $admin_content = '<p>A new quote request has been received from '
            . esc_html( $order->get_billing_first_name() ) . ' '
            . esc_html( $order->get_billing_last_name() ) . '.</p>'
            . $order_details_html;

        $mailer->send(
            $admin_recipients,
            "New Quote Request (#$order_id)",
            $mailer->wrap_message( 'New Quote Request', $admin_content )
        );

        $customer_content = '<p>Hi ' . esc_html( $order->get_billing_first_name() )
            . ', thank you for your request. We are reviewing it now.</p>'
            . $order_details_html;

        $mailer->send(
            $order->get_billing_email(),
            "Quote Received #$order_id",
            $mailer->wrap_message( 'Quote Request Received', $customer_content )
        );

        update_post_meta( $order_id, '_quote_email_sent', 'yes' );
    }


    /**
     * Remove Total and keep only Subtotal for Quote Requests
     */
    public function quote_item_totals( $total_rows, $order, $tax_display ) {
        
        if ( $order->has_status( 'new-quote-request' ) ) {
            
            if ( isset( $total_rows['order_total'] ) ) {
                unset( $total_rows['order_total'] );
            }

            if ( isset( $total_rows['tax'] ) ) {
                unset( $total_rows['tax'] );
            }
            
            if ( isset( $total_rows['cart_subtotal'] ) ) {
                $total_rows['cart_subtotal']['label'] = __( 'Quote Subtotal:', 'woocommerce' );
            }
        }

        return $total_rows;
    }

    /**
     * Suspend order hooks
     */
    private function suspend_order_hooks() {
        global $wp_filter;

        $hooks = [
            'save_post',
            'save_post_shop_order',
            'wp_insert_post',
            'transition_post_status',
            'woocommerce_update_order',
            'woocommerce_order_status_changed',
        ];

        $stored = [];

        foreach ( $hooks as $hook ) {
            if ( isset( $wp_filter[ $hook ] ) ) {
                $stored[ $hook ] = $wp_filter[ $hook ];
                unset( $wp_filter[ $hook ] );
            }
        }

        return $stored;
    }

    /**
     * Restore order hooks
     */
    private function restore_order_hooks( $stored ) {
        global $wp_filter;

        foreach ( $stored as $hook => $callbacks ) {
            $wp_filter[ $hook ] = $callbacks;
        }
    }

}

new Woo_Quote_System();