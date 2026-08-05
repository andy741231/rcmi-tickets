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

add_action('rcmi_ticket_created', 'rcmi_tickets_email_ticket_created', 10, 3);
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
function rcmi_tickets_send_email($to, $subject, $html, $plain) {
    $boundary = '=_rcmi_tickets_' . wp_generate_password(16, false, false);
    $body = "--{$boundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $plain . "\r\n\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $html . "\r\n\r\n"
        . "--{$boundary}--\r\n";

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];

    return wp_mail($to, $subject, $body, $headers);
}

/**
 * Escape values for HTML email output.
 */
function rcmi_tickets_email_esc($value) {
    return esc_html((string) $value);
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

    $html = '<!doctype html><html><body>'
        . '<h2>' . __('New ticket assigned', 'rcmi-tickets') . '</h2>'
        . '<p>' . sprintf(__('You have been assigned to ticket #%d.', 'rcmi-tickets'), $ticket_id) . '</p>'
        . '<p><strong>' . __('Title:', 'rcmi-tickets') . '</strong> ' . $title . '</p>'
        . '<p><strong>' . __('Submitted by:', 'rcmi-tickets') . '</strong> ' . $author_html . '</p>'
        . '<p><a href="' . $url . '">' . __('View ticket', 'rcmi-tickets') . '</a></p>'
        . '</body></html>';

    $plain = sprintf(
        "New ticket assigned\n\nYou have been assigned to ticket #%d.\nTitle: %s\nSubmitted by: %s\n\nView ticket: %s",
        $ticket_id,
        $ticket['title'],
        $author_name,
        wp_strip_all_tags($url)
    );

    rcmi_tickets_send_email($recipients, $subject, $html, $plain);
}

/**
 * Send status notifications to assignees or the ticket author.
 */
