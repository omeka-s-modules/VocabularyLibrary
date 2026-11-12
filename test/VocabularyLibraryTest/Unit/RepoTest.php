<?php
namespace VocabularyLibraryTest\Unit;

use RuntimeException;
use VocabularyLibrary\Scripts\Repo;
use VocabularyLibraryTest\TempDirTestCase;

class RepoTest extends TempDirTestCase
{
    protected function repo()
    {
        return new Repo($this->dir . '/');
    }

    public function testJsonIsWrittenWithSortedKeysAndReadBack()
    {
        $repo = $this->repo();
        $repo->writeJson('scripts/sources.json', ['b' => ['z' => 1, 'a' => ['y', 'x']], 'a' => 'http://example.org/é']);
        $this->assertSame(
            "{\n    \"a\": \"http://example.org/é\",\n    \"b\": {\n        \"a\": [\n            \"y\",\n            \"x\"\n        ],\n        \"z\": 1\n    }\n}\n",
            file_get_contents($this->dir . '/scripts/sources.json')
        );
        $this->assertSame(['a' => 'http://example.org/é', 'b' => ['a' => ['y', 'x'], 'z' => 1]], $repo->readJson('scripts/sources.json'));
    }

    public function testAMissingFileReadsAsNoneAndTheCollectionAsEmpty()
    {
        $this->assertNull($this->repo()->readJson('scripts/sources.json'));
        $this->assertSame([], $this->repo()->sources());
        $this->assertSame([], $this->repo()->excluded());
    }

    public function testAFileThatIsNotJsonThrowsRatherThanReadingAsEmpty()
    {
        mkdir($this->dir . '/scripts');
        file_put_contents($this->dir . '/scripts/sources.json', '{"a": 1,}');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('scripts/sources.json is not valid JSON');
        $this->repo()->sources();
    }

    public function testStoringAFileReplacesTheOneWithAnotherExtension()
    {
        $repo = $this->repo();
        $this->assertSame('vocabs/ex.rdf', $repo->storeFile('ex', 'old', 'rdf'));
        $repo->storeFile('example', 'other', 'ttl');
        $this->assertSame('vocabs/ex.ttl', $repo->storeFile('ex', 'new', 'ttl'));
        $this->assertSame([$this->dir . '/data/vocabs/ex.ttl'], $repo->files('ex'));
        $this->assertSame('new', file_get_contents($this->dir . '/data/vocabs/ex.ttl'));
        $this->assertSame([], $repo->files('none'));
    }

    public function testExcludedVocabulariesAreIndexedBySlugAndByNamespace()
    {
        $repo = $this->repo();
        $record = ['slug' => 'ex', 'namespace' => 'http://Example.org/ns#', 'excluded' => 'taken down'];
        $repo->writeExcluded([['slug' => 'zz', 'namespace' => 'http://example.org/zz/', 'excluded' => 'no terms'], $record]);
        $this->assertSame(['ex', 'zz'], array_column($repo->excluded(), 'slug'));
        [$bySlug, $byNamespace] = $repo->excludedIndex();
        // Records are written with their keys in order.
        ksort($record);
        $this->assertSame($record, $bySlug['ex']);
        $this->assertSame($record, $byNamespace['example.org/ns']);
    }

    public function testTheTermIndexHasOneVocabularyPerLine()
    {
        $repo = $this->repo();
        mkdir($this->dir . '/data');
        $repo->writeTermIndex(['b' => ['Thing', ['hasPart', 'has part']], 'a' => ['Été']]);
        $this->assertSame(
            "{\n\"a\":[\"Été\"],\n\"b\":[\"Thing\",[\"hasPart\",\"has part\"]]\n}\n",
            file_get_contents($this->dir . '/data/term-index.json')
        );
    }
}
