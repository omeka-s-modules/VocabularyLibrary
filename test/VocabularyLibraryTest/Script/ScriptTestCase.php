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
