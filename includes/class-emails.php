<?php
/**
 * Email notifications for RCMI Tickets (ticket-plan.md §7).
 *
 * Hooks:
 * - rcmi_ticket_created($ticket_id, $author_id, $assignee_ids)
 * - rcmi_ticket_status_changed($ticket_id, $new_status, $old_status, $message)
 * - rcmi_ticket_mention($comment_id, $ticket_id, $from_user_id, $mentioned_ids)
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rcmi_ticket_status_changed', 'rcmi_tickets_email_status_changed', 10, 4);
add_action('rcmi_ticket_mention', 'rcmi_tickets_email_mentions', 10, 4);
add_action('rcmi_ticket_approval_step', 'rcmi_tickets_email_approval_step', 10, 3);
add_action('rcmi_ticket_approval_rejected', 'rcmi_tickets_email_approval_rejected', 10, 3);

/**
 * Build the canonical frontend URL for a ticket.
 */
function rcmi_tickets_email_ticket_url($ticket_id) {
    $base = function_exists('rcmi_tickets_get_app_url')
        ? rcmi_tickets_get_app_url()
        : home_url('/');

    return add_query_arg([], untrailingslashit($base)) . '#/ticket/' . (int) $ticket_id;
}

/**
 * Send an HTML + plain-text multipart email through wp_mail().
 *
 * @param string|string[] $to
 * @param string          $subject
 * @param string          $html
 * @param string          $plain
 * @return bool
 */
function rcmi_tickets_send_email($to, $subject, $html, $plain = '') {
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
    ];

    return wp_mail($to, $subject, $html, $headers);
}

/**
 * Escape values for HTML email output.
 */
function rcmi_tickets_email_esc($value) {
    return esc_html((string) $value);
}

/**
 * Build ticket details as HTML table rows + plain-text lines for email.
 * Returns ['html' => string, 'plain' => string].
 */
function rcmi_tickets_email_ticket_details($ticket) {
    $form_answers = rcmi_tickets_get_ticket_form_answers((int) $ticket['id']);
    $form_fields = rcmi_tickets_get_all_form_fields();

    $details_html = '';
    $details_plain = '';

    // Core fields
    $detail_rows = [
        'Due date'  => $ticket['due_date'] ?: '—',
        'Status'    => $ticket['status'],
    ];
    foreach ($detail_rows as $label => $val) {
        $details_html .= '<tr><td style="padding:4px 12px 4px 0;color:#666;font-size:13px;white-space:nowrap;">' . rcmi_tickets_email_esc($label) . ':</td>'
            . '<td style="padding:4px 0;font-size:13px;">' . rcmi_tickets_email_esc($val) . '</td></tr>';
        $details_plain .= $label . ': ' . $val . "\n";
    }

    // Form answers
    foreach ($form_fields as $f) {
        $key = $f['field_key'];
        if (!array_key_exists($key, $form_answers)) continue;
        $val = $form_answers[$key];
        if (is_array($val)) $val = implode(', ', $val);
        if ($val === '' || $val === null) $val = '—';
        $details_html .= '<tr><td style="padding:4px 12px 4px 0;color:#666;font-size:13px;white-space:nowrap;">' . rcmi_tickets_email_esc($f['label']) . ':</td>'
            . '<td style="padding:4px 0;font-size:13px;">' . rcmi_tickets_email_esc($val) . '</td></tr>';
        $details_plain .= $f['label'] . ': ' . $val . "\n";
    }

    // Description
    $desc_text = $ticket['description_text'] ?: wp_strip_all_tags($ticket['description']);
    if ($desc_text) {
        $details_html .= '<tr><td style="padding:4px 12px 4px 0;color:#666;font-size:13px;white-space:nowrap;vertical-align:top;">Description:</td>'
            . '<td style="padding:4px 0;font-size:13px;">' . nl2br(rcmi_tickets_email_esc(mb_substr($desc_text, 0, 500))) . (mb_strlen($desc_text) > 500 ? '…' : '') . '</td></tr>';
        $details_plain .= 'Description: ' . mb_substr($desc_text, 0, 500) . (mb_strlen($desc_text) > 500 ? '…' : '') . "\n";
    }

    return ['html' => $details_html, 'plain' => $details_plain];
}

/**
 * Send a notification to assigned users when a ticket is created.
 */
