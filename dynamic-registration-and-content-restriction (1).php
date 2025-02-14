<?php
/*
Plugin Name: Dynamic Registration and Content Restriction
Description: Handles dynamic user registration with token validation, content restriction, and profile-based access. Includes PayPro integration, IP restriction, and makes subdomain non-searchable by Google.
Version: 2.0
Author: <a href="https://www.upwork.com/freelancers/~01a8d9752ae6e0996d?mp_source=share" target="_blank">Usama Bin Amir</a>
*/

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Register a custom endpoint for dynamic registration
 */
function drcr_add_rewrite_rules() {
    add_rewrite_rule('^register-([a-zA-Z0-9_-]+)$', 'index.php?token=$matches[1]', 'top');
}
add_action('init', 'drcr_add_rewrite_rules');

/**
 * Register a Rewrite Rule for Content Pages
*/
function drcr_add_content_rewrite_rules() {
    add_rewrite_rule('^content-([a-zA-Z0-9_-]+)$', 'index.php?drcr_label=$matches[1]', 'top');
}
add_action('init', 'drcr_add_content_rewrite_rules');

/**
 * Add query vars for lables
 */
function drcr_add_content_query_vars($vars) {
    $vars[] = 'drcr_label';
    return $vars;
}
add_filter('query_vars', 'drcr_add_content_query_vars');

/**
 * Add query vars for token
 */
function drcr_add_query_vars($vars) {
    $vars[] = 'token';
    return $vars;
}
add_filter('query_vars', 'drcr_add_query_vars');

/**
 * Handle Content Display
 */
function drcr_handle_content_display() {
    $label = get_query_var('drcr_label');
    if ($label) {
        // Verify the user has access to this label
        $user_id = get_current_user_id();
        $user_labels = get_user_meta($user_id, 'drcr_labels', true) ?: [];

        if (!in_array($label, $user_labels)) {
            wp_die('You do not have permission to view this content.', 'Access Denied', ['response' => 403]);
        }

        // Display content for the label
        echo "<h1>Welcome to Content: " . esc_html($label) . "</h1>";
        echo "<p>This is the content for " . esc_html($label) . ".</p>";
        exit;
    }
}
add_action('template_redirect', 'drcr_handle_content_display');

/**
 * Handle dynamic registration endpoint
 */
function drcr_template_redirect() {
    $token = get_query_var('token');

    if ($token) {
        // Validate token
        if (!drcr_validate_token($token)) {
            wp_die('Invalid token. Please check your link.', 'Invalid Token', ['response' => 403]);
        }

        // Show the registration form
        drcr_registration_form_template();
        exit;
    }
}
add_action('template_redirect', 'drcr_template_redirect');

/**
 * Validate the token (dummy validation for demo purposes)
 */
function drcr_validate_token($token) {
    // Implement token validation logic (e.g., check PayPro webhook or database)
    return true; // Assume valid for now
}

/**
 * Process the registration form
 */
function drcr_handle_registration() {
    if (isset($_POST['drcr_register_nonce']) && wp_verify_nonce($_POST['drcr_register_nonce'], 'drcr_register_action')) {
        $username = sanitize_text_field($_POST['username']);
        $email = sanitize_email($_POST['email']);
        $password = sanitize_text_field($_POST['password']);
        $token = sanitize_text_field($_POST['token']);

        // Assign label based on token
        $content_label = drcr_map_token_to_label($token);

        // Create user or retrieve existing user
        $user_id = email_exists($email) ? get_user_by('email', $email)->ID : wp_create_user($username, $password, $email);

        if (is_wp_error($user_id)) {
            wp_die('Registration failed: ' . $user_id->get_error_message(), 'Registration Error', ['response' => 400]);
        }

        // Assign content label to user meta
        $existing_labels = get_user_meta($user_id, 'drcr_labels', true) ?: [];
        $existing_labels[] = $content_label;
        update_user_meta($user_id, 'drcr_labels', array_unique($existing_labels));
        
        // Send registration confirmation email
        drcr_send_registration_email($username, $email);

        // Log the user in
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);

        // Redirect to profile or welcome page
        wp_redirect(home_url('/profile')); // Change to your desired URL
        exit;
    }
}
add_action('admin_post_drcr_register', 'drcr_handle_registration');
add_action('admin_post_nopriv_drcr_register', 'drcr_handle_registration');

