<?php
namespace VocabularyLibrary\Scripts;

/**
 * Reads and writes the collection's files.
 *
 * scripts/sources.json     one entry per slug: the curated fields
 * scripts/excluded.json    vocabularies left out or removed, and why
 * data/vocabs/<slug>.<ext> the file to import
 * data/vocabularies.json   one entry per slug: what the module reads, written
 *                          by build.php from the sources and the files
 * data/term-index.json     every importable term, for term search, written by
 *                          build.php
 */
class Repo
{
    /**
     * Where a vocabulary's file came from, when it is not the publisher's.
     */
    const COPY_SOURCES = ['LOV', 'DBpedia Archivo', 'Open Metadata Registry', 'Ontology Design Patterns'];

    /**
     * Slugs appear in the module's URLs, so they are plain and never change.
     */
    const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    /**
     * Each vocabulary has one to this many tags.
     */
    const MAX_TAGS = 3;

    /**
     * Prefixes Omeka reserves (VocabularyAdapter::$reservedPrefixes).
     */
    const RESERVED_PREFIX = '/^o(-|$)/';

    protected $root;

    public function __construct($root)
    {
        $this->root = rtrim($root, '/');
    }

    /**
     * A namespace without its scheme, case or trailing #//, for spotting the
     * same namespace written two ways.
     */
    public static function normalizedNamespace($namespace)
    {
        return rtrim(preg_replace('#^https?://#i', '', mb_strtolower((string) $namespace)), '#/');
    }

    public function path($relative = '')
    {
        return $this->root . ($relative === '' ? '' : '/' . $relative);
    }

    /**
     * @return array Curated entries keyed by slug
     */
    public function sources()
    {
        return $this->readJson('scripts/sources.json') ?: [];
    }

    public function writeSources(array $sources)
    {
        $this->writeJson('scripts/sources.json', $sources);
    }

    /**
     * @return array The module's entries keyed by slug, as build.php last
     *   wrote them
     */
    public function vocabularies()
    {
        return $this->readJson('data/vocabularies.json') ?: [];
    }

    public function writeVocabularies(array $vocabularies)
    {
        $this->writeJson('data/vocabularies.json', $vocabularies);
    }

    /**
     * @return array Excluded vocabularies, each with at least "slug" and
     *   "excluded" (the reason)
     */
    public function excluded()
    {
        return $this->readJson('scripts/excluded.json') ?: [];
    }

    public function writeExcluded(array $excluded)
    {
        usort($excluded, function ($a, $b) {
            return strcmp($a['slug'], $b['slug']);
        });
        $this->writeJson('scripts/excluded.json', $excluded);
    }

    /**
     * @return string[] Paths of the files stored for a slug; exactly one when
     *   the collection is consistent
     */
    public function files($slug)
    {
        return glob($this->path("data/vocabs/$slug.*")) ?: [];
    }

    /**
     * Stores a vocabulary's file, removing any it replaces.
     *
     * @return string The path relative to data/
     */
    public function storeFile($slug, $data, $extension)
    {
        foreach ($this->files($slug) as $old) {
            unlink($old);
        }
        $relative = "vocabs/$slug.$extension";
        if (!is_dir($this->path('data/vocabs'))) {
            mkdir($this->path('data/vocabs'), 0777, true);
        }
        file_put_contents($this->path("data/$relative"), $data);
        return $relative;
    }

    /**
     * @return array Tags from the module's config, key => label
     */
    public function tags()
    {
        $config = include $this->path('config/module.config.php');
        return $config['vocabulary_library']['tags'] ?? [];
    }

    /**
     * Writes the term index one vocabulary per line, so a rebuild's diff
     * shows which vocabularies' terms changed.
     *
     * @param array $index Per slug, a list of local names, or of [local name,
     *   label] pairs where the label differs from the local name
     */
    public function writeTermIndex(array $index)
    {
        ksort($index);
        $lines = [];
        foreach ($index as $slug => $terms) {
            $lines[] = json_encode((string) $slug, JSON_THROW_ON_ERROR) . ':' . json_encode($terms, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        file_put_contents($this->path('data/term-index.json'), "{\n" . implode(",\n", $lines) . "\n}\n");
    }

    public function readJson($relative)
    {
        $path = $this->path($relative);
        return is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }

    /**
     * Writes pretty-printed JSON with sorted keys, so diffs stay one line per
     * change.
     */
    public function writeJson($relative, $data)
    {
        $path = $this->path($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        // Throws rather than writing an empty file if the data can't be encoded.
        $json = json_encode(self::sortKeys($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        file_put_contents($path, $json . "\n");
    }

    protected static function sortKeys($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        if ($data && array_keys($data) !== range(0, count($data) - 1)) {
            ksort($data);
        }
        return array_map([self::class, 'sortKeys'], $data);
    }
}
