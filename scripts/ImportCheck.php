<?php
namespace VocabularyLibrary\Scripts;

use EasyRdf\Graph as RdfGraph;
use Omeka\Api\Exception\ValidationException;
use Omeka\Entity\Vocabulary;
use Omeka\Stdlib\RdfImporter;
use ReflectionClass;
use RuntimeException;
use Throwable;
use VocabularyLibrary\Stdlib\PlainText;
use VocabularyLibrary\Stdlib\TermReading;

/**
 * Tests a vocabulary file against Omeka's own importer, without a database or
 * a running install.
 *
 * The file is read by the importer's own readGraph(), as
 * RdfImporter::getMembers() reads it, then the importer's own
 * getMembersOfTypes() is called with the namespace and its http/https and #//
 * variants (Vocabulary::getNamespaceUriForms()), and the best result is kept.
 * Only if all of those import nothing is the namespace the file's terms
 * actually use tried, because a file that redeclares another vocabulary's
 * terms would otherwise be credited with that vocabulary. With no namespace
 * given, it is found as core's import details finds it. What the file says of
 * its vocabulary, its languages, and the terms too long to store are read by
 * the importer too (CheckImporter).
 *
 * The database is not touched, so the namespace's column length is checked
 * here; a vocabulary that breaks it fails on import.
 */
class ImportCheck
{
    /**
     * Where terms' labels and comments are read from, as the module imports
     * them.
     */
    const LABEL_PROPERTY = TermReading::LABEL_PROPERTY;
    const COMMENT_PROPERTY = TermReading::COMMENT_PROPERTY;

    /**
     * The column length of a vocabulary's namespace_uri, varchar(190) in
     * application/data/install/schema.sql, which core has no constant or
     * check for. Core checks terms' local names and labels itself.
     */
    const MAX_NAMESPACE = 190;

    /**
     * The language of labels and comments the check first reads terms in.
     * Where a file has no English, the terms are read again in the language
     * the module uses, TermReading::preferredLanguage().
     */
    const LANG = 'en';

    protected $importer;

    public function __construct()
    {
        $this->importer = CheckImporter::create();
    }

    /**
     * The reason a check result does not import, or null if it does.
     *
     * A result whose namespace was inferred from the file's terms, because
     * the given namespace and its variants import nothing, counts as a
     * failure: the file is likely another vocabulary's. So does, given the
     * namespace an entry records, a result in another form of it, http for
     * https or another closing # or /: to Omeka that is another vocabulary
     * (scripts/README.md, Versions). Adding a vocabulary gives none, to take
     * the form its file uses.
     */
    public static function failure(array $result, $namespace = null)
    {
        if (!$result['parsed']) {
            return 'does not parse: ' . $result['error'];
        }
        if ($result['classes'] + $result['properties'] === 0) {
            return 'imports no classes or properties';
        }
        if ($result['namespace_source'] === 'terms') {
            return sprintf('the namespace imports nothing; the file\'s terms use %s', $result['namespace']);
        }
        if ($namespace !== null && $result['namespace'] !== $namespace) {
            return sprintf('the file\'s terms use the namespace %s, not %s', $result['namespace'], $namespace);
        }
        if (!empty($result['limits'])) {
            return implode('; ', $result['limits']);
        }
        return null;
    }

    /**
     * How a vocabulary's file differs from an earlier one, in what the
     * library and an administrator comparing the two would see.
     *
     * Labels and comments are compared in every language both files have
     * either in, and regardless of spacing, as core's review shows them, with
     * comments read as the library imports them, a definition first. So a
     * change only to the comment of a term that also has a definition, which
     * core's review shows for a vocabulary read comment first, isn't counted.
     *
     * @param array $old A check result
     * @param array $new
     * @return array Counts of terms "added" and "removed", and of the terms
     *   in both whose "labels" or "comments" differ in any language; and
     *   "details", the names of what the file says of the vocabulary that
     *   differs: title, description, license, prefix, languages
     */
    public static function differences(array $old, array $new)
    {
        $importer = CheckImporter::create();
        $labels = $comments = [];
        // Language by language, or as each file reads by default where they
        // share no language, as when a file's labels gain language tags.
        $pairs = [];
        foreach (array_intersect_key($new['readings'], $old['readings']) as $language => $members) {
            $pairs[] = [$old['readings'][$language], $members];
        }
        foreach ($pairs ?: [[$old['members'], $new['members']]] as [$oldMembers, $members]) {
            foreach (['classes', 'properties'] as $kind) {
                // As core's review compares (RdfImporter::getDiff()), so a
                // vocabulary counted as changed shows changes there. Core's
                // review also never puts the local name a term without a
                // label stands in for in place of a label; here a label that
                // is its local name counts as one, where core can tell a real
                // one.
                $diff = $importer->diff($oldMembers[$kind], $members[$kind]);
                foreach ($diff['label'] as $localName => $change) {
                    if ((string) $change[1] !== (string) $localName) {
                        $labels["$kind $localName"] = true;
                    }
                }
                foreach (array_keys($diff['comment']) as $localName) {
                    $comments["$kind $localName"] = true;
                }
            }
        }
        $differences = ['added' => 0, 'removed' => 0, 'labels' => count($labels), 'comments' => count($comments), 'details' => []];
        foreach (['classes', 'properties'] as $kind) {
            $differences['added'] += count(array_diff_key($new['members'][$kind], $old['members'][$kind]));
            $differences['removed'] += count(array_diff_key($old['members'][$kind], $new['members'][$kind]));
        }
        foreach (['title', 'description', 'license', 'prefix', 'languages'] as $detail) {
            if ($old[$detail] !== $new[$detail]) {
                $differences['details'][] = $detail;
            }
        }
        return $differences;
    }

