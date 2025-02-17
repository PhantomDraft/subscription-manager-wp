<?php
/**
 * Plugin Name: PD Subscription Manager
 * Plugin URI: https://github.com/PhantomDraft/subscription-manager-wp
 * Description: Subscription mechanism. When a specific WooCommerce product is purchased, the user is assigned a specified role for a set number of days (calculated as [quantity * days per copy]). After the subscription expires (checked daily or via the "Refresh Subscriptions" button), the user's role is reverted to the default (if specified). The "Subscribers" page displays a list of active subscriptions with the ability to edit the remaining days.
 * Version:     1.2
 * Author:      PD
 * Author URI:  https://guides.phantom-draft.com/
 * License:     GPL2
 * Text Domain: pd-subscription-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PD_Subscription_Manager {
    private static $instance = null;
    private $option_name = 'pd_subscription_manager_options';
    private $cron_hook = 'pd_subscription_manager_cron';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Register settings pages in the "PD" menu
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        // If the parameter for immediate subscription refresh is passed, run the check
        add_action( 'admin_init', [ $this, 'maybe_manual_refresh' ] );
        // Handle subscription update via the form on the "Subscribers" page
        add_action( 'admin_post_pd_subscription_update', [ $this, 'process_subscription_update' ] );
        // Handle WooCommerce order completion
        add_action( 'woocommerce_order_status_completed', [ $this, 'order_completed_handler' ], 10, 1 );
        // Cron job for checking subscriptions (automatically runs daily)
        add_action( $this->cron_hook, [ $this, 'check_expired_subscriptions' ] );
        // Add admin dashboard notices
        add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );
    }

    /**
     * Activate the cron job.
     */
    public static function activate() {
        if ( ! wp_next_scheduled( 'pd_subscription_manager_cron' ) ) {
            wp_schedule_event( time(), 'daily', 'pd_subscription_manager_cron' );
        }
    }

    /**
     * Deactivate the cron job.
     */
    public static function deactivate() {
        $timestamp = wp_next_scheduled( 'pd_subscription_manager_cron' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'pd_subscription_manager_cron' );
        }
    }

    /**
     * Add menu items to the global "PD" menu.
     */
    public function add_admin_menu() {
        // Check if the global PD menu is already registered; if not, register it.
        if ( ! defined( 'PD_GLOBAL_MENU_REGISTERED' ) ) {
            add_menu_page(
                'PD',                           // Title in admin area
                'PD',                           // Menu title
                'manage_options',               // Required capability
                'pd_main_menu',                 // Global menu slug
                'pd_global_menu_callback',      // Callback function for the global menu page
                'dashicons-shield',             // Shield icon
                2                               // Menu position
            );
            define( 'PD_GLOBAL_MENU_REGISTERED', true );
        }

        // Add PD Subscription Manager settings as submenus under the global PD menu
        add_submenu_page(
            'pd_main_menu', // Parent menu slug
            'PD Subscription Manager – Subscription Settings',
            'PD Subscription Manager',
            'manage_options',
            'pd_subscription_manager',
            [ $this, 'settings_page' ]
        );
        add_submenu_page(
            'pd_main_menu',
            'PD Subscription Manager – Subscribers',
            'Subscriptions',
            'manage_options',
            'pd_subscription_manager_subscribers',
            [ $this, 'subscribers_page' ]
        );
    }

    /**
     * Subscription settings page.
     */
    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>PD Subscription Manager Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_name );
                do_settings_sections( $this->option_name );
                submit_button();
                ?>
            </form>
            <hr>
            <h2>Refresh Subscriptions</h2>
            <p>Click the button to immediately check for expired subscriptions.</p>
            <form method="get" action="">
                <input type="hidden" name="page" value="pd_subscription_manager" />
                <input type="hidden" name="pd_subscription_refresh" value="1" />
                <?php submit_button( 'Refresh Subscriptions', 'secondary', 'submit', false ); ?>
            </form>
            <hr>
            <h2>Subscription Conditions Format</h2>
            <p>Each line is a rule in the following format:</p>
            <pre>identifier|assigned_role|days_per_copy|default_role</pre>
            <p>Examples:</p>
            <pre>
