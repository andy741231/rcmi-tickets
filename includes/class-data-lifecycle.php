<?php

if (!defined('ABSPATH')) {
    exit;
}

function rcmi_tickets_data_manifest() {
    return [
        'tables' => [
            'rcmi_ticket_comment_reactions',
            'rcmi_ticket_attachments',
            'rcmi_ticket_comments',
            'rcmi_form_answers',
            'rcmi_ticket_approvals',
            'rcmi_approval_steps',
            'rcmi_approval_chains',
            'rcmi_tag_rules',
            'rcmi_ticket_tag_map',
            'rcmi_ticket_tags',
            'rcmi_ticket_status_log',
            'rcmi_ticket_assignees',
            'rcmi_form_fields',
            'rcmi_tickets',
        ],
        'options' => [
            'rcmi_tickets_db_version',
            'rcmi_tickets_installed_sha',
            'rcmi_tickets_public_success',
            'rcmi_tickets_allow_public_submit',
            'rcmi_tickets_view_token_secret',
        ],
        'transients' => [
            'rcmi_tickets_github_commit',
            'rcmi_tickets_app_url',
        ],
        'transient_prefixes' => [
            'rcmi_pub_rl_',
            'rcmi_pub_att_rl_',
        ],
        'roles' => [
            'rcmi_ticket_user',
            'rcmi_ticket_manager',
        ],
        'capabilities' => [
            'rcmi_view_tickets',
            'rcmi_create_tickets',
            'rcmi_manage_tickets',
        ],
        'upload_directory' => 'uploads/rcmi-tickets',
    ];
}
