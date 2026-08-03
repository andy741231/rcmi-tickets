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

// ============================================================
// DIAGNOSTIC ERROR CAPTURE (temporary — remove after activation
// issue is resolved).
//
// WordPress swallows the real fatal error and shows a generic
// "Plugin could not be activated because it triggered a fatal
// error." message. This block captures the actual error via:
//   1. try/catch around each require_once (catches ParseError,
//      TypeError, Error, etc.)
//   2. register_shutdown_function (catches E_ERROR that
//      try/catch misses)
// The error is stored in a transient and displayed as an
// admin notice on the Plugins page after the redirect.
// ============================================================

/**
 * Store the activation error so it survives the redirect back
 * to plugins.php.
 */
function rcmi_tickets_store_activation_error($message, $file, $line) {
    set_transient('rcmi_tickets_activation_error', [
        'message' => $message,
        'file'    => $file,
        'line'    => $line,
        'php'     => PHP_VERSION,
        'time'    => current_time('mysql'),
    ], 300);
}

/**
 * Show the captured error as a red admin notice on plugins.php.
 */
add_action('admin_notices', function () {
    if (!function_exists('get_current_screen')) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'plugins') {
        return;
    }
    $error = get_transient('rcmi_tickets_activation_error');
    if (!$error) {
        return;
    }
    delete_transient('rcmi_tickets_activation_error');
    echo '<div class="notice notice-error is-dismissible" style="padding:12px 16px;">';
    echo '<h3 style="margin:4px 0 8px;">RCMI Tickets — Activation Error</h3>';
    echo '<p><strong>PHP ' . esc_html($error['php']) . '</strong></p>';
    echo '<p style="font-family:monospace;font-size:13px;background:#fff;border:1px solid #ddd;padding:8px 12px;">';
    echo esc_html($error['message']);
    echo '</p>';
    echo '<p>File: <code>' . esc_html($error['file']) . '</code> &nbsp; Line: <code>' . (int) $error['line'] . '</code></p>';
    echo '<p style="color:#666;font-size:12px;">Captured at ' . esc_html($error['time']) . '</p>';
    echo '</div>';
});

// Catch fatal errors that try/catch cannot (E_ERROR from C
// extensions, etc.). Runs after the script shuts down.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        rcmi_tickets_store_activation_error(
            'FATAL (' . $e['type'] . '): ' . $e['message'],
            $e['file'],
            $e['line']
        );
    }
});

// Load includes with try/catch — catches ParseError, TypeError,
// Error, and any Throwable from file-level code.
$rcmi_tickets_includes = [
    'includes/class-roles.php',
    'includes/class-permissions.php',
    'includes/class-activator.php',
    'includes/class-deactivator.php',
    'includes/class-rest-tickets.php',
    'includes/class-rest-tags.php',
    'includes/class-rest-comments.php',
    'includes/class-rest-attachments.php',
    'includes/class-rest-meta.php',
    'includes/class-emails.php',
    'includes/class-updater.php',
    'includes/class-settings.php',
];

foreach ($rcmi_tickets_includes as $rcmi_inc_file) {
    try {
        require_once RCMI_TICKETS_DIR . $rcmi_inc_file;
    } catch (\Throwable $rcmi_inc_err) {
        rcmi_tickets_store_activation_error(
            'Error loading ' . $rcmi_inc_file . ': ' . $rcmi_inc_err->getMessage(),
            $rcmi_inc_err->getFile(),
            $rcmi_inc_err->getLine()
        );
        // Stop loading — the plugin can't function without its
        // includes. The error will be shown on plugins.php.
        return;
    }
}

register_activation_hook(__FILE__, 'rcmi_tickets_activate');
register_deactivation_hook(__FILE__, 'rcmi_tickets_deactivate');

function rcmi_tickets_activate() {
    try {
        rcmi_tickets_register_roles();
        rcmi_tickets_create_tables();
    } catch (\Throwable $e) {
        rcmi_tickets_store_activation_error(
            'Activation hook error: ' . $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
        // Show the error immediately on the activation screen
        // (before WordPress redirects to plugins.php).
        wp_die(
            '<h2>RCMI Tickets — Activation Error</h2>' .
            '<p><strong>PHP ' . PHP_VERSION . '</strong></p>' .
            '<p style="font-family:monospace;background:#f9f9f9;padding:10px;border:1px solid #ddd;">' .
            esc_html($e->getMessage()) . '</p>' .
            '<p>File: <code>' . esc_html($e->getFile()) . '</code> &nbsp; Line: <code>' .
            (int) $e->getLine() . '</code></p>' .
            '<p><a href="' . esc_url(admin_url('plugins.php')) . '">&larr; Back to plugins</a></p>',
            'RCMI Tickets Activation Error',
            ['back_link' => true, 'response' => 500]
        );
    }
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
