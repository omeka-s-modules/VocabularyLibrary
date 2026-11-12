<?php
/**
 * Downloads each vocabulary's file again and keeps it if it has changed and
 * still imports.
 *
 * Usage: php scripts/fetch.php [<slug> ...]
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
 * Fetches one vocabulary per host at a time, and retries once on HTTP 429. A
 * term list's terms are fetched a few at a time from its host
 * (Download::TERM_BATCH). Prints what changed and what failed; run build.php
 * afterwards.
 */
require __DIR__ . '/bootstrap.php';

use VocabularyLibrary\Scripts\Download;
use VocabularyLibrary\Scripts\ImportCheck;

// Requests made at a time, each to a different host.
const PARALLEL = 8;
// The longest a Retry-After is waited out, in seconds.
const MAX_RETRY_WAIT = 60;

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

$changed = $unchanged = $failed = [];
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
        if ($response['status'] === 429 && !isset($retried[$url])) {
            $retried[$url] = true;
            $notBefore[parse_url($url, PHP_URL_HOST)] = time() + min($response['retry_after'] ?? 10, MAX_RETRY_WAIT);
            continue;
        }
        array_shift($pending[$slug]);
        $entry = $sources[$slug];
        $reason = $response['error'];
        if (!$reason) {
            [$data, $extension] = Download::storable($response, $entry['format'] ?? null, $entry['namespace']);
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
            $repo->storeFile($slug, $data, $extension);
            $changed[] = $slug;
        }
        unset($pending[$slug], $failed[$slug]);
    }
}

sort($changed);
ksort($failed);
printf("%d fetched: %d changed, %d unchanged, %d failed; %d archived copies and %d submitted files skipped\n",
    count($changed) + count($unchanged) + count($failed), count($changed), count($unchanged), count($failed), $skipped, $submitted);
foreach ($changed as $slug) {
    echo "  changed: $slug\n";
}
foreach ($failed as $slug => $reasons) {
    echo "  failed: $slug\n";
    foreach ($reasons as $reason) {
        echo "    $reason\n";
    }
}
if ($changed) {
    echo "Run build.php to update data/vocabularies.json.\n";
}