/**
 * Send Registration Confirmation Email
 */
function drcr_send_registration_email($username, $email) {

    $subject = 'Welcome to Our Platform';
    $message = "Hi $username,\n\nThank you for registering! You now have access to your purchased content.\n\nFeel free to log in to your profile page to see your content:\n" . home_url('/profile') . "\n\nBest regards,\nThe Team";
    
    wp_mail($email, $subject, $message);
}

/**
 * Map token to content label (example mapping logic)
 */
function drcr_map_token_to_label($token) {
    // Map tokens to labels (this can be replaced with a database query or API call)
    $mapping = [
        '26145452hgfdtfgus' => 'content-a',
        '989243476ghytbvc' => 'content-b',
    ];

    // return $mapping[$token] ?? 'unknown';
    return isset($mapping[$token]) ? $mapping[$token] : '';
}

/**
 * Restrict content based on labels
 */
function drcr_restrict_content($query) {
    // Skip all restrictions for admin users
    if (current_user_can('administrator') || is_admin()) {
        return;
    }

    // Proceed only for main query on singular posts/pages
    if ($query->is_main_query() && is_singular(['post', 'page'])) {
        // Fetch the required label for the current post
        $required_label = get_post_meta(get_the_ID(), '_drcr_required_label', true);
            
        // If no restriction label is set, allow access
        if (empty($required_label)) {
            return;
        }
    
        // Fetch user meta labels
        $user_id = get_current_user_id();
        $user_labels = get_user_meta($user_id, 'drcr_labels', true) ?: [];

        // Debugging to log label information (optional)
        error_log("Required Label: " . $required_label);
        error_log("User Labels: " . implode(', ', $user_labels));
        
        // Ensure labels are an array
        if (!is_array($user_labels)) {
            $user_labels = explode(',', $user_labels);
        }

        // Check if the required label is in the user's labels
        if ($required_label && !in_array($required_label, $user_labels)) {
            wp_die('You do not have permission to view this content.', 'Access Denied', ['response' => 403]);
        }
    }
}
add_action('pre_get_posts', 'drcr_restrict_content');

/**
 * Add a Custom Redirect After Login
 */
function drcr_redirect_after_login($redirect_to, $requested_redirect_to, $user) {
    // Ensure $user is a valid WP_User object
    if (isset($user->roles) && is_array($user->roles)) {
        // Redirect only for non-admin users
        if (!in_array('administrator', $user->roles)) {
            return home_url('/profile'); // Change '/profile' to your custom profile page slug
        }
    }
    return $redirect_to;
}
add_filter('login_redirect', 'drcr_redirect_after_login', 10, 3);

/**
 * Sync manually added labels on login
 */

function drcr_sync_user_labels($user_login, $user) {
    $labels = get_user_meta($user->ID, 'drcr_labels', true);

    // Ensure labels are always an array
    if (!is_array($labels)) {
        $labels = $labels ? explode(',', $labels) : [];
        update_user_meta($user->ID, 'drcr_labels', array_unique($labels));
    }
}
add_action('wp_login', 'drcr_sync_user_labels', 10, 2);

/**
 * Add a shortcode for the profile page
 */
function drcr_profile_page() {
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view this page.</p>';
    }

    // Fetch user labels
    $user_labels = get_user_meta(get_current_user_id(), 'drcr_labels', true) ?: [];
    ob_start();

    if (!empty($user_labels)) {
        echo '<h3>Your Purchased Content:</h3><ul>';

        // foreach ($user_labels as $label) {
        //     echo '<li><a href="' . esc_url(home_url('/content-' . $label)) . '">Access ' . esc_html($label) . '</a></li>';
        // }
        
        // Query both posts and pages restricted to the user's labels
        $args = [
            'post_type' => ['post', 'page'],
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_drcr_required_label',
                    'value' => $user_labels,
                    'compare' => 'IN',
                ],
            ],
        ];
        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                // Display the correct post/page URL and title
                echo '<li><a href="' . get_permalink() . '">' . get_the_title() . '</a></li>';
            }
        } else {
            echo '<p>No content available for your purchase.</p>';
        }

        wp_reset_postdata();
        echo '</ul>';
    } else {
        echo '<p>You have not purchased any content yet.</p>';
    }

    return ob_get_clean();
}
add_shortcode('drcr_profile', 'drcr_profile_page');

