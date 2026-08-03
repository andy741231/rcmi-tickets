<?php
/**
 * GitHub-based updater for RCMI Tickets.
 *
 * Uses the latest commit on main as the version identifier so the plugin can
 * update without release tags. The repository contains a top-level
 * rcmi-tickets/ directory after extraction.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RCMI_TICKETS_GITHUB_USER', 'andy741231');
define('RCMI_TICKETS_GITHUB_REPO', 'rcmi-tickets');

/**
 * Fetch the latest commit metadata from GitHub, cached for six hours.
 *
 * @return array|false
 */
function rcmi_tickets_get_github_commit() {
    $cached = get_transient('rcmi_tickets_github_commit');
    if (false !== $cached) {
        return $cached;
    }

    $url = sprintf(
        'https://api.github.com/repos/%s/%s/commits/main',
        RCMI_TICKETS_GITHUB_USER,
        RCMI_TICKETS_GITHUB_REPO
    );
    $response = wp_remote_get($url, [
        'headers' => [
            'Accept'     => 'application/vnd.github.v3+json',
            'User-Agent' => 'RCMI-Tickets-Updater',
        ],
        'timeout' => 10,
    ]);

    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        set_transient('rcmi_tickets_github_commit', false, 30 * MINUTE_IN_SECONDS);
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['sha'])) {
        set_transient('rcmi_tickets_github_commit', false, 30 * MINUTE_IN_SECONDS);
        return false;
    }

    $commit = $body['commit'] ?? [];
    $data = [
        'sha'          => sanitize_text_field($body['sha']),
        'short_sha'    => substr(sanitize_text_field($body['sha']), 0, 7),
        'message'      => sanitize_textarea_field($commit['message'] ?? ''),
        'date'         => sanitize_text_field($commit['committer']['date'] ?? ''),
        'html_url'     => esc_url_raw($body['html_url'] ?? ''),
        'download_url' => sprintf(
            'https://codeload.github.com/%s/%s/zip/refs/heads/main',
            RCMI_TICKETS_GITHUB_USER,
            RCMI_TICKETS_GITHUB_REPO
        ),
    ];

    set_transient('rcmi_tickets_github_commit', $data, 6 * HOUR_IN_SECONDS);
    return $data;
}

function rcmi_tickets_get_installed_sha() {
    $sha = get_option('rcmi_tickets_installed_sha');
    return $sha ?: RCMI_TICKETS_VERSION;
}

/**
 * Add a native WordPress plugin update entry.
 */
function rcmi_tickets_check_for_updates($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }

    $commit = rcmi_tickets_get_github_commit();
    if (!$commit || $commit['sha'] === rcmi_tickets_get_installed_sha()) {
        return $transient;
    }

    $plugin = plugin_basename(RCMI_TICKETS_FILE);
    $transient->response[$plugin] = (object) [
        'slug'        => 'rcmi-tickets',
        'plugin'      => $plugin,
        'new_version' => $commit['short_sha'],
        'url'         => $commit['html_url'],
        'package'     => $commit['download_url'],
        'tested'      => '6.0',
        'icons'       => [],
        'banners'     => [],
    ];

    return $transient;
}
add_filter('pre_set_site_transient_update_plugins', 'rcmi_tickets_check_for_updates');

/**
 * Populate the native plugin details popup.
 */
function rcmi_tickets_plugins_api_info($result, $action, $args) {
    if ('plugin_information' !== $action || empty($args->slug) || 'rcmi-tickets' !== $args->slug) {
        return $result;
    }

    $commit = rcmi_tickets_get_github_commit();
    if (!$commit) {
        return $result;
    }

    return (object) [
        'name'             => 'RCMI Tickets',
        'slug'             => 'rcmi-tickets',
        'version'          => $commit['short_sha'],
        'author'           => 'RCMI',
        'homepage'         => $commit['html_url'],
        'short_description' => 'Frontend ticketing system for WordPress.',
        'sections'         => [
            'description' => 'Frontend ticketing system integrated with WordPress users and the REST API.',
            'changelog'   => wp_kses_post(nl2br(esc_html($commit['message']))),
        ],
        'last_updated'     => $commit['date'],
        'download_link'    => $commit['download_url'],
    ];
}
add_filter('plugins_api', 'rcmi_tickets_plugins_api_info', 10, 3);

/**
 * Rename GitHub's extracted rcmi-tickets-main directory before WordPress
 * calculates the final plugin destination.
 */
function rcmi_tickets_fix_source_folder($source, $remote_source, $upgrader, $hook_extra) {
    if (is_wp_error($source) || empty($hook_extra['plugin']) || false === strpos($hook_extra['plugin'], 'rcmi-tickets')) {
        return $source;
    }

    $expected = 'rcmi-tickets';
    if (basename(untrailingslashit($source)) === $expected) {
        return $source;
    }

    $new_source = trailingslashit(dirname(untrailingslashit($source))) . $expected;
    if (@rename(untrailingslashit($source), $new_source)) {
        return trailingslashit($new_source);
    }

    return $source;
}
add_filter('upgrader_source_selection', 'rcmi_tickets_fix_source_folder', 10, 4);

/**
 * Allow in-place overwrite on hosts where active plugin files cannot be
 * deleted before copying the new version.
 */
function rcmi_tickets_skip_clear_destination($removed, $local_destination, $remote_destination, $hook_extra) {
    if (!empty($hook_extra['plugin']) && false !== strpos($hook_extra['plugin'], 'rcmi-tickets')) {
        return true;
    }
    return $removed;
}
add_filter('upgrader_clear_destination', 'rcmi_tickets_skip_clear_destination', 20, 4);

/**
 * Record the installed SHA after a successful plugin upgrade.
 */
function rcmi_tickets_post_install($response, $hook_extra, $result) {
    if (empty($hook_extra['plugin']) || false === strpos($hook_extra['plugin'], 'rcmi-tickets')) {
        return $result;
    }

    wp_clean_plugins_cache();
    $commit = rcmi_tickets_get_github_commit();
    if ($commit && !empty($commit['sha'])) {
        update_option('rcmi_tickets_installed_sha', $commit['sha']);
    }
    delete_transient('rcmi_tickets_github_commit');

    return $result;
}
add_filter('upgrader_post_install', 'rcmi_tickets_post_install', 10, 3);

/**
 * Allow an explicit admin refresh without waiting for the six-hour cache.
 * Triggered by the "Check for updates" link on the Plugins page or the
 * Settings page.
 */
function rcmi_tickets_maybe_refresh_update_cache() {
    if (!isset($_GET['rcmi_tickets_check_updates']) || !current_user_can('manage_options')) {
        return;
    }

    delete_transient('rcmi_tickets_github_commit');
    delete_site_transient('update_plugins');
    rcmi_tickets_get_github_commit();

    // Redirect back to the page the request came from, stripping the
    // check_updates query arg so a refresh doesn't re-trigger it.
    $redirect = remove_query_arg('rcmi_tickets_check_updates');
    // Add a flag so the settings page can show a "check completed" notice.
    if (strpos($redirect, 'page=rcmi-tickets') !== false) {
        $redirect = add_query_arg('rcmi_tickets_checked', '1', $redirect);
    }
    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_init', 'rcmi_tickets_maybe_refresh_update_cache');
