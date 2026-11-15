<?php
namespace VocabularyLibraryTest\Script;

use VocabularyLibrary\Scripts\Download;

/**
 * Runs fetch.php against a web server on this machine that fails the way a
 * publisher's can: test/fixtures/publisher.php, run with PHP's built-in
 * server.
 */
class FetchFromServerTest extends ScriptTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->startPublisher();
    }

    /**
     * Fetch the collection's vocabulary from a path on the server, which
     * serves the basic fixture with one more class.
     *
     * @return array [output, the paths the server was asked for]
     */
    protected function fetch($path)
    {
        file_put_contents("$this->dir/published.ttl", file_get_contents(self::fixture('basic.ttl')) . "\nex:Extra a rdfs:Class .\n");
        $this->writeSources(['ex' => ['download' => $this->address . $path] + self::ENTRY]);
        [$status, $output] = $this->script('fetch', ['--retry-wait=0']);
        $this->assertSame(0, $status, $output);
        return [$output, file("$this->dir/requests.log", FILE_IGNORE_NEW_LINES)];
    }

    protected function assertStoredFileKept()
    {
        $this->assertFileEquals(self::fixture('basic.ttl'), "$this->dir/data/vocabs/ex.ttl");
    }

    public function testAServerThatFailsOnceIsTriedAgainAndTheFileFetched()
    {
        [$output, $requests] = $this->fetch('/flaky.ttl');
        $this->assertSame(['/flaky.ttl', '/flaky.ttl'], $requests);
        $this->assertStringContainsString('1 fetched: 1 changed, 0 unchanged, 0 failed', $output);
        $this->assertStringContainsString('changed: ex (1 added)', $output);
        $this->assertStringContainsString('ex:Extra', file_get_contents("$this->dir/data/vocabs/ex.ttl"));
    }

    public function testABusyServerIsTriedAgainAfterTheWaitItNames()
    {
        [$output, $requests] = $this->fetch('/busy.ttl');
        $this->assertSame(['/busy.ttl', '/busy.ttl'], $requests);
        $this->assertStringContainsString('1 changed', $output);
    }

    public function testAServerThatKeepsFailingIsTriedTwiceAndTheStoredFileKept()
    {
        [$output, $requests] = $this->fetch('/down.ttl');
        $this->assertSame(['/down.ttl', '/down.ttl'], $requests);
        $this->assertStringContainsString('0 changed, 0 unchanged, 1 failed', $output);
        $this->assertStringContainsString('failed: ex (server unavailable, stored file kept)', $output);
        $this->assertStringContainsString('HTTP 503', $output);
        $this->assertStoredFileKept();
    }

    public function testAMissingFileIsNotTriedAgainAndNeedsALook()
    {
        [$output, $requests] = $this->fetch('/gone.ttl');
        $this->assertSame(['/gone.ttl'], $requests);
        $this->assertStringContainsString('failed: ex (no longer usable, needs a look)', $output);
        $this->assertStringContainsString('HTTP 404', $output);
        $this->assertStoredFileKept();
    }

    public function testAHostThatFailsTwiceIsNotAskedAgainInTheRun()
    {
        $entries = [];
        foreach (['a', 'b', 'c'] as $slug) {
            $entries[$slug] = ['download' => "$this->address/down/$slug.ttl", 'prefix' => $slug] + self::ENTRY;
            copy(self::fixture('basic.ttl'), "$this->dir/data/vocabs/$slug.ttl");
        }
        unlink("$this->dir/data/vocabs/ex.ttl");
        $this->writeSources($entries);
        [$status, $output] = $this->script('fetch', ['--retry-wait=0']);
        $this->assertSame(0, $status, $output);
        // The first is asked twice, and the others not at all.
        $this->assertSame(['/down/a.ttl', '/down/a.ttl'], file("$this->dir/requests.log", FILE_IGNORE_NEW_LINES));
        $this->assertStringContainsString('3 failed', $output);
        $this->assertSame(3, substr_count($output, '(server unavailable, stored file kept)'));
        $this->assertStringContainsString('its server failed twice earlier in this run', $output);
    }

    public function testAServerThatFailsBehindARedirectLeavesTheOthersBehindItAsked()
    {
        // One server under two names: localhost stands for purl.org, which
        // redirects, and 127.0.0.1 for the publishers it redirects to.
        $redirector = str_replace('127.0.0.1', 'localhost', $this->address);
        file_put_contents("$this->dir/published.ttl", file_get_contents(self::fixture('basic.ttl')) . "\nex:Extra a rdfs:Class .\n");
        $this->writeSources([
            'a' => ['download' => "$redirector/via/down/a.ttl", 'prefix' => 'a'] + self::ENTRY,
            'ex' => ['download' => "$redirector/via/vocabulary.ttl"] + self::ENTRY,
        ]);
        copy(self::fixture('basic.ttl'), "$this->dir/data/vocabs/a.ttl");
        [$status, $output] = $this->script('fetch', ['--retry-wait=0']);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('failed: a (server unavailable, stored file kept)', $output);
        $this->assertStringContainsString('changed: ex (1 added)', $output);
        $this->assertContains('/vocabulary.ttl', file("$this->dir/requests.log", FILE_IGNORE_NEW_LINES));
    }

    public function testATermListsTermIsTriedAgainWhenItsServerFails()
    {
        [$data, $extension, $unavailable] = Download::termList(Download::fetchAll(["$this->address/terms/"])[0], "$this->address/terms/");
        $this->assertSame('nt', $extension);
        $this->assertFalse($unavailable);
        $this->assertStringContainsString("<$this->address/terms/Flaky> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type>", $data);
        $this->assertStringContainsString("<$this->address/terms/Steady> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type>", $data);
        $requests = file("$this->dir/requests.log", FILE_IGNORE_NEW_LINES);
        $this->assertSame(2, count(array_keys($requests, '/terms/Flaky', true)));
        $this->assertSame(1, count(array_keys($requests, '/terms/Steady', true)));
    }

    public function testAnUnchangedFileIsNotCheckedAgain()
    {
        // The stored file would fail the check, but the download is the same.
        $broken = 'this is not RDF {{{ @prefix';
        file_put_contents("$this->dir/data/vocabs/ex.ttl", $broken);
        file_put_contents("$this->dir/published.ttl", $broken);
        $this->writeSources(['ex' => ['download' => "$this->address/vocabulary.ttl"] + self::ENTRY]);
        [, $output] = $this->script('fetch');
        $this->assertStringContainsString('0 changed, 1 unchanged, 0 failed', $output);
    }

    public function testABodyLargerThanTheLimitIsNotKept()
    {
        file_put_contents("$this->dir/published.ttl", str_repeat('# padding ', 2000));
        $response = Download::fetchAll(["$this->address/vocabulary.ttl"], true, 1000)[0];
        $this->assertSame('', $response['body']);
        $this->assertSame('larger than 0 MB', $response['error']);
        $this->assertSame(20000, strlen(Download::fetchAll(["$this->address/vocabulary.ttl"])[0]['body']));
    }

    public function testAFileWhoseTermsMovedToAnotherFormOfTheNamespaceIsNotKept()
    {
        file_put_contents("$this->dir/published.ttl", str_replace('http://example.org/ns#', 'https://example.org/ns#', file_get_contents(self::fixture('basic.ttl'))));
        $this->writeSources(['ex' => ['download' => "$this->address/vocabulary.ttl"] + self::ENTRY]);
        [$status, $output] = $this->script('fetch', ['--retry-wait=0']);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('failed: ex (no longer usable, needs a look)', $output);
        $this->assertStringContainsString("the file's terms use the namespace https://example.org/ns#, not http://example.org/ns#", $output);
        $this->assertStoredFileKept();
    }

    public function testAnArchivedCopysPublisherInAnotherFormOfTheNamespaceIsReported()
    {
        file_put_contents("$this->dir/published.ttl", str_replace('http://example.org/ns#', 'https://example.org/ns#', file_get_contents(self::fixture('basic.ttl'))));
        $this->writeSources(['ex' => ['copy_from' => 'LOV', 'download' => "$this->address/vocabulary.ttl"] + self::ENTRY]);
        [$status, $output] = $this->script('fetch', ['--retry-wait=0']);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('  ex (its terms now under https://example.org/ns#)', $output);
        $this->assertStoredFileKept();
    }

    public function testAnArchivedCopysPublisherIsTriedAndReportedButTheCopyKept()
    {
        file_put_contents("$this->dir/published.ttl", file_get_contents(self::fixture('basic.ttl')) . "\nex:Extra a rdfs:Class .\n");
        $this->writeSources(['ex' => ['copy_from' => 'LOV', 'download' => "$this->address/vocabulary.ttl"] + self::ENTRY]);
        [$status, $output] = $this->script('fetch', ['--retry-wait=0']);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('0 fetched: 0 changed, 0 unchanged, 0 failed; 1 archived copies tried at their publishers', $output);
        $this->assertStringContainsString("Archived copies whose publisher's file works again", $output);
        $this->assertStringContainsString('  ex (1 added)', $output);
        $this->assertStringNotContainsString('build.php', $output);
        $this->assertStoredFileKept();
    }

    public function testAnArchivedCopysPublisherThatStillFailsIsNotTriedAgainOrReported()
    {
        $this->writeSources(['ex' => ['copy_from' => 'LOV', 'download' => "$this->address/down.ttl"] + self::ENTRY]);
        [$status, $output] = $this->script('fetch', ['--retry-wait=0']);
        $this->assertSame(0, $status, $output);
        $this->assertSame(['/down.ttl'], file("$this->dir/requests.log", FILE_IGNORE_NEW_LINES));
        $this->assertStringContainsString('0 failed; 1 archived copies tried at their publishers', $output);
        $this->assertStringNotContainsString('failed: ex', $output);
        $this->assertStringNotContainsString('works again', $output);
        $this->assertStoredFileKept();
    }

    public function testAnAlternateAddressIsUsedWhenTheFirstFails()
    {
        file_put_contents("$this->dir/published.ttl", file_get_contents(self::fixture('basic.ttl')));
        $this->writeSources(['ex' => ['download' => "$this->address/gone.ttl", 'alternates' => ["$this->address/busy.ttl"]] + self::ENTRY]);
        [$status, $output] = $this->script('fetch', ['--retry-wait=0']);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('0 changed, 1 unchanged, 0 failed', $output);
    }
}
