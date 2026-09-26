<?php
/**
 * Adds a vocabulary to the collection from the URL of its file.
 *
 * Usage: php scripts/add.php <url> --tags=<tag>[,<tag>…] [--prefix=] [--slug=]
 *            [--namespace=] [--title=] [--format=rdfa]
 *            [--file=<path> --copy-from=<archive>]
 *
 * Downloads the file and runs the import check. The namespace is the one the
 * check finds to import, unless --namespace is given. The prefix is the one
 * the vocabulary declares for itself (vann:preferredNamespacePrefix), unless
 * --prefix is given; most files declare none. The slug defaults to the
 * prefix, lowercased; slugs are permanent once released, so choose with
 * care. --title is needed only when the file has no title. --format=rdfa is
 * for a vocabulary published only as RDFa in an HTML page. --tags takes one to
 * three of the tags in config/module.config.php.
 *
 * To add an archived copy when the publisher no longer serves a working file,
 * give the publisher's address as <url>, the copy as --file, and where it came
 * from as --copy-from (LOV, DBpedia Archivo, Open Metadata Registry, or
 * Ontology Design Patterns).
 *
 * Refuses a slug already used or excluded, a prefix already used or invalid,
 * and a namespace already in the collection or excluded, in any #//,
 * http/https form. On
 * success, writes the entry to scripts/sources.json and stores the file, then
 * runs build.php; if the build fails, both are undone.
 */
require __DIR__ . '/bootstrap.php';

use Omeka\Entity\Vocabulary;
use VocabularyLibrary\Scripts\Download;
use VocabularyLibrary\Scripts\ImportCheck;
use VocabularyLibrary\Scripts\Repo;

const USAGE = 'Usage: php scripts/add.php <url> --tags=<tag>[,<tag>…] [--prefix=] [--slug=] [--namespace=] [--title=] [--format=rdfa] [--file=<path> --copy-from=<archive>]';
const OPTIONS = ['tags', 'prefix', 'slug', 'namespace', 'title', 'format', 'file', 'copy-from'];
// Extensions an archived copy may keep.
const FILE_EXTENSIONS = ['jsonld', 'nt', 'owl', 'rdf', 'ttl', 'xml'];

// Refuse anything unexpected: "--slug foo" or a misspelt option would
// otherwise be ignored, and slugs are permanent once released.
$positional = positionalArgs($argv);
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--') === 0 && !preg_match('/^--(' . implode('|', OPTIONS) . ')=/', $arg)) {
        fail("Unknown option: $arg\n" . USAGE);
    }
}
$url = $positional[0] ?? null;
if (count($positional) !== 1 || !preg_match('#^https?://#', $url)) {
    fail(USAGE);
}
$tags = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) option($argv, 'tags'))), 'strlen')));
$knownTags = array_keys($repo->tags());
if (!$tags || count($tags) > Repo::MAX_TAGS || array_diff($tags, $knownTags)) {
    fail('Give one to three tags with --tags=, from: ' . implode(', ', $knownTags));
}
$format = option($argv, 'format');
if ($format !== null && $format !== 'rdfa') {
    fail('--format may only be "rdfa".');
}
$namespace = option($argv, 'namespace');
$file = option($argv, 'file');
$copyFrom = option($argv, 'copy-from');
if (($file === null) !== ($copyFrom === null)) {
    fail('--file and --copy-from go together.');
}
if ($copyFrom !== null && !in_array($copyFrom, Repo::COPY_SOURCES, true)) {
    fail('--copy-from must be one of: ' . implode(', ', Repo::COPY_SOURCES));
}

