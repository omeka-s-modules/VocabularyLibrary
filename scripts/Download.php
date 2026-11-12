<?php
namespace VocabularyLibrary\Scripts;

use EasyRdf\Graph as RdfGraph;
use Throwable;

/**
 * Downloads vocabulary files and turns a response into a file to store.
 */
class Download
{
    /**
     * The formats a request asks for, most wanted first.
     */
    const ACCEPT = 'text/turtle, application/rdf+xml;q=0.9, application/ld+json;q=0.8, application/n-triples;q=0.7, */*;q=0.1';

    /**
     * How a request names itself to the publisher.
     */
    const USER_AGENT = 'Omeka S Vocabulary Library (+https://omeka.org)';

    /**
     * Text near the start of a body that marks it as RDF. "@id" is for
     * expanded JSON-LD, which has no "@context".
     */
    const RDF_SIGNS = ['<rdf:RDF', '<?xml', '@prefix', '@base', 'PREFIX', '"@context"', '"@id"', 'owl:Ontology'];

    /**
     * The extension a file is stored with, by its content type.
     */
    const EXTENSIONS = ['text/turtle' => 'ttl', 'application/rdf+xml' => 'rdf', 'application/ld+json' => 'jsonld', 'application/json' => 'jsonld', 'application/n-triples' => 'nt'];

    /**
     * Term documents fetched at a time for a term list, all from the one
     * publisher, with a pause between batches.
     */
    const TERM_BATCH = 4;
    const TERM_PAUSE = 250000;

    /**
     * The most terms a term list may name. Each is a request, and the list
     * is the publisher's to write; the largest in the library has a few
     * hundred.
     */
    const MAX_TERMS = 2000;

    /**
     * The largest body kept from a download, and the most a gzipped one may
     * unzip to, in bytes. The largest file in the library is under 20 MB.
     */
    const MAX_BYTES = 100 * 1024 * 1024;

