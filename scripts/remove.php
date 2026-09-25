<?php
/**
 * Removes a vocabulary from the collection, for example on a takedown request.
 *
 * Usage: php scripts/remove.php <slug> "<reason>"
 *
 * Deletes the entry from scripts/sources.json and its file, and records the
 * slug and the reason in scripts/excluded.json, so the slug stays reserved
 * and is never reused for another vocabulary: the module's URLs use it. Then
 * runs build.php.
 */
require __DIR__ . '/bootstrap.php';

[$slug, $reason] = positionalArgs($argv) + [null, null];
if (!$slug || !$reason) {
    fail('Usage: php scripts/remove.php <slug> "<reason>"');
}
$sources = $repo->sources();
if (!isset($sources[$slug])) {
    fail("Unknown slug: $slug");
}

$entry = $sources[$slug];
$excluded = $repo->excluded();
$excluded[] = [
    'slug' => $slug,
    'title' => $entry['title'] ?? $repo->vocabularies()[$slug]['title'] ?? null,
    'namespace' => $entry['namespace'],
    'prefix' => $entry['prefix'],
    'download' => $entry['download'],
    'excluded' => $reason,
];
unset($sources[$slug]);
foreach ($repo->files($slug) as $file) {
    unlink($file);
}
$repo->writeSources($sources);
$repo->writeExcluded($excluded);
echo "Removed $slug.\n";

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/build.php'), $status);
exit($status);
