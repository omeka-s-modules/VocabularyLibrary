<?php
/**
 * Downloads each vocabulary's file again and keeps it if it has changed and
 * still imports.
 *
 * Usage: php scripts/fetch.php [<slug> ...] [--retry-wait=<seconds>]
 *
 * Tries the entry's download URL, then each of its alternates, asking for RDF
 * by content negotiation and following redirects. A downloaded file replaces
 * the stored one only if it differs and passes the import check under the
 * entry's namespace, as written, so a publisher breaking their file, or
 * moving its terms to https, leaves the last working copy in place.
 * An entry with "copy_from" has an archived copy because the publisher's
 * file didn't work. Its publisher's addresses are tried too, once each, but
 * the copy is never replaced, since a file that imports may still be a
 * partial one: the report lists those whose publisher's file works again,
 * and how each differs from the copy, for a maintainer to decide. One that
 * still fails is expected, so it isn't reported, and its server isn't
 * marked as down for the others. Entries with no download address, whose
 * file was sent to the library, have nothing to fetch.
 *
 * Fetches one vocabulary per host at a time, and tries an address once more
 * when its server fails or does not answer, after the wait the server names
 * or else --retry-wait, 10 seconds by default. A server that fails both times
 * is not asked again in the run, so one that is down doesn't hold up the
 * rest for each of its vocabularies; that is the server that answered, as an
 * address may redirect, as purl.org's do, to another publisher's server. A
 * term list's terms are fetched a few at a time from its host
 * (Download::TERM_BATCH).
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
foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, '--') === 0 && !preg_match('/^--retry-wait=\d+$/', $argument)) {
        fail("Unknown option: $argument\nUsage: php scripts/fetch.php [<slug> ...] [--retry-wait=<seconds>]");
    }
}
// The wait before trying an address again, in seconds, where its server
// names none.
define('RETRY_WAIT', (int) (option($argv, 'retry-wait') ?? 10));

$check = new ImportCheck;
$sources = $repo->sources();
$only = positionalArgs($argv);
foreach (array_diff($only, array_keys($sources)) as $unknown) {
    fail("Unknown slug: $unknown");
}

$pending = $archived = [];
$submitted = 0;
foreach ($sources as $slug => $entry) {
    if ($only && !in_array((string) $slug, $only, true)) {
        continue;
    }
    $urls = array_values(array_filter(array_merge([$entry['download'] ?? null], $entry['alternates'] ?? [])));
    if (!$urls) {
        $submitted++;
        continue;
    }
    if (isset($entry['copy_from'])) {
        $archived[$slug] = true;
    }
    $pending[$slug] = $urls;
}

// Changed: per slug, how it differs, as ImportCheck::differences() counts
// it, or a phrase where there is nothing to count. Needs look: whether a
// failed slug has an address that no longer serves a usable file. Working
// again: per archived copy whose publisher's file now passes the check, how
// that file differs from the copy, and the namespace its terms use if that
// is another form of the entry's.
$changed = $unchanged = $failed = $needsLook = $workingAgain = [];
// Retry-After describes a host, so the back-off is per host asked. Down: the
// hosts that answered failing on both tries, which may not be the ones asked,
// as when purl.org redirects to a publisher whose server is down.
$retried = $notBefore = $down = [];
while ($pending) {
    // What a host that is down serves fails without being asked for.
    foreach ($pending as $slug => $urls) {
        while ($urls && isset($down[(string) parse_url($urls[0], PHP_URL_HOST)])) {
            $failed[$slug][] = array_shift($urls) . ': its server failed twice earlier in this run';
            $needsLook[$slug] ??= false;
        }
        $pending[$slug] = $urls;
        if (!$urls) {
            unset($pending[$slug]);
        }
    }
    if (!$pending) {
        break;
    }
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
        // or no response at all. Not an archived copy's publisher, whose
        // failing is expected.
        $noResponse = $response['error'] && $response['status'] < 400;
        if ((Download::serverUnavailable($response) || $noResponse) && !isset($retried[$url]) && !isset($archived[$slug])) {
            $retried[$url] = true;
            $wait = isset($response['retry_after']) ? min($response['retry_after'], MAX_RETRY_WAIT) : RETRY_WAIT;
            $notBefore[parse_url($url, PHP_URL_HOST)] = time() + $wait;
            continue;
        }
        array_shift($pending[$slug]);
        $entry = $sources[$slug];
        $reason = $response['error'];
        $unavailable = Download::serverUnavailable($response);
        $same = false;
        if ($unavailable && !isset($archived[$slug]) && $host = (string) parse_url($response['url'] ?: $url, PHP_URL_HOST)) {
            $down[$host] = true;
        }
        if (!$reason) {
            [$data, $extension, $unavailable] = Download::storable($response, $entry['format'] ?? null, $entry['namespace']);
            $reason = $data === null ? $extension : null;
        }
        if (!$reason) {
            // The stored file was checked when it was stored, so one the
            // same to the byte needs no check.
            $current = $repo->files($slug);
            $same = count($current) === 1
                && pathinfo($current[0], PATHINFO_EXTENSION) === $extension
                && hash_file('sha256', $current[0]) === hash('sha256', $data);
            if (!$same) {
                $path = Download::tempFile($data, $extension);
                $result = $check->check($path, $entry['namespace']);
                unlink($path);
                // In the entry's namespace, not another form of it, which to
                // Omeka is a new vocabulary: a decision, not an update. An
                // archived copy's publisher is reported either way.
                $reason = ImportCheck::failure($result, isset($archived[$slug]) ? null : $entry['namespace']);
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
        if (isset($archived[$slug])) {
            // The copy stays; how the publisher's file differs from it.
            $workingAgain[$slug] = $same ? [null, null] : [
                ImportCheck::differences($check->check($current[0], $entry['namespace']), $result),
                $result['namespace'] !== $entry['namespace'] ? $result['namespace'] : null,
            ];
            unset($pending[$slug], $failed[$slug], $needsLook[$slug]);
            continue;
        }
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

// An archived copy's publisher failing is expected, so it isn't reported.
$failed = array_diff_key($failed, $archived);
ksort($changed);
ksort($failed);
ksort($workingAgain);
printf("%d fetched: %d changed, %d unchanged, %d failed; %d archived copies tried at their publishers; %d submitted files skipped\n",
    count($changed) + count($unchanged) + count($failed), count($changed), count($unchanged), count($failed), count($archived), $submitted);
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
if ($workingAgain) {
    echo "Archived copies whose publisher's file works again (copy kept; to switch back, see scripts/README.md):\n";
    foreach ($workingAgain as $slug => [$differences, $namespace]) {
        $parts = [];
        foreach (['added', 'removed', 'labels', 'comments'] as $kind) {
            if ($differences[$kind] ?? 0) {
                $parts[] = "{$differences[$kind]} $kind";
            }
        }
        if ($namespace !== null) {
            $parts[] = "its terms now under $namespace";
        }
        printf("  %s (%s)\n", $slug, $differences === null ? 'same file' : ($parts ? implode(', ', $parts) : 'same terms'));
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
