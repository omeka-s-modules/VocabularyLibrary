<?php
namespace VocabularyLibraryTest\Script;

class AddRemoveTest extends ScriptTestCase
{
    const OPTIONS = ['--tags=general', '--summary=Classes for describing other things.'];

    /**
     * The submitted file declares no namespace of its own, so it is given.
     */
    const NAMESPACE_OPTION = '--namespace=http://example.org/other#';

    /**
     * A second vocabulary's file, outside the collection.
     */
    protected function file()
    {
        $path = "$this->dir/submitted.ttl";
        file_put_contents($path, str_replace('http://example.org/ns#', 'http://example.org/other#', file_get_contents(self::fixture('no-metadata.ttl'))));
        return $path;
    }

    public function testAFileSentToTheLibraryIsAddedWithWhoSentIt()
    {
        [$status, $output] = $this->script('add', array_merge(self::OPTIONS, [
            '--file=' . $this->file(), '--submitted-by=A. Potter', self::NAMESPACE_OPTION, '--prefix=other', '--alt-title=Other Things', '--alt-title-source=the submitter',
        ]));
        $this->assertSame(0, $status, $output);
        $this->assertSame([
            'alt_title' => 'Other Things',
            'alt_title_source' => 'the submitter',
            'namespace' => 'http://example.org/other#',
            'prefix' => 'other',
            'submitted_by' => 'A. Potter',
            'submitted_on' => gmdate('Y-m-d'),
            'summary' => 'Classes for describing other things.',
            'summary_basis' => ['file'],
            'tags' => ['general'],
        ], $this->read('scripts/sources.json')['other']);
        $this->assertFileExists("$this->dir/data/vocabs/other.ttl");
        $built = $this->read('data/vocabularies.json');
        $this->assertSame(['ex', 'other'], array_keys($built));
        $this->assertSame(2, $built['other']['classes']);
    }

    public function testAnArchivedCopyIsAddedWithItsPublishersAddress()
    {
        [$status, $output] = $this->script('add', array_merge(self::OPTIONS, [
            'http://example.org/other', '--file=' . $this->file(), '--copy-from=LOV', self::NAMESPACE_OPTION, '--prefix=other', '--alt-title=Other Things', '--alt-title-source=LOV',
        ]));
        $this->assertSame(0, $status, $output);
        $entry = $this->read('scripts/sources.json')['other'];
        $this->assertSame('http://example.org/other', $entry['download']);
        $this->assertSame('LOV', $entry['copy_from']);
        $this->assertArrayNotHasKey('submitted_by', $entry);
    }

    /**
     * @dataProvider refusedArguments
     */
    public function testAddRefusesWhatItCannotAdd(array $arguments, $message)
    {
        $before = $this->read('scripts/sources.json');
        $arguments = array_map(function ($argument) {
            return str_replace('{file}', $this->file(), $argument);
        }, $arguments);
        [$status, $output] = $this->script('add', $arguments);
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString($message, $output);
        $this->assertSame($before, $this->read('scripts/sources.json'));
        $this->assertSame(["$this->dir/data/vocabs/ex.ttl"], glob("$this->dir/data/vocabs/*"));
    }

    public function refusedArguments()
    {
        $options = self::OPTIONS;
        return [
            'no address and no file' => [$options, 'Without a <url>'],
            'a file with no word on who sent it' => [array_merge($options, ['--file={file}']), 'Without a <url>'],
            'a submitter with an address' => [array_merge($options, ['http://example.org/other', '--submitted-by=A. Potter']), '--submitted-by is for a file with no <url>'],
            'an unknown option' => [array_merge($options, ['--file={file}', '--submitted-by=A. Potter', '--slugg=x']), 'Unknown option: --slugg=x'],
            'an unknown tag' => [['--tags=nonsense', '--summary=Classes.', '--file={file}', '--submitted-by=A. Potter'], 'Give one to three tags'],
            'no summary' => [['--tags=general', '--file={file}', '--submitted-by=A. Potter'], 'one-sentence summary'],
            'a file that does not say its namespace' => [array_merge($options, ['--file={file}', '--submitted-by=A. Potter']), "the file's terms use http://example.org/other#"],
            'a prefix already used' => [array_merge($options, ['--file={file}', '--submitted-by=A. Potter', self::NAMESPACE_OPTION, '--prefix=ex', '--alt-title=Other', '--alt-title-source=x']), 'The prefix "ex" is already used by "ex"'],
            'a file with no title and no name given' => [array_merge($options, ['--file={file}', '--submitted-by=A. Potter', self::NAMESPACE_OPTION, '--prefix=other']), 'The file has no title'],
        ];
    }

