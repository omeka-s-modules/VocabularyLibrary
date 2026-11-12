<?php
/**
 * Adds a vocabulary to the collection from the URL of its file, or from a
 * file sent to the library.
 *
 * Usage: php scripts/add.php [<url>] --tags=<tag>[,<tag>…] --summary=
 *            [--summary-basis=<url>] [--prefix=] [--slug=] [--namespace=]
 *            [--alt-title= --alt-title-source=] [--format=rdfa|term-list]
 *            [--file=<path> --copy-from=<archive>|--submitted-by=<who>]
 *
 * Downloads the file and runs the import check. The namespace is the one the
 * check finds to import, unless --namespace is given. The prefix is the one
 * the vocabulary declares for itself (vann:preferredNamespacePrefix), unless
 * --prefix is given; most files declare none. The slug defaults to the
 * prefix, lowercased; slugs are permanent once released, so choose with
 * care. --summary is the library's one-sentence summary of the vocabulary.
 * --summary-basis is the address of the publisher's documentation, if the
 * summary was drafted from it as well as from the file.
 * --alt-title is the library's name for it, another official name such as its
 * short name, needed when the file has no title, none in English, or a
 * misleading one; --alt-title-source says where the name comes from.
 * --format=rdfa is for a vocabulary published only as RDFa in an HTML page,
 * and --format=term-list for one whose <url> serves only a list of its terms,
 * each described at its own URI, as TDWG publishes Darwin Core. --tags takes
 * one to three of the tags in config/module.config.php.
 *
 * To add an archived copy when the publisher no longer serves a working file,
 * give the publisher's address as <url>, the copy as --file, and where it came
 * from as --copy-from (LOV, DBpedia Archivo, Open Metadata Registry, or
 * Ontology Design Patterns).
 *
 * To add a file sent to the library, which has a namespace but no address to
 * download it from, give no <url>, the file as --file, and who sent it as
 * --submitted-by. The entry records who and the date in place of an address,
 * and fetch.php has nothing to download for it.
 *
 * Refuses a slug already used or excluded, a prefix already used or invalid,
 * and a namespace already in the collection or excluded, in any #//,
 * http/https form. On success, writes the entry to scripts/sources.json and
 * stores the file, then runs build.php; if the build fails, both are undone.
 */
require __DIR__ . '/bootstrap.php';

use Omeka\Entity\Vocabulary;
use VocabularyLibrary\Scripts\Download;
use VocabularyLibrary\Scripts\ImportCheck;
use VocabularyLibrary\Scripts\Repo;
use VocabularyLibrary\Stdlib\NamespaceUri;

const USAGE = 'Usage: php scripts/add.php [<url>] --tags=<tag>[,<tag>…] --summary= [--summary-basis=<url>] [--prefix=] [--slug=] [--namespace=] [--alt-title= --alt-title-source=] [--format=rdfa|term-list] [--file=<path> --copy-from=<archive>|--submitted-by=<who>]';
const OPTIONS = ['tags', 'summary', 'summary-basis', 'prefix', 'slug', 'namespace', 'alt-title', 'alt-title-source', 'format', 'file', 'copy-from', 'submitted-by'];
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
// No URL for a file sent to the library.
$url = $positional[0] ?? null;
if (count($positional) > 1 || ($url !== null && !preg_match('#^https?://#', $url))) {
    fail(USAGE);
}
$tags = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) option($argv, 'tags'))), 'strlen')));
$knownTags = array_keys($repo->tags());
if (!$tags || count($tags) > Repo::MAX_TAGS || array_diff($tags, $knownTags)) {
    fail('Give one to three tags with --tags=, from: ' . implode(', ', $knownTags));
}
$summary = trim((string) option($argv, 'summary'));
if ($summary === '') {
    fail('Give the library\'s one-sentence summary of the vocabulary with --summary=.');
}
// What the summary was drafted from: the file, and any documentation.
$summaryBasis = ['file'];
if (($documentation = option($argv, 'summary-basis')) !== null) {
    if (!preg_match('#^https?://#', $documentation)) {
        fail('--summary-basis takes the address of the publisher\'s documentation.');
    }
    $summaryBasis[] = $documentation;
}
$altTitle = option($argv, 'alt-title');
$altTitleSource = option($argv, 'alt-title-source');
if ($altTitle !== null && (trim($altTitle) === '' || trim((string) $altTitleSource) === '')) {
    fail('--alt-title needs a name, and --alt-title-source where the name comes from.');
}
$format = option($argv, 'format');
if ($format !== null && !in_array($format, ['rdfa', 'term-list'], true)) {
    fail('--format may only be "rdfa" or "term-list".');
}
$namespace = option($argv, 'namespace');
// A term list's URI is its namespace, and a request for it may redirect to a
// file with another URI.
if ($format === 'term-list' && $namespace === null) {
    $namespace = $url;
}
$file = option($argv, 'file');
$copyFrom = option($argv, 'copy-from');
$submittedBy = option($argv, 'submitted-by');
if ($url === null) {
    if ($file === null || trim((string) $submittedBy) === '' || $copyFrom !== null || $format !== null) {
        fail('Without a <url>, give the file with --file= and who sent it with --submitted-by=, and no --copy-from or --format.');
    }
} elseif ($submittedBy !== null) {
    fail('--submitted-by is for a file with no <url>.');
} elseif (($file === null) !== ($copyFrom === null)) {
    fail('--file and --copy-from go together.');
}
// What the file is called in messages: its address, else its path.
$from = $url ?? $file;
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
    fail("$from: {$response['error']}");
}
if (!$format && Download::isHtml($response['body'])) {
    fail("$from is HTML. If the page carries the vocabulary as RDFa, pass --format=rdfa.");
}
[$data, $extension] = Download::storable($response, $format, $namespace);
if ($data === null) {
    fail("$from: $extension");
}
// A file given with --file keeps its own extension where it's a known one,
// since it has no content type to go by.
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
if ($record = $excludedNamespaces[NamespaceUri::normalized($namespace)] ?? null) {
    fail("The namespace $namespace is in scripts/excluded.json as \"{$record['slug']}\": {$record['excluded']}\nRemove it from there first to add it again.");
}
// The namespace the check found to import can differ from the curated one
// only in its #// or http/https form, which the comparison ignores.
foreach ($sources as $slug => $entry) {
    if (NamespaceUri::normalized($entry['namespace']) === NamespaceUri::normalized($namespace)) {
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

if ($altTitle === null && $result['title'] === null) {
    fail('The file has no title. Pass the publisher\'s name for the vocabulary with --alt-title= and --alt-title-source=.');
}

$entry = ['namespace' => $namespace, 'prefix' => $prefix, 'summary' => $summary, 'summary_basis' => $summaryBasis, 'tags' => $tags];
// Where the file comes from: its address, or who sent it and when.
if ($url !== null) {
    $entry['download'] = $url;
} else {
    $entry['submitted_by'] = trim($submittedBy);
    $entry['submitted_on'] = date('Y-m-d');
}
if ($altTitle !== null) {
    $entry['alt_title'] = $altTitle;
    $entry['alt_title_source'] = $altTitleSource;
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
    $slug, $altTitle ?? $result['title'], $prefix, $namespace, $result['namespace_source'], $result['classes'], $result['properties']);

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/build.php'), $status);
if ($status !== 0) {
    // Leave the collection as it was.
    foreach ($repo->files($slug) as $file) {
        unlink($file);
    }
    $repo->writeSources($before);
    fail("Not added: fix the problems above and try again.");
}
