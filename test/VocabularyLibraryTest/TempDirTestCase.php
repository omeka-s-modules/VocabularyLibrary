<?php
namespace VocabularyLibraryTest;

use PHPUnit\Framework\TestCase;

/**
 * A test with a temporary directory of its own, removed afterwards.
 */
abstract class TempDirTestCase extends TestCase
{
    protected $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vocabulary-library-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    /**
     * The path to one of the tests' fixture files.
     */
    protected static function fixture($name)
    {
        return dirname(__DIR__) . '/fixtures/' . $name;
    }

    protected static function remove($path)
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                self::remove("$path/$entry");
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
