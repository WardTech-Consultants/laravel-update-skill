#!/usr/bin/env php
<?php

/**
 * Diff two composer.lock files and classify every change.
 *
 * Usage: php lock-diff.php composer.lock.bak composer.lock [composer.json]
 *
 * Marks each changed package as direct or transitive (by reading composer.json) and
 * as patch / minor / MAJOR — treating a 0.x minor bump as a major, per semver.
 */

$before = $argv[1] ?? 'composer.lock.bak';
$after = $argv[2] ?? 'composer.lock';
$manifest = $argv[3] ?? 'composer.json';

foreach ([$before, $after] as $file) {
    if (! is_readable($file)) {
        fwrite(STDERR, "Cannot read {$file}\n");
        exit(1);
    }
}

function packages(string $file): array
{
    $lock = json_decode(file_get_contents($file), true);
    $out = [];

    foreach (['packages', 'packages-dev'] as $section) {
        foreach ($lock[$section] ?? [] as $package) {
            $out[$package['name']] = $package['version'];
        }
    }

    return $out;
}

/** Split a version into integer parts, ignoring a leading v and any stability suffix. */
function parts(string $version): array
{
    $clean = preg_replace('/^v/', '', $version);
    $clean = preg_split('/[-+]/', $clean)[0];

    return array_map('intval', array_pad(explode('.', $clean), 3, '0'));
}

/**
 * Semver severity. For 0.x releases the minor position is the breaking one,
 * so 0.18 -> 0.19 reports as MAJOR.
 */
function severity(string $from, string $to): string
{
    [$fromMajor, $fromMinor] = parts($from);
    [$toMajor, $toMinor] = parts($to);

    if ($fromMajor !== $toMajor) {
        return 'MAJOR';
    }

    if ($fromMajor === 0 && $fromMinor !== $toMinor) {
        return 'MAJOR';
    }

    return $fromMinor !== $toMinor ? 'minor' : 'patch';
}

$direct = [];
if (is_readable($manifest)) {
    $json = json_decode(file_get_contents($manifest), true);
    $direct = array_merge(
        array_keys($json['require'] ?? []),
        array_keys($json['require-dev'] ?? [])
    );
}

$old = packages($before);
$new = packages($after);

$changed = $added = $removed = [];

foreach ($new as $name => $version) {
    if (! isset($old[$name])) {
        $added[$name] = $version;
    } elseif ($old[$name] !== $version) {
        $changed[$name] = [$old[$name], $version, severity($old[$name], $version)];
    }
}

foreach ($old as $name => $version) {
    if (! isset($new[$name])) {
        $removed[$name] = $version;
    }
}

if (! $changed && ! $added && ! $removed) {
    echo "No package changes between {$before} and {$after}.\n";
    exit(0);
}

// Most significant first, then alphabetically.
$rank = ['MAJOR' => 0, 'minor' => 1, 'patch' => 2];
uasort($changed, fn ($a, $b) => [$rank[$a[2]], 0] <=> [$rank[$b[2]], 0] ?: 0);

$width = $changed ? max(array_map('strlen', array_keys($changed))) : 20;
$counts = ['MAJOR' => 0, 'minor' => 0, 'patch' => 0];

printf("%-{$width}s  %-14s  %-14s  %-6s  %s\n", 'PACKAGE', 'FROM', 'TO', 'BUMP', 'SCOPE');
echo str_repeat('-', $width + 50), "\n";

foreach ($changed as $name => [$from, $to, $level]) {
    $counts[$level]++;
    printf(
        "%-{$width}s  %-14s  %-14s  %-6s  %s\n",
        $name,
        $from,
        $to,
        $level,
        in_array($name, $direct, true) ? 'direct' : 'transitive'
    );
}

echo "\n";
printf(
    "%d changed (%d major, %d minor, %d patch), %d added, %d removed.\n",
    count($changed),
    $counts['MAJOR'],
    $counts['minor'],
    $counts['patch'],
    count($added),
    count($removed)
);

foreach (['Added' => $added, 'Removed' => $removed] as $label => $set) {
    if ($set) {
        echo "\n{$label}:\n";
        foreach ($set as $name => $version) {
            echo "  {$name} {$version}\n";
        }
    }
}

if ($counts['MAJOR'] > 0) {
    echo "\nMajor bumps landed in this update — review their upgrade notes before shipping.\n";
}
