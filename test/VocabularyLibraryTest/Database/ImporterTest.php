<?php
namespace VocabularyLibraryTest\Database;

use VocabularyLibrary\Stdlib\Importer;
use VocabularyLibraryTest\DbTestCase;

/**
 * Reads and imports the tests' vocabulary through core's importer, in the
 * test database. Comparing and updating are core's review's.
 */
class ImporterTest extends DbTestCase
{
    protected $importer;
    protected $entry;

    public function setUp(): void
    {
        parent::setUp();
        $this->importer = new Importer(self::services()->get('Omeka\RdfImporter'), self::libraryDir());
        $this->entry = self::library()['ex'];
    }

    protected function import($lang)
    {
        return $this->importer->import($this->entry, ['o:prefix' => 'ex', 'o:label' => 'Example Vocabulary', 'o:comment' => 'A comment.'], $lang);
    }

    /**
     * The vocabulary as it now is in Omeka.
     */
    protected function reload($vocabulary)
    {
        self::services()->get('Omeka\EntityManager')->clear();
        return self::services()->get('Omeka\ApiManager')->read('vocabularies', $vocabulary->id())->getContent();
    }

    /**
     * A vocabulary's terms of one kind: local name => [label, comment].
     */
    protected static function terms(array $terms)
    {
        $byName = [];
        foreach ($terms as $term) {
            $byName[$term->localName()] = [$term->label(), $term->comment()];
        }
        ksort($byName);
        return $byName;
    }

    public function testMembersAreReadInTheLanguageAsked()
    {
        $english = $this->importer->members($this->entry, 'en');
        $this->assertSame(['Thing', 'Part'], array_keys($english['classes']));
        $this->assertSame('has part', (string) $english['properties']['hasPart']['label']);
        $german = $this->importer->members($this->entry, 'de');
        $this->assertSame('hat Teil', (string) $german['properties']['hasPart']['label']);
    }

    public function testImportingMakesTheVocabularyWithItsTerms()
    {
        $vocabulary = $this->reload($this->import('en'));
        $this->assertSame('http://example.org/ns#', $vocabulary->namespaceUri());
        $this->assertSame('ex', $vocabulary->prefix());
        $this->assertSame('Example Vocabulary', $vocabulary->label());
        $this->assertSame([
            'Part' => ['Part', null],
            // The definition, where a term has a definition and a comment.
            'Thing' => ['Thing', 'Anything at all.'],
        ], self::terms($vocabulary->resourceClasses()));
        $this->assertSame(['hasPart', 'isPartOf'], array_keys(self::terms($vocabulary->properties())));
        $this->assertSame(['has part', 'Relates a thing to a part of it.'], self::terms($vocabulary->properties())['hasPart']);
    }

    public function testImportingInAnotherLanguageTakesItsLabels()
    {
        $vocabulary = $this->reload($this->import('de'));
        $this->assertSame('Ding', self::terms($vocabulary->resourceClasses())['Thing'][0]);
        $this->assertSame('ist Teil von', self::terms($vocabulary->properties())['isPartOf'][0]);
    }

    public function testTheImportRecordsHowTheFileWasRead()
    {
        // Core keeps the reading, so its review of the library's file starts
        // on it, with the comments core names "Definition, else comment".
        $options = $this->reload($this->import('de'))->importOptions();
        $this->assertSame('de', $options['lang']);
        $this->assertSame(\Omeka\Stdlib\RdfImporter::DEFINITION_COMMENT_PROPERTY, $options['comment_property']);
        $this->assertSame(\Omeka\Stdlib\RdfImporter::LABEL_PROPERTY, $options['label_property']);
    }
}
