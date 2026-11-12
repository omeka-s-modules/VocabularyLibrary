<?php
namespace VocabularyLibrary\Stdlib;

use EasyRdf\Graph as RdfGraph;
use Omeka\Api\Representation\VocabularyRepresentation;
use Omeka\Stdlib\RdfImporter;

/**
 * Reads, imports and compares the library's files with core's importer, and
 * knows where they are. Every read of a file's terms goes through here, so
 * this is where its options are set and the memory limit is raised.
 */
class Importer
{
    /**
     * Memory to read a vocabulary's file in. Core's importer holds the whole
     * file in memory, and the largest files need more than PHP's default of
     * 128M to compare.
     */
    const MEMORY_LIMIT = '256M';

    /**
     * The ways Compare can read terms' comments: as the library imports them,
     * or as Omeka's own import does.
     */
    const COMMENT_READINGS = [
        'definitions' => TermReading::COMMENT_PROPERTY,
        'comments' => TermReading::OMEKA_COMMENT_PROPERTY,
    ];

    protected $rdfImporter;
    protected $dataDir;

    /**
     * @param string $dataDir The module's data directory
     */
    public function __construct(RdfImporter $rdfImporter, $dataDir)
    {
        $this->rdfImporter = $rdfImporter;
        $this->dataDir = $dataDir;
    }

    /**
     * The classes and properties in an entry's file, as core's importer reads
     * them.
     *
     * @param string|null $lang The language to read labels and comments in
     * @param string $commentProperty Where terms' comments are read from
     * @return array ['classes' => [local name => ['label' => …, 'comment' =>
     *   …], …], 'properties' => […]]
     */
    public function members(array $entry, $lang, $commentProperty = TermReading::COMMENT_PROPERTY)
    {
        return $this->rdfImporter->getMembers('file', $entry['namespace'], $this->options($entry, $lang, $commentProperty));
    }

    /**
     * Imports an entry's file as a new vocabulary.
     *
     * @param array $vocabulary The vocabulary's "o:prefix", "o:label" and
     *   "o:comment"
     * @param string|null $lang The language to read labels and comments in
     * @return VocabularyRepresentation
     */
    public function import(array $entry, array $vocabulary, $lang)
    {
        return $this->rdfImporter->import(
            'file',
            ['o:namespace_uri' => $entry['namespace']] + $vocabulary,
            $this->options($entry, $lang)
        )->getContent();
    }

    /**
     * How the vocabulary in Omeka differs from an entry's file, as core's
     * importer computes it. A comment that only goes from none to an empty
     * one, or back, is left out: a file's empty definition reads as "", where
     * Omeka has none, and Compare would show "None" changing to "None".
     *
     * @param string|null $lang The language to read labels and comments in
     * @param string $commentProperty Where terms' comments are read from:
     *   TermReading::COMMENT_PROPERTY, or
     *   TermReading::OMEKA_COMMENT_PROPERTY, to compare a vocabulary imported
     *   outside the library
     */
    public function diff(array $entry, $lang, $commentProperty)
    {
        $diff = $this->rdfImporter->getDiff('file', $entry['namespace'], $this->options($entry, $lang, $commentProperty));
        foreach (['classes', 'properties'] as $kind) {
            $diff[$kind]['comment'] = array_filter($diff[$kind]['comment'], function ($change) {
                return (string) $change[0] !== '' || (string) $change[1] !== '';
            });
        }
        return $diff;
    }

    /**
     * How the vocabulary in Omeka differs from an entry's file, with its
     * comments read the way asked, or else the way it was imported.
     *
     * The library imports a term's definition where the file has one, and
     * Omeka's own import only its comment. Read the other way, every such
     * comment would show as changed, so the admin chooses. Until they do,
     * comments are read whichever way would change fewer of those in Omeka,
     * which is the way the vocabulary was imported; where both ways read the
     * same, there's no choice to offer.
     *
     * @param string|null $lang The language to read labels and comments in
     * @param string|null $reading A key of COMMENT_READINGS; anything else
     *   is no choice
     * @return array [a key of COMMENT_READINGS, or null where no way was
     *   asked and both read the same, and the diff]
     */
    public function diffAsImported(array $entry, $lang, $reading = null)
    {
        if (is_string($reading) && isset(self::COMMENT_READINGS[$reading])) {
            return [$reading, $this->diff($entry, $lang, self::COMMENT_READINGS[$reading])];
        }
        $diffs = [];
        foreach (self::COMMENT_READINGS as $key => $property) {
            $diffs[$key] = $this->diff($entry, $lang, $property);
        }
        if ($diffs['definitions'] == $diffs['comments']) {
            return [null, $diffs['definitions']];
        }
        $changed = function (array $diff) {
            return count($diff['classes']['comment']) + count($diff['properties']['comment']);
        };
        $reading = $changed($diffs['comments']) < $changed($diffs['definitions']) ? 'comments' : 'definitions';
        return [$reading, $diffs[$reading]];
    }

