<?php
namespace VocabularyLibraryTest\Database;

use VocabularyLibrary\Stdlib\Importer;
use VocabularyLibraryTest\DbTestCase;

/**
 * Reads the tests' vocabulary through core's importer. Importing is core's
 * import details', and reviewing changes core's review's.
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

    public function testMembersAreReadInTheLanguageAsked()
    {
        $english = $this->importer->members($this->entry, 'en');
        $this->assertSame(['Thing', 'Part'], array_keys($english['classes']));
        $this->assertSame('has part', (string) $english['properties']['hasPart']['label']);
        $german = $this->importer->members($this->entry, 'de');
        $this->assertSame('hat Teil', (string) $german['properties']['hasPart']['label']);
    }
}
