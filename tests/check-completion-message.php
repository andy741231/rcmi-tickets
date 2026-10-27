<?php
/**
 * Global completion-message regression checks. Run under WP-CLI:
 *   php wp-cli.phar eval-file wp-content/plugins/rcmi-tickets/tests/check-completion-message.php
 *
 * Covers GET/PUT /settings roundtrip (rich allowlist + stripping),
 * partial-update isolation between public_success and completion_message,
 * permission denial, and the global-over-chain getter. Options restored.
 */

if (!function_exists('rcmi_tickets_get_completion_message')) {
    fwrite(STDERR, "Plugin functions not loaded — run via wp-cli eval-file.\n");
    exit(1);
}

function cm_check($label, $cond) {
    if ($cond) {
        $GLOBALS['cm_pass'] = ($GLOBALS['cm_pass'] ?? 0) + 1;
        echo "  ok    $label\n";
    } else {
        $GLOBALS['cm_errors'][] = $label;
        echo "  FAIL  $label\n";
    }
}

function cm_put($body) {
    $req = new WP_REST_Request('PUT', '/rcmi/v1/settings');
    $req->set_body(json_encode($body));
    $req->set_header('Content-Type', 'application/json');
    return rest_do_request($req);
}

$saved_cm      = get_option('rcmi_tickets_completion_message', null);
$saved_success = get_option('rcmi_tickets_public_success', null);
$saved_public  = get_option('rcmi_tickets_allow_public_submit', null);

$mgr = get_user_by('login', 'ticketmgr1') ?: (get_users(['role' => 'administrator', 'number' => 1])[0] ?? null);
if (!$mgr) { fwrite(STDERR, "No manager user found.\n"); exit(1); }
wp_set_current_user($mgr->ID);

try {
    echo "Settings roundtrip\n";

    $r = cm_put([
        'completion_message' => '<p>Done: <strong>thanks</strong>!</p><ul><li>a</li></ul><p><a href="https://ex.org/x" title="t">link</a></p><script>alert(1)</script><a href="javascript:x()">j</a>',
    ]);
    cm_check('PUT accepted for manager', $r->get_status() === 200);
    $d = $r->get_data();
    cm_check('response exposes completion_message + default',
        array_key_exists('completion_message', $d) && array_key_exists('completion_message_default', $d));
    $m = $d['completion_message'] ?? '';
    cm_check('rich tags kept', strpos($m, '<strong>thanks</strong>') !== false && strpos($m, '<li>a</li>') !== false);
    cm_check('safe link kept', strpos($m, 'href="https://ex.org/x"') !== false);
    cm_check('script tag stripped', stripos($m, '<script') === false);
    cm_check('javascript: URL stripped', stripos($m, 'javascript:') === false);
    cm_check('getter returns saved global', rcmi_tickets_get_completion_message() === $m);
    cm_check('delegating getter matches', rcmi_tickets_get_chain_completion_message(999) === $m);

    echo "Global overrides chain\n";
    global $wpdb;
    $chain1 = $wpdb->get_var("SELECT completion_message FROM {$wpdb->prefix}rcmi_approval_chains WHERE id = 1");
    cm_check('global ignores chain-1 text',
        rcmi_tickets_get_completion_message() !== rcmi_tickets_sanitize_completion_message((string) $chain1)
        || rcmi_tickets_sanitize_completion_message((string) $chain1) === $m);

    echo "Partial-update isolation\n";
    $r = cm_put(['public_success' => ['heading' => 'IsoH', 'message' => '<p>IsoM</p>'], 'allow_public_submit' => true]);
    $d = $r->get_data();
    cm_check('public-only PUT keeps global', ($d['completion_message'] ?? '') === $m);
    $r = cm_put(['completion_message' => '<p>Second global</p>']);
    $d = $r->get_data();
    cm_check('global-only PUT keeps public heading', ($d['public_success']['heading'] ?? '') === 'IsoH');
    cm_check('global-only PUT keeps allow flag', !empty($d['allow_public_submit']));
    cm_check('global updated', strpos($d['completion_message'] ?? '', 'Second global') !== false);

    echo "Permissions\n";
    wp_set_current_user(0);
    cm_check('anonymous PUT denied', in_array(cm_put(['completion_message' => 'x'])->get_status(), [401, 403], true));
    $outsider = get_user_by('login', 'outsider1');
    if ($outsider) {
        wp_set_current_user($outsider->ID);
        cm_check('non-manager PUT denied', in_array(cm_put(['completion_message' => 'x'])->get_status(), [401, 403], true));
    }
} finally {
    if ($saved_cm === null) { delete_option('rcmi_tickets_completion_message'); }
    else { update_option('rcmi_tickets_completion_message', $saved_cm); }
    if ($saved_success === null) { delete_option('rcmi_tickets_public_success'); }
    else { update_option('rcmi_tickets_public_success', $saved_success); }
    if ($saved_public === null) { delete_option('rcmi_tickets_allow_public_submit'); }
    else { update_option('rcmi_tickets_allow_public_submit', $saved_public); }
}

$errors = $GLOBALS['cm_errors'] ?? [];
if ($errors) {
    fwrite(STDERR, "\n" . count($errors) . " check(s) failed:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
printf("\nCompletion-message check passed: %d assertions.%s", $GLOBALS['cm_pass'] ?? 0, "\n");