/**
 * Add meta box for content restriction
 */
function drcr_add_meta_box() {
    add_meta_box('drcr_meta_box', 'Content Restriction', 'drcr_meta_box_callback', ['post', 'page'], 'side');
}
add_action('add_meta_boxes', 'drcr_add_meta_box');

function drcr_meta_box_callback($post) {
    $value = get_post_meta($post->ID, '_drcr_required_label', true);
    echo '<label for="drcr_required_label">Required Label:</label>'; 
    echo '<input type="text" id="drcr_required_label" name="drcr_required_label" value="' . esc_attr($value) . '" size="25" />';
}

/**
 * Save meta box data
 */
function drcr_save_meta_box($post_id) {
    if (array_key_exists('drcr_required_label', $_POST)) {
        update_post_meta($post_id, '_drcr_required_label', sanitize_text_field($_POST['drcr_required_label']));
    }
}
add_action('save_post', 'drcr_save_meta_box');

function drcr_add_content_restriction_meta_box() {
    add_meta_box(
        'drcr_content_restriction',
        'Content Restriction',
        'drcr_render_content_restriction_meta_box',
        'post',
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'drcr_add_content_restriction_meta_box');

/**
 * Meta Box for Content Restriction
 */
function drcr_render_content_restriction_meta_box($post) {
    $value = get_post_meta($post->ID, '_drcr_required_label', true);
    ?>
    <label for="drcr_required_label">Required Label:</label>
    <input type="text" id="drcr_required_label" name="drcr_required_label" value="<?php echo esc_attr($value); ?>" />
    <?php
}

function drcr_save_content_restriction_meta_box($post_id) {
    if (array_key_exists('drcr_required_label', $_POST)) {
        update_post_meta(
            $post_id,
            '_drcr_required_label',
            sanitize_text_field($_POST['drcr_required_label'])
        );
    }
}
add_action('save_post', 'drcr_save_content_restriction_meta_box');

/**
 * Make subdomain non-searchable by Google
 */
function drcr_add_noindex_header() {
    echo '<meta name="robots" content="noindex, nofollow">';
}
add_action('wp_head', 'drcr_add_noindex_header');

/**
 * Track User Login IPs
 */
function drcr_track_user_ip($user_login, $user) {
    $user_id = $user->ID;
    $current_ip = $_SERVER['REMOTE_ADDR'];
    $ip_list = get_user_meta($user_id, 'drcr_ip_list', true) ?: [];

    // Check if IP is already in the list
    if (!in_array($current_ip, $ip_list)) {
        $ip_list[] = $current_ip;

        // Check if IP limit is exceeded
        $max_ips = 2; // Set the maximum allowed IPs
        if (count($ip_list) > $max_ips) {
            update_user_meta($user_id, 'drcr_access_blocked', true);
        } else {
            update_user_meta($user_id, 'drcr_ip_list', $ip_list);
        }
    }
}
add_action('wp_login', 'drcr_track_user_ip', 10, 2);

/**
 * Restrict Access for Blocked Users
 */
function drcr_restrict_blocked_users() {
    // Skip restriction for administrators
    if (current_user_can('administrator')) {
        return;
    }
    
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $access_blocked = get_user_meta($user_id, 'drcr_access_blocked', true);

        if ($access_blocked) {
            wp_safe_redirect(home_url('/contact-for-access')); // Redirect to a contact page
            exit;
        }
    }
}
add_action('template_redirect', 'drcr_restrict_blocked_users');

/**
 * Reset Access for Users (Admin Functionality)
 */
function drcr_reset_user_access($user_id) {
    delete_user_meta($user_id, 'drcr_access_blocked');
    delete_user_meta($user_id, 'drcr_ip_list');
}

/**
 * Add a custom section in the user profile page to manage labels
 */