if ($file !== null) {
    if (!is_readable($file)) {
        fail("Cannot read $file");
    }
    $response = ['body' => file_get_contents($file), 'type' => '', 'url' => $url, 'status' => 200, 'error' => null];
} else {
    $response = Download::fetchAll([$url])[0];
}
if ($response['error']) {
    fail("$url: {$response['error']}");
}
if (!$format && Download::isHtml($response['body'])) {
    fail("$url returns HTML. If the page carries the vocabulary as RDFa, pass --format=rdfa.");
}
[$data, $extension] = Download::storable($response, $format, $namespace);
if ($data === null) {
    fail("$url: $extension");
}
// An archived copy keeps its own extension where it's a known one, since it
// has no content type to go by.
$fileExtension = $file !== null ? strtolower(pathinfo($file, PATHINFO_EXTENSION)) : null;
if ($format === null && in_array($fileExtension, FILE_EXTENSIONS, true)) {
    $extension = $fileExtension;
}
$path = Download::tempFile($data, $extension);
$result = (new ImportCheck)->check($path, $namespace);
unlink($path);
if ($failure = ImportCheck::failure($result)) {
    fail("The file does not import: $failure");
}
$namespace = $result['namespace'];

$sources = $repo->sources();
[$excluded, $excludedNamespaces] = $repo->excludedIndex();
if ($record = $excludedNamespaces[Repo::normalizedNamespace($namespace)] ?? null) {
    fail("The namespace $namespace is in scripts/excluded.json as \"{$record['slug']}\": {$record['excluded']}\nRemove it from there first to add it again.");
}
// The namespace the build found to import can differ from the curated one
// only in its #// or http/https form, which the comparison ignores.
foreach ($sources as $slug => $entry) {
    if (Repo::normalizedNamespace($entry['namespace']) === Repo::normalizedNamespace($namespace)) {
        fail("The namespace $namespace is already in the collection as \"$slug\" ({$entry['namespace']}).");
    }
}

// Prefixes are lowercase, as nearly all published ones are, though a file may
// declare one with capitals.
$prefix = option($argv, 'prefix') ?? ($result['prefix'] === null ? null : strtolower($result['prefix']));
if ($prefix === null) {
    fail("The file declares no prefix. Pass one with --prefix=.");
}
if (!preg_match(Vocabulary::PREFIX_REGEX, $prefix) || preg_match(Repo::RESERVED_PREFIX, $prefix) || $prefix !== strtolower($prefix)) {
    fail("The prefix \"$prefix\" is invalid or reserved: use lowercase letters, numbers, hyphens and underscores, not \"o\" or \"o-…\".");
}
foreach ($sources as $slug => $entry) {
    if (mb_strtolower($entry['prefix']) === mb_strtolower($prefix)) {
        fail("The prefix \"$prefix\" is already used by \"$slug\". Pass another with --prefix=.");
    }
}

$slug = option($argv, 'slug') ?? trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($prefix)), '-');
if (!preg_match(Repo::SLUG_PATTERN, $slug)) {
    fail("The slug \"$slug\" must match " . Repo::SLUG_PATTERN . '.');
}
if (isset($sources[$slug])) {
    fail("The slug \"$slug\" is already used. Pass another with --slug=.");
}
if (isset($excluded[$slug])) {
    fail("The slug \"$slug\" is in scripts/excluded.json: {$excluded[$slug]['excluded']}\nRemove it from there first to add it again.");
}

$title = option($argv, 'title');
if ($title !== null && trim($title) === '') {
    fail('--title is empty.');
}
if ($title === null && $result['title'] === null) {
    fail('The file has no title. Pass the publisher\'s name for the vocabulary with --title=.');
}

$entry = ['download' => $url, 'namespace' => $namespace, 'prefix' => $prefix, 'tags' => $tags];
if ($title !== null) {
    $entry['title'] = $title;
}
if ($format !== null) {
    $entry['format'] = $format;
}
if ($copyFrom !== null) {
    $entry['copy_from'] = $copyFrom;
}
$before = $sources;
$sources[$slug] = $entry;
$repo->storeFile($slug, $data, $extension);
$repo->writeSources($sources);

printf("Added %s: %s, prefix %s, namespace %s (%s), %d classes, %d properties\n",
    $slug, $title ?? $result['title'], $prefix, $namespace, $result['namespace_source'], $result['classes'], $result['properties']);

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/build.php'), $status);
if ($status !== 0) {
    // Leave the collection as it was.
    foreach ($repo->files($slug) as $file) {
        unlink($file);
    }
    $repo->writeSources($before);
    fail("Not added: fix the problems above and try again.");
}
