#!/usr/bin/env php
<?php

/**
 * Add a hostname entry to the system hosts file.
 *
 * Usage:
 *   php add-host.php <hostname> [ip]
 *
 * Examples:
 *   php add-host.php tenant1.spatie-laravel-multitenancy.test
 *   php add-host.php tenant1.spatie-laravel-multitenancy.test 192.168.1.100
 *
 * Must be run as administrator/sudo.
 */
$hostname = $argv[1] ?? null;
$ip = $argv[2] ?? '127.0.0.1';

if (! $hostname) {
    echo "Usage: php add-host.php <hostname> [ip]\n";
    echo "  hostname: The hostname to add (e.g., tenant1.example.test)\n";
    echo "  ip:       The IP address (default: 127.0.0.1)\n";
    exit(1);
}

// Determine hosts file path based on OS
$hostsPath = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
    ? 'C:\Windows\System32\drivers\etc\hosts'
    : '/etc/hosts';

// Check if hosts file is writable
if (! is_writable($hostsPath)) {
    echo "Error: Cannot write to {$hostsPath}\n";
    echo "Run this script as administrator (Windows) or with sudo (Linux/macOS).\n";
    exit(1);
}

// Read current hosts file
$hosts = file_get_contents($hostsPath);

// Check if hostname already exists
$lines = explode("\n", $hosts);
foreach ($lines as $line) {
    $line = trim($line);
    // Skip empty lines and comments
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    // Check if this line contains our hostname
    if (preg_match('/\s+'.preg_quote($hostname, '/').'\b/', $line)) {
        echo "Entry for '{$hostname}' already exists in hosts file.\n";
        exit(0);
    }
}

// Add the new entry at the end of the file
$entry = "{$ip}\t{$hostname}";

// Ensure file ends with a newline before adding
if (substr($hosts, -1) !== "\n") {
    $hosts .= "\n";
}

$hosts .= $entry."\n";

if (file_put_contents($hostsPath, $hosts) !== false) {
    echo "Added: {$entry}\n";
    echo "Hosts file: {$hostsPath}\n";
    exit(0);
} else {
    echo "Error: Failed to write to hosts file.\n";
    exit(1);
}
