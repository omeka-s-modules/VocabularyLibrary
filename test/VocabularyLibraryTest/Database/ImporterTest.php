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
    protected $temporary = [];

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

    public function testCommentsAreReadTheWayTheVocabularyWasImported()
    {
        // As the library imports: the definition matches, so no change.
        $this->import('en');
        [$reading, $diff] = $this->importer->diffAsImported($this->entry, 'en');
        $this->assertSame('definitions', $reading);
        $this->assertSame([], $diff['classes']['comment']);
    }

    public function testCommentsImportedAsOmekaDoesAreReadThatWay()
    {
        $vocabulary = $this->import('en');
        $this->importer->update($vocabulary->id(), $this->importer->diff($this->entry, 'en', TermReading::OMEKA_COMMENT_PROPERTY));
        self::services()->get('Omeka\EntityManager')->clear();
        [$reading, $diff] = $this->importer->diffAsImported($this->entry, 'en');
        $this->assertSame('comments', $reading);
        $this->assertSame([], $diff['classes']['comment']);
    }

    public function testTheReadingAskedForIsUsed()
    {
        $this->import('en');
        [$reading, $diff] = $this->importer->diffAsImported($this->entry, 'en', 'comments');
        $this->assertSame('comments', $reading);
        $this->assertSame(['Thing'], array_keys($diff['classes']['comment']));
        // Anything else is no choice.
        $this->assertSame('definitions', $this->importer->diffAsImported($this->entry, 'en', 'nonsense')[0]);
        $this->assertSame('definitions', $this->importer->diffAsImported($this->entry, 'en', ['comments'])[0]);
    }

    public function testThereIsNoReadingToChooseWhereBothReadTheSame()
    {
        $entry = ['file' => 'vocabs/same.ttl', 'namespace' => 'http://example.org/same#', 'format' => null, 'languages' => []];
        $importer = new Importer(self::services()->get('Omeka\RdfImporter'), $this->dir());
        $importer->import($entry, ['o:prefix' => 'same', 'o:label' => 'Same'], null);
        $this->assertNull($importer->diffAsImported($entry, null)[0]);
    }

    /**
     * A library directory with a vocabulary whose terms have no definitions.
     */
    protected function dir()
    {
        $dir = sys_get_temp_dir() . '/vocabulary-library-test-' . bin2hex(random_bytes(6));
        mkdir("$dir/vocabs", 0777, true);
        file_put_contents("$dir/vocabs/same.ttl", "@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .\n<http://example.org/same#Thing> a rdfs:Class ; rdfs:label \"Thing\" ; rdfs:comment \"A comment.\" .\n");
        $this->temporary[] = $dir;
        return $dir;
    }

    public function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->temporary as $dir) {
            unlink("$dir/vocabs/same.ttl");
            rmdir("$dir/vocabs");
            rmdir($dir);
        }
        $this->temporary = [];
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
        // come first in the file, which are the English ones, so English
        // and no language match equally.
        $vocabulary = $this->reload($this->import(null));
        $this->assertSame('Thing', self::terms($vocabulary->resourceClasses())['Thing'][0]);
        $this->assertSame('en', $this->importer->matchingLanguage($this->entry, $vocabulary));
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
