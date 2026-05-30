#!/usr/bin/env php
<?php

/**
 * Remove a hostname entry from the system hosts file.
 *
 * Usage:
 *   php remove-host.php <hostname>
 *
 * Example:
 *   php remove-host.php tenant1.spatie-laravel-multitenancy.test
 *
 * Must be run as administrator/sudo.
 */
$hostname = $argv[1] ?? null;

if (! $hostname) {
    echo "Usage: php remove-host.php <hostname>\n";
    exit(1);
}

// Determine hosts file path based on OS
$hostsPath = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
    ? 'C:\Windows\System32\drivers\etc\hosts'
    : '/etc/hosts';

if (! is_writable($hostsPath)) {
    echo "Error: Cannot write to {$hostsPath}\n";
    echo "Run this script as administrator (Windows) or with sudo (Linux/macOS).\n";
    exit(1);
}

$hosts = file_get_contents($hostsPath);
$lines = explode("\n", $hosts);
$newLines = [];
$removed = false;

foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed !== '' && $trimmed[0] !== '#' && preg_match('/\s+'.preg_quote($hostname, '/').'\b/', $trimmed)) {
        $removed = true;

        continue; // Skip this line (remove it)
    }
    $newLines[] = $line;
}

if (! $removed) {
    echo "No entry found for '{$hostname}' in hosts file.\n";
    exit(0);
}

if (file_put_contents($hostsPath, implode("\n", $newLines)) !== false) {
    echo "Removed: '{$hostname}' from hosts file.\n";
    exit(0);
} else {
    echo "Error: Failed to write to hosts file.\n";
    exit(1);
}
