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
    'includes/class-rest-tag-rules.php',
    'includes/class-rest-comments.php',
    'includes/class-rest-attachments.php',
    'includes/class-rest-meta.php',
    'includes/class-rest-public.php',
    'includes/class-rest-form-fields.php',
    'includes/class-rest-approval-chains.php',
    'includes/class-rest-approvals.php',
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

// ============================================================
// SMTP CONFIGURATION — UH Campus SMTP
// Configures wp_mail() to send through post-office.uh.edu.
// This affects ALL WordPress emails (not just tickets):
//   password resets, admin notifications, Spectra, etc.
// To disable, comment out this block or remove the add_action.
// ============================================================
add_action('phpmailer_init', function ($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host       = 'post-office.uh.edu';
    $phpmailer->Port       = 25;
    $phpmailer->SMTPAuth   = false;          // no auth on campus SMTP
    $phpmailer->SMTPSecure = '';             // no TLS/SSL (port 25 plaintext)
    $phpmailer->SMTPAutoTLS = false;         // don't auto-upgrade to TLS
    $phpmailer->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];
    // EHLO hostname — some SMTP servers reject default localhost
    $phpmailer->Helo = 'central.uh.edu';

    // From address — overrides WordPress defaults
    $phpmailer->From     = 'donotreply@uh.edu';
    $phpmailer->FromName = get_bloginfo('name') ?: 'RCMI';
});

// Also set the From header at the wp_mail filter level so it's
// consistent even if phpmailer_init is bypassed by other plugins.
add_filter('wp_mail_from', function () {
    return 'donotreply@uh.edu';
});
add_filter('wp_mail_from_name', function () {
    return get_bloginfo('name') ?: 'RCMI';
});

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

/**
 * Use a blank template (no theme header/footer) on the tickets page so
 * the SPA fills the viewport without site chrome.
 */
add_filter('template_include', 'rcmi_tickets_blank_template', 99);

function rcmi_tickets_blank_template($template) {
    if (!is_singular() || !has_shortcode(get_post()->post_content ?? '', 'rcmi_tickets')) {
        return $template;
    }
    $blank = RCMI_TICKETS_DIR . 'includes/template-blank.php';
    return file_exists($blank) ? $blank : $template;
}

function rcmi_tickets_render_shortcode() {
    // Load the Vue app for both logged-in and logged-out users.
    // Logged-out users get a public submission form (router restricts to /create).
    // Logged-in users get the full ticket system.
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

    // Source Sans 3 font for the ticket UI
    wp_enqueue_style(
        'rcmi-tickets-source-sans',
        'https://fonts.googleapis.com/css2?family=Source+Sans+3:ital,wght@0,400;0,600;0,700;1,400&display=swap',
        [],
        null
    );

    wp_localize_script('rcmi-tickets-app', 'rcmiTickets', [
        'apiBase'  => esc_url_raw(home_url('/wp-json/rcmi/v1')),
        'nonce'    => wp_create_nonce('wp_rest'),
        'loginUrl' => wp_login_url(get_permalink()),
        'isLoggedIn' => is_user_logged_in(),
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'appUrl'   => get_permalink(),
    ]);
}

// ============================================================
// AJAX LOGIN — allows the Vue login page to authenticate without
// redirecting to wp-login.php. Returns JSON success/error.
// ============================================================
add_action('wp_ajax_nopriv_rcmi_tickets_ajax_login', 'rcmi_tickets_ajax_login');
add_action('wp_ajax_rcmi_tickets_ajax_login', 'rcmi_tickets_ajax_login');

function rcmi_tickets_ajax_login() {
    // Verify the wp_rest nonce (same one localized to the app)
    check_ajax_referer('wp_rest', 'nonce');

    $creds = [
        'user_login'    => sanitize_user($_POST['user_login'] ?? ''),
        'user_password' => $_POST['user_password'] ?? '',
        'remember'      => !empty($_POST['rememberme']) ? true : false,
    ];

    if (empty($creds['user_login']) || empty($creds['user_password'])) {
        wp_send_json_error(['message' => 'Please enter both username and password.'], 400);
    }

    $user = wp_signon($creds, is_ssl());

    if (is_wp_error($user)) {
        wp_send_json_error(['message' => 'Invalid username or password.'], 401);
    }

    wp_send_json_success([
        'user_id'   => $user->ID,
        'display_name' => $user->display_name,
    ]);
}

// ============================================================
// AJAX PASSWORD RESET — triggers WordPress's lostpassword flow
// without redirecting to wp-login.php.
// ============================================================
add_action('wp_ajax_nopriv_rcmi_tickets_ajax_reset', 'rcmi_tickets_ajax_reset');
add_action('wp_ajax_rcmi_tickets_ajax_reset', 'rcmi_tickets_ajax_reset');

function rcmi_tickets_ajax_reset() {
    check_ajax_referer('wp_rest', 'nonce');

    $user_login = sanitize_user($_POST['user_login'] ?? '');
    if (empty($user_login)) {
        wp_send_json_error(['message' => 'Please enter a username or email.'], 400);
    }

    // Use WordPress's retrieve_password which sends the reset email.
    // It returns WP_Error on failure or true on success.
    $result = retrieve_password($user_login);

    // Always return a generic success message — don't leak whether
    // the account exists (security best practice).
    wp_send_json_success(['message' => 'If an account exists for that email/username, a reset link has been sent.']);
}

add_filter('script_loader_tag', 'rcmi_tickets_module_script', 10, 3);

function rcmi_tickets_module_script($tag, $handle, $src) {
    if ($handle !== 'rcmi-tickets-app') {
        return $tag;
    }
    return sprintf('<script type="module" src="%s"></script>' . "\n", esc_url($src));
}
