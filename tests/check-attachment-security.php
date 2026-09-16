<?php

$test_root = sys_get_temp_dir() . '/rcmi-tickets-security-' . bin2hex(random_bytes(8));

define('ABSPATH', dirname(__DIR__) . '/');
define('WP_CONTENT_DIR', $test_root);
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

function add_action($hook, $callback) {}
function trailingslashit($value) {
    return rtrim($value, "/\\") . '/';
}
function wp_mkdir_p($target) {
    return is_dir($target) || @mkdir($target, 0777, true);
}

class WP_Error {
    private $code;
    private $message;

    public function __construct($code, $message, $data = null) {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }
}

function is_wp_error($value) {
    return $value instanceof WP_Error;
}

class RCMI_Tickets_Test_Request extends ArrayObject {
    public function get_param($key) {
        return $this[$key] ?? null;
    }
}

class RCMI_Tickets_Test_Wpdb {
    public $prefix = 'wp_';

    public function prepare($query, ...$args) {
        return $query;
    }

    public function get_row($query, $format = null) {
        global $rcmi_tickets_test_attachment;
        return $rcmi_tickets_test_attachment;
    }

    public function get_var($query) {
        return null;
    }
}

$rcmi_tickets_test_attachment = [
    'id' => '9',
    'ticket_id' => '42',
    'comment_id' => null,
    'uploader_id' => '2',
    'file_path' => 'sample.txt',
    'original_name' => 'sample.txt',
    'mime_type' => 'text/plain',
    'size' => '7',
];
$rcmi_tickets_test_user_id = 0;
$rcmi_tickets_test_valid_token = 'valid-token';
$wpdb = new RCMI_Tickets_Test_Wpdb();

function get_current_user_id() {
    global $rcmi_tickets_test_user_id;
    return $rcmi_tickets_test_user_id;
}

function rcmi_tickets_load_ticket($ticket_id) {
    return [
        'id' => (int) $ticket_id,
        'author_id' => 2,
        'status' => 'Received',
        'assignee_ids' => [],
    ];
}

function rcmi_tickets_validate_view_token($ticket_id, $token) {
    global $rcmi_tickets_test_valid_token;
    if ($token === $rcmi_tickets_test_valid_token) {
        return ['id' => (int) $ticket_id];
    }
    return new WP_Error('rcmi_tickets_bad_view_token', 'Invalid or missing view token.', ['status' => 403]);
}

function rcmi_tickets_can($user_id, $action, $ticket = null) {
    return (int) $user_id === 7 && $action === 'view';
}

require_once dirname(__DIR__) . '/includes/class-data-lifecycle.php';
require_once dirname(__DIR__) . '/includes/class-rest-attachments.php';

$errors = [];

if (!rcmi_tickets_ensure_upload_protection()) {
    $errors[] = 'Protection files could not be created.';
}

$required_directives = [
    'index.php' => ['exit;'],
    '.htaccess' => ['Require all denied', 'Deny from all'],
    'web.config' => ['<requestFiltering>', '<add segment="rcmi-tickets" />'],
];
foreach ($required_directives as $filename => $needles) {
    $path = trailingslashit(rcmi_tickets_upload_root()) . $filename;
    if (!is_file($path)) {
        $errors[] = $filename . ' was not created.';
        continue;
    }
    $contents = file_get_contents($path);
    foreach ($needles as $needle) {
        if (strpos($contents, $needle) === false) {
            $errors[] = $filename . ' is missing: ' . $needle;
        }
    }
}

$web_config = file_get_contents(trailingslashit(rcmi_tickets_upload_root()) . 'web.config');
if (function_exists('simplexml_load_string') && false === simplexml_load_string($web_config)) {
    $errors[] = 'web.config is not valid XML.';
}

$dir = rcmi_tickets_upload_dir(42);
$expected_dir = trailingslashit(rcmi_tickets_upload_root()) . '42';
if (!is_array($dir) || ($dir['path'] ?? '') !== $expected_dir) {
    $errors[] = 'Ticket upload directory was not created at the protected root.';
} else {
    file_put_contents($dir['path'] . '/sample.txt', 'private');
    $path = rcmi_tickets_attachment_path($rcmi_tickets_test_attachment);
    if (!is_readable($path) || file_get_contents($path) !== 'private') {
        $errors[] = 'Protected attachment is not readable through the PHP filesystem path.';
    }
    if (array_key_exists('url', $dir)) {
        $errors[] = 'Upload directory exposes a raw public URL.';
    }
}

$request = new RCMI_Tickets_Test_Request(['id' => 9, 'token' => 'valid-token']);
if (rcmi_tickets_perm_attachment_download($request) !== true) {
    $errors[] = 'Valid public view token did not authorize the protected download.';
}

$request = new RCMI_Tickets_Test_Request(['id' => 9, 'token' => 'invalid-token']);
$result = rcmi_tickets_perm_attachment_download($request);
if (!is_wp_error($result) || $result->get_error_code() !== 'rcmi_tickets_bad_view_token') {
    $errors[] = 'Anonymous invalid token was not rejected by the protected download.';
}

$rcmi_tickets_test_user_id = 7;
$request = new RCMI_Tickets_Test_Request(['id' => 9]);
if (rcmi_tickets_perm_attachment_download($request) !== true) {
    $errors[] = 'Authenticated ticket viewer was not authorized by normal permissions.';
}

$rcmi_tickets_test_user_id = 0;
if (rcmi_tickets_perm_attachment_download($request) !== false) {
    $errors[] = 'Anonymous request without a token was not rejected.';
}

if (is_dir($test_root)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($test_root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());
        } elseif ($item->isDir()) {
            @rmdir($item->getPathname());
        }
    }
    @rmdir($test_root);
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "Attachment security check passed: server guards, protected storage path, public token, authenticated fallback, and anonymous denial." . PHP_EOL;
