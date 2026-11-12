<?php
/**
 * Lists what each vocabulary's file says about its version and date, and
 * flags those that call themselves a draft, beta or experimental version.
 *
 * Usage: php scripts/versions.php [<slug> ...] [--drafts]
 *
 * Reads the file's owl:Ontology, or the resource at its namespace:
 * owl:versionInfo (or owl:versionIRI) for the version, and dcterms:modified,
 * dcterms:issued, a Dublin Core date or dcterms:created, the first found, for
 * the date. Values are as the publisher wrote them, but only their first
 * line, since some files keep a changelog in owl:versionInfo. --drafts lists
 * only the flagged ones. For curation only; the module doesn't use this.
 */
require __DIR__ . '/bootstrap.php';

use EasyRdf\Graph as RdfGraph;

// Where the date is read from, in the order tried.
const DATES = [
    'modified' => 'dcterms:modified',
    'issued' => 'dcterms:issued',
    'date' => 'dcterms:date|dc11:date',
    'created' => 'dcterms:created',
];
// Words a version gives itself when it isn't final. A leading word boundary
// only, so "beta202408" matches.
const DRAFT = '/\b(draft|beta|alpha|experimental|unstable|preview|work in progress)/i';
// The most of a version that is printed, in characters.
const WIDTH = 50;

$vocabularies = $repo->vocabularies();
$only = positionalArgs($argv);
foreach (array_diff($only, array_keys($vocabularies)) as $unknown) {
    fail("Unknown slug: $unknown");
}
$draftsOnly = in_array('--drafts', $argv, true);

/**
 * The first line of the first non-empty value of a property on any of the
 * resources.
 */
function firstValue(array $resources, $property)
{
    foreach ($resources as $resource) {
        $value = trim((string) $resource->get($property));
        if ($value !== '') {
            return trim(preg_replace('/\s+/', ' ', strtok($value, "\n")));
        }
    }
    return null;
}

$rows = [];
$counts = ['version' => 0, 'date' => 0, 'neither' => 0, 'draft' => 0];
foreach ($vocabularies as $slug => $entry) {
    if ($only && !in_array((string) $slug, $only, true)) {
        continue;
    }
    $graph = new RdfGraph;
    try {
        $graph->parseFile($repo->path('data/' . $entry['file']), $entry['format'] ?: null);
    } catch (Throwable $e) {
        $rows[] = [$slug, 'does not parse', '', ''];
        continue;
    }
    $resources = $graph->allOfType('owl:Ontology');
    foreach (array_unique([$entry['namespace'], rtrim($entry['namespace'], '#/')]) as $uri) {
        $resources[] = $graph->resource($uri);
    }
    $version = firstValue($resources, 'owl:versionInfo') ?? firstValue($resources, 'owl:versionIRI');
    $date = null;
    foreach (DATES as $kind => $property) {
        if ($value = firstValue($resources, $property)) {
            $date = "$value ($kind)";
            break;
        }
    }
    $draft = $version !== null && preg_match(DRAFT, $version);
    $counts['version'] += $version !== null;
    $counts['date'] += $date !== null;
    $counts['neither'] += $version === null && $date === null;
    $counts['draft'] += $draft;
    if (!$draftsOnly || $draft) {
        $rows[] = [$slug, mb_strimwidth($version ?? '', 0, WIDTH, '…'), $date ?? '', $draft ? 'DRAFT' : ''];
    }
}

$widths = [];
foreach ($rows as $row) {
    foreach ($row as $i => $cell) {
        $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
    }
}
foreach ($rows as $row) {
    $line = '';
    foreach ($row as $i => $cell) {
        $line .= $cell . str_repeat(' ', $widths[$i] - mb_strlen($cell) + 2);
    }
    echo rtrim($line), "\n";
}
printf("%d with a version, %d with a date, %d with neither; %d flagged as drafts\n",
    $counts['version'], $counts['date'], $counts['neither'], $counts['draft']);