    /**
     * @param string $path The vocabulary file
     * @param string|null $namespace The namespace a user would enter, if known
     * @return array The check result: "parsed", and "classes" and
     *   "properties" as counts; "error" where the file, or a term's label or
     *   comment, doesn't parse; and where they do:
     *   - namespace: the namespace found to import
     *   - namespace_source: where that came from: "given", or as core's
     *     import details names it: "declared" (the file's
     *     vann:preferredNamespaceUri), "ontology" (its owl:Ontology),
     *     "other_form" (another #// or http/https form of one of those), or
     *     "terms" (the one most of its terms use, which failure() refuses)
     *   - members: the classes and properties, as CheckImporter::members()
     *     returns them, in the language the module shows
     *   - readings: the same, in each language the file has labels or
     *     comments in, by language tag
     *   - limits: Omeka's column limits broken in any of the languages, as
     *     limits() words them
     *   - languages: the language tags on the terms' labels
     *   - language_coverage: for each of them, how many classes and
     *     properties core's import details counts as having a label or
     *     comment in it (CheckImporter::languageCoverage())
     *   - prefix, title, description, license: what the file says of the
     *     vocabulary, each null where it says nothing
     */
    public function check($path, $namespace = null)
    {
        try {
            $graph = $this->importer->graph($path, $namespace);
        } catch (Throwable $e) {
            return ['parsed' => false, 'error' => mb_substr($e->getMessage(), 0, 300), 'classes' => 0, 'properties' => 0];
        }
        // Reading the terms reads their labels and comments, which EasyRdf
        // checks against their datatypes only then. One that isn't valid,
        // such as a label typed as a number that isn't one, throws, as it
        // would in Omeka's importer, and the file doesn't import.
        try {
            return $this->checkParsed($graph, $namespace);
        } catch (Throwable $e) {
            return ['parsed' => false, 'error' => 'a term\'s label or comment: ' . mb_substr($e->getMessage(), 0, 300), 'classes' => 0, 'properties' => 0];
        }
    }

