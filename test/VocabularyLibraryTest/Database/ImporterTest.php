<?php
namespace VocabularyLibraryTest\Database;

use VocabularyLibrary\Stdlib\Importer;
use VocabularyLibrary\Stdlib\TermReading;
use VocabularyLibraryTest\DbTestCase;

/**
 * Imports, compares and updates the tests' vocabulary through core's
 * importer, in the test database.
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

    public function testAVocabularyJustImportedDoesNotDifferFromItsFile()
    {
        $this->import('en');
        $diff = $this->importer->diff($this->entry, 'en', TermReading::COMMENT_PROPERTY);
        foreach (['classes', 'properties'] as $kind) {
            $this->assertSame([], $diff[$kind]['new'], $kind);
            $this->assertSame([], $diff[$kind]['label'], $kind);
            // The empty definition of isPartOf, where Omeka has no comment,
            // is not a change.
            $this->assertSame([], $diff[$kind]['comment'], $kind);
        }
    }

    public function testReadAsOmekaReadsCommentsADefinitionShowsAsChanged()
    {
        $this->import('en');
        $diff = $this->importer->diff($this->entry, 'en', TermReading::OMEKA_COMMENT_PROPERTY);
        $this->assertSame(['Thing' => ['Anything at all.', 'A note about things.']], array_map(function ($change) {
            return array_map('strval', $change);
        }, $diff['classes']['comment']));
        $this->assertSame([], $diff['properties']['comment']);
    }

    public function testAnotherLanguageShowsItsLabelsAsChanges()
    {
        $this->import('en');
        $diff = $this->importer->diff($this->entry, 'de', TermReading::COMMENT_PROPERTY);
        $this->assertSame(['Part', 'Thing'], self::sorted(array_keys($diff['classes']['label'])));
        $this->assertSame(['Thing', 'Ding'], array_map('strval', $diff['classes']['label']['Thing']));
    }

    public function testUpdatingAppliesTheDifference()
    {
        $vocabulary = $this->import('en');
        $this->importer->update($vocabulary->id(), $this->importer->diff($this->entry, 'de', TermReading::COMMENT_PROPERTY));
        $vocabulary = $this->reload($vocabulary);
        $this->assertSame('Ding', self::terms($vocabulary->resourceClasses())['Thing'][0]);
        $this->assertSame('hat Teil', self::terms($vocabulary->properties())['hasPart'][0]);
    }

    public function testTheLanguageAVocabularyWasImportedInIsFound()
    {
        $this->assertSame('de', $this->importer->matchingLanguage($this->entry, $this->reload($this->import('de'))));
    }

    public function testTheLibrarysDefaultLanguageWinsATie()
    {
        // Imported with no preferred language, the labels are whichever
        // come first in the file, which are the English ones.
        $this->assertSame('en', $this->importer->matchingLanguage($this->entry, $this->reload($this->import('en'))));
    }

    public function testTermsOmekaHasAndTheFileDoesNotAreMissingOnesAndAreKept()
    {
        $vocabulary = $this->import('en');
        self::services()->get('Omeka\ApiManager')->create('resource_classes', [
            'o:vocabulary' => ['o:id' => $vocabulary->id()],
            'o:local_name' => 'Extra',
            'o:label' => 'An extra class',
        ]);
        $vocabulary = $this->reload($vocabulary);
        $this->assertSame(
            ['classes' => ['Extra' => 'An extra class'], 'properties' => []],
            $this->importer->missingTerms($this->entry, $vocabulary, 'en')
        );
        $this->importer->update($vocabulary->id(), $this->importer->diff($this->entry, 'de', TermReading::COMMENT_PROPERTY));
        $this->assertArrayHasKey('Extra', self::terms($this->reload($vocabulary)->resourceClasses()));
    }

    protected static function sorted(array $values)
    {
        sort($values);
        return $values;
    }
}
