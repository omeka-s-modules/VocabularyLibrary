<?php
namespace VocabularyLibrary\Stdlib;

use Omeka\Api\Representation\VocabularyRepresentation;
use Omeka\Stdlib\RdfImporter;

/**
 * Reads and imports the library's files with core's importer, and knows
 * where they are. Every read of a file's terms goes through here, so this is
 * where its options are set. Comparing and updating are core's review's
 * (VocabularyReview), and core raises PHP's memory limit for a large file.
 */
class Importer
{
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
     * @return array ['classes' => [local name => ['label' => …, 'comment' =>
     *   …], …], 'properties' => […]]
     */
    public function members(array $entry, $lang)
    {
        return $this->rdfImporter->getMembers('file', $entry['namespace'], $this->options($entry, $lang));
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
    protected function options(array $entry, $lang)
    {
        return [
            'file' => $this->path($entry),
            'format' => $entry['format'] ?? 'guess',
            'lang' => $lang,
            'label_property' => TermReading::LABEL_PROPERTY,
            'comment_property' => TermReading::COMMENT_PROPERTY,
        ];
    }
}
