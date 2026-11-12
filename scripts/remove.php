<?php
/**
 * Removes a vocabulary from the collection, for example on a takedown request.
 *
 * Usage: php scripts/remove.php <slug> "<reason>"
 *
 * Deletes the entry from scripts/sources.json and its file, and records the
 * slug and the reason in scripts/excluded.json, so the slug stays reserved
 * and is never reused for another vocabulary: the module's URLs use it. Then
 * runs build.php; if the build fails, all of that is undone.
 */
require __DIR__ . '/bootstrap.php';

// Exactly two arguments: a reason without its quotes would otherwise be
// recorded as its first word.
$args = array_slice($argv, 1);
if (count($args) !== 2 || trim($args[0]) === '' || trim($args[1]) === '') {
    fail('Usage: php scripts/remove.php <slug> "<reason>"');
}
[$slug, $reason] = $args;
$sources = $repo->sources();
if (!isset($sources[$slug])) {
    fail("Unknown slug: $slug");
}

$entry = $sources[$slug];
$excluded = $repo->excluded();
[$sourcesBefore, $excludedBefore] = [$sources, $excluded];
$files = [];
foreach ($repo->files($slug) as $file) {
    $files[$file] = file_get_contents($file);
}
// Recorded by the name the library showed: its alternative title, else the
// file's title.
$built = $repo->vocabularies()[$slug] ?? [];
$record = [
    'slug' => $slug,
    'title' => $built['alt_title'] ?? $built['title'] ?? null,
    'namespace' => $entry['namespace'],
    'prefix' => $entry['prefix'],
    'excluded' => $reason,
];
// Where the file came from: its address, or who sent it and when.
foreach (['download', 'submitted_by', 'submitted_on'] as $field) {
    if (isset($entry[$field])) {
        $record[$field] = $entry[$field];
    }
}
$excluded[] = $record;
unset($sources[$slug]);
foreach (array_keys($files) as $file) {
    unlink($file);
}
$repo->writeSources($sources);
$repo->writeExcluded($excluded);

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/build.php'), $status);
if ($status !== 0) {
    // Leave the collection as it was.
    foreach ($files as $file => $data) {
        file_put_contents($file, $data);
    }
    $repo->writeSources($sourcesBefore);
    $repo->writeExcluded($excludedBefore);
    fail("Not removed: fix the problems above and try again.");
}
echo "Removed $slug.\n";