    /**
     * The classes and properties the vocabulary in Omeka has and an entry's
     * file doesn't. Core's update keeps them.
     *
     * @param string|null $lang The language to read the file in
     * @return array ['classes' => [local name => label, …], 'properties' =>
     *   […]]
     */
    public function missingTerms(array $entry, VocabularyRepresentation $vocabulary, $lang)
    {
        $members = $this->members($entry, $lang);
        $missing = ['classes' => [], 'properties' => []];
        foreach (['classes' => $vocabulary->resourceClasses(), 'properties' => $vocabulary->properties()] as $kind => $terms) {
            foreach ($terms as $term) {
                if (!isset($members[$kind][$term->localName()])) {
                    $missing[$kind][$term->localName()] = $term->label();
                }
            }
        }
        return $missing;
    }

    /**
     * The language the vocabulary in Omeka was most likely imported in: of
     * the entry's languages, and none ("First available"), the one whose
     * labels match the most of its labels. Ties go to the library's default
     * language.
     *
     * The file is read once, and each term's label in each language read as
     * core's importer reads it: in that language, else in any, else its local
     * name. Reading the file once per language would take seconds for
     * vocabularies with twenty languages.
     *
     * @return string|null A language tag, or null for none
     */
    public function matchingLanguage(array $entry, VocabularyRepresentation $vocabulary)
    {
        $labels = [];
        foreach (array_merge($vocabulary->resourceClasses(), $vocabulary->properties()) as $term) {
            $labels[$term->localName()] = $term->label();
        }
        $this->raiseMemoryLimit();
        $graph = new RdfGraph;
        $graph->parseFile($this->path($entry), $entry['format'] ?? 'guess', $entry['namespace']);
        $default = TermReading::preferredLanguage($entry['languages']);
        $best = $default;
        $bestMatches = -1;
        foreach (array_unique(array_merge([$default], $entry['languages'], [null])) as $lang) {
            $matches = 0;
            foreach ($labels as $localName => $label) {
                $term = $graph->resource($entry['namespace'] . $localName);
                $read = $term->get(TermReading::LABEL_PROPERTY, 'literal', $lang) ?: $term->get(TermReading::LABEL_PROPERTY, 'literal');
                $value = $read ? (string) $read->getValue() : '';
                $matches += ($value === '' ? (string) $localName : $value) === $label ? 1 : 0;
            }
            if ($matches > $bestMatches) {
                [$best, $bestMatches] = [$lang, $matches];
            }
        }
        return $best;
    }

    /**
     * Applies a diff to the vocabulary in Omeka, with core's update.
     */
    public function update($vocabularyId, array $diff)
    {
        $this->rdfImporter->update($vocabularyId, $diff);
    }

    /**
     * The path to an entry's file.
     */
    public function path(array $entry)
    {
        return $this->dataDir . '/' . $entry['file'];
    }

    /**
     * Options for core's importer to read an entry's file. A null format is
     * one the build check found by autodetecting.
     */
    protected function options(array $entry, $lang, $commentProperty = TermReading::COMMENT_PROPERTY)
    {
        $this->raiseMemoryLimit();
        return [
            'file' => $this->path($entry),
            'format' => $entry['format'] ?? 'guess',
            'lang' => $lang,
            'label_property' => TermReading::LABEL_PROPERTY,
            'comment_property' => $commentProperty,
        ];
    }

    /**
     * Raises PHP's memory limit to MEMORY_LIMIT where it is lower, as Omeka's
     * own Datascribe and OSII modules do for their jobs. A host that doesn't
     * allow it keeps its limit.
     */
    protected function raiseMemoryLimit()
    {
        $limit = (string) ini_get('memory_limit');
        if ($limit !== '-1' && self::bytes($limit) < self::bytes(self::MEMORY_LIMIT)) {
            ini_set('memory_limit', self::MEMORY_LIMIT);
        }
    }

    /**
     * Bytes in a PHP memory size, such as "128M".
     */
    protected static function bytes($size)
    {
        $size = trim($size);
        $units = ['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3];
        return (int) $size * ($units[strtolower(substr($size, -1))] ?? 1);
    }
}
