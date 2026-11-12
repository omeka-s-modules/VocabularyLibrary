<?php
namespace VocabularyLibrary\Scripts;

use EasyRdf\Graph as RdfGraph;
use Omeka\Stdlib\RdfImporter;
use ReflectionClass;
use Throwable;
use VocabularyLibrary\Stdlib\PlainText;
use VocabularyLibrary\Stdlib\TermReading;

/**
 * Tests a vocabulary file against Omeka's own importer, without a database or
 * a running install.
 *
 * The file is parsed the way RdfImporter::getMembers() parses it, then the
 * importer's own getMembersOfTypes() is called with the namespace and its
 * http/https and #// variants, and the best result is kept. Only if all of
 * those import nothing is the namespace the file's terms actually use tried,
 * because a file that redeclares another vocabulary's terms would otherwise be
 * credited with that vocabulary.
 *
 * The database is not touched, so the column limits of Omeka's schema are
 * checked here; a vocabulary that breaks one fails on import.
 */
class ImportCheck
{
    /**
     * Where terms' labels and comments are read from, as the module imports
     * them.
     */
    const LABEL_PROPERTY = TermReading::LABEL_PROPERTY;
    const COMMENT_PROPERTY = TermReading::COMMENT_PROPERTY;

    const VANN = 'http://purl.org/vocab/vann/';

