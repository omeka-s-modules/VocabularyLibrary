<?php
namespace VocabularyLibraryTest\Script;

/**
 * Runs fetch.php against a web server on this machine that fails the way a
 * publisher's can: test/fixtures/publisher.php, run with PHP's built-in
 * server.
 */
class FetchFromServerTest extends ScriptTestCase
{
    protected $server;
    protected $address;

    protected function setUp(): void
    {
        parent::setUp();
        // A port nothing uses: ask for any, then give it back.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->address = "http://127.0.0.1:$port";
        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", self::fixture('publisher.php')],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PUBLISHER_DIR' => $this->dir] + getenv()
        );
        for ($tries = 0; $tries < 100; $tries++) {
            if ($connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1)) {
                fclose($connection);
                return;
            }
            usleep(50000);
        }
        $this->fail('The test server did not start.');
    }

    protected function tearDown(): void
    {
        if ($this->server) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        parent::tearDown();
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

    public function testAnAlternateAddressIsUsedWhenTheFirstFails()
    {
        file_put_contents("$this->dir/published.ttl", file_get_contents(self::fixture('basic.ttl')));
        $this->writeSources(['ex' => ['download' => "$this->address/gone.ttl", 'alternates' => ["$this->address/busy.ttl"]] + self::ENTRY]);
        [$status, $output] = $this->script('fetch', ['--retry-wait=0']);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('0 changed, 1 unchanged, 0 failed', $output);
    }
}