    /**
     * The rest of check(), for a file that parsed.
     */
    protected function checkParsed(RdfGraph $graph, $namespace)
    {
        if ($namespace) {
            $best = $namespace;
            $source = 'given';
            $bestMembers = $this->importer->members($graph, $namespace, self::LANG);
            $bestCount = $this->count($bestMembers);
            foreach (Vocabulary::getNamespaceUriForms($namespace) as $candidate) {
                // The namespace itself is the first candidate, already counted.
                if ($candidate === $namespace) {
                    continue;
                }
                $members = $this->importer->members($graph, $candidate, self::LANG);
                if ($this->count($members) > $bestCount) {
                    [$best, $bestMembers, $bestCount, $source] = [$candidate, $members, $this->count($members), 'other_form'];
                }
            }
            if (!$bestCount && $inferred = $this->importer->mostUsedNamespaceUri($graph)) {
                $members = $this->importer->members($graph, $inferred, self::LANG);
                if ($this->count($members)) {
                    [$best, $bestMembers, $bestCount, $source] = [$inferred, $members, $this->count($members), 'terms'];
                }
            }
        } else {
            [$best, $source] = $this->importer->namespaceUri($graph);
            $bestMembers = $best === null ? ['classes' => [], 'properties' => []] : $this->importer->members($graph, $best, self::LANG);
            $bestCount = $this->count($bestMembers);
        }
        $best = (string) $best;
        $languages = $this->safely(function () use ($graph, $best) {
            return $this->importer->languages($graph, $best, self::LABEL_PROPERTY);
        }) ?: [];
        // Counted by core's own import details, so the module's import starts
        // on the language core's would, for the languages the library lists.
        $coverage = array_intersect_key($this->safely(function () use ($graph) {
            return $this->importer->languageCoverage($graph);
        }) ?: [], array_flip($languages));
        ksort($coverage, SORT_STRING);
        // Labels and comments in the language the module shows and imports
        // them in, so the term index matches the sidebar. Only they change
        // with the language, not which terms there are.
        $lang = TermReading::preferredLanguage($languages, $coverage);
        $read = self::LANG;
        if ($bestCount && $lang !== null && $lang !== self::LANG) {
            $bestMembers = $this->importer->members($graph, $best, $lang);
            $read = $lang;
        }
        // An admin can import the terms in any of the file's languages, so
        // the limits are checked in each.
        $limits = $this->limits($best, $bestMembers);
        $readings = [$read => $bestMembers];
        // And read in each, comments' languages included, to compare one
        // file with another.
        $commentLanguages = $this->safely(function () use ($graph, $best) {
            return $this->importer->languages($graph, $best, self::COMMENT_PROPERTY);
        }) ?: [];
        foreach ($bestCount ? array_diff(array_unique(array_merge($languages, $commentLanguages)), [$read]) : [] as $language) {
            $readings[$language] = $this->importer->members($graph, $best, $language);
            foreach ($this->limits($best, $readings[$language]) as $limit) {
                if (!in_array($limit, $limits, true)) {
                    $limits[] = "$limit in \"$language\"";
                }
            }
        }

        return [
            'parsed' => true,
            'namespace' => $best,
            'namespace_source' => $source,
            'classes' => count($bestMembers['classes']),
            'properties' => count($bestMembers['properties']),
            'members' => $bestMembers,
            'readings' => $readings,
            'limits' => $limits,
            'languages' => $languages,
            'language_coverage' => $coverage,
            'prefix' => $this->safely(function () use ($graph, $best) {
                return $this->importer->vocabularyPrefix($graph, $best);
            }),
            'title' => $this->safely(function () use ($graph, $best) {
                return $this->plain($this->importer->vocabularyTitle($graph, $best), false);
            }),
            'description' => $this->safely(function () use ($graph, $best) {
                return $this->plain($this->importer->vocabularyDescription($graph, $best), true);
            }),
            'license' => $this->safely(function () use ($graph, $best) {
                return $this->license($graph, $best);
            }),
        ];
    }