    public function testAVocabularyAlreadyInTheCollectionIsRefusedInAnyForm()
    {
        $path = "$this->dir/again.ttl";
        copy(self::fixture('basic.ttl'), $path);
        [$status, $output] = $this->script('add', array_merge(self::OPTIONS, ["--file=$path", '--submitted-by=A. Potter', '--namespace=https://example.org/ns/', '--prefix=again']));
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('is already in the collection as "ex"', $output);
    }

    public function testRemovingAVocabularyKeepsItsSlugAndNamespaceOut()
    {
        $this->script('build');
        [$status, $output] = $this->script('remove', ['ex', 'taken down at the publisher\'s request']);
        $this->assertSame(0, $status, $output);
        $this->assertSame([], $this->read('scripts/sources.json'));
        $this->assertSame([], glob("$this->dir/data/vocabs/*"));
        $this->assertSame([[
            'download' => 'http://example.org/ns',
            'excluded' => 'taken down at the publisher\'s request',
            'namespace' => 'http://example.org/ns#',
            'prefix' => 'ex',
            'slug' => 'ex',
            'title' => 'Example Vocabulary',
        ]], $this->read('scripts/excluded.json'));

        // It can't be added again while it is excluded.
        $path = "$this->dir/again.ttl";
        copy(self::fixture('basic.ttl'), $path);
        [$status, $output] = $this->script('add', array_merge(self::OPTIONS, ["--file=$path", '--submitted-by=A. Potter']));
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('is in scripts/excluded.json as "ex": taken down', $output);
    }

    public function testRemovingASubmittedVocabularyKeepsWhoSentIt()
    {
        $entry = ['submitted_by' => 'A. Potter', 'submitted_on' => '2026-11-12'] + array_diff_key(self::ENTRY, ['download' => 1]);
        $this->writeSources(['ex' => $entry]);
        // Built first, for the title it is recorded by.
        $this->script('build');
        [$status, $output] = $this->script('remove', ['ex', 'taken down']);
        $this->assertSame(0, $status, $output);
        $this->assertSame([[
            'excluded' => 'taken down',
            'namespace' => 'http://example.org/ns#',
            'prefix' => 'ex',
            'slug' => 'ex',
            'submitted_by' => 'A. Potter',
            'submitted_on' => '2026-11-12',
            'title' => 'Example Vocabulary',
        ]], $this->read('scripts/excluded.json'));
    }

    public function testASubmittedPageIsNotToldToUseAFormatItCannot()
    {
        $path = "$this->dir/page.html";
        file_put_contents($path, '<!DOCTYPE html><html><body></body></html>');
        [$status, $output] = $this->script('add', array_merge(self::OPTIONS, ["--file=$path", '--submitted-by=A. Potter']));
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('is HTML', $output);
        $this->assertStringNotContainsString('--format', $output);
    }

    public function testRemoveNeedsItsReasonInOneArgument()
    {
        [$status, $output] = $this->script('remove', ['ex', 'taken', 'down']);
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('Usage: php scripts/remove.php', $output);
        $this->assertArrayHasKey('ex', $this->read('scripts/sources.json'));

        [$status, $output] = $this->script('remove', ['nothing', 'a reason']);
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('Unknown slug: nothing', $output);
    }

    /**
     * Point the collection's vocabulary at a file to download, in place of
     * a publisher's server.
     */
    protected function publish($content)
    {
        file_put_contents("$this->dir/published.ttl", $content);
        $this->writeSources(['ex' => ['download' => "file://$this->dir/published.ttl"] + self::ENTRY]);
    }

    public function testFetchSaysHowAChangedVocabularysTermsDiffer()
    {
        $file = file_get_contents(self::fixture('basic.ttl'));
        $this->publish(str_replace('"has part"@en', '"has a part"@en', $file) . "\nex:Extra a rdfs:Class .\n");
        [$status, $output] = $this->script('fetch');
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('1 fetched: 1 changed, 0 unchanged, 0 failed', $output);
        $this->assertStringContainsString('changed: ex (1 added; 1 labels)', $output);
        $this->assertStringContainsString("to list in the release notes:\n  ex\n", $output);
        $this->assertStringContainsString("so check their summaries:\n  ex\n", $output);
        $this->assertStringContainsString('ex:Extra', file_get_contents("$this->dir/data/vocabs/ex.ttl"));
    }

