<?php
/**
 * Loads Omeka's code (for its RDF importer and EasyRdf) and the scripts'
 * classes.
 *
 * Omeka is found through the OMEKA_DIR environment variable, defaulting to
 * the install this module sits in.
 */
$omekaDir = getenv('OMEKA_DIR') ?: dirname(__DIR__, 3);
if (!is_file("$omekaDir/vendor/autoload.php")) {
    fwrite(STDERR, "Omeka S not found at $omekaDir; set OMEKA_DIR to an Omeka S install with its dependencies installed.\n");
    exit(1);
}
require "$omekaDir/vendor/autoload.php";
require __DIR__ . '/Repo.php';
require __DIR__ . '/ImportCheck.php';
require __DIR__ . '/Download.php';

$repo = new VocabularyLibrary\Scripts\Repo(dirname(__DIR__));
// Omeka\Module is not autoloadable, so read its VERSION constant directly.
preg_match("/const VERSION = '([^']+)'/", file_get_contents("$omekaDir/application/Module.php"), $match);
$omekaVersion = $match[1];

/**
 * Positional arguments: those not starting with "--".
 */
function positionalArgs(array $argv)
{
    return array_values(array_filter(array_slice($argv, 1), function ($arg) {
        return strpos($arg, '--') !== 0;
    }));
}

/**
 * The value of a "--name=value" option, or null.
 */
function option(array $argv, $name)
{
    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, "--$name=") === 0) {
            return substr($arg, strlen("--$name="));
        }
    }
    return null;
}