    /**
     * Runs a metadata lookup, returning null if EasyRdf throws on a malformed
     * value (an invalid xsd:decimal, for example). Omeka's importer never
     * reads these values, so they must not fail the check.
     */
    protected function safely(callable $lookup)
    {
        try {
            return $lookup();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function count(array $members)
    {
        return count($members['classes']) + count($members['properties']);
    }

    /**
     * Omeka's limits that the namespace or a term would break: the
     * namespace's column length, text that isn't valid UTF-8, and the local
     * names and labels core refuses as too long to store.
     *
     * @return string[] One message per kind of violation
     */
    protected function limits($namespace, array $members)
    {
        $limits = [];
        if (mb_strlen($namespace) > self::MAX_NAMESPACE) {
            $limits[] = sprintf('namespace longer than %d characters', self::MAX_NAMESPACE);
        }
        $invalid = 0;
        foreach (['classes', 'properties'] as $kind) {
            foreach ($members[$kind] as $localName => $member) {
                $invalid += mb_check_encoding($localName . $member['label'] . $member['comment'], 'UTF-8') ? 0 : 1;
            }
        }
        if ($invalid) {
            $limits[] = sprintf('%d terms that are not valid UTF-8', $invalid);
        }
        return array_merge($limits, $this->importer->lengthLimits($members));
    }

    /**
     * A title or description as core read it from the file, without the
     * XHTML or Markdown some publishers write them in: a title on one line,
     * a description in paragraphs.
     */
    protected function plain($text, $paragraphs)
    {
        if ($text === null) {
            return null;
        }
        $text = PlainText::from($text);
        $blocks = $paragraphs ? Markdown::paragraphs($text) : array_filter([Markdown::line($text)], 'strlen');
        return $blocks ? implode("\n\n", $blocks) : null;
    }

    /**
     * The license the vocabulary's records declare, from the first that
     * declares one, if any: the records core reads the title and
     * description from.
     */
    protected function license(RdfGraph $graph, $namespace)
    {
        $properties = ['dcterms:license', 'dc11:rights', 'dcterms:rights', '<http://creativecommons.org/ns#license>', '<http://www.w3.org/1999/xhtml/vocab#license>'];
        foreach ($this->importer->describingResources($graph, $namespace) as $record) {
            foreach ($properties as $property) {
                $value = trim(PlainText::from($record->get($property)));
                if ($value !== '' && mb_check_encoding($value, 'UTF-8')) {
                    return $value;
                }
            }
        }
        return null;
    }
}

/**
 * Exposes the importer's reading, unchanged, for a graph parsed once: its
 * terms, what it says of its vocabulary, its languages, its namespace, and
 * how two readings of its terms differ.
 */
class CheckImporter extends RdfImporter
{
    /**
     * An importer without Omeka's services, which reading a file and its
     * terms doesn't use.
     */
    public static function create()
    {
        return (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
    }

    /**
     * A file's graph, read as core reads a file to import it (readGraph()):
     * in the format EasyRdf guesses, then in each format in turn where the
     * guess fails or reads nothing, with the namespace as the base for
     * relative URIs.
     *
     * @param string|null $namespace
     * @throws Throwable The parser's first error, where no format reads the
     *   file: core's own message says only that, not why
     */
    public function graph($path, $namespace)
    {
        // Core reads nothing from an empty file either, but its JSON-LD
        // parser warns on one first.
        if (is_file($path) && !filesize($path)) {
            throw new RuntimeException('the file is empty');
        }
        $error = null;
        $read = function (RdfGraph $graph, $format) use ($path, $namespace, &$error) {
            try {
                $graph->parseFile($path, $format, $namespace);
            } catch (Throwable $e) {
                $error ??= $e;
                throw $e;
            }
        };
        try {
            return $this->readGraph($read, 'guess', 'Could not parse vocabulary file.');
        } catch (ValidationException $e) {
            throw $error ?? $e;
        }
    }

    public function members(RdfGraph $graph, $namespaceUri, $lang = null)
    {
        return [
            'classes' => $this->getMembersOfTypes($graph, $this->classTypes, $namespaceUri, ImportCheck::LABEL_PROPERTY, ImportCheck::COMMENT_PROPERTY, $lang),
            'properties' => $this->getMembersOfTypes($graph, $this->propertyTypes, $namespaceUri, ImportCheck::LABEL_PROPERTY, ImportCheck::COMMENT_PROPERTY, $lang),
        ];
    }

    /**
     * The namespace and where it was found, as core's import details finds
     * it in a file (findNamespaceUri()).
     *
     * @return array [namespace or null, source or null]
     */
    public function namespaceUri(RdfGraph $graph)
    {
        return $this->findNamespaceUri($graph);
    }

    public function mostUsedNamespaceUri(RdfGraph $graph)
    {
        return $this->getMostUsedNamespaceUri($graph);
    }

    /**
     * The language tags on the values of a property, such as the labels, of
     * the classes and properties in the namespace.
     */
    public function languages(RdfGraph $graph, $namespaceUri, $property)
    {
        return $this->getGraphLanguages($graph, $namespaceUri, $property);
    }

    /**
     * The prefix the file declares for the vocabulary, if Omeka can use it.
     */
    public function vocabularyPrefix(RdfGraph $graph, $namespaceUri)
    {
        return $this->getVocabularyPrefix($graph, $namespaceUri);
    }

    /**
     * The file's title for the vocabulary, in English or the nearest
     * language it has, as written.
     */
    public function vocabularyTitle(RdfGraph $graph, $namespaceUri)
    {
        return $this->getVocabularyTitle($graph, $namespaceUri, ImportCheck::LANG);
    }

    /**
     * The file's description of the vocabulary, the same way.
     */
    public function vocabularyDescription(RdfGraph $graph, $namespaceUri)
    {
        return $this->getVocabularyDescription($graph, $namespaceUri, ImportCheck::LANG);
    }

    /**
     * The resources that may describe the vocabulary, in the order core
     * reads its title and description from them.
     */
    public function describingResources(RdfGraph $graph, $namespaceUri)
    {
        return $this->getDescribingResources($graph, $namespaceUri);
    }

    /**
     * The changes core's review would make from one reading of a kind of
     * terms to another: new terms, and labels and comments that differ.
     */
    public function diff(array $from, array $to)
    {
        return $this->calculateDiff($from, $to);
    }

    /**
     * Core's reasons the terms can't be stored: local names and labels too
     * long, naming them.
     *
     * @return string[]
     */
    public function lengthLimits(array $members)
    {
        try {
            $this->checkTermLengths($members);
        } catch (ValidationException $e) {
            $limits = [];
            foreach ($e->getErrorStore()->getErrors() as $messages) {
                foreach ($messages as $message) {
                    $limits[] = (string) $message;
                }
            }
            return $limits;
        }
        return [];
    }

    /**
     * How many classes and properties have a label or comment in each
     * language, counted as core's import details counts them to choose its
     * starting language (getVocabularyDetails()).
     *
     * @return int[] By language tag
     */
    public function languageCoverage(RdfGraph $graph)
    {
        return $this->countGraphLanguages($graph, null, self::LABEL_PROPERTY . '|' . self::COMMENT_PROPERTY);
    }
}