function rcmi_tickets_email_ticket_created($ticket_id, $author_id, $assignee_ids) {
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    if (!$ticket) {
        return;
    }

    $author = get_userdata($author_id);
    $author_name = $author ? $author->display_name : __('A user', 'rcmi-tickets');
    $recipients = [];

    foreach ((array) $assignee_ids as $user_id) {
        $user = get_userdata((int) $user_id);
        if ($user && is_email($user->user_email)) {
            $recipients[] = $user->user_email;
        }
    }
    $recipients = array_values(array_unique($recipients));
    if (!$recipients) {
        return;
    }

    $title = rcmi_tickets_email_esc($ticket['title']);
    $url = esc_url(rcmi_tickets_email_ticket_url($ticket_id));
    $author_html = rcmi_tickets_email_esc($author_name);
    $subject = sprintf(__('New ticket assigned: #%d %s', 'rcmi-tickets'), $ticket_id, $ticket['title']);

    // Ticket details
    $details = rcmi_tickets_email_ticket_details($ticket);

    $html = '<!doctype html><html><body>'
        . '<h2>' . __('New ticket assigned', 'rcmi-tickets') . '</h2>'
        . '<p>' . sprintf(__('You have been assigned to ticket #%d.', 'rcmi-tickets'), $ticket_id) . '</p>'
        . '<p><strong>' . __('Title:', 'rcmi-tickets') . '</strong> ' . $title . '</p>'
        . '<p><strong>' . __('Submitted by:', 'rcmi-tickets') . '</strong> ' . $author_html . '</p>'
        . '<p style="margin-top:1rem;"><a href="' . $url . '" style="display:inline-block;padding:.6rem 1.2rem;background:#c8102e;color:#fff;text-decoration:none;border-radius:.375rem;">' . __('View ticket', 'rcmi-tickets') . '</a></p>'
        . '<h3 style="margin-top:1.5rem;font-size:15px;color:#333;">' . __('Ticket details', 'rcmi-tickets') . '</h3>'
        . '<table style="border-collapse:collapse;margin-top:.5rem;">' . $details['html'] . '</table>'
        . '</body></html>';

    $plain = sprintf(
        "New ticket assigned\n\nYou have been assigned to ticket #%d.\nTitle: %s\nSubmitted by: %s\n\nView ticket: %s\n\nTicket details:\n%s",
        $ticket_id,
        $ticket['title'],
        $author_name,
        wp_strip_all_tags($url),
        $details['plain']
    );

    rcmi_tickets_send_email($recipients, $subject, $html, $plain);
}

/**
 * Send status notifications to assignees or the ticket requestor.
 * When status is 'Completed', includes the custom completion message
 * from the approval chain that processed the ticket (if any).
 */
