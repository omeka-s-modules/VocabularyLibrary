<?php
/**
 * Downloads each vocabulary's file again and keeps it if it has changed and
 * still imports.
 *
 * Usage: php scripts/fetch.php [<slug> ...] [--retry-wait=<seconds>]
 *
 * Tries the entry's download URL, then each of its alternates, asking for RDF
 * by content negotiation and following redirects. A downloaded file replaces
 * the stored one only if it differs and passes the import check, so a
 * publisher breaking their file leaves the last working copy in place.
 * Entries with "copy_from" are skipped: their file is an archived copy
 * because the publisher's does not work; remove the field to try again.
 * Entries with no download address, whose file was sent to the library, have
 * nothing to fetch.
 *
 * Fetches one vocabulary per host at a time, and tries an address once more
 * when its server fails or does not answer, after the wait the server names
 * or else --retry-wait, 10 seconds by default. A term list's terms are
 * fetched a few at a time from its host (Download::TERM_BATCH).
 *
 * Prints what changed and what failed. A changed file says how it differs
 * from the stored one: in its terms, their labels or their comments, in any
 * language, which are the ones to list in release notes; in what it says of
 * the vocabulary, such as its description, whose summary is then worth
 * checking; or in the file only, as when a publisher saves it again. A
 * failure is either a server that is unavailable for now, which keeps the
 * stored file and needs nothing, or an address that no longer serves a file
 * the library can use, which needs a look. Run build.php afterwards.
 */
require __DIR__ . '/bootstrap.php';

use VocabularyLibrary\Scripts\Download;
use VocabularyLibrary\Scripts\ImportCheck;

// Requests made at a time, each to a different host.
const PARALLEL = 8;
// The longest a Retry-After is waited out, in seconds.
const MAX_RETRY_WAIT = 60;
// The wait before trying an address again, in seconds, where its server
// names none.
foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, '--') === 0 && !preg_match('/^--retry-wait=\d+$/', $argument)) {
        fail("Unknown option: $argument\nUsage: php scripts/fetch.php [<slug> ...] [--retry-wait=<seconds>]");
    }
}
define('RETRY_WAIT', (int) (option($argv, 'retry-wait') ?? 10));

$check = new ImportCheck;
$sources = $repo->sources();
$only = positionalArgs($argv);
foreach (array_diff($only, array_keys($sources)) as $unknown) {
    fail("Unknown slug: $unknown");
}

$pending = [];
$skipped = $submitted = 0;
foreach ($sources as $slug => $entry) {
    if ($only && !in_array((string) $slug, $only, true)) {
        continue;
    }
    if (isset($entry['copy_from'])) {
        $skipped++;
        continue;
    }
    $urls = array_values(array_filter(array_merge([$entry['download'] ?? null], $entry['alternates'] ?? [])));
    if (!$urls) {
        $submitted++;
        continue;
    }
    $pending[$slug] = $urls;
}