function drcr_show_user_labels($user) {
    $labels = get_user_meta($user->ID, 'drcr_labels', true) ?: [];
    $ip_list = get_user_meta($user->ID, 'drcr_ip_list', true) ?: [];

    echo '<h3>Content Access</h3>';
    echo '<table class="form-table">';
    echo '<tr><th><label for="drcr_labels">Access Labels</label></th><td>';
    echo '<input type="text" name="drcr_labels" id="drcr_labels" value="' . esc_attr(implode(", ", $labels)) . '" class="regular-text" />';
    echo '<p class="description">Comma-separated labels (e.g., content-a, content-b)</p>';
    echo '</td></tr>';

    echo '<tr><th><label for="drcr_ip_list">Registered IPs</label></th><td>';
    echo '<textarea name="drcr_ip_list" id="drcr_ip_list" class="large-text" rows="3">' . esc_textarea(implode("\n", $ip_list)) . '</textarea>';
    echo '<p class="description">IPs used by the user. To reset IP restrictions, clear this field.</p>';
    echo '</td></tr>';
    echo '</table>';
}
add_action('show_user_profile', 'drcr_show_user_labels');
add_action('edit_user_profile', 'drcr_show_user_labels');

/**
 * Save the user labels from the admin profile page
 */
function drcr_save_user_labels($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }
    if (isset($_POST['drcr_labels'])) {
        $labels = array_map('trim', explode(',', sanitize_text_field($_POST['drcr_labels'])));
        update_user_meta($user_id, 'drcr_labels', $labels);
    }
    if (isset($_POST['drcr_ip_list'])) {
        $ip_list = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['drcr_ip_list']))));
        update_user_meta($user_id, 'drcr_ip_list', $ip_list);
    }
}
add_action('personal_options_update', 'drcr_save_user_labels');
add_action('edit_user_profile_update', 'drcr_save_user_labels');

/**
 * Registration Form Template
 */
function drcr_registration_form_template() {
    $token = sanitize_text_field(get_query_var('token'));
    $action_url = esc_url(admin_url('admin-post.php?action=drcr_register'));

    echo '<style>
        .registration-form {
            max-width: 500px;
            margin: 50px auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 10px;
            background: #f9f9f9;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            font-family: system-ui;
        }
        .registration-form h2 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }
        .registration-form label {
            font-weight: bold;
            font-family: system-ui;
            color: #555;
        }
        .registration-form input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .registration-form button {
            width: 100%;
            padding: 10px;
            background: #0073aa;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-family: system-ui;
        }
        .registration-form button:hover {
            background: #005a8c;
        }
        .toggle-password {
            cursor: pointer;
            position: absolute;
            right: 15px;
            top: 17px;
            color: #0073aa;
        }
    </style>
    <form class="registration-form" method="POST" action="' . $action_url . '">
        <h2>New Registeration</h2>
        <input type="hidden" name="drcr_register_nonce" value="' . wp_create_nonce('drcr_register_action') . '" />
        <input type="hidden" name="token" value="' . esc_attr($token) . '" />
        <label for="username">Username:</label>
        <input type="text" name="username" required placeholder="Enter your username" />
        <label for="email">Email:</label>
        <input type="email" name="email" required placeholder="Enter your email" />
        <label for="password">Password:</label>
        <div style="position: relative;">
            <input type="password" id="password" name="password" required placeholder="Enter a secure password" />
            <span class="toggle-password" onclick="togglePasswordVisibility()">
                <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="gray" width="24" height="24">
                    <path id="eye-open" d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" />
                    <circle id="eye-pupil" cx="12" cy="12" r="3" />
                    <path id="eye-closed-line" d="M3 3l18 18" stroke-linecap="round" stroke-linejoin="round" stroke="gray"/>
                </svg>
            </span>
        </div>
        <button type="submit">Register</button>
    </form>
    <script>
        let isPasswordVisible = false;
        function togglePasswordVisibility() {
            const passwordField = document.getElementById("password");
            const eyeIcon = document.getElementById("eye-icon");
            const eyeOpen = document.getElementById("eye-open");
            const eyeClosedLine = document.getElementById("eye-closed-line");

            if (!isPasswordVisible) {
                passwordField.type = "text";
                eyeIcon.setAttribute("stroke", "#0073aa");
                isPasswordVisible = true;
                eyeClosedLine.style.display = "none";
            } else {
                passwordField.type = "password";
                eyeIcon.setAttribute("stroke", "gray");
                isPasswordVisible = false;
                eyeClosedLine.style.display = "block";
            }
        }
    </script>';
}
?>