    public function testFetchSaysWhenOnlyTheFileChanged()
    {
        $this->publish(file_get_contents(self::fixture('basic.ttl')) . "\n# Saved again.\n");
        [$status, $output] = $this->script('fetch');
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('changed: ex (file only)', $output);
        $this->assertStringNotContainsString('release notes', $output);
        $this->assertStringNotContainsString('summaries', $output);
    }

    public function testFetchSaysWhenALabelChangedInAnotherLanguage()
    {
        $this->publish(str_replace('"Ding"@de', '"Sache"@de', file_get_contents(self::fixture('basic.ttl'))));
        [, $output] = $this->script('fetch');
        $this->assertStringContainsString('changed: ex (1 labels)', $output);
    }

    public function testFetchSaysWhenOnlyWhatTheFileSaysOfTheVocabularyChanged()
    {
        $this->publish(str_replace('For describing things.', 'For describing all things.', file_get_contents(self::fixture('basic.ttl'))));
        [, $output] = $this->script('fetch');
        $this->assertStringContainsString('changed: ex (description)', $output);
        // Nothing for administrators to compare, but the summary to check.
        $this->assertStringNotContainsString('release notes', $output);
        $this->assertStringContainsString("so check their summaries:\n  ex\n", $output);
    }

    public function testFetchDoesNotCallAReplacementANewFile()
    {
        file_put_contents("$this->dir/data/vocabs/ex.ttl", 'this is not RDF {{{');
        $this->publish(file_get_contents(self::fixture('basic.ttl')));
        [, $output] = $this->script('fetch');
        $this->assertStringContainsString('changed: ex (differences unknown)', $output);
    }

    public function testFetchLeavesAnUnchangedFileAlone()
    {
        $this->publish(file_get_contents(self::fixture('basic.ttl')));
        [, $output] = $this->script('fetch');
        $this->assertStringContainsString('0 changed, 1 unchanged, 0 failed', $output);
    }

    public function testFetchKeepsTheStoredFileWhenTheAddressServesSomethingUnusable()
    {
        $this->publish('<!DOCTYPE html><html><body>Moved.</body></html>');
        [$status, $output] = $this->script('fetch');
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('failed: ex (no longer usable, needs a look)', $output);
        $this->assertFileEquals(self::fixture('basic.ttl'), "$this->dir/data/vocabs/ex.ttl");
    }

    public function testFetchKeepsTheStoredFileWhenTheServerIsUnavailable()
    {
        // Nothing listens on this port, so the connection is refused.
        $this->writeSources(['ex' => ['download' => 'http://127.0.0.1:1/ex.ttl'] + self::ENTRY]);
        // It is tried a second time, here without the wait.
        [$status, $output] = $this->script('fetch', ['--retry-wait=0']);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('failed: ex (server unavailable, stored file kept)', $output);
        $this->assertFileEquals(self::fixture('basic.ttl'), "$this->dir/data/vocabs/ex.ttl");
    }

    public function testAnAddressThatServesNothingNeedsALook()
    {
        $this->writeSources(['ex' => ['download' => "file://$this->dir/nothing-here.ttl"] + self::ENTRY]);
        [$status, $output] = $this->script('fetch', ['--retry-wait=0']);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('failed: ex (no longer usable, needs a look)', $output);
    }

    public function testFetchRefusesAnOptionItDoesNotKnow()
    {
        foreach (['--retry-wait=soon', '--retry-wait', '--retries=2'] as $option) {
            [$status, $output] = $this->script('fetch', [$option]);
            $this->assertSame(1, $status, $output);
            $this->assertStringContainsString("Unknown option: $option", $output);
        }
    }

    public function testFetchHasNothingToDownloadForASubmittedFile()
    {
        $entry = ['submitted_by' => 'A. Potter', 'submitted_on' => '2026-11-12'] + array_diff_key(self::ENTRY, ['download' => 1]);
        $this->writeSources(['ex' => $entry]);
        [$status, $output] = $this->script('fetch');
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('0 fetched', $output);
        $this->assertStringContainsString('1 submitted files skipped', $output);
    }
}