// Changed: per slug, how it differs, as ImportCheck::differences() counts
// it, or a phrase where there is nothing to count. Needs look: whether a
// failed slug has an address that no longer serves a usable file.
$changed = $unchanged = $failed = $needsLook = [];
// Retry-After describes a host, so the back-off is per host.
$retried = $notBefore = [];
while ($pending) {
    // One URL per vocabulary, one request per host, PARALLEL at a time.
    $batch = $hosts = [];
    foreach ($pending as $slug => $urls) {
        $host = parse_url($urls[0], PHP_URL_HOST);
        if (($notBefore[$host] ?? 0) > time() || isset($hosts[$host])) {
            continue;
        }
        $batch[$slug] = $urls[0];
        $hosts[$host] = true;
        if (count($batch) === PARALLEL) {
            break;
        }
    }
    if (!$batch) {
        sleep(1);
        continue;
    }
    foreach (Download::fetchAll($batch) as $slug => $response) {
        $url = $batch[$slug];
        // Any failure to get an answer is tried once more: a server error,
        // or no response at all.
        $noResponse = $response['error'] && $response['status'] < 400;
        if ((Download::serverUnavailable($response) || $noResponse) && !isset($retried[$url])) {
            $retried[$url] = true;
            $wait = isset($response['retry_after']) ? min($response['retry_after'], MAX_RETRY_WAIT) : RETRY_WAIT;
            $notBefore[parse_url($url, PHP_URL_HOST)] = time() + $wait;
            continue;
        }
        array_shift($pending[$slug]);
        $entry = $sources[$slug];
        $reason = $response['error'];
        $unavailable = Download::serverUnavailable($response);
        if (!$reason) {
            [$data, $extension, $unavailable] = Download::storable($response, $entry['format'] ?? null, $entry['namespace']) + [2 => false];
            if ($data === null) {
                $reason = $extension;
            } else {
                $path = Download::tempFile($data, $extension);
                $result = $check->check($path, $entry['namespace']);
                unlink($path);
                $reason = ImportCheck::failure($result);
            }
        }
        if ($reason) {
            $failed[$slug][] = "$url: $reason";
            // Needs a look if any of its addresses answered unusably.
            $needsLook[$slug] = ($needsLook[$slug] ?? false) || !$unavailable;
            if (!$pending[$slug]) {
                unset($pending[$slug]);
            }
            continue;
        }
        $current = $repo->files($slug);
        $same = count($current) === 1
            && pathinfo($current[0], PATHINFO_EXTENSION) === $extension
            && hash_file('sha256', $current[0]) === hash('sha256', $data);
        if ($same) {
            $unchanged[] = $slug;
        } else {
            // How it differs, read from the stored file before it is
            // replaced; unknown where that file can't be read as one.
            $stored = count($current) === 1 ? $check->check($current[0], $entry['namespace']) : null;
            if (!$current) {
                $changed[$slug] = 'new file';
            } elseif (empty($stored['readings'])) {
                $changed[$slug] = 'differences unknown';
            } else {
                $changed[$slug] = ImportCheck::differences($stored, $result);
            }
            $repo->storeFile($slug, $data, $extension);
        }
        unset($pending[$slug], $failed[$slug], $needsLook[$slug]);
    }
}

ksort($changed);
ksort($failed);
printf("%d fetched: %d changed, %d unchanged, %d failed; %d archived copies and %d submitted files skipped\n",
    count($changed) + count($unchanged) + count($failed), count($changed), count($unchanged), count($failed), $skipped, $submitted);
$inTerms = $inDetails = [];
foreach ($changed as $slug => $differences) {
    if (!is_array($differences)) {
        // Unknown, so treated as changed in everything.
        $inTerms[] = $inDetails[] = $slug;
        echo "  changed: $slug ($differences)\n";
        continue;
    }
    $parts = [];
    foreach (['added', 'removed', 'labels', 'comments'] as $kind) {
        if ($differences[$kind]) {
            $parts[] = "{$differences[$kind]} $kind";
        }
    }
    if ($parts) {
        $inTerms[] = $slug;
    }
    if ($differences['details']) {
        $inDetails[] = $slug;
        $parts[] = implode(', ', $differences['details']);
    }
    printf("  changed: %s (%s)\n", $slug, $parts ? implode('; ', $parts) : 'file only');
}
$groups = ['server unavailable, stored file kept' => [], 'no longer usable, needs a look' => []];
foreach ($failed as $slug => $reasons) {
    $groups[$needsLook[$slug] ? 'no longer usable, needs a look' : 'server unavailable, stored file kept'][$slug] = $reasons;
}
foreach ($groups as $what => $slugs) {
    foreach ($slugs as $slug => $reasons) {
        echo "  failed: $slug ($what)\n";
        foreach ($reasons as $reason) {
            echo '    ', printable($reason), "\n";
        }
    }
}
if ($inTerms) {
    echo "Changed in their terms, to list in the release notes:\n  ", implode(' ', $inTerms), "\n";
}
if ($inTerms || $inDetails) {
    echo "Changed in their terms or in what they say of themselves, so check their summaries:\n  ", implode(' ', array_unique(array_merge($inTerms, $inDetails))), "\n";
}
if ($changed) {
    echo "Run build.php to update data/vocabularies.json.\n";
}