    /**
     * Column lengths in application/data/install/schema.sql: property and
     * resource_class label varchar(255) and local_name varchar(190);
     * vocabulary namespace_uri varchar(190).
     */
    const MAX_LABEL = 255;
    const MAX_LOCAL_NAME = 190;
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
        $this->importer = (new ReflectionClass(CheckImporter::class))->newInstanceWithoutConstructor();
    }

    /**
     * The reason a check result does not import, or null if it does.
     *
     * A result whose namespace was inferred from the file's terms, because
     * the given namespace and its variants import nothing, counts as a
     * failure: the file is likely another vocabulary's.
     */
    public static function failure(array $result)
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
        if (!empty($result['limits'])) {
            return implode('; ', $result['limits']);
        }
        return null;
    }

    /**
     * @param string $path The vocabulary file
     * @param string|null $namespace The namespace a user would enter, if known
     * @return array The check result: "parsed", and "classes" and
     *   "properties" as counts; "error" where the file doesn't parse; and
     *   where it does:
     *   - format: the format it parsed as, or null if autodetected
     *   - namespace: the namespace found to import
     *   - namespace_source: where that came from: "given", "declared" (the
     *     file's vann:preferredNamespaceUri), "ontology" (its owl:Ontology),
     *     "variant" (another #// or http/https form of one of those), or
     *     "terms" (the one its terms use, which failure() refuses)
     *   - members: the classes and properties, as CheckImporter::members()
     *     returns them, in the language the module shows
     *   - limits: Omeka's column limits broken in any of the languages, as
     *     limits() words them
     *   - languages: the language tags on the terms' labels
     *   - prefix, title, description, license: what the file says of the
     *     vocabulary, each null where it says nothing
     */
    public function check($path, $namespace = null)
    {
        // As Omeka's form does by default, autodetect the format. If that
        // fails, try the formats a user can choose explicitly.
        $graph = null;
        $error = null;
        $format = null;
        foreach (['guess', 'rdfxml', 'turtle', 'jsonld', 'ntriples'] as $try) {
            $attempt = new RdfGraph;
            try {
                $attempt->parseFile($path, $try, $namespace);
                [$graph, $format] = [$attempt, $try];
                break;
            } catch (Throwable $e) {
                $error = $error ?: mb_substr($e->getMessage(), 0, 300);
            }
        }
        if (!$graph) {
            return ['parsed' => false, 'error' => $error, 'classes' => 0, 'properties' => 0];
        }

        $source = $namespace ? 'given' : null;
        if (!$namespace) {
            $declared = $this->safely(function () use ($graph) {
                return $this->literal($graph, self::VANN . 'preferredNamespaceUri');
            });
            if ($declared) {
                [$namespace, $source] = [$declared, 'declared'];
            }
        }
        if (!$namespace && ($ontology = $graph->allOfType('owl:Ontology')) && preg_match('#^https?://#', (string) $ontology[0]->getUri())) {
            [$namespace, $source] = [$ontology[0]->getUri(), 'ontology'];
        }

        $best = $namespace;
        $bestMembers = $namespace ? $this->importer->members($graph, $namespace, self::LANG) : ['classes' => [], 'properties' => []];
        $bestCount = $this->count($bestMembers);
        foreach ($namespace ? $this->candidates($namespace) : [] as $candidate) {
            // The namespace itself is the first candidate, already counted.
            if ($candidate === $namespace) {
                continue;
            }
            $members = $this->importer->members($graph, $candidate, self::LANG);
            if ($this->count($members) > $bestCount) {
                [$best, $bestMembers, $bestCount, $source] = [$candidate, $members, $this->count($members), 'variant'];
            }
        }
        if (!$bestCount && $inferred = $this->inferredNamespace($graph)) {
            $members = $this->importer->members($graph, $inferred, self::LANG);
            if ($this->count($members)) {
                [$best, $bestMembers, $bestCount, $source] = [$inferred, $members, $this->count($members), 'terms'];
            }
        }
        $best = (string) $best;
        $languages = $this->safely(function () use ($graph, $best) {
            return $this->languages($graph, $best);
        }) ?: [];
        // Labels and comments in the language the module shows and imports
        // them in, so the term index matches the sidebar. Only they change
        // with the language, not which terms there are.
        $lang = TermReading::preferredLanguage($languages);
        $read = self::LANG;
        if ($bestCount && $lang !== null && $lang !== self::LANG) {
            $bestMembers = $this->importer->members($graph, $best, $lang);
            $read = $lang;
        }
        // An admin can import the terms in any of the file's languages, so
        // the limits are checked in each.
        $limits = $this->limits($best, $bestMembers);
        foreach ($bestCount ? array_diff($languages, [$read]) : [] as $language) {
            foreach ($this->limits($best, $this->importer->members($graph, $best, $language)) as $limit) {
                if (!in_array($limit, $limits, true)) {
                    $limits[] = "$limit in \"$language\"";
                }
            }
        }

        return [
            'parsed' => true,
            'format' => $format === 'guess' ? null : $format,
            'namespace' => $best,
            'namespace_source' => $source,
            'classes' => count($bestMembers['classes']),
            'properties' => count($bestMembers['properties']),
            'members' => $bestMembers,
            'limits' => $limits,
            'languages' => $languages,
            'prefix' => $this->safely(function () use ($graph, $best) {
                return $this->declaredPrefix($graph, $best);
            }),
            'title' => $this->safely(function () use ($graph, $best) {
                return $this->describe($graph, $best, 'dcterms:title|dc11:title|rdfs:label', false);
            }),
            // A note only where there's no description: some element sets
            // describe themselves in skos:note.
            'description' => $this->safely(function () use ($graph, $best) {
                return $this->describe($graph, $best, 'dcterms:description|dc11:description|rdfs:comment|skos:definition', true)
                    ?? $this->describe($graph, $best, 'skos:note', true);
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
     * The namespace with its #// and http/https variants.
     */
    public static function candidates($namespace)
    {
        $forms = [$namespace];
        $base = rtrim($namespace, '#/');
        foreach (['#', '/'] as $end) {
            $forms[] = $base . $end;
        }
        foreach ($forms as $form) {
            if (strpos($form, 'http://') === 0) {
                $forms[] = 'https://' . substr($form, 7);
            } elseif (strpos($form, 'https://') === 0) {
                $forms[] = 'http://' . substr($form, 8);
            }
        }
        return array_values(array_unique($forms));
    }

    /**
     * Omeka's column limits that the namespace or a term would break.
     *
     * @return string[] One message per kind of violation
     */
    protected function limits($namespace, array $members)
    {
        $limits = [];
        if (mb_strlen($namespace) > self::MAX_NAMESPACE) {
            $limits[] = sprintf('namespace longer than %d characters', self::MAX_NAMESPACE);
        }
        $longNames = $longLabels = $invalid = 0;
        foreach (['classes', 'properties'] as $kind) {
            foreach ($members[$kind] as $localName => $member) {
                $longNames += mb_strlen((string) $localName) > self::MAX_LOCAL_NAME ? 1 : 0;
                $longLabels += mb_strlen((string) $member['label']) > self::MAX_LABEL ? 1 : 0;
                $invalid += mb_check_encoding($localName . $member['label'] . $member['comment'], 'UTF-8') ? 0 : 1;
            }
        }
        if ($invalid) {
            $limits[] = sprintf('%d terms that are not valid UTF-8', $invalid);
        }
        if ($longNames) {
            $limits[] = sprintf('%d local names longer than %d characters', $longNames, self::MAX_LOCAL_NAME);
        }
        if ($longLabels) {
            $limits[] = sprintf('%d labels longer than %d characters', $longLabels, self::MAX_LABEL);
        }
        return $limits;
    }

    /**
     * The namespace most of the file's class and property terms use: each
     * term's URI minus its local name, by frequency.
     */
    protected function inferredNamespace(RdfGraph $graph)
    {
        $counts = [];
        foreach ($this->importer->acceptedTypes() as $type) {
            foreach ($graph->allOfType($type) as $resource) {
                if ($resource->isBNode()) {
                    continue;
                }
                $uri = $resource->getUri();
                $local = $resource->localName();
                // Without a namespace to use as the base, relative URIs
                // resolve against the file's own path; never offer that.
                if (!preg_match('#^https?://#', (string) $uri)) {
                    continue;
                }
                if ($local && substr($uri, -strlen($local)) === $local) {
                    $prefix = substr($uri, 0, -strlen($local));
                    $counts[$prefix] = ($counts[$prefix] ?? 0) + 1;
                }
            }
        }
        arsort($counts);
        return key($counts);
    }

    /**
     * The language tags on the labels of the terms that would be imported.
     *
     * @return string[]
     */
    protected function languages(RdfGraph $graph, $namespace)
    {
        $tags = [];
        foreach ($this->importer->acceptedTypes() as $type) {
            foreach ($graph->allOfType($type) as $resource) {
                if ($resource->getUri() !== $namespace . $resource->localName()) {
                    continue;
                }
                foreach ($resource->all(self::LABEL_PROPERTY, 'literal') as $label) {
                    if ($label->getLang()) {
                        $tags[$label->getLang()] = true;
                    }
                }
            }
        }
        $tags = array_keys($tags);
        sort($tags);
        return $tags;
    }

    /**
     * The first value of a property on any resource, as a string.
     */
    protected function literal(RdfGraph $graph, $property)
    {
        foreach ($graph->resources() as $resource) {
            if ($value = $resource->get('<' . $property . '>')) {
                return (string) $value;
            }
        }
        return null;
    }

    /**
     * The prefix the vocabulary declares for itself: vann:preferredNamespacePrefix
     * on the namespace resource or on the ontology record for the namespace.
     * A file that also describes vocabularies it reuses may declare their
     * prefixes on their records, so no other resource is read.
     */
    protected function declaredPrefix(RdfGraph $graph, $namespace)
    {
        if ($namespace === '') {
            return null;
        }
        $subjects = $this->ownOntologies($graph, $namespace);
        $subjects[] = $graph->resource($namespace);
        $subjects[] = $graph->resource(rtrim($namespace, '#/'));
        foreach ($subjects as $subject) {
            $value = trim((string) $subject->get('<' . self::VANN . 'preferredNamespacePrefix>'));
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * The file's ontology records for the namespace.
     *
     * @return \EasyRdf\Resource[]
     */
    protected function ownOntologies(RdfGraph $graph, $namespace)
    {
        $base = rtrim($namespace, '#/');
        $own = [];
        foreach ($graph->allOfType('owl:Ontology') as $ontology) {
            if (rtrim((string) $ontology->getUri(), '#/') === $base) {
                $own[] = $ontology;
            }
        }
        return $own;
    }

    /**
     * The ontology records that describe the vocabulary itself: those for
     * the namespace, or else the file's only one, which many files give a
     * URI other than the namespace. A file that also describes vocabularies
     * it reuses has several, and theirs are not read.
     *
     * @return \EasyRdf\Resource[]
     */
    protected function describingOntologies(RdfGraph $graph, $namespace)
    {
        $own = $this->ownOntologies($graph, $namespace);
        if ($own) {
            return $own;
        }
        $all = $graph->allOfType('owl:Ontology');
        return count($all) === 1 ? $all : [];
    }

    /**
     * The record most of the vocabulary's terms name with rdfs:isDefinedBy,
     * or null if they name none.
     */
    protected function definingRecord(RdfGraph $graph, $namespace)
    {
        $counts = [];
        foreach ($graph->resources() as $resource) {
            if ($resource->isBNode() || strpos($resource->getUri(), $namespace) !== 0) {
                continue;
            }
            foreach ($resource->allResources('rdfs:isDefinedBy') as $record) {
                if (!$record->isBNode()) {
                    $counts[$record->getUri()] = ($counts[$record->getUri()] ?? 0) + 1;
                }
            }
        }
        if (!$counts) {
            return null;
        }
        arsort($counts);
        return array_key_first($counts);
    }

    /**
     * The vocabulary's own title or description, as its publisher wrote it:
     * from its ontology record, or else from the namespace resource, or else
     * from the record its terms name with rdfs:isDefinedBy, as element sets
     * from the Open Metadata Registry do. English if the file offers it,
     * otherwise the first value in any language. A description the file
     * gives as several values, such as a description and a changelog, keeps
     * them all, each as its own paragraph.
     *
     * @param bool $paragraphs Keep paragraph breaks (blank lines), and all of
     *   the values; otherwise the text is one line, from one value
     */
    protected function describe(RdfGraph $graph, $namespace, $properties, $paragraphs)
    {
        $subjects = $this->describingOntologies($graph, $namespace);
        if ($namespace !== '') {
            $subjects[] = $graph->resource($namespace);
            $subjects[] = $graph->resource(rtrim($namespace, '#/'));
            $defining = $this->definingRecord($graph, $namespace);
            if ($defining !== null) {
                $subjects[] = $graph->resource($defining);
            }
        }
        foreach ($subjects as $subject) {
            $values = $paragraphs
                ? self::allValues($subject, $properties)
                : array_filter([$subject->get($properties, 'literal', 'en') ?: $subject->get($properties, 'literal')]);
            $blocks = [];
            foreach ($values as $value) {
                // Some files use XHTML literals, and some Markdown; keep the text.
                $text = PlainText::from($value);
                if (!mb_check_encoding($text, 'UTF-8')) {
                    continue;
                }
                $blocks = array_merge($blocks, $paragraphs ? Markdown::paragraphs($text) : array_filter([Markdown::line($text)], 'strlen'));
            }
            if ($blocks) {
                return implode("\n\n", $blocks);
            }
        }
        return null;
    }

    /**
     * All the values of a description, in the file's order, from the
     * property a lookup of one value would choose: the first of the
     * properties with an English value, taking its values in English and
     * those with no language; or else the first with any value, taking its
     * values in that value's language.
     *
     * @param \EasyRdf\Resource $subject
     * @param string $properties Alternatives, as "a|b"
     * @return \EasyRdf\Literal[]
     */
    protected static function allValues($subject, $properties)
    {
        foreach ([true, false] as $english) {
            foreach (explode('|', $properties) as $property) {
                $values = $subject->all($property, 'literal');
                if (!$values) {
                    continue;
                }
                $languages = array_map(function ($value) {
                    return (string) $value->getLang();
                }, $values);
                if ($english && !in_array('en', $languages, true)) {
                    continue;
                }
                $keep = $english ? ['en', ''] : [$languages[0]];
                return array_values(array_filter($values, function ($value) use ($keep) {
                    return in_array((string) $value->getLang(), $keep, true);
                }));
            }
        }
        return [];
    }

    /**
     * The license the vocabulary's ontology record declares, if any.
     */
    protected function license(RdfGraph $graph, $namespace)
    {
        $properties = ['dcterms:license', 'dc11:rights', 'dcterms:rights', '<http://creativecommons.org/ns#license>', '<http://www.w3.org/1999/xhtml/vocab#license>'];
        foreach ($this->describingOntologies($graph, $namespace) as $ontology) {
            foreach ($properties as $property) {
                $value = trim(PlainText::from($ontology->get($property)));
                if ($value !== '' && mb_check_encoding($value, 'UTF-8')) {
                    return $value;
                }
            }
        }
        return null;
    }
}

/**
 * Exposes the importer's term selection, unchanged, for a graph parsed once.
 */
class CheckImporter extends RdfImporter
{
    public function members(RdfGraph $graph, $namespaceUri, $lang = null)
    {
        return [
            'classes' => $this->getMembersOfTypes($graph, $this->classTypes, $namespaceUri, ImportCheck::LABEL_PROPERTY, ImportCheck::COMMENT_PROPERTY, $lang),
            'properties' => $this->getMembersOfTypes($graph, $this->propertyTypes, $namespaceUri, ImportCheck::LABEL_PROPERTY, ImportCheck::COMMENT_PROPERTY, $lang),
        ];
    }

    public function acceptedTypes()
    {
        return array_merge($this->classTypes, $this->propertyTypes);
    }
}