function rcmi_tickets_email_status_changed($ticket_id, $new_status, $old_status, $message = null) {
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    if (!$ticket || $new_status === $old_status) {
        return;
    }

    $recipients = [];
    $event_label = $new_status;

    if ($new_status === 'Approved') {
        foreach ($ticket['assignee_ids'] as $user_id) {
            $user = get_userdata($user_id);
            if ($user && is_email($user->user_email)) {
                $recipients[] = $user->user_email;
            }
        }
    } elseif (in_array($new_status, ['Rejected', 'Completed'], true)) {
        $author = get_userdata($ticket['author_id']);
        if ($author && is_email($author->user_email)) {
            $recipients[] = $author->user_email;
        }
    }

    $recipients = array_values(array_unique($recipients));
    if (!$recipients) {
        return;
    }

    $title = rcmi_tickets_email_esc($ticket['title']);
    $url = esc_url(rcmi_tickets_email_ticket_url($ticket_id));
    $status_html = rcmi_tickets_email_esc($new_status);
    $subject = sprintf(__('Ticket #%d %s: %s', 'rcmi-tickets'), $ticket_id, $event_label, $ticket['title']);
    $message = $message ? sanitize_textarea_field($message) : '';
    $message_html = $message !== ''
        ? '<p><strong>' . __('Message:', 'rcmi-tickets') . '</strong> ' . nl2br(rcmi_tickets_email_esc($message)) . '</p>'
        : '';
    $message_plain = $message !== '' ? "\nMessage: {$message}\n" : '';

    $html = '<!doctype html><html><body>'
        . '<h2>' . sprintf(__('Ticket status updated to %s', 'rcmi-tickets'), $status_html) . '</h2>'
        . '<p><strong>' . __('Ticket:', 'rcmi-tickets') . '</strong> #' . (int) $ticket_id . ' — ' . $title . '</p>'
        . $message_html
        . '<p><a href="' . $url . '">' . __('View ticket', 'rcmi-tickets') . '</a></p>'
        . '</body></html>';

    $plain = sprintf(
        "Ticket status updated to %s\n\nTicket: #%d — %s%s\nView ticket: %s",
        $new_status,
        $ticket_id,
        $ticket['title'],
        $message_plain,
        wp_strip_all_tags($url)
    );

    rcmi_tickets_send_email($recipients, $subject, $html, $plain);
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

    $commenter_name = $from_user->display_name;
    $recipients = [];
    foreach ((array) $mentioned_ids as $user_id) {
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
    $excerpt = wp_trim_words(wp_strip_all_tags($comment['body']), 40, '…');
    $url = esc_url(rcmi_tickets_email_ticket_url($ticket_id));
    $commenter_html = rcmi_tickets_email_esc($commenter_name);
    $excerpt_html = rcmi_tickets_email_esc($excerpt);
    $subject = sprintf(__('You were mentioned on ticket #%d: %s', 'rcmi-tickets'), $ticket_id, $ticket['title']);

    $html = '<!doctype html><html><body>'
        . '<h2>' . __('You were mentioned in a ticket comment', 'rcmi-tickets') . '</h2>'
        . '<p>' . sprintf(__('%s mentioned you on ticket #%d.', 'rcmi-tickets'), $commenter_html, $ticket_id) . '</p>'
        . '<p><strong>' . __('Ticket:', 'rcmi-tickets') . '</strong> ' . $title . '</p>'
        . '<blockquote>' . $excerpt_html . '</blockquote>'
        . '<p><a href="' . $url . '">' . __('View comment', 'rcmi-tickets') . '</a></p>'
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
}

/**
 * Build a one-click approve/reject URL for an approval step token.
 * Uses the REST API with plain-permalink-safe ?rest_route= format.
 */
function rcmi_tickets_email_approval_action_url($approval_id, $token, $action) {
    $base = rest_url('rcmi/v1/approvals/' . (int) $approval_id . '/token-' . $action);
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
    $approve_url = esc_url(rcmi_tickets_email_approval_action_url($approval_id, $approval['token'], 'approve'));
    $reject_url = esc_url(rcmi_tickets_email_approval_action_url($approval_id, $approval['token'], 'reject'));

    $subject = sprintf(__('Approval needed: ticket #%d %s', 'rcmi-tickets'), $ticket_id, $ticket['title']);

    $html = '<!doctype html><html><body>'
        . '<h2>' . sprintf(__('Your approval is requested (ticket #%d)', 'rcmi-tickets'), $ticket_id) . '</h2>'
        . '<p><strong>' . __('Title:', 'rcmi-tickets') . '</strong> ' . $title . '</p>'
        . '<p><strong>' . __('Stage:', 'rcmi-tickets') . '</strong> ' . rcmi_tickets_email_esc($step_label) . '</p>'
        . '<p style="margin-top:1.5rem;">'
        . '<a href="' . $approve_url . '" style="display:inline-block;padding:.6rem 1.2rem;background:#00866c;color:#fff;text-decoration:none;border-radius:.375rem;margin-right:.5rem;">' . __('Approve', 'rcmi-tickets') . '</a>'
        . '<a href="' . $reject_url . '" style="display:inline-block;padding:.6rem 1.2rem;background:#c8102e;color:#fff;text-decoration:none;border-radius:.375rem;">' . __('Reject', 'rcmi-tickets') . '</a>'
        . '</p>'
        . '<p style="margin-top:1rem;color:#666;font-size:13px;">' . __('Or view the ticket and decide there:', 'rcmi-tickets') . ' <a href="' . $ticket_url . '">' . $ticket_url . '</a></p>'
        . '</body></html>';

    $plain = sprintf(
        "Your approval is requested (ticket #%d)\n\nTitle: %s\nStage: %s\n\nApprove: %s\nReject: %s\n\nView ticket: %s",
        $ticket_id, $ticket['title'], $step_label,
        wp_strip_all_tags($approve_url),
        wp_strip_all_tags($reject_url),
        wp_strip_all_tags($ticket_url)
    );

    rcmi_tickets_send_email($recipients, $subject, $html, $plain);
}

/**
 * Notify the ticket author when a chain rejection occurred.
 * Hooked on rcmi_ticket_approval_rejected($ticket_id, $mode, $comment).
 * $mode: 'restart' | 'terminal'
 */
function rcmi_tickets_email_approval_rejected($ticket_id, $mode, $comment) {
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    if (!$ticket) {
        return;
    }
    $author = get_userdata((int) $ticket['author_id']);
    if (!$author || !is_email($author->user_email)) {
        return;
    }

    $title = rcmi_tickets_email_esc($ticket['title']);
    $url = esc_url(rcmi_tickets_email_ticket_url($ticket_id));
    $comment_html = $comment ? '<p><strong>' . __('Reviewer note:', 'rcmi-tickets') . '</strong> ' . nl2br(rcmi_tickets_email_esc($comment)) . '</p>' : '';
    $comment_plain = $comment ? "\nReviewer note: {$comment}\n" : '';

    if ($mode === 'terminal') {
        $subject = sprintf(__('Ticket #%d rejected: %s', 'rcmi-tickets'), $ticket_id, $ticket['title']);
        $headline = __('Your ticket has been rejected', 'rcmi-tickets');
        $guidance = __('This request has been denied. If you believe this is an error, please contact a ticket manager.', 'rcmi-tickets');
    } else { // restart
        $subject = sprintf(__('Ticket #%d sent back for revision: %s', 'rcmi-tickets'), $ticket_id, $ticket['title']);
        $headline = __('Your ticket needs revision', 'rcmi-tickets');
        $guidance = __('Please review the reviewer note, edit your ticket, and resubmit. The approval chain will restart from the first step.', 'rcmi-tickets');
    }

    $html = '<!doctype html><html><body>'
        . '<h2>' . rcmi_tickets_email_esc($headline) . '</h2>'
        . '<p><strong>' . __('Ticket:', 'rcmi-tickets') . '</strong> #' . (int) $ticket_id . ' — ' . $title . '</p>'
        . $comment_html
        . '<p>' . rcmi_tickets_email_esc($guidance) . '</p>'
        . '<p><a href="' . $url . '">' . __('View / edit ticket', 'rcmi-tickets') . '</a></p>'
        . '</body></html>';

    $plain = sprintf(
        "%s\n\nTicket: #%d — %s%s\n%s\n\nView ticket: %s",
        $headline, $ticket_id, $ticket['title'], $comment_plain, $guidance, wp_strip_all_tags($url)
    );

    rcmi_tickets_send_email($author->user_email, $subject, $html, $plain);
}