function rcmi_tickets_email_status_changed($ticket_id, $new_status, $old_status, $message = null) {
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    if (!$ticket || $new_status === $old_status) {
        return;
    }

    // Rejection emails are handled by rcmi_tickets_email_approval_rejected
    // (hooked to rcmi_ticket_approval_rejected), which includes the reviewer's
    // comment and revision guidance. Skip here to avoid sending a duplicate.
    if (in_array($new_status, ['Rejected', 'Rejected: Pending Revision'], true)) {
        return;
    }

    $recipients = [];
    $event_label = $new_status;
    $is_public_ticket = function_exists('rcmi_tickets_is_public_ticket') && rcmi_tickets_is_public_ticket($ticket);

    if ($new_status === 'Approved') {
        // Assignees are notified when the ticket is approved — this is their
        // assignment notification (the ticket is now ready for them to work on).
        foreach ($ticket['assignee_ids'] as $user_id) {
            $user = get_userdata($user_id);
            if ($user && is_email($user->user_email)) {
                $recipients[] = $user->user_email;
            }
        }
    } elseif (in_array($new_status, ['Rejected', 'Completed'], true)) {
        // Public tickets share a "Guest Submitter" account as requestor, so the
        // requestor's WP user_email is a fake placeholder. Use the per-ticket
        // submitter_email column instead.
        if ($is_public_ticket && !empty($ticket['submitter_email']) && is_email($ticket['submitter_email'])) {
            $recipients[] = $ticket['submitter_email'];
        } else {
            $author = get_userdata($ticket['author_id']);
            if ($author && is_email($author->user_email)) {
                $recipients[] = $author->user_email;
            }
        }
    }

    $recipients = array_values(array_unique($recipients));
    if (!$recipients) {
        return;
    }

    // For Completed status, fetch the chain's custom completion message
    $completion_message = '';
    if ($new_status === 'Completed') {
        $completion_message = rcmi_tickets_get_chain_completion_message($ticket_id);
    }

    $title = rcmi_tickets_email_esc($ticket['title']);
    // Public submitters get the same stable view link in every email.
    $url = esc_url($is_public_ticket && function_exists('rcmi_tickets_public_ticket_url')
        ? rcmi_tickets_public_ticket_url($ticket_id)
        : rcmi_tickets_email_ticket_url($ticket_id));
    $status_html = rcmi_tickets_email_esc($new_status);
    $subject = sprintf(__('Ticket #%d %s: %s', 'rcmi-tickets'), $ticket_id, $event_label, $ticket['title']);

    // Headline: "Ticket Approved" / "Ticket Completed"
    $headline = sprintf(__('Ticket %s', 'rcmi-tickets'), $new_status);
    $intro = '';
    if ($new_status === 'Approved') {
        $intro = __('You have been assigned to this ticket. It has been approved and is ready for you to work on.');
    }

    $message = $message ? sanitize_textarea_field($message) : '';
    $message_html = $message !== ''
        ? '<p><strong>' . __('Message:', 'rcmi-tickets') . '</strong> ' . nl2br(rcmi_tickets_email_esc($message)) . '</p>'
        : '';
    $message_plain = $message !== '' ? "\nMessage: {$message}\n" : '';

    // Ticket details
    $details = rcmi_tickets_email_ticket_details($ticket);

    // Completion message from the chain (if any)
    $completion_html = '';
    $completion_plain = '';
    if ($completion_message !== '') {
        $allowed_html = [
            'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [],
            'a' => ['href' => true, 'title' => true],
            'ul' => [], 'ol' => [], 'li' => [],
        ];
        $safe_html = wp_kses($completion_message, $allowed_html);
        $completion_plain = "\n" . wp_strip_all_tags($completion_message) . "\n";
        $completion_html = '<div style="margin-top:1rem;padding:1rem;background:#f0fdf4;border-left:4px solid #00B388;border-radius:.375rem;">'
            . '<div style="font-size:14px;color:#166534;">' . $safe_html . '</div>'
            . '</div>';
    }

    $intro_html = $intro ? '<p>' . rcmi_tickets_email_esc($intro) . '</p>' : '';
    $intro_plain = $intro ? $intro . "\n\n" : '';

    $html = '<!doctype html><html><body>'
        . '<h2>' . rcmi_tickets_email_esc($headline) . '</h2>'
        . $intro_html
        . '<p><strong>' . __('Ticket:', 'rcmi-tickets') . '</strong> #' . (int) $ticket_id . ' — ' . $title . '</p>'
        . $message_html
        . $completion_html
        . '<p style="margin-top:1rem;"><a href="' . $url . '" style="display:inline-block;padding:.6rem 1.2rem;background:#c8102e;color:#fff;text-decoration:none;border-radius:.375rem;">' . __('View ticket', 'rcmi-tickets') . '</a></p>'
        . '<h3 style="margin-top:1.5rem;font-size:15px;color:#333;">' . __('Ticket details', 'rcmi-tickets') . '</h3>'
        . '<table style="border-collapse:collapse;margin-top:.5rem;">' . $details['html'] . '</table>'
        . '</body></html>';

    $plain = sprintf(
        "%s\n\n%sTicket: #%d — %s%s%s\nView ticket: %s\n\nTicket details:\n%s",
        $headline,
        $intro_plain,
        $ticket_id,
        $ticket['title'],
        $message_plain,
        $completion_plain,
        wp_strip_all_tags($url),
        $details['plain']
    );

    rcmi_tickets_send_email($recipients, $subject, $html, $plain);
}

/**
 * Get the completion message from the approval chain that processed
 * a given ticket. Looks up the chain via the ticket_approvals table.
 *
 * @param int $ticket_id
 * @return string Completion message (empty string if no chain or no message)
 */
function rcmi_tickets_get_chain_completion_message($ticket_id) {
    global $wpdb;
    $chain_id = $wpdb->get_var($wpdb->prepare(
        "SELECT chain_id FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE ticket_id = %d ORDER BY id DESC LIMIT 1",
        (int) $ticket_id
    ));
    if (!$chain_id) {
        return '';
    }
    $message = $wpdb->get_var($wpdb->prepare(
        "SELECT completion_message FROM {$wpdb->prefix}rcmi_approval_chains WHERE id = %d",
        (int) $chain_id
    ));
    return $message ?: '';
}

