<?php
/**
 * Plugin Name:       RCMI Tickets
 * Description:       Frontend SPA ticket system (Vue 3 + WP REST API). Mount via the [rcmi_tickets] shortcode.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            RCMI
 * Text Domain:       rcmi-tickets
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RCMI_TICKETS_VERSION', '0.1.0');
define('RCMI_TICKETS_FILE', __FILE__);
define('RCMI_TICKETS_DIR', plugin_dir_path(__FILE__));
define('RCMI_TICKETS_URL', plugin_dir_url(__FILE__));

require_once RCMI_TICKETS_DIR . 'includes/class-roles.php';
require_once RCMI_TICKETS_DIR . 'includes/class-permissions.php';
require_once RCMI_TICKETS_DIR . 'includes/class-activator.php';
require_once RCMI_TICKETS_DIR . 'includes/class-deactivator.php';
require_once RCMI_TICKETS_DIR . 'includes/class-rest-tickets.php';
require_once RCMI_TICKETS_DIR . 'includes/class-rest-tags.php';
require_once RCMI_TICKETS_DIR . 'includes/class-rest-comments.php';
require_once RCMI_TICKETS_DIR . 'includes/class-rest-attachments.php';
require_once RCMI_TICKETS_DIR . 'includes/class-rest-meta.php';
require_once RCMI_TICKETS_DIR . 'includes/class-emails.php';
require_once RCMI_TICKETS_DIR . 'includes/class-updater.php';

register_activation_hook(__FILE__, 'rcmi_tickets_activate');
register_deactivation_hook(__FILE__, 'rcmi_tickets_deactivate');

function rcmi_tickets_activate() {
    rcmi_tickets_register_roles();
    rcmi_tickets_create_tables();
}

function rcmi_tickets_deactivate() {
    rcmi_tickets_remove_roles();
    rcmi_tickets_deactivate_schema();
}

add_shortcode('rcmi_tickets', 'rcmi_tickets_render_shortcode');

function rcmi_tickets_render_shortcode() {
    if (!is_user_logged_in()) {
        ob_start();
        ?>
        <style>
            .rcmi-tickets-login{max-width:28rem;margin:2rem auto;padding:2rem;border:1px solid #e5e7eb;border-radius:.75rem;background:#fff;box-shadow:0 10px 25px rgba(15,23,42,.08)}
            .rcmi-tickets-login h2{margin:0 0 .5rem;font-size:1.5rem;line-height:2rem;color:#111827}
            .rcmi-tickets-login>p{margin:0 0 1.5rem;color:#6b7280}
            .rcmi-tickets-login form p{margin:0 0 1rem}
            .rcmi-tickets-login label{display:block;margin-bottom:.35rem;font-size:.875rem;font-weight:600;color:#374151}
            .rcmi-tickets-login input[type=text],.rcmi-tickets-login input[type=password]{box-sizing:border-box;width:100%;padding:.625rem .75rem;border:1px solid #d1d5db;border-radius:.375rem}
            .rcmi-tickets-login .login-remember{display:flex;align-items:center;gap:.4rem;font-size:.875rem;color:#4b5563}
            .rcmi-tickets-login .login-submit{margin-top:1.25rem}
            .rcmi-tickets-login input[type=submit]{width:100%;padding:.625rem 1rem;border:0;border-radius:.375rem;background:#b91c1c;color:#fff;font-weight:600;cursor:pointer}
            .rcmi-tickets-login input[type=submit]:hover{background:#991b1b}
        </style>
        <div class="rcmi-tickets-login" role="region" aria-labelledby="rcmi-tickets-login-title">
            <h2 id="rcmi-tickets-login-title"><?php esc_html_e('Sign in to view tickets', 'rcmi-tickets'); ?></h2>
            <p><?php esc_html_e('Use your WordPress account to access the ticket system.', 'rcmi-tickets'); ?></p>
            <?php
            wp_login_form([
                'echo'           => true,
                'redirect'       => get_permalink(),
                'label_username' => __('Username or Email Address', 'rcmi-tickets'),
                'label_password' => __('Password', 'rcmi-tickets'),
                'label_remember' => __('Remember Me', 'rcmi-tickets'),
                'label_log_in'   => __('Sign In', 'rcmi-tickets'),
                'remember'       => true,
            ]);
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    rcmi_tickets_enqueue_app();

    return '<div id="rcmi-tickets-app"></div>';
}

function rcmi_tickets_enqueue_app() {
    $manifest_path = RCMI_TICKETS_DIR . 'dist/.vite/manifest.json';

    if (!file_exists($manifest_path)) {
        if (current_user_can('manage_options')) {
            wp_enqueue_script('rcmi-tickets-missing', 'data:text/javascript,console.error(' . wp_json_encode('RCMI Tickets: dist/ not built. Run npm run build in plugin app/ directory.') . ')', [], RCMI_TICKETS_VERSION, true);
        }
        return;
    }

    $manifest = json_decode(file_get_contents($manifest_path), true);
    $entry = $manifest['src/main.js'] ?? null;

    if (!$entry) {
        return;
    }

    wp_enqueue_script(
        'rcmi-tickets-app',
        RCMI_TICKETS_URL . 'dist/' . $entry['file'],
        [],
        RCMI_TICKETS_VERSION,
        true
    );

    if (!empty($entry['css'])) {
        foreach ($entry['css'] as $i => $css_file) {
            wp_enqueue_style(
                'rcmi-tickets-app-' . $i,
                RCMI_TICKETS_URL . 'dist/' . $css_file,
                [],
                RCMI_TICKETS_VERSION
            );
        }
    }

    wp_localize_script('rcmi-tickets-app', 'rcmiTickets', [
        'apiBase'  => esc_url_raw(rest_url('rcmi/v1')),
        'nonce'    => wp_create_nonce('wp_rest'),
        'loginUrl' => wp_login_url(get_permalink()),
    ]);
}

add_filter('script_loader_tag', 'rcmi_tickets_module_script', 10, 3);

function rcmi_tickets_module_script($tag, $handle, $src) {
    if ($handle !== 'rcmi-tickets-app') {
        return $tag;
    }
    return sprintf('<script type="module" src="%s"></script>' . "\n", esc_url($src));
}