123|subscriber|30|customer
about-us-subscription|vip|30|subscriber
            </pre>
            <p>If the default_role field is left empty, the subscription is considered perpetual.</p>
        </div>
        <?php
    }

    /**
     * If the GET parameter for immediate subscription refresh is received, run the check.
     */
    public function maybe_manual_refresh() {
        if ( is_admin() && isset( $_GET['pd_subscription_refresh'] ) && '1' === $_GET['pd_subscription_refresh'] && current_user_can( 'manage_options' ) ) {
            $this->check_expired_subscriptions();
            add_action( 'admin_notices', function() {
                echo '<div class="updated notice"><p>Subscriptions refreshed.</p></div>';
            } );
        }
    }

    /**
     * Process the subscription update form on the "Subscribers" page.
     */
    public function process_subscription_update() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        check_admin_referer( 'pd_subscription_update_action', 'pd_subscription_update_nonce' );
        $user_id = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : 0;
        $days_left = isset( $_POST['days_left'] ) ? intval( $_POST['days_left'] ) : '';
        if ( ! $user_id ) {
            wp_die( 'Invalid user' );
        }
        if ( $days_left === '' ) {
            // Perpetual subscription
            delete_user_meta( $user_id, 'pd_subscription_expiry' );
        } else {
            $days = intval( $days_left );
            if ( $days <= 0 ) {
                delete_user_meta( $user_id, 'pd_subscription_expiry' );
            } else {
                $new_expiry = time() + ( $days * DAY_IN_SECONDS );
                update_user_meta( $user_id, 'pd_subscription_expiry', $new_expiry );
            }
        }
        wp_redirect( add_query_arg( 'pd_subscription_updated', '1', wp_get_referer() ) );
        exit;
    }

    /**
     * Subscribers page – list of users with active subscriptions.
     * Includes a dropdown filter for users.
     */
    public function subscribers_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // Process update notification
        if ( isset( $_GET['pd_subscription_updated'] ) && '1' === $_GET['pd_subscription_updated'] ) {
            echo '<div class="updated notice"><p>Subscription updated.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Subscribers</h1>
            <form method="get" action="">
                <input type="hidden" name="page" value="pd_subscription_manager_subscribers" />
                <label for="filter_user">Select a user: </label>
                <select name="filter_user" id="filter_user">
                    <option value="0">All Users</option>
                    <?php
                    // Get all users who have a subscription meta field
                    $all_subscribers = get_users( [ 'meta_key' => 'pd_subscription_expiry' ] );
                    $filter_user = isset( $_GET['filter_user'] ) ? intval( $_GET['filter_user'] ) : 0;
                    foreach ( $all_subscribers as $user ) {
                        echo '<option value="' . esc_attr( $user->ID ) . '" ' . selected( $filter_user, $user->ID, false ) . '>' . esc_html( $user->display_name . ' (' . $user->user_email . ')' ) . '</option>';
                    }
                    ?>
                </select>
                <?php submit_button( 'Filter', 'secondary', 'submit', false ); ?>
            </form>
            <br>
            <?php
            // If a specific user is selected, show only that user
            if ( $filter_user > 0 ) {
                $users = get_users( [ 'include' => [ $filter_user ] ] );
            } else {
                // Otherwise, show all users with a subscription
                $users = get_users( [
                    'meta_key' => 'pd_subscription_expiry',
                    'orderby'  => 'meta_value_num',
                    'order'    => 'ASC',
                ] );
            }
            if ( $users ) {
                echo '<table class="widefat fixed">';
                echo '<thead><tr>';
                echo '<th>ID</th>';
                echo '<th>Name</th>';
                echo '<th>Email</th>';
                echo '<th>Subscription Expires</th>';
                echo '<th>Days Left</th>';
                echo '<th>Role</th>';
                echo '<th>Action</th>';
                echo '</tr></thead>';
                echo '<tbody>';
                foreach ( $users as $user ) {
                    $expiry = get_user_meta( $user->ID, 'pd_subscription_expiry', true );
                    if ( $expiry && $expiry > time() ) {
                        $days_left = ceil( ( $expiry - time() ) / DAY_IN_SECONDS );
                        $expiry_text = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiry );
                    } else {
                        $days_left = 'Forever';
                        $expiry_text = 'Perpetual';
                    }
                    echo '<tr>';
                    echo '<td>' . esc_html( $user->ID ) . '</td>';
                    echo '<td>' . esc_html( $user->display_name ) . '</td>';
                    echo '<td>' . esc_html( $user->user_email ) . '</td>';
                    echo '<td>' . esc_html( $expiry_text ) . '</td>';
                    echo '<td>';
                    if ( is_numeric( $days_left ) ) {
                        echo esc_html( $days_left );
                    } else {
                        echo 'Forever';
                    }
                    echo '</td>';
                    echo '<td>' . esc_html( implode( ', ', $user->roles ) ) . '</td>';
                    echo '<td>';
                    // Form to update remaining subscription days
                    ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="pd_subscription_update" />
                        <?php wp_nonce_field( 'pd_subscription_update_action', 'pd_subscription_update_nonce' ); ?>
                        <input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>" />
                        <input type="text" name="days_left" placeholder="New days" size="5" />
                        <input type="submit" value="Save" class="button-secondary" />
                    </form>
                    <?php
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p>No subscribers found.</p>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting( $this->option_name, $this->option_name, [ $this, 'sanitize_settings' ] );

        add_settings_field(
            'default_days_per_month',
            'Default Days per Month',
            [ $this, 'field_default_days_per_month' ],
            $this->option_name,
            'pd_subscription_main'
        );

        add_settings_section(
            'pd_subscription_main',
            'Subscription Settings',
            null,
            $this->option_name
        );

        add_settings_field(
            'subscription_conditions',
            'Subscription Conditions',
            [ $this, 'field_subscription_conditions' ],
            $this->option_name,
            'pd_subscription_main'
        );
    }

    /**
     * Sanitize plugin settings.
     */
    public function sanitize_settings( $input ) {
        $valid = [];
        $valid['subscription_conditions'] = isset( $input['subscription_conditions'] ) ? sanitize_textarea_field( $input['subscription_conditions'] ) : '';
        return $valid;
        $valid['default_days_per_month'] = isset( $input['default_days_per_month'] ) ? intval( $input['default_days_per_month'] ) : 30;
    }

    /**
     * Subscription conditions input field.
     */
    public function field_subscription_conditions() {
        $options = get_option( $this->option_name );
        $value = isset( $options['subscription_conditions'] ) ? $options['subscription_conditions'] : '';
        ?>
        <textarea name="<?php echo esc_attr( $this->option_name ); ?>[subscription_conditions]" rows="10" cols="80" style="font-family: monospace;"><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">Each line is a rule in the following format: <code>identifier|assigned_role|days_per_copy|default_role</code></p>
        <?php
    }

    /**
     * Field for default days per month.
     */
    public function field_default_days_per_month() {
        $options = get_option( $this->option_name );
        $value = isset( $options['default_days_per_month'] ) ? intval( $options['default_days_per_month'] ) : 30;
        ?>
        <input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[default_days_per_month]" value="<?php echo esc_attr( $value ); ?>" min="1" step="1" />
        <p class="description">Enter the number of days that represent one month. Default is 30.</p>
        <?php
    }

    /**
     * Handle WooCommerce order completion.
     */
    public function order_completed_handler( $order_id ) {
        if ( ! $order_id ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $user_id = $order->get_user_id();
        if ( ! $user_id ) {
            return;
        }
        // Get subscription conditions from settings
        $options = get_option( $this->option_name );
        $conditions_raw = isset( $options['subscription_conditions'] ) ? $options['subscription_conditions'] : '';
        $conditions = $this->parse_conditions( $conditions_raw );
        if ( empty( $conditions ) ) {
            return;
        }
        // Loop through order items
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }
            $product_id = $product->get_id();
            $product_slug = $product->get_slug();
            $quantity = $item->get_quantity();

            // Check if the product meets any subscription rule
            foreach ( $conditions as $cond ) {
                $identifier = $cond['identifier'];
                if ( ( is_numeric( $identifier ) && intval( $identifier ) === $product_id ) ||
                     ( ! is_numeric( $identifier ) && $identifier === $product_slug ) ) {

                    // If days_per_copy (the third part of the rule) is not set or equals 0, use the global value
                    $days_per_copy = intval( $cond['days'] );
                    if ( $days_per_copy <= 0 ) {
                        $options = get_option( $this->option_name );
                        $days_per_copy = isset( $options['default_days_per_month'] ) ? intval( $options['default_days_per_month'] ) : 30;
                    }
                    $days = $days_per_copy * intval( $quantity );
                    if ( $days > 0 ) {
                        $current_expiry = intval( get_user_meta( $user_id, 'pd_subscription_expiry', true ) );
                        $new_expiry = time() + ( $days * DAY_IN_SECONDS );
                        // If the user already has an active subscription, extend it
                        if ( $current_expiry && $current_expiry > time() ) {
                            $new_expiry = $current_expiry + ( $days * DAY_IN_SECONDS );
                        }
                        update_user_meta( $user_id, 'pd_subscription_expiry', $new_expiry );
                    } else {
                        // If days == 0, subscription is perpetual – remove meta
                        delete_user_meta( $user_id, 'pd_subscription_expiry' );
                    }
                    // Update user role if subscription role is specified
                    $assigned_role = sanitize_text_field( $cond['role'] );
                    if ( ! empty( $assigned_role ) && in_array( $assigned_role, array_keys( get_editable_roles() ) ) ) {
                        wp_update_user( [ 'ID' => $user_id, 'role' => $assigned_role ] );
                        // Set a transient for new subscription notice (1 hour)
                        if ( false === get_transient( 'pd_subscription_new_subscription' ) ) {
                            set_transient( 'pd_subscription_new_subscription', true, HOUR_IN_SECONDS );
                        }
                    }
                }
            }
        }
    }

    /**
     * Parse raw subscription conditions into an array of rules.
     * Format: identifier|assigned_role|days_per_copy|default_role
     */
    private function parse_conditions( $raw ) {
        $rules = [];
        $lines = preg_split( "/\r\n|\n|\r/", trim( $raw ) );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }
            $parts = explode( '|', $line );
            $identifier   = isset( $parts[0] ) ? sanitize_text_field( trim( $parts[0] ) ) : '';
            $role         = isset( $parts[1] ) ? sanitize_text_field( trim( $parts[1] ) ) : '';
            $days         = isset( $parts[2] ) ? sanitize_text_field( trim( $parts[2] ) ) : '0';
            $default_role = isset( $parts[3] ) ? sanitize_text_field( trim( $parts[3] ) ) : '';
            if ( ! empty( $identifier ) && ! empty( $role ) ) {
                $rules[] = [
                    'identifier'   => $identifier,
                    'role'         => $role,
                    'days'         => $days,
                    'default_role' => $default_role,
                ];
            }
        }
        return $rules;
    }

    /**
     * Cron job: Check for expired subscriptions.
     * If a subscription has expired, revert the user to the default role (if specified) and remove the meta.
     */
    public function check_expired_subscriptions() {
        $users = get_users( [
            'meta_key'     => 'pd_subscription_expiry',
            'meta_compare' => '<',
            'meta_value'   => time(),
        ] );
        if ( $users ) {
            $options = get_option( $this->option_name );
            $conditions = $this->parse_conditions( isset( $options['subscription_conditions'] ) ? $options['subscription_conditions'] : '' );
            foreach ( $users as $user ) {
                $user_id = $user->ID;
                // Loop through rules to revert user to default role if specified
                foreach ( $conditions as $cond ) {
                    if ( ! empty( $cond['default_role'] ) ) {
                        wp_update_user( [ 'ID' => $user_id, 'role' => $cond['default_role'] ] );
                        break;
                    }
                }
                delete_user_meta( $user_id, 'pd_subscription_expiry' );
            }
        }
    }

    /**
     * Display admin notices on the Dashboard.
     */
    public function display_admin_notices() {
        // Notice for new subscription
        if ( get_transient( 'pd_subscription_new_subscription' ) ) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>New subscription purchased. Go to <a href="' . esc_url( admin_url( 'admin.php?page=pd_subscription_manager_subscribers' ) ) . '">Subscribers</a>.</p>';
            echo '</div>';
            delete_transient( 'pd_subscription_new_subscription' );
        }

        // Notice for subscriptions expiring within 5 days
        $now = time();
        $future = $now + ( 5 * DAY_IN_SECONDS );
        $user_query = new WP_User_Query( [
            'meta_key'     => 'pd_subscription_expiry',
            'meta_value'   => [ $now, $future ],
            'meta_compare' => 'BETWEEN',
            'meta_type'    => 'NUMERIC',
        ] );
        if ( ! empty( $user_query->get_results() ) ) {
            $count = count( $user_query->get_results() );
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>Warning: ' . intval( $count ) . ' subscription(s) will expire in less than 5 days. <a href="' . esc_url( admin_url( 'admin.php?page=pd_subscription_manager_subscribers' ) ) . '">View Subscribers</a>.</p>';
            echo '</div>';
        }
    }
}

// Register activation/deactivation hooks
register_activation_hook( __FILE__, [ 'PD_Subscription_Manager', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'PD_Subscription_Manager', 'deactivate' ] );

if ( ! function_exists( 'pd_global_menu_callback' ) ) {
    function pd_global_menu_callback() {
        ?>
        <div class="wrap">
            <h1>PD Global Menu</h1>
            <p>Please visit our GitHub page:</p>
            <p><a href="https://github.com/PhantomDraft" target="_blank">https://github.com/PhantomDraft</a></p>
        </div>
        <?php
    }
}

// Initialize the plugin
PD_Subscription_Manager::get_instance();