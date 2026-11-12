<?php
/**
 * Bootstraps the module's tests, run from Omeka's root:
 *
 *   vendor/bin/phpunit -c modules/VocabularyLibrary/test/phpunit.xml
 *
 * The unit tests need only Omeka's development dependencies. The script tests
 * run the collection's scripts on a small collection of their own in a
 * temporary directory, without the network. Neither uses a database.
 */
require dirname(__DIR__, 3) . '/bootstrap.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

// The module's classes, which Omeka autoloads only once the module is
// active; the scripts' classes, which the scripts load themselves; and the
// tests' own.
$loader = require OMEKA_PATH . '/vendor/autoload.php';
$loader->addPsr4('VocabularyLibrary\\Scripts\\', dirname(__DIR__) . '/scripts');
$loader->addPsr4('VocabularyLibrary\\', dirname(__DIR__) . '/src');
$loader->addPsr4('VocabularyLibraryTest\\', __DIR__ . '/VocabularyLibraryTest');
