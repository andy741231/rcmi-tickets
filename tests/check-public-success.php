<?php
/**
 * Public submission-confirmation settings regression checks. Run under WP-CLI:
 *   php wp-cli.phar eval-file wp-content/plugins/rcmi-tickets/tests/check-public-success.php
 *
 * Covers PUT /settings sanitization (rich-text allowlist for the message,
 * sanitize_text_field for the heading), partial-update safety, legacy
 * plain-text retention, and permission denial. Options are restored.
 */

if (!function_exists('rcmi_tickets_handle_settings_update')) {
    fwrite(STDERR, "Plugin functions not loaded — run via wp-cli eval-file.\n");
    exit(1);
}

// eval-file wraps this file in a function, so plain `global` doesn't reach
// the file's top-level vars — collect results through $GLOBALS instead.
function ps_check($label, $cond) {
    if ($cond) {
        $GLOBALS['ps_pass'] = ($GLOBALS['ps_pass'] ?? 0) + 1;
        echo "  ok    $label\n";
    } else {
        $GLOBALS['ps_errors'][] = $label;
        echo "  FAIL  $label\n";
    }
}

function ps_put_settings($body) {
    $req = new WP_REST_Request('PUT', '/rcmi/v1/settings');
    $req->set_body(json_encode($body));
    $req->set_header('Content-Type', 'application/json');
    return rest_do_request($req);
}

$saved_success = get_option('rcmi_tickets_public_success', null);
$saved_public  = get_option('rcmi_tickets_allow_public_submit', null);

// A manager identity for the authorized calls.
$mgr = get_user_by('login', 'ticketmgr1');
if (!$mgr) {
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    $mgr = $admins ? $admins[0] : null;
}
if (!$mgr) {
    fwrite(STDERR, "No manager/admin user found for the authorized checks.\n");
    exit(1);
}
wp_set_current_user($mgr->ID);

try {
    echo "Rich-text sanitization\n";

    $r = ps_put_settings([
        'public_success' => [
            'heading' => 'Rich heading',
            'message' => '<p>Please <strong>keep</strong> your <em>ticket</em> number.</p><ul><li>One</li><li>Two</li></ul><p><a href="https://example.org/status" title="Status">Check status</a></p>',
        ],
    ]);
    ps_check('PUT accepted for manager', $r->get_status() === 200);
    $d = $r->get_data();
    $msg = $d['public_success']['message'] ?? '';
    ps_check('strong kept', strpos($msg, '<strong>keep</strong>') !== false);
    ps_check('em kept', strpos($msg, '<em>ticket</em>') !== false);
    ps_check('ul/li kept', strpos($msg, '<ul>') !== false && strpos($msg, '<li>One</li>') !== false);
    ps_check('safe https link kept', strpos($msg, 'href="https://example.org/status"') !== false);

    $r = ps_put_settings([
        'public_success' => [
            'heading' => 'Safe heading',
            'message' => '<p>ok</p><script>alert(1)</script><p><img src="x" onerror="alert(2)"></p><p><a href="javascript:alert(3)">bad</a></p>',
        ],
    ]);
    $msg = $r->get_data()['public_success']['message'] ?? '';
    // wp_kses strips the <script> tags; the inner text remains as inert
    // plain text — the security property is that no script element survives.
    ps_check('script tag removed', stripos($msg, '<script') === false && stripos($msg, '</script') === false);
    ps_check('img/onerror removed', strpos($msg, 'onerror') === false && strpos($msg, '<img') === false);
    ps_check('javascript: URL removed', stripos($msg, 'javascript:') === false);
    ps_check('inner text kept', strpos($msg, 'ok') !== false);

    echo "Heading sanitization\n";

    $r = ps_put_settings([
        'public_success' => ['heading' => 'A <b>bold</b> heading <script>x</script>'],
    ]);
    $heading = $r->get_data()['public_success']['heading'] ?? '';
    ps_check('heading strips HTML tags', $heading === 'A bold heading x' || $heading === 'A bold heading');

    echo "Partial update safety\n";

    // Set a known baseline first.
    ps_put_settings([
        'allow_public_submit' => true,
        'public_success' => ['heading' => 'Baseline heading', 'message' => '<p>Baseline</p>'],
    ]);
    // Now send only the message — heading + allow flag must survive.
    $r = ps_put_settings([
        'public_success' => ['message' => '<p>New message only</p>'],
    ]);
    $d = $r->get_data();
    ps_check('partial save keeps heading', ($d['public_success']['heading'] ?? '') === 'Baseline heading');
    ps_check('partial save keeps allow_public_submit', !empty($d['allow_public_submit']));
    ps_check('partial save updates message', strpos($d['public_success']['message'] ?? '', 'New message only') !== false);

    echo "Legacy plain text\n";

    $r = ps_put_settings([
        'public_success' => ['heading' => 'Plain', 'message' => 'Line one\nLine two, no markup at all.'],
    ]);
    ps_check('plain legacy message retained',
        ($r->get_data()['public_success']['message'] ?? '') === 'Line one\nLine two, no markup at all.');

    echo "Permissions\n";

    wp_set_current_user(0); // anonymous
    $r = ps_put_settings(['public_success' => ['message' => 'x'], 'allow_public_submit' => false]);
    ps_check('anonymous PUT denied', in_array($r->get_status(), [401, 403], true));

    $outsider = get_user_by('login', 'outsider1');
    if ($outsider) {
        wp_set_current_user($outsider->ID);
        $r = ps_put_settings(['public_success' => ['message' => 'x']]);
        ps_check('non-manager PUT denied', in_array($r->get_status(), [401, 403], true));
    }
} finally {
    if ($saved_success === null) {
        delete_option('rcmi_tickets_public_success');
    } else {
        update_option('rcmi_tickets_public_success', $saved_success);
    }
    if ($saved_public === null) {
        delete_option('rcmi_tickets_allow_public_submit');
    } else {
        update_option('rcmi_tickets_allow_public_submit', $saved_public);
    }
}

$errors = $GLOBALS['ps_errors'] ?? [];
if ($errors) {
    fwrite(STDERR, "\n" . count($errors) . " check(s) failed:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
printf("\nPublic-success check passed: %d assertions.%s", $GLOBALS['ps_pass'] ?? 0, "\n");
