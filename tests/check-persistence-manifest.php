<?php

define('ABSPATH', dirname(__DIR__) . '/');
require_once dirname(__DIR__) . '/includes/class-data-lifecycle.php';

function rcmi_tickets_test_sorted_unique($values) {
    $values = array_values(array_unique($values));
    sort($values);
    return $values;
}

function rcmi_tickets_test_compare($label, $actual, $expected, &$errors) {
    $actual = rcmi_tickets_test_sorted_unique($actual);
    $expected = rcmi_tickets_test_sorted_unique($expected);
    if ($actual !== $expected) {
        $errors[] = $label . ' mismatch' . PHP_EOL
            . '  discovered: ' . implode(', ', $actual) . PHP_EOL
            . '  manifest:   ' . implode(', ', $expected);
    }
}

$root = dirname(__DIR__);
$manifest = rcmi_tickets_data_manifest();
$errors = [];

$schema = file_get_contents($root . '/includes/class-activator.php');
preg_match_all('/CREATE TABLE \{\$prefix\}(rcmi_[a-z0-9_]+)/', $schema, $matches);
rcmi_tickets_test_compare('Tables', $matches[1], $manifest['tables'], $errors);

$source_files = array_values(array_filter(
    glob($root . '/includes/*.php'),
    function ($source_file) {
        return basename($source_file) !== 'class-data-lifecycle.php';
    }
));
$source_files[] = $root . '/rcmi-tickets.php';
$source = '';
foreach ($source_files as $source_file) {
    $source .= PHP_EOL . file_get_contents($source_file);
}

preg_match_all("/(?:add|get|update|delete)_option\\(\\s*['\"](rcmi_tickets_[^'\"]+)['\"]/", $source, $matches);
rcmi_tickets_test_compare('Options', $matches[1], $manifest['options'], $errors);

preg_match_all("/(?:set|get|delete)_transient\\(\\s*['\"](rcmi_[^'\"]+)['\"]/", $source, $matches);
rcmi_tickets_test_compare('Transients', $matches[1], $manifest['transients'], $errors);

preg_match_all('/\$transient_key\s*=\s*[\'\"](rcmi_[^\'\"]+)[\'\"]\s*\./', $source, $matches);
rcmi_tickets_test_compare('Transient prefixes', $matches[1], $manifest['transient_prefixes'], $errors);

preg_match_all("/['\"](rcmi_ticket_(?:user|manager))['\"]/", $source, $matches);
rcmi_tickets_test_compare('Roles', $matches[1], $manifest['roles'], $errors);

preg_match_all("/['\"](rcmi_(?:view|create|manage)_tickets)['\"]/", $source, $matches);
rcmi_tickets_test_compare('Capabilities', $matches[1], $manifest['capabilities'], $errors);

$uninstall = file_get_contents($root . '/uninstall.php');
if (strpos($uninstall, 'rcmi_tickets_data_manifest()') === false) {
    $errors[] = 'uninstall.php does not consume rcmi_tickets_data_manifest().';
}
foreach (['tables', 'options', 'transients', 'transient_prefixes', 'roles', 'capabilities', 'upload_directory'] as $manifest_key) {
    $needle = '$manifest[\'' . $manifest_key . '\']';
    if (strpos($uninstall, $needle) === false) {
        $errors[] = 'uninstall.php does not consume the ' . $manifest_key . ' manifest entry.';
    }
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL . PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

printf(
    "Persistence manifest check passed: %d tables, %d options, %d transients, %d transient prefixes, %d roles, %d capabilities.%s",
    count($manifest['tables']),
    count($manifest['options']),
    count($manifest['transients']),
    count($manifest['transient_prefixes']),
    count($manifest['roles']),
    count($manifest['capabilities']),
    PHP_EOL
);
