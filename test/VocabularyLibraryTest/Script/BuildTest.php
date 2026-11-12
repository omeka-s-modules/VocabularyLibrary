<?php
namespace VocabularyLibraryTest\Script;

class BuildTest extends ScriptTestCase
{
    public function testTheBuildWritesWhatTheModuleReads()
    {
        [$status, $output] = $this->script('build');
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('1 vocabularies, 2 terms', $output);
        $this->assertSame(['ex' => [
            'alt_title' => null,
            'classes' => 1,
            'description' => "For describing things.\n\nSecond paragraph.",
            'file' => 'vocabs/ex.ttl',
            'format' => null,
            'languages' => ['de', 'en'],
            'license' => 'https://creativecommons.org/licenses/by/4.0/',
            'namespace' => 'http://example.org/ns#',
            'prefix' => 'ex',
            'properties' => 1,
            'summary' => self::ENTRY['summary'],
            'tags' => ['general'],
            'title' => 'Example Vocabulary',
        ]], $this->read('data/vocabularies.json'));
        // A label that is the local name is written once.
        $this->assertSame(['ex' => ['Thing', ['hasPart', 'has part']]], $this->read('data/term-index.json'));
    }

    public function testTheDatabaseTestsLibraryIsWhatTheBuildWrites()
    {
        // test/fixtures/library is kept as built, so those tests read what
        // the module would.
        $library = dirname(self::fixture('library/data'));
        copy("$library/scripts/sources.json", "$this->dir/scripts/sources.json");
        copy("$library/data/vocabs/ex.ttl", "$this->dir/data/vocabs/ex.ttl");
        [$status, $output] = $this->script('build');
        $this->assertSame(0, $status, $output);
        foreach (['vocabularies.json', 'term-index.json'] as $file) {
            $this->assertFileEquals("$library/data/$file", "$this->dir/data/$file", "$file: run the build on test/fixtures/library again");
        }
    }

    /**
     * @dataProvider brokenEntries
     */
    public function testAnEntryThatBreaksARuleStopsTheBuild(array $change, $problem)
    {
        $entry = array_diff_key(array_merge(self::ENTRY, $change), array_filter($change, 'is_null'));
        $this->writeSources(['ex' => $entry]);
        [$status, $output] = $this->script('build');
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('Nothing written.', $output);
        $this->assertStringContainsString($problem, $output);
        $this->assertNull($this->read('data/vocabularies.json'));
    }

    public function brokenEntries()
    {
        return [
            'no summary' => [['summary' => null], 'ex: no summary'],
            'summary without the word' => [['summary' => 'Classes for describing things.'], "the vocabulary has properties, but the summary doesn't mention them"],
            'no summary basis' => [['summary_basis' => null], 'ex: no summary_basis'],
            'no tags' => [['tags' => []], 'ex: needs 1 to 3 tags'],
            'unknown tag' => [['tags' => ['nonsense']], 'ex: unknown tag "nonsense"'],
            'reserved prefix' => [['prefix' => 'o-ex'], 'ex: prefix "o-ex" is reserved by Omeka'],
            'capitals in prefix' => [['prefix' => 'Ex'], 'ex: prefix "Ex" has capitals'],
            'no address and no submitter' => [['download' => null], 'ex: no download, or submitted_by'],
            'submitter without a date' => [['download' => null, 'submitted_by' => 'A. Potter'], 'ex: submitted_by needs submitted_on'],
            'a date without a submitter' => [['submitted_on' => '2026-11-12'], 'ex: submitted_on needs submitted_by'],
            'an address and a submitter' => [['submitted_by' => 'A. Potter', 'submitted_on' => '2026-11-12'], 'ex: has both download and submitted_by'],
            'a title of its own' => [['title' => 'My title'], 'ex: "title" is the file\'s'],
            'wrong namespace' => [['namespace' => 'http://example.org/unrelated/'], 'ex: the namespace imports nothing'],
        ];
    }

    public function testASubmittedFileNeedsNoAddress()
    {
        $entry = ['submitted_by' => 'A. Potter', 'submitted_on' => '2026-11-12'] + array_diff_key(self::ENTRY, ['download' => 1]);
        $this->writeSources(['ex' => $entry]);
        [$status, $output] = $this->script('build');
        $this->assertSame(0, $status, $output);
    }

    public function testAFileWithNoEntryStopsTheBuild()
    {
        copy(self::fixture('no-metadata.ttl'), "$this->dir/data/vocabs/stray.ttl");
        [$status, $output] = $this->script('build');
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('data/vocabs/stray.ttl: no entry in scripts/sources.json', $output);
    }

    public function testAnEntryWithNoFileStopsTheBuild()
    {
        unlink("$this->dir/data/vocabs/ex.ttl");
        [$status, $output] = $this->script('build');
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('ex: 0 files in data/vocabs, expected 1', $output);
    }

    public function testAStoredFileThatWouldRunOnTheSiteStopsTheBuild()
    {
        file_put_contents("$this->dir/data/vocabs/ex.ttl", '<!DOCTYPE html><html><body></body></html>');
        [, $output] = $this->script('build');
        $this->assertStringContainsString('ex: the stored file is HTML', $output);

        unlink("$this->dir/data/vocabs/ex.ttl");
        file_put_contents("$this->dir/data/vocabs/ex.rdf", '<?xml version="1.0"?><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:rdfs="http://www.w3.org/2000/01/rdf-schema#"><rdfs:Class rdf:about="http://example.org/ns#Thing"><rdfs:comment rdf:parseType="Literal"><script xmlns="http://www.w3.org/1999/xhtml">alert(1)</script></rdfs:comment></rdfs:Class></rdf:RDF>');
        [, $output] = $this->script('build');
        $this->assertStringContainsString('ex: the stored file carries markup a browser would run', $output);

        unlink("$this->dir/data/vocabs/ex.rdf");
        file_put_contents("$this->dir/data/vocabs/ex.jsonld", '{"@context": "http://example.org/context.jsonld", "@id": "http://example.org/ns#Thing", "@type": "http://www.w3.org/2000/01/rdf-schema#Class"}');
        [, $output] = $this->script('build');
        $this->assertStringContainsString('ex: the stored file takes its JSON-LD context from an address', $output);
    }

    public function testAnEmptyStoredFileIsReportedAsAProblemOfTheFile()
    {
        file_put_contents("$this->dir/data/vocabs/ex.ttl", '');
        [$status, $output] = $this->script('build');
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString("Nothing written. Problems:\n  ex: ", $output);
        $this->assertStringNotContainsString('Warning', $output);
    }

    public function testAnExcludedVocabularyStaysOutUnderAnySlug()
    {
        file_put_contents("$this->dir/scripts/excluded.json", json_encode([
            ['slug' => 'gone', 'namespace' => 'https://example.org/ns/', 'excluded' => 'taken down'],
        ]));
        [$status, $output] = $this->script('build');
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('ex: the namespace is in scripts/excluded.json as "gone"', $output);
    }
}
