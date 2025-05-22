<?php

/**
 * Plugin Name: WooCommerce Visual Audio Books
 * Description: Creates protected pages for visual audio book products with unique token access
 * Version: 1.0
 * Author: rahmat mondol
 * Author URI: https://rahmatmondol.com
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WooVisualAudioBooks
{

    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'visual_audiobook_tokens';

        add_action('init', array($this, 'init'));
        add_action('woocommerce_order_status_completed', array($this, 'generate_token_on_purchase'));
        add_action('woocommerce_payment_complete', array($this, 'generate_token_on_payment'));
        add_action('wp', array($this, 'check_token_access'));
        add_action('woocommerce_thankyou', array($this, 'display_access_links_thankyou'));
        add_shortcode('user_name_display', array($this, 'display_user_name_shortcode'));

        // My Account page integration
        add_filter('woocommerce_account_menu_items', array($this, 'add_my_account_menu_items'));
        add_action('woocommerce_account_visual-audiobooks_endpoint', array($this, 'my_account_visual_audiobooks_content'));
        add_action('init', array($this, 'add_my_account_endpoint'));


        register_activation_hook(__FILE__, array($this, 'create_tokens_table'));
    }

    public function init()
    {
        // Initialize plugin
    }

    /**
     * Create database table for storing tokens
     */
    public function create_tokens_table()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            order_id bigint(20) NOT NULL,
            product_id bigint(20) NOT NULL,
            page_id bigint(20) NOT NULL,
            token varchar(255) NOT NULL,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Generate unique token when payment is completed (immediate access)
     */
    public function generate_token_on_payment($order_id)
    {
        $this->generate_token_on_purchase($order_id);
    }

    /**
     * Display access links on thank you page
     */
    public function display_access_links_thankyou($order_id)
    {
        if (!$order_id) return;

        global $wpdb;

        // Get tokens for this order
        $tokens = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE order_id = %d",
            $order_id
        ));

        if (!empty($tokens)) {
            echo '<div class="visual-audiobook-access" style="background: #f8f9fa; border: 2px solid #28a745; border-radius: 8px; padding: 20px; margin: 20px 0;">';
            echo '<h3 style="color: #28a745; margin-top: 0;"><i class="dashicons dashicons-yes-alt"></i> Your Visual Audio Books Are Ready!</h3>';
            echo '<p style="margin-bottom: 15px;">Thank you for your purchase! You can now access your visual audio books using the links below:</p>';

            foreach ($tokens as $token) {
                $product_title = get_the_title($token->product_id);
                $page_url = get_permalink($token->page_id);
                $access_url = add_query_arg('token', $token->token, $page_url);

                echo '<div style="background: white; border-radius: 5px; padding: 15px; margin-bottom: 10px; border-left: 4px solid #007cba;">';
                echo '<h4 style="margin: 0 0 10px 0; color: #333;">' . esc_html($product_title) . '</h4>';
                echo '<p style="margin: 5px 0;"><strong>Access Link:</strong></p>';
                echo '<div style="background: #f1f1f1; padding: 10px; border-radius: 3px; margin: 5px 0;">';
                echo '<code style="word-break: break-all; font-size: 12px;">' . esc_url($access_url) . '</code>';
                echo '</div>';
                echo '<a href="' . esc_url($access_url) . '" target="_blank" style="display: inline-block; background: #007cba; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; margin-top: 5px;">Access Now →</a>';
                echo '</div>';
            }

            echo '<p style="margin-bottom: 0; font-size: 14px; color: #666;"><strong>Important:</strong> Please bookmark these links or save this email. You\'ll need them to access your content anytime.</p>';
            echo '</div>';

            // Add some CSS for better styling
            echo '<style>
                .visual-audiobook-access .dashicons {
                    font-size: 20px;
                    vertical-align: middle;
                    margin-right: 5px;
                }
                .visual-audiobook-access a:hover {
                    background: #005a87 !important;
                }
            </style>';
        }
    }
    public function generate_token_on_purchase($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) return;

        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $product = wc_get_product($product_id);

            // Check if this is a visual audio book product
            if ($this->is_visual_audiobook_product($product_id)) {
                $this->create_token_for_user($order, $product_id);
            }
        }
    }

    /**
     * Check if product is a visual audio book
     */
    private function is_visual_audiobook_product($product_id)
    {
        // You can customize this logic based on your needs
        // For example, check for a specific product category or custom field
        $product_categories = wp_get_post_terms($product_id, 'product_cat');

        foreach ($product_categories as $category) {
            if ($category->slug === 'visual-audiobooks') {
                return true;
            }
        }

        // Alternative: check for custom field
        $is_visual_audiobook = get_post_meta($product_id, '_is_visual_audiobook', true);
        return $is_visual_audiobook === 'yes';
    }

    /**
     * Create token for user
     */
    private function create_token_for_user($order, $product_id)
    {
        global $wpdb;

        $user_id = $order->get_user_id();
        $billing_first_name = $order->get_billing_first_name();
        $billing_last_name = $order->get_billing_last_name();
        $billing_email = $order->get_billing_email();

        // Generate unique token
        $token = $this->generate_unique_token();

        // Get associated page ID for this product
        $page_id = get_post_meta($product_id, '_visual_audiobook_page_id', true);

        if (!$page_id) {
            // If no page is associated, you might want to create one or log an error
            error_log("No page associated with product ID: $product_id");
            return;
        }

        // Insert token into database
        $wpdb->insert(
            $this->table_name,
            array(
                'user_id' => $user_id,
                'order_id' => $order->get_id(),
                'product_id' => $product_id,
                'page_id' => $page_id,
                'token' => $token,
                'first_name' => $billing_first_name,
                'last_name' => $billing_last_name,
                'email' => $billing_email
            )
        );

        // Optionally send email with token link
        $this->send_token_email($billing_email, $token, $page_id, $billing_first_name);
    }

    /**
     * Generate unique token
     */
    private function generate_unique_token()
    {
        return wp_generate_password(32, false);
    }

    /**
     * Send email with token link
     */
    private function send_token_email($email, $token, $page_id, $first_name)
    {
        $page_url = get_permalink($page_id);
        $token_url = add_query_arg('token', $token, $page_url);
        $product_title = get_the_title(get_post_meta($page_id, '_visual_audiobook_product_id', true));

        $subject = 'Your Visual Audio Book Access - ' . get_bloginfo('name');

        // HTML Email
        $message = '
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #007cba; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 5px 5px; }
                .access-box { background: white; border: 2px solid #28a745; border-radius: 5px; padding: 20px; margin: 20px 0; }
                .access-link { background: #007cba; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; margin: 10px 0; }
                .token-url { background: #f1f1f1; padding: 10px; border-radius: 3px; word-break: break-all; font-family: monospace; font-size: 12px; margin: 10px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🎧 Your Visual Audio Book is Ready!</h1>
                </div>
                <div class="content">
                    <p>Hi <strong>' . esc_html($first_name) . '</strong>,</p>
                    
                    <p>Thank you for your purchase! Your visual audio book is now available for access.</p>
                    
                    <div class="access-box">
                        <h3 style="color: #28a745; margin-top: 0;">📚 Ready to Access</h3>
                        <p><strong>Product:</strong> ' . esc_html($product_title) . '</p>
                        
                        <p><strong>Your Personal Access Link:</strong></p>
                        <div class="token-url">' . esc_url($token_url) . '</div>
                        
                        <a href="' . esc_url($token_url) . '" class="access-link">🚀 Access Your Audio Book Now</a>
                    </div>
                    
                    <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0;">
                        <h4 style="margin-top: 0; color: #856404;">📌 Important Notes:</h4>
                        <ul style="margin-bottom: 0;">
                            <li><strong>Bookmark this link</strong> - You\'ll need it to access your content anytime</li>
                            <li><strong>Share carefully</strong> - Anyone with this link can access your audio book</li>
                            <li><strong>No expiration</strong> - This link will work indefinitely</li>
                            <li><strong>Any device</strong> - Access from computer, tablet, or mobile</li>
                        </ul>
                    </div>
                    
                    <p>If you have any questions or need assistance, please don\'t hesitate to contact us.</p>
                    
                    <p>Enjoy your visual audio book experience!</p>
                    
                    <p>Best regards,<br>
                    <strong>' . get_bloginfo('name') . '</strong></p>
                </div>
                <div class="footer">
                    <p>This email was sent to ' . esc_html($email) . ' because you purchased a visual audio book from ' . get_bloginfo('name') . '</p>
                </div>
            </div>
        </body>
        </html>';

        // Set headers for HTML email
        $headers = array('Content-Type: text/html; charset=UTF-8');

        wp_mail($email, $subject, $message, $headers);
    }

    /**
     * Check token access on page load
     */
    public function check_token_access()
    {
        if (!isset($_GET['token'])) {
            return;
        }

        $token = sanitize_text_field($_GET['token']);
        $current_page_id = get_queried_object_id();

        global $wpdb;

        // Check if token exists and matches current page
        $token_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE token = %s AND page_id = %d",
            $token,
            $current_page_id
        ));

        if (!$token_data) {
            // Invalid token or wrong page
            wp_die('Invalid access token or unauthorized page access.', 'Access Denied', array('response' => 403));
        }

        // Store token data in global variable for shortcode access
        global $current_token_data;
        $current_token_data = $token_data;
    }

    /**
     * Shortcode to display user name
     */
    public function display_user_name_shortcode($atts)
    {


        // Check if token have been set
        if (!isset($_GET['token']) && !current_user_can('administrator')) {
            wp_die('This content requires a valid access token.', 'Access Restricted', array('response' => 403));
        }


        global $current_token_data;

        if (!$current_token_data) {
            return '';
            wp_die('This content requires a valid access token.', 'Access Restricted', array('response' => 403));
        }

        $atts = shortcode_atts(array(
            'format' => 'full', // 'full', 'first', 'last'
            'greeting' => ''
        ), $atts);

        $first_name = $current_token_data->first_name;
        $last_name = $current_token_data->last_name;

        $output = '';

        if ($atts['greeting']) {
            $output .= $atts['greeting'] . ' ';
        }

        switch ($atts['format']) {
            case 'first':
                $output .= $first_name;
                break;
            case 'last':
                $output .= $last_name;
                break;
            case 'full':
            default:
                $output .= $first_name . ' ' . $last_name;
                break;
        }

        return $output;
    }

    /**
     * Protect pages that don't have valid tokens
     */
    public function protect_visual_audiobook_pages()
    {
        if (is_admin()) return;

        $current_page_id = get_queried_object_id();

        // Check if current page is a visual audiobook page
        if ($this->is_protected_page($current_page_id)) {
            if (!isset($_GET['token'])) {
                wp_die('This content requires a valid access token.', 'Access Restricted', array('response' => 403));
            }
        }
    }

    /**
     * Check if page is a protected visual audiobook page
     */
    private function is_protected_page($page_id)
    {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $this->table_name WHERE page_id = %d",
            $page_id
        ));

        return $result > 0;
    }

    /**
     * Add "Visual Audio Books" menu item to My Account page
     */
    public function add_my_account_menu_items($items)
    {
        $items['visual-audiobooks'] = __('Visual Audio Books', 'woocommerce');
        return $items;
    }

    /**
     * Content for "Visual Audio Books" endpoint on My Account page
     */
    public function my_account_visual_audiobooks_content()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'visual_audiobook_tokens';

        $user_id = get_current_user_id();
        $tokens = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ));

        echo '<div class="woocommerce-MyAccount-content">';
        echo '<h2>' . esc_html__('Your Visual Audio Books', 'woocommerce') . '</h2>';
        echo '<table class="woocommerce-MyAccount-orders shop_table shop_table_responsive account-orders-table">';
        echo '<thead><tr><th>' . esc_html__('Access URL', 'woocommerce') . '</th><th>' . esc_html__('Product', 'woocommerce') . '</th><th>' . esc_html__('Page', 'woocommerce') . '</th><th>' . esc_html__('Created', 'woocommerce') . '</th></tr></thead>';
        echo '<tbody>';

        foreach ($tokens as $token) {
            $page_url = get_permalink($token->page_id);
            $access_url = add_query_arg('token', $token->token, $page_url);
            $product_title = get_the_title($token->product_id);
            $page_title = get_the_title($token->page_id);

            echo '<tr>';
            echo '<td><a href="' . esc_url($access_url) . '" target="_blank">' . esc_html__('View', 'woocommerce') . '</a></td>';
            echo '<td>' . esc_html($product_title) . '</td>';
            echo '<td>' . esc_html($page_title) . '</td>';
            echo '<td>' . esc_html($token->created_at) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * Add "Visual Audio Books" endpoint to My Account page
     */
    public function add_my_account_endpoint()
    {
        add_rewrite_endpoint('visual-audiobooks', EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
    }
}

// Initialize the plugin
new WooVisualAudioBooks();

/**
 * Add custom field to product admin page
 */
add_action('woocommerce_product_options_general_product_data', 'add_visual_audiobook_fields');
function add_visual_audiobook_fields()
{
    echo '<div class="options_group">';

    woocommerce_wp_checkbox(array(
        'id' => '_is_visual_audiobook',
        'label' => 'Visual Audio Book',
        'description' => 'Check this if this product is a visual audio book'
    ));

    woocommerce_wp_select(array(
        'id' => '_visual_audiobook_page_id',
        'label' => 'Associated Page',
        'description' => 'Select the page that will be accessible after purchase',
        'options' => array('' => 'Select a page...') + get_pages_array()
    ));

    echo '</div>';
}

/**
 * Save custom fields
 */
add_action('woocommerce_process_product_meta', 'save_visual_audiobook_fields');
function save_visual_audiobook_fields($post_id)
{
    $is_visual_audiobook = isset($_POST['_is_visual_audiobook']) ? 'yes' : 'no';
    update_post_meta($post_id, '_is_visual_audiobook', $is_visual_audiobook);

    if (isset($_POST['_visual_audiobook_page_id'])) {
        update_post_meta($post_id, '_visual_audiobook_page_id', sanitize_text_field($_POST['_visual_audiobook_page_id']));
    }
}

/**
 * Helper function to get pages array for dropdown
 */
function get_pages_array()
{
    $pages = get_pages();
    $pages_array = array();

    foreach ($pages as $page) {
        $pages_array[$page->ID] = $page->post_title;
    }

    return $pages_array;
}

/**
 * Add admin menu for managing tokens
 */
add_action('admin_menu', 'visual_audiobook_admin_menu');
function visual_audiobook_admin_menu()
{
    add_submenu_page(
        'woocommerce',
        'Visual Audio Book Tokens',
        'Audio Book Tokens',
        'manage_options',
        'visual-audiobook-tokens',
        'visual_audiobook_tokens_page'
    );
}

/**
 * Admin page to view all tokens
 */
function visual_audiobook_tokens_page()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'visual_audiobook_tokens';

    $tokens = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

    echo '<div class="wrap">';
    echo '<h1>Visual Audio Book Tokens</h1>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>Token</th><th>User</th><th>Product</th><th>Page</th><th>Access URL</th><th>Created</th></tr></thead>';
    echo '<tbody>';

    foreach ($tokens as $token) {
        $page_url = get_permalink($token->page_id);
        $access_url = add_query_arg('token', $token->token, $page_url);
        $product_title = get_the_title($token->product_id);
        $page_title = get_the_title($token->page_id);

        echo '<tr>';
        echo '<td>' . substr($token->token, 0, 10) . '...</td>';
        echo '<td>' . $token->first_name . ' ' . $token->last_name . '</td>';
        echo '<td>' . $product_title . '</td>';
        echo '<td>' . $page_title . '</td>';
        echo '<td><a href="' . $access_url . '" target="_blank">View</a></td>';
        echo '<td>' . $token->created_at . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}
