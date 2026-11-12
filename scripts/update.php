<?php
/**
 * Fetches every vocabulary, then rebuilds.
 *
 * Usage: php scripts/update.php [<slug> ...] [--retry-wait=<seconds>]
 *
 * Runs fetch.php with the same arguments, then build.php. Review the diff
 * before committing.
 */
$php = escapeshellarg(PHP_BINARY);
$slugs = implode(' ', array_map('escapeshellarg', array_slice($argv, 1)));
passthru("$php " . escapeshellarg(__DIR__ . '/fetch.php') . " $slugs", $status);
if ($status !== 0) {
    exit($status);
}
passthru("$php " . escapeshellarg(__DIR__ . '/build.php'), $status);
exit($status);