    /**
     * Fetches URLs in parallel, following redirects. Only from the web: an
     * address or a redirect of another kind, such as a file on this machine,
     * is refused, since a publisher's list of terms can name any address.
     *
     * @param bool $decode Whether to ask for compressed responses, which curl
     *   unzips
     * @param int $maxBytes The largest body to keep; a larger one fails
     * @return array Per key: body, type (content type), url (final URL),
     *   status, error, retry_after (seconds, from a Retry-After header), and
     *   no_answer (whether the server did not answer in time or refused the
     *   connection)
     */
    public static function fetchAll(array $urls, $decode = true, $maxBytes = self::MAX_BYTES)
    {
        $multi = curl_multi_init();
        $handles = [];
        $retryAfter = [];
        $bodies = $tooLarge = [];
        foreach ($urls as $key => $url) {
            $bodies[$key] = '';
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                // The body as it arrives, unzipped, so one that grows past
                // the limit is cut off rather than held.
                CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$bodies, &$tooLarge, $key, $maxBytes) {
                    if (strlen($bodies[$key]) + strlen($chunk) > $maxBytes) {
                        $tooLarge[$key] = true;
                        return 0;
                    }
                    $bodies[$key] .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Accept: ' . self::ACCEPT],
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_ENCODING => $decode ? '' : null,
                CURLOPT_HEADERFUNCTION => function ($handle, $header) use (&$retryAfter, $key) {
                    if (preg_match('/^Retry-After:\s*(\d+)/i', $header, $match)) {
                        $retryAfter[$key] = (int) $match[1];
                    }
                    return strlen($header);
                },
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$key] = $handle;
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi);
        } while ($running);
        // Each transfer's result, which curl_error() doesn't report for
        // transfers run together.
        $codes = [];
        while ($info = curl_multi_info_read($multi)) {
            $codes[spl_object_id($info['handle'])] = $info['result'];
        }
        $results = [];
        $badEncoding = [];
        foreach ($handles as $key => $handle) {
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $code = $codes[spl_object_id($handle)] ?? CURLE_OK;
            $results[$key] = [
                'body' => isset($tooLarge[$key]) ? '' : $bodies[$key],
                'type' => strtolower(trim(explode(';', (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE))[0])),
                'url' => curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
                'status' => $status,
                'error' => isset($tooLarge[$key])
                    ? sprintf('larger than %d MB', $maxBytes / 1024 / 1024)
                    : (curl_error($handle) ?: ($code !== CURLE_OK ? curl_strerror($code) : ($status >= 400 ? "HTTP $status" : null))),
                'retry_after' => $retryAfter[$key] ?? null,
                'no_answer' => in_array($code, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT], true),
            ];
            if ($code === CURLE_BAD_CONTENT_ENCODING) {
                $badEncoding[$key] = $urls[$key];
            }
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);
        // A server that names its compression wrongly, as one sending
        // "Content-Encoding: application/gzip" does, fails curl's unzipping.
        // Fetched again without asking for compression, the body arrives as
        // sent, and storable() unzips it.
        if ($decode && $badEncoding) {
            $results = array_replace($results, self::fetchAll($badEncoding, false, $maxBytes));
        }
        return $results;
    }

    /**
     * Whether a response says its server is busy, failing or not answering,
     * which passes, rather than that the address no longer serves the file:
     * HTTP 429, a 5xx status, a timeout or a refused connection. A name that
     * doesn't resolve, a bad certificate or a missing page is not one: the
     * address may be dead, and needs a look.
     */
    public static function serverUnavailable(array $response)
    {
        return $response['status'] === 429 || $response['status'] >= 500 || !empty($response['no_answer']);
    }

    /**
     * The start of a body, without a UTF-8 byte order mark or leading space.
     */
    public static function head($body)
    {
        return ltrim(substr(preg_replace('/^\xEF\xBB\xBF/', '', $body), 0, 4000));
    }

    /**
     * Whether a body is an HTML page, including XHTML that begins with an XML
     * declaration: its doctype or first element is html, after any XML
     * declaration, processing instructions and comments. RDF that only
     * mentions the element, in a comment or a description, is not a page.
     */
    public static function isHtml($body)
    {
        return (bool) preg_match('/^(?:<\?.*?\?>\s*|<!--.*?-->\s*)*<(?:!doctype\s+html|html[\s>])/is', self::head($body));
    }

    /**
     * Whether a body is XML carrying markup a browser would run: a script
     * element, in any namespace prefix, an event handler attribute such as
     * onerror, or a javascript: address. A browser that opens an RDF/XML file
     * as XML runs XHTML inside it, as in a description written as an XML
     * literal. The other formats are not markup a browser parses, so there
     * it is only text.
     *
     * This is a second line of defence, and can't be a complete one: markup
     * can be spelled in ways no pattern lists. The first is data/.htaccess,
     * which stops the web server serving the files at all, where the server
     * is Apache.
     */
    public static function hasScript($body)
    {
        return strncmp(self::head($body), '<', 1) === 0 && preg_match(
            '/<(?:[\w.-]+:)?script[\s\/>]|<[^>]*\son[a-z]+\s*=\s*["\']|=\s*["\']\s*javascript:/i',
            $body
        );
    }

    /**
     * Whether a body is JSON-LD that takes its context from an address
     * rather than carrying it. The parser fetches such a context each time
     * the file is read, on every site, so the publisher would learn of each
     * reading, could have the site request any address, and could change
     * what the file's terms mean after it was checked.
     */
    public static function hasRemoteContext($body)
    {
        $first = substr(self::head($body), 0, 1);
        $json = $first === '{' || $first === '[' ? json_decode($body, true) : null;
        return is_array($json) && self::namesContext($json);
    }

    /**
     * Whether any "@context" in decoded JSON-LD, at any depth, is an address
     * or lists one.
     */
    protected static function namesContext(array $json)
    {
        foreach ($json as $key => $value) {
            // A list's keys are numbers; an inline context's are its terms.
            $isList = is_array($value) && array_keys($value) === array_keys(array_values($value));
            if ($key === '@context' && (is_string($value) || ($isList && array_filter($value, 'is_string')))) {
                return true;
            }
            if (is_array($value) && self::namesContext($value)) {
                return true;
            }
        }
        return false;
    }

    public static function looksLikeRdf($body)
    {
        if (self::isHtml($body)) {
            return false;
        }
        $head = self::head($body);
        foreach (self::RDF_SIGNS as $sign) {
            if (strpos($head, $sign) !== false) {
                return true;
            }
        }
        // N-Triples has no header: a first triple of IRIs or blank nodes.
        return (bool) preg_match('/^(<[^>\s]+>|_:\S+)\s+<[^>\s]+>\s+/m', $head);
    }

    /**
     * Turns a response into the data and extension to store.
     *
     * HTML is never stored: files under modules/ are served directly by the
     * web server, so an HTML file would run as a page on the Omeka site. A
     * vocabulary published as RDFa ("format": "rdfa") is parsed from its page
     * and stored as N-Triples, which imports the same. So is one published
     * as a term list ("format": "term-list"), assembled by termList(). XML
     * carrying markup a browser would run is not stored either, for the same
     * reason, nor JSON-LD whose context is at an address (hasRemoteContext()).
     *
     * A gzipped body is unzipped first: some publishers serve a gzipped file
     * without the Content-Encoding header that lets curl unzip it, as the
     * Dutch Cultural Heritage Agency serves its dataset downloads.
     *
     * @param array $response A result from fetchAll()
     * @param string|null $format The entry's curated format
     * @param string|null $namespace The base URI for parsing RDFa, and a term
     *   list's URI
     * @return array [data, extension], or [null, reason] if not storable,
     *   with true as a third value where the reason is a server that is
     *   unavailable, as serverUnavailable() has it
     */
    public static function storable(array $response, $format, $namespace)
    {
        if (strncmp($response['body'], "\x1f\x8b", 2) === 0) {
            $unzipped = self::unzipped($response['body']);
            if ($unzipped === null) {
                return [null, sprintf('gzipped, and unzips to more than %d MB', self::MAX_BYTES / 1024 / 1024)];
            }
            if ($unzipped === '') {
                return [null, 'gzipped, and did not unzip'];
            }
            $response['body'] = $unzipped;
        }
        $body = $response['body'];
        if (trim($body) === '') {
            return [null, sprintf('empty response (HTTP %s)', $response['status'])];
        }
        if ($format === 'term-list') {
            return self::termList($response, $namespace);
        }
        if ($format === 'rdfa') {
            try {
                $graph = new RdfGraph;
                $graph->parse($body, 'rdfa', $namespace ?: $response['url']);
                $data = $graph->serialise('ntriples');
            } catch (Throwable $e) {
                return [null, 'RDFa did not parse: ' . mb_substr($e->getMessage(), 0, 200)];
            }
            return $data === '' ? [null, 'the page carries no RDFa'] : [$data, 'nt'];
        }
        if (!self::looksLikeRdf($body)) {
            return [null, 'not RDF (' . ($response['type'] ?: 'no content type') . ')'];
        }
        if (self::hasScript($body)) {
            return [null, 'carries markup a browser would run on the site'];
        }
        if (self::hasRemoteContext($body)) {
            return [null, 'takes its JSON-LD context from an address; the file must carry it'];
        }
        // Otherwise by the first character: a Turtle file may begin with "[",
        // so only "{" means JSON-LD.
        $first = self::head($body)[0];
        $extension = self::EXTENSIONS[$response['type']] ?? ($first === '<' ? 'rdf' : ($first === '{' ? 'jsonld' : 'ttl'));
        return [$body, $extension];
    }

    /**
     * Assembles a vocabulary published as a term list, as TDWG publishes
     * Darwin Core: the namespace serves a list of its current terms, each
     * dcterms:isPartOf the list, and each term's own URI serves the term's
     * description. The list and every term's document are combined as they
     * are and stored as N-Triples, sorted so an unchanged vocabulary gives
     * an identical file. Fails if any term's document can't be fetched.
     *
     * @return array [data, extension], or [null, reason], with true as a third
     *   value where a term's server is unavailable
     */
    public static function termList(array $response, $namespace)
    {
        // The list's URI, not the response's: the request may redirect.
        if (!$namespace) {
            return [null, 'a term list needs its namespace'];
        }
        try {
            $graph = new RdfGraph;
            $graph->parse($response['body'], $response['type'] ?: null, $namespace);
            $terms = [];
            // Only terms in the namespace: the list is the publisher's to
            // write, and each term is an address this machine then fetches.
            foreach ($graph->resourcesMatching('dcterms:isPartOf', $graph->resource($namespace)) as $term) {
                if ($term->getUri() !== $namespace && strpos((string) $term->getUri(), $namespace) === 0) {
                    $terms[] = $term->getUri();
                }
            }
            if (!$terms) {
                return [null, 'the term list has no terms in its namespace'];
            }
            if (count($terms) > self::MAX_TERMS) {
                return [null, sprintf('the term list has more than %d terms', self::MAX_TERMS)];
            }
            sort($terms);
            foreach (array_chunk($terms, self::TERM_BATCH) as $i => $batch) {
                if ($i) {
                    usleep(self::TERM_PAUSE);
                }
                foreach (self::fetchAll(array_combine($batch, $batch)) as $term => $termResponse) {
                    if ($termResponse['error'] || !self::looksLikeRdf($termResponse['body'])) {
                        return [null, sprintf('term %s: %s', $term, $termResponse['error'] ?: 'not RDF'), self::serverUnavailable($termResponse)];
                    }
                    $graph->parse($termResponse['body'], $termResponse['type'] ?: null, $term);
                }
            }
        } catch (Throwable $e) {
            return [null, 'term list did not parse: ' . mb_substr($e->getMessage(), 0, 200)];
        }
        $lines = array_filter(explode("\n", $graph->serialise('ntriples')));
        sort($lines);
        return [implode("\n", $lines) . "\n", 'nt'];
    }

    /**
     * A gzipped body, unzipped: through zlib's stream reader, which reads
     * every part of a gzip file made of several; gzdecode() stops after the
     * first.
     *
     * @return string|null "" if it does not unzip, null if it unzips to more
     *   than the limit
     */
    public static function unzipped($body, $maxBytes = self::MAX_BYTES)
    {
        $path = self::tempFile($body, 'gz');
        $stream = @fopen('compress.zlib://' . $path, 'rb');
        $unzipped = $stream ? (string) @stream_get_contents($stream, $maxBytes + 1) : '';
        if ($stream) {
            fclose($stream);
        }
        unlink($path);
        return strlen($unzipped) > $maxBytes ? null : $unzipped;
    }

    /**
     * Writes data to a temporary file with the given extension. The file is
     * made under a name only this process can have, then given its
     * extension, so nothing another user of the machine puts at the final
     * name is written through.
     *
     * @return string The path; the caller deletes it
     */
    public static function tempFile($data, $extension)
    {
        $base = tempnam(sys_get_temp_dir(), 'vocabulary-library-');
        file_put_contents($base, $data);
        $path = "$base.$extension";
        rename($base, $path);
        return $path;
    }
}