/**
 * Notify mentioned users about a new comment.
 */
function rcmi_tickets_email_mentions($comment_id, $ticket_id, $from_user_id, $mentioned_ids) {
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    $comment = rcmi_tickets_load_comment($comment_id);
    $from_user = get_userdata($from_user_id);
    if (!$ticket || !$comment || !$from_user) {
        return;
    }

    $guest_user_id = function_exists('rcmi_tickets_get_or_create_guest_user') ? rcmi_tickets_get_or_create_guest_user() : 0;
    $commenter_name = ($guest_user_id && (int) $from_user_id === $guest_user_id && !empty($ticket['submitter_name']))
        ? $ticket['submitter_name']
        : $from_user->display_name;

    // Staff recipients get the internal ticket URL. The external submitter
    // (shared "Guest Submitter" account) gets their own tokenized view URL
    // and their real email/name from the ticket row — never the generic
    // guest account's placeholder email.
    $staff_recipients = [];
    $submitter_recipient = '';
    foreach ((array) $mentioned_ids as $user_id) {
        $user_id = (int) $user_id;
        if ($guest_user_id && $user_id === $guest_user_id) {
            if (!empty($ticket['submitter_email']) && is_email($ticket['submitter_email'])) {
                $submitter_recipient = $ticket['submitter_email'];
            }
            continue;
        }
        $user = get_userdata($user_id);
        if ($user && is_email($user->user_email)) {
            $staff_recipients[] = $user->user_email;
        }
    }
    $staff_recipients = array_values(array_unique($staff_recipients));

    $title = rcmi_tickets_email_esc($ticket['title']);
    $excerpt = wp_trim_words(wp_strip_all_tags($comment['body']), 40, '…');
    $commenter_html = rcmi_tickets_email_esc($commenter_name);
    $excerpt_html = rcmi_tickets_email_esc($excerpt);
    $subject = sprintf(__('You were mentioned on ticket #%d: %s', 'rcmi-tickets'), $ticket_id, $ticket['title']);

    $send_mention_email = function ($recipients, $url) use ($title, $excerpt, $excerpt_html, $commenter_html, $commenter_name, $subject, $ticket_id, $ticket) {
        $url_esc = esc_url($url);
        $html = '<!doctype html><html><body>'
            . '<h2>' . __('You were mentioned in a ticket comment', 'rcmi-tickets') . '</h2>'
            . '<p>' . sprintf(__('%s mentioned you on ticket #%d.', 'rcmi-tickets'), $commenter_html, $ticket_id) . '</p>'
            . '<p><strong>' . __('Ticket:', 'rcmi-tickets') . '</strong> ' . $title . '</p>'
            . '<blockquote>' . $excerpt_html . '</blockquote>'
            . '<p><a href="' . $url_esc . '">' . __('View comment', 'rcmi-tickets') . '</a></p>'
            . '</body></html>';

        $plain = sprintf(
            "%s mentioned you on ticket #%d.\nTicket: %s\n\nComment:\n%s\n\nView comment: %s",
            $commenter_name,
            $ticket_id,
            $ticket['title'],
            $excerpt,
            wp_strip_all_tags($url)
        );

        rcmi_tickets_send_email($recipients, $subject, $html, $plain);
    };

    if ($staff_recipients) {
        $send_mention_email($staff_recipients, rcmi_tickets_email_ticket_url($ticket_id));
    }

    if ($submitter_recipient) {
        $view_url = function_exists('rcmi_tickets_public_ticket_url')
            ? rcmi_tickets_public_ticket_url($ticket_id)
            : rcmi_tickets_email_ticket_url($ticket_id);
        $send_mention_email([$submitter_recipient], $view_url);
    }
}

/**
 * Build a one-click approve/reject URL for an approval step token.
 * Uses the REST API with plain-permalink-safe ?rest_route= format.
 */
function rcmi_tickets_email_approval_action_url($approval_id, $token, $action) {
    $base = home_url('/wp-json/rcmi/v1/approvals/' . (int) $approval_id . '/token-' . $action);
    return add_query_arg('token', $token, $base);
}

/**
 * Notify the approver of a pending step.
 * Hooked on rcmi_ticket_approval_step($ticket_id, $approval_id, $event).
 * $event: 'chain_started' | 'approve_advanced' | 'reject_back_one'
 */
function rcmi_tickets_email_approval_step($ticket_id, $approval_id, $event) {
    global $wpdb;
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    if (!$ticket) {
        return;
    }
    $approval = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE id = %d",
        (int) $approval_id
    ), ARRAY_A);
    if (!$approval || $approval['status'] !== 'pending') {
        return;
    }

    // Resolve recipient
    $approver_user_id = $approval['approver_user_id'] ? (int) $approval['approver_user_id'] : 0;
    if (!$approver_user_id) {
        // Role-based: email all users with that role
        $users = get_users(['role' => $approval['approver_role'], 'fields' => ['user_email']]);
        $recipients = array_filter(wp_list_pluck($users, 'user_email'), 'is_email');
    } else {
        $u = get_userdata($approver_user_id);
        $recipients = ($u && is_email($u->user_email)) ? [$u->user_email] : [];
    }
    if (!$recipients) {
        return;
    }

    $title = rcmi_tickets_email_esc($ticket['title']);
    $ticket_url = esc_url(rcmi_tickets_email_ticket_url($ticket_id));
    $step_label = sprintf(__('Step %d', 'rcmi-tickets'), (int) $approval['sort_order']);

    // Requestor info
    $author = get_userdata((int) $ticket['author_id']);
    $author_name = $author ? $author->display_name : __('Unknown', 'rcmi-tickets');
    $author_email = $author ? $author->user_email : '';
    $author_html = rcmi_tickets_email_esc($author_name) . ($author_email ? ' &lt;' . rcmi_tickets_email_esc($author_email) . '&gt;' : '');

    // Ticket details
    $details = rcmi_tickets_email_ticket_details($ticket);

    $subject = sprintf(__('Approval needed: ticket #%d %s', 'rcmi-tickets'), $ticket_id, $ticket['title']);

    $html = '<!doctype html><html><body>'
        . '<h2>' . sprintf(__('Your approval is requested (ticket #%d)', 'rcmi-tickets'), $ticket_id) . '</h2>'
        . '<p><strong>' . __('Title:', 'rcmi-tickets') . '</strong> ' . $title . '</p>'
        . '<p><strong>' . __('Stage:', 'rcmi-tickets') . '</strong> ' . rcmi_tickets_email_esc($step_label) . '</p>'
        . '<p><strong>' . __('Ticket submitted by:', 'rcmi-tickets') . '</strong> ' . $author_html . '</p>'
        . '<p style="margin-top:1rem;"><a href="' . $ticket_url . '" style="display:inline-block;padding:.6rem 1.2rem;background:#c8102e;color:#fff;text-decoration:none;border-radius:.375rem;">' . __('View ticket', 'rcmi-tickets') . '</a></p>'
        . '<h3 style="margin-top:1.5rem;font-size:15px;color:#333;">' . __('Ticket details', 'rcmi-tickets') . '</h3>'
        . '<table style="border-collapse:collapse;margin-top:.5rem;">' . $details['html'] . '</table>'
        . '</body></html>';

    $plain = sprintf(
        "Your approval is requested (ticket #%d)\n\nTitle: %s\nStage: %s\nTicket submitted by: %s <%s>\n\nView ticket: %s\n\nTicket details:\n%s",
        $ticket_id, $ticket['title'], $step_label, $author_name, $author_email,
        wp_strip_all_tags($ticket_url),
        $details['plain']
    );

    rcmi_tickets_send_email($recipients, $subject, $html, $plain);
}

/**
 * Notify the ticket requestor when a chain rejection occurred.
 * Hooked on rcmi_ticket_approval_rejected($ticket_id, $mode, $comment).
 * $mode: 'restart' | 'terminal'
 */
function rcmi_tickets_email_approval_rejected($ticket_id, $mode, $comment) {
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    if (!$ticket) {
        return;
    }
    $author = get_userdata((int) $ticket['author_id']);
    $formatted_ticket = rcmi_tickets_format_ticket($ticket);
    $recipient_email = $formatted_ticket['author_email'];
    if (!$author || !is_email($recipient_email)) {
        return;
    }

    $title = rcmi_tickets_email_esc($ticket['title']);
    $is_public = function_exists('rcmi_tickets_is_public_ticket') && rcmi_tickets_is_public_ticket($ticket);
    $url = esc_url($is_public && function_exists('rcmi_tickets_public_ticket_url')
        ? rcmi_tickets_public_ticket_url($ticket_id)
        : rcmi_tickets_email_ticket_url($ticket_id));
    $comment_html = $comment ? '<p><strong>' . __('Reviewer note:', 'rcmi-tickets') . '</strong> ' . nl2br(rcmi_tickets_email_esc($comment)) . '</p>' : '';
    $comment_plain = $comment ? "\nReviewer note: {$comment}\n" : '';

    // Ticket details
    $details = rcmi_tickets_email_ticket_details($ticket);

    if ($mode === 'terminal') {
        $subject = sprintf(__('Ticket #%d: %s', 'rcmi-tickets'), $ticket_id, $ticket['title']);
        $headline = __('Your request was not approved', 'rcmi-tickets');
        $guidance = __('Unfortunately, your request could not be approved at this time. If you believe this is an error or would like to discuss next steps, please reach out to an operation manager:');
        // List ticket managers with name + email
        $manager_ids = function_exists('rcmi_tickets_get_manage_user_ids') ? rcmi_tickets_get_manage_user_ids() : [];
        $manager_lines_html = [];
        $manager_lines_plain = [];
        foreach ($manager_ids as $mid) {
            $mu = get_userdata((int) $mid);
            if ($mu && is_email($mu->user_email)) {
                $name = rcmi_tickets_email_esc($mu->display_name);
                $email = rcmi_tickets_email_esc($mu->user_email);
                $manager_lines_html[] = '<li style="margin-bottom:.25rem;"><strong>' . $name . '</strong> &lt;<a href="mailto:' . $email . '" style="color:#c8102e;text-decoration:none;">' . $email . '</a>&gt;</li>';
                $manager_lines_plain[] = '  • ' . $mu->display_name . ' <' . $mu->user_email . '>';
            }
        }
        $managers_html = $manager_lines_html ? '<ul style="list-style:none;padding:0;margin:.5rem 0 0;">' . implode('', $manager_lines_html) . '</ul>' : '';
        $managers_plain = $manager_lines_plain ? "\n" . implode("\n", $manager_lines_plain) : '';
    } elseif ($mode === 'back_one') {
        $subject = sprintf(__('Ticket #%d: %s', 'rcmi-tickets'), $ticket_id, $ticket['title']);
        $headline = __('Revision required', 'rcmi-tickets');
        $guidance = __('Your ticket needs a few changes before it can move forward. Please review the reviewer note below, update your ticket, and resubmit — the approval process will resume from the previous step.');
        $managers_html = '';
        $managers_plain = '';
    } else { // restart
        $subject = sprintf(__('Ticket #%d: %s', 'rcmi-tickets'), $ticket_id, $ticket['title']);
        $headline = __('Revision required', 'rcmi-tickets');
        $guidance = __('Your ticket needs a few changes before it can be approved. Please review the reviewer note below, update your ticket, and resubmit — the approval process will start fresh from the first step.');
        $managers_html = '';
        $managers_plain = '';
    }

    $html = '<!doctype html><html><body>'
        . '<h2>' . rcmi_tickets_email_esc($headline) . '</h2>'
        . '<p><strong>' . __('Ticket:', 'rcmi-tickets') . '</strong> #' . (int) $ticket_id . ' — ' . $title . '</p>'
        . '<p>' . rcmi_tickets_email_esc($guidance) . '</p>'
        . $managers_html
        . $comment_html
        . '<p><a href="' . $url . '" style="display:inline-block;padding:.6rem 1.2rem;background:#c8102e;color:#fff;text-decoration:none;border-radius:.375rem;">' . __('View / edit ticket', 'rcmi-tickets') . '</a></p>'
        . '<h3 style="margin-top:1.5rem;font-size:15px;color:#333;">' . __('Ticket details', 'rcmi-tickets') . '</h3>'
        . '<table style="border-collapse:collapse;margin-top:.5rem;">' . $details['html'] . '</table>'
        . '</body></html>';

    $plain = sprintf(
        "%s\n\nTicket: #%d — %s\n%s%s%s\n\nView ticket: %s\n\nTicket details:\n%s",
        $headline, $ticket_id, $ticket['title'], $guidance, $managers_plain, $comment_plain, wp_strip_all_tags($url),
        $details['plain']
    );

    rcmi_tickets_send_email($recipient_email, $subject, $html, $plain);
}
