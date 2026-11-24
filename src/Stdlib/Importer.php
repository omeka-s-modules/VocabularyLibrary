<?php
namespace VocabularyLibrary\Stdlib;

use Omeka\Stdlib\RdfImporter;

/**
 * Reads the library's files with core's importer, and knows where they are.
 * Core's import details imports them (VocabularyImport), and core's review
 * compares and updates them (VocabularyReview). Every read of a file's terms
 * goes through here, so this is where its options are set. Core raises PHP's
 * memory limit for a large file.
 */
class Importer
{
    /**
     * The format the library's files are read in, and handed to core in:
     * the one EasyRdf guesses, where core's importer then tries each format
     * in turn if the guess fails or reads nothing. The build reads every
     * file the same way, so each one the library has reads.
     */
    const FORMAT = 'guess';

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
     * The path to an entry's file.
     */
    public function path(array $entry)
    {
        return $this->dataDir . '/' . $entry['file'];
    }

    /**
     * Options for core's importer to read an entry's file.
     */
    protected function options(array $entry, $lang)
    {
        return [
            'file' => $this->path($entry),
            'format' => self::FORMAT,
            'lang' => $lang,
            'label_property' => TermReading::LABEL_PROPERTY,
            'comment_property' => TermReading::COMMENT_PROPERTY,
        ];
    }
}
