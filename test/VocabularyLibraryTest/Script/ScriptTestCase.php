<?php
namespace VocabularyLibraryTest\Script;

use VocabularyLibraryTest\TempDirTestCase;

/**
 * A test that runs the collection's scripts on a small collection of its own:
 * a copy of the scripts and what they load, in a temporary directory, with
 * one vocabulary. The scripts find their collection beside themselves, so the
 * module's own is never touched.
 */
abstract class ScriptTestCase extends TempDirTestCase
{
    const ENTRY = [
        'download' => 'http://example.org/ns',
        'namespace' => 'http://example.org/ns#',
        'prefix' => 'ex',
        'summary' => 'Classes and properties for describing things and their parts.',
        'summary_basis' => ['file'],
        'tags' => ['general'],
    ];

    /**
     * The publisher's server, once startPublisher() has run, and its address.
     */
    protected $server;
    protected $address;

    protected function setUp(): void
    {
        parent::setUp();
        $module = dirname(__DIR__, 3);
        foreach (['scripts', 'config', 'src/Stdlib', 'data/vocabs'] as $directory) {
            mkdir("$this->dir/$directory", 0777, true);
        }
        foreach (array_merge(glob("$module/scripts/*.php"), glob("$module/src/Stdlib/*.php"), ["$module/config/module.config.php"]) as $file) {
            copy($file, $this->dir . substr($file, strlen($module)));
        }
        copy(self::fixture('basic.ttl'), "$this->dir/data/vocabs/ex.ttl");
        $this->writeSources(['ex' => self::ENTRY]);
    }

    protected function tearDown(): void
    {
        if ($this->server) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
        parent::tearDown();
    }

    /**
     * Start a web server on this machine that stands in for a publisher's:
     * test/fixtures/publisher.php, run with PHP's built-in server. It serves
     * published.ttl in the test's directory, and stops after the test.
     *
     * @return string Its address, such as http://127.0.0.1:49152
     */
    protected function startPublisher()
    {
        if ($this->server) {
            return $this->address;
        }
        // The server takes a port of its own choosing, read from its first
        // line, so no other process can take it in between.
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:0', self::fixture('publisher.php')],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            ['PUBLISHER_DIR' => $this->dir] + getenv()
        );
        $started = fgets($pipes[2]);
        if (!preg_match('#http://127\.0\.0\.1:\d+#', (string) $started, $match)) {
            $this->fail('The test server did not start: ' . $started);
        }
        return $this->address = $match[0];
    }

    protected function writeSources(array $sources)
    {
        file_put_contents("$this->dir/scripts/sources.json", json_encode($sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array The decoded file, or null if the scripts have not
     *   written it
     */
    protected function read($relative)
    {
        $path = "$this->dir/$relative";
        return is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }

    /**
     * Runs one of the scripts with Omeka's own code.
     *
     * @param string $script The script's name, such as "build"
     * @param string[] $arguments
     * @return array [exit status, output and errors together]
     */
    protected function script($script, array $arguments = [])
    {
        $command = sprintf(
            // In UTC, as Omeka sets the tests' own clock.
            'OMEKA_DIR=%s %s -d date.timezone=UTC -d error_reporting=%d %s %s 2>&1',
            escapeshellarg(OMEKA_PATH),
            escapeshellarg(PHP_BINARY),
            E_ALL & ~E_DEPRECATED,
            escapeshellarg("$this->dir/scripts/$script.php"),
            implode(' ', array_map('escapeshellarg', $arguments))
        );
        exec($command, $output, $status);
        return [$status, implode("\n", $output)];
    }
}
