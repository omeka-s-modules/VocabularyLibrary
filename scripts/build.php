<?php
/**
 * Checks every vocabulary file and writes what the module reads.
 *
 * Usage: php scripts/build.php
 *
 * Runs the import check on each file in data/vocabs with the namespace in its
 * entry in scripts/sources.json, and writes what the module reads:
 * data/vocabularies.json (each vocabulary's title, prefix, tags, and what the
 * check found) and data/term-index.json. Touches nothing else.
 *
 * Nothing is written if any entry has a problem: a file that does not parse,
 * imports nothing, or breaks one of Omeka's column limits; a slug with no
 * file or several; a stored HTML file; a missing or empty title; an invalid,
 * reserved, duplicate or capitalized prefix; a duplicate namespace; a slug or namespace in
 * scripts/excluded.json; or missing, unknown or repeated tags. Each problem is
 * listed and the script exits non-zero.
 */
require __DIR__ . '/bootstrap.php';

use Omeka\Entity\Vocabulary;
use VocabularyLibrary\Scripts\Download;
use VocabularyLibrary\Scripts\ImportCheck;
use VocabularyLibrary\PlainText;
use VocabularyLibrary\Scripts\Repo;

$check = new ImportCheck;
$sources = $repo->sources();
$vocabularies = [];
$tags = $repo->tags();
$problems = [];
$index = [];
$prefixes = [];
$namespaces = [];
// Vocabularies left out or removed stay out, under any slug.
[$excluded, $excludedNamespaces] = $repo->excludedIndex();

foreach ($sources as $slug => $entry) {
    $slug = (string) $slug;
    if (!preg_match(Repo::SLUG_PATTERN, $slug)) {
        $problems[] = "$slug: slug must match " . Repo::SLUG_PATTERN;
    }
    if (isset($excluded[$slug])) {
        $problems[] = "$slug: in scripts/excluded.json: {$excluded[$slug]['excluded']}";
    }
    foreach (['download', 'namespace', 'prefix'] as $field) {
        if (empty($entry[$field])) {
            $problems[] = "$slug: no $field";
        }
    }
    $entryTags = $entry['tags'] ?? [];
    if (!is_array($entryTags) || !$entryTags || count($entryTags) > Repo::MAX_TAGS) {
        $problems[] = sprintf('%s: needs 1 to %d tags', $slug, Repo::MAX_TAGS);
    } else {
        foreach (array_diff($entryTags, array_keys($tags)) as $unknown) {
            $problems[] = "$slug: unknown tag \"$unknown\" (tags are in config/module.config.php)";
        }
        if (count(array_unique($entryTags)) !== count($entryTags)) {
            $problems[] = "$slug: a tag is listed twice";
        }
    }
    if (isset($entry['copy_from']) && !in_array($entry['copy_from'], Repo::COPY_SOURCES, true)) {
        $problems[] = "$slug: copy_from must be one of " . implode(', ', Repo::COPY_SOURCES);
    }

    $prefix = (string) ($entry['prefix'] ?? '');
    if ($prefix !== '' && !preg_match(Vocabulary::PREFIX_REGEX, $prefix)) {
        $problems[] = "$slug: prefix \"$prefix\" may only contain letters, numbers, hyphens and underscores";
    }
    if (preg_match(Repo::RESERVED_PREFIX, $prefix)) {
        $problems[] = "$slug: prefix \"$prefix\" is reserved by Omeka";
    }
    // Omeka accepts capitals, but published prefixes are nearly all lowercase.
    if ($prefix !== strtolower($prefix)) {
        $problems[] = "$slug: prefix \"$prefix\" has capitals; prefixes are lowercase";
    }
    // Omeka compares prefixes without regard to case.
    $prefixes[mb_strtolower($prefix)][] = $slug;

    $files = $repo->files($slug);
    if (count($files) !== 1) {
        $problems[] = sprintf('%s: %d files in data/vocabs, expected 1', $slug, count($files));
        continue;
    }
    // Files under modules/ are served directly, so an HTML file would run as
    // a page on the site.
    if (Download::isHtml(file_get_contents($files[0]))) {
        $problems[] = "$slug: the stored file is HTML; give the entry \"format\": \"rdfa\" and fetch it again";
        continue;
    }
    $result = $check->check($files[0], $entry['namespace'] ?? null);
    if ($failure = ImportCheck::failure($result)) {
        $problems[] = "$slug: $failure";
        continue;
    }
    if (($entry['title'] ?? null) === null && $result['title'] === null) {
        $problems[] = "$slug: the file has no title, so the entry needs one";
    }
    if (isset($entry['title']) && trim($entry['title']) === '') {
        $problems[] = "$slug: title is empty";
    }
    $normalized = Repo::normalizedNamespace($result['namespace']);
    $namespaces[$normalized][] = $slug;
    if (isset($excludedNamespaces[$normalized])) {
        $problems[] = "$slug: the namespace is in scripts/excluded.json as \"{$excludedNamespaces[$normalized]['slug']}\"";
    }

    // The label alone where it differs from the local name, to keep the
    // index small: many files have no labels, so Omeka uses the local name.
    $terms = [];
    foreach (['classes', 'properties'] as $kind) {
        foreach ($result['members'][$kind] as $localName => $member) {
            $localName = (string) $localName;
            // Without markup, as the sidebar shows it, and with whitespace
            // collapsed, as the browser shows it, so a search for several
            // words matches.
            $label = trim(preg_replace('/\s+/u', ' ', PlainText::from($member['label'])));
            $terms[] = $label === $localName ? $localName : [$localName, $label];
        }
    }
    $index[$slug] = $terms;

    // The namespace and format are the ones the check found to import, and
    // the title is the curated one where the file has none or a misleading
    // one.
    $vocabularies[$slug] = [
        'classes' => $result['classes'],
        'description' => $result['description'],
        'file' => substr($files[0], strlen($repo->path('data')) + 1),
        'format' => $result['format'],
        'languages' => $result['languages'],
        'license' => $result['license'],
        'namespace' => $result['namespace'],
        'prefix' => $prefix,
        'properties' => $result['properties'],
        'tags' => $entryTags,
        'title' => $entry['title'] ?? $result['title'],
    ];
}

foreach ($prefixes as $prefix => $slugs) {
    if (count($slugs) > 1) {
        $problems[] = sprintf('prefix "%s" is used by %s', $prefix, implode(', ', $slugs));
    }
}
foreach ($namespaces as $namespace => $slugs) {
    if (count($slugs) > 1) {
        $problems[] = sprintf('namespace "%s" is used by %s', $namespace, implode(', ', $slugs));
    }
}
// Files with no entry would ship without being listed.
foreach (glob($repo->path('data/vocabs/*')) as $path) {
    $slug = pathinfo($path, PATHINFO_FILENAME);
    if (!isset($sources[$slug])) {
        $problems[] = 'data/vocabs/' . basename($path) . ': no entry in scripts/sources.json';
    }
}

if ($problems) {
    fail("Nothing written. Problems:\n  " . implode("\n  ", $problems));
}

$repo->writeVocabularies($vocabularies);
$repo->writeTermIndex($index);
printf("Omeka %s: %d vocabularies, %d terms\n", $omekaVersion, count($vocabularies), array_sum(array_map('count', $index)));
