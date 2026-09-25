<?php
namespace VocabularyLibrary\Scripts;

use EasyRdf\Graph as RdfGraph;
use Throwable;

/**
 * Downloads vocabulary files and turns a response into a file to store.
 */
class Download
{
    const ACCEPT = 'text/turtle, application/rdf+xml;q=0.9, application/ld+json;q=0.8, application/n-triples;q=0.7, */*;q=0.1';
    const USER_AGENT = 'Omeka S Vocabulary Library (+https://omeka.org)';
    const RDF_SIGNS = ['<rdf:RDF', '<?xml', '@prefix', '@base', 'PREFIX', '"@context"', 'owl:Ontology'];
    const EXTENSIONS = ['text/turtle' => 'ttl', 'application/rdf+xml' => 'rdf', 'application/ld+json' => 'jsonld', 'application/json' => 'jsonld', 'application/n-triples' => 'nt'];

    /**
     * Fetches URLs in parallel, following redirects.
     *
     * @return array Per key: body, type (content type), url (final URL),
     *   status, error, and retry_after (seconds, from a Retry-After header)
     */
    public static function fetchAll(array $urls)
    {
        $multi = curl_multi_init();
        $handles = [];
        $retryAfter = [];
        foreach ($urls as $key => $url) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Accept: ' . self::ACCEPT],
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_ENCODING => '',
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
        $results = [];
        foreach ($handles as $key => $handle) {
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $results[$key] = [
                'body' => (string) curl_multi_getcontent($handle),
                'type' => strtolower(trim(explode(';', (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE))[0])),
                'url' => curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
                'status' => $status,
                'error' => curl_error($handle) ?: ($status >= 400 ? "HTTP $status" : null),
                'retry_after' => $retryAfter[$key] ?? null,
            ];
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);
        return $results;
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
     * declaration.
     */
    public static function isHtml($body)
    {
        return (bool) preg_match('/<(!doctype\s+html|html[\s>])/i', self::head($body));
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
     * and stored as N-Triples, which imports the same.
     *
     * @param array $response A result from fetchAll()
     * @param string|null $format The entry's curated format
     * @param string|null $namespace The base URI for parsing RDFa
     * @return array [data, extension], or [null, reason] if not storable
     */
    public static function storable(array $response, $format, $namespace)
    {
        $body = $response['body'];
        if (trim($body) === '') {
            return [null, sprintf('empty response (HTTP %s)', $response['status'])];
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
        // Otherwise by the first character: a Turtle file may begin with "[",
        // so only "{" means JSON-LD.
        $first = self::head($body)[0];
        $extension = self::EXTENSIONS[$response['type']] ?? ($first === '<' ? 'rdf' : ($first === '{' ? 'jsonld' : 'ttl'));
        return [$body, $extension];
    }

    /**
     * Writes data to a temporary file with the given extension.
     *
     * @return string The path; the caller deletes it
     */
    public static function tempFile($data, $extension)
    {
        $base = tempnam(sys_get_temp_dir(), 'vocabulary-library-');
        unlink($base);
        $path = "$base.$extension";
        file_put_contents($path, $data);
        return $path;
    }
}
