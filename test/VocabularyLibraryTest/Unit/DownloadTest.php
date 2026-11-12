<?php
namespace VocabularyLibraryTest\Unit;

use PHPUnit\Framework\TestCase;
use VocabularyLibrary\Scripts\Download;

class DownloadTest extends TestCase
{
    const TURTLE = "@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .\n<http://example.org/ns#Thing> a rdfs:Class .\n";
    const RDF_XML = '<?xml version="1.0"?><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:rdfs="http://www.w3.org/2000/01/rdf-schema#"><rdfs:Class rdf:about="http://example.org/ns#Thing"><rdfs:comment rdf:parseType="Literal">%s</rdfs:comment></rdfs:Class></rdf:RDF>';

    protected static function response($body, $type = '')
    {
        return ['body' => $body, 'type' => $type, 'url' => 'http://example.org/ns', 'status' => 200, 'error' => null];
    }

    /**
     * @dataProvider htmlPages
     */
    public function testAPageWhoseRootIsHtmlIsHtml($body)
    {
        $this->assertTrue(Download::isHtml($body));
    }

    public function htmlPages()
    {
        return [
            'doctype' => ['<!DOCTYPE html><html><head></head></html>'],
            'no doctype, leading space' => ["\n  <html lang=\"en\">"],
            'byte order mark' => ["\xEF\xBB\xBF<!doctype html>"],
            'XHTML' => ['<?xml version="1.0"?>' . "\n" . '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "x">' . "\n" . '<html xmlns="http://www.w3.org/1999/xhtml">'],
            'processing instruction and comment first' => ['<?xml version="1.0"?><?xml-stylesheet href="a.xsl"?><!-- a comment --><html>'],
        ];
    }

    /**
     * @dataProvider rdfThatMentionsHtml
     */
    public function testRdfThatOnlyMentionsHtmlIsNotHtml($body)
    {
        $this->assertFalse(Download::isHtml($body));
        $this->assertTrue(Download::looksLikeRdf($body));
    }

    public function rdfThatMentionsHtml()
    {
        return [
            'in a Turtle literal' => ["@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .\n<http://example.org/a> rdfs:comment \"The <html> element of a page\" ."],
            'in an XML comment' => ['<?xml version="1.0"?>' . "\n" . '<!DOCTYPE rdf:RDF [ ]>' . "\n" . '<rdf:RDF><!-- <html> --></rdf:RDF>'],
        ];
    }

    public function testAServerIsUnavailableWhenItFailsOrDoesNotAnswer()
    {
        $response = ['status' => 200, 'error' => null, 'no_answer' => false];
        $this->assertFalse(Download::serverUnavailable($response));
        $this->assertTrue(Download::serverUnavailable(['status' => 503] + $response));
        $this->assertTrue(Download::serverUnavailable(['status' => 429] + $response));
        $this->assertTrue(Download::serverUnavailable(['status' => 0, 'error' => 'Timeout', 'no_answer' => true]));
        // A missing page, or a name that doesn't resolve, may be for good.
        $this->assertFalse(Download::serverUnavailable(['status' => 404, 'error' => 'HTTP 404'] + $response));
        $this->assertFalse(Download::serverUnavailable(['status' => 0, 'error' => 'Could not resolve host'] + $response));
    }

    public function testNTriplesLooksLikeRdfThoughItHasNoHeader()
    {
        $this->assertTrue(Download::looksLikeRdf("<http://example.org/a> <http://example.org/b> <http://example.org/c> .\n"));
        $this->assertFalse(Download::looksLikeRdf('Not found.'));
    }

    public function testAScriptElementInXmlIsFound()
    {
        $this->assertTrue((bool) Download::hasScript(sprintf(self::RDF_XML, '<script xmlns="http://www.w3.org/1999/xhtml">alert(1)</script>')));
        $this->assertTrue((bool) Download::hasScript(sprintf(self::RDF_XML, '<h:script xmlns:h="http://www.w3.org/1999/xhtml"/>')));
    }

    public function testOtherMarkupABrowserWouldRunIsFound()
    {
        $this->assertTrue((bool) Download::hasScript(sprintf(self::RDF_XML, '<h:img xmlns:h="http://www.w3.org/1999/xhtml" src="x" onerror="alert(1)"/>')));
        $this->assertTrue((bool) Download::hasScript(sprintf(self::RDF_XML, "<a xmlns='http://www.w3.org/1999/xhtml' href='javascript:alert(1)'>x</a>")));
    }

    public function testOrdinaryRdfIsNotMarkupABrowserWouldRun()
    {
        // An attribute and prose that only resemble an event handler.
        $this->assertFalse((bool) Download::hasScript(sprintf(self::RDF_XML, 'Based on one = two, and online="yes" in prose.')));
        $this->assertFalse((bool) Download::hasScript('<?xml version="1.0"?><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:owl="http://www.w3.org/2002/07/owl#"><owl:Restriction><owl:onProperty rdf:resource="http://example.org/p"/></owl:Restriction></rdf:RDF>'));
    }

    /**
     * @dataProvider jsonLdContexts
     */
    public function testJsonLdMustCarryItsContext($body, $remote)
    {
        $this->assertSame($remote, Download::hasRemoteContext($body));
    }

    public function jsonLdContexts()
    {
        return [
            'inline' => ['{"@context": {"ex": "http://example.org/ns#"}, "@id": "ex:Thing"}', false],
            'none' => ['[{"@id": "http://example.org/ns#Thing"}]', false],
            'an address' => ['{"@context": "http://example.org/context.jsonld", "@id": "ex:Thing"}', true],
            'a list with an address' => ['{"@context": [{"ex": "http://example.org/ns#"}, "http://example.org/context.jsonld"]}', true],
            'nested' => ['{"@context": {}, "@graph": [{"@context": "file:///etc/passwd", "@id": "x"}]}', true],
            'the word in a value' => ['{"@context": {"ex": "http://example.org/ns#"}, "ex:note": "@context"}', false],
            'not JSON' => ["@prefix ex: <http://example.org/ns#> .\nex:a ex:b \"@context\" .", false],
        ];
    }

    public function testJsonLdWithAContextAtAnAddressIsNotStored()
    {
        $this->assertSame(
            [null, 'takes its JSON-LD context from an address; the file must carry it', false],
            Download::storable(self::response('{"@context": "http://example.org/context.jsonld", "@id": "http://example.org/ns#Thing"}', 'application/ld+json'), null, null)
        );
    }

    public function testTheWordScriptIsNotAScriptElement()
    {
        $this->assertFalse((bool) Download::hasScript(sprintf(self::RDF_XML, 'A manuscript, or the description of a script.')));
        $this->assertFalse((bool) Download::hasScript(''));
        // Not XML, so only text.
        $this->assertFalse((bool) Download::hasScript("@prefix rdfs: <http://x/> .\n<http://a> rdfs:comment \"Use a <script> element\" ."));
    }

    public function testAFileIsStoredWithTheExtensionOfItsContentType()
    {
        $this->assertSame([self::TURTLE, 'ttl', false], Download::storable(self::response(self::TURTLE, 'text/turtle'), null, null));
        $this->assertSame('nt', Download::storable(self::response(self::TURTLE, 'application/n-triples'), null, null)[1]);
    }

    public function testWithoutAKnownContentTypeTheFirstCharacterDecides()
    {
        $this->assertSame('ttl', Download::storable(self::response(self::TURTLE), null, null)[1]);
        $this->assertSame('rdf', Download::storable(self::response(sprintf(self::RDF_XML, 'A comment.')), null, null)[1]);
        $this->assertSame('jsonld', Download::storable(self::response('{"@context": {}, "@id": "http://example.org/a"}', 'text/plain'), null, null)[1]);
    }

    public function testATermListsTermsMustBeInItsNamespace()
    {
        $namespace = 'http://example.org/terms/';
        // A file on this machine and a term of another host: neither is
        // fetched, so there is nothing to assemble.
        $list = "<file:///etc/hostname> <http://purl.org/dc/terms/isPartOf> <$namespace> .\n<http://other.example/terms/x> <http://purl.org/dc/terms/isPartOf> <$namespace> .\n";
        $this->assertSame([null, 'the term list has no terms in its namespace', false], Download::termList(self::response($list, 'application/n-triples'), $namespace));
    }

    public function testATermListMayNotNameTooManyTerms()
    {
        $namespace = 'http://example.org/terms/';
        $list = '';
        for ($i = 0; $i <= Download::MAX_TERMS; $i++) {
            $list .= "<{$namespace}t$i> <http://purl.org/dc/terms/isPartOf> <$namespace> .\n";
        }
        $this->assertSame([null, 'the term list has more than 2000 terms', false], Download::termList(self::response($list, 'application/n-triples'), $namespace));
    }

    public function testAGzippedBodyThatUnzipsPastTheLimitIsRefused()
    {
        $this->assertSame('small', Download::unzipped(gzencode('small'), 10));
        $this->assertNull(Download::unzipped(gzencode(str_repeat('a', 5000)), 1000));
        $this->assertSame('', Download::unzipped("\x1f\x8bnot gzip", 1000));
    }

    public function testATemporaryFileHasItsDataAndExtension()
    {
        $path = Download::tempFile('data', 'ttl');
        $this->assertStringEndsWith('.ttl', $path);
        $this->assertSame('data', file_get_contents($path));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        unlink($path);
    }

    public function testAGzippedBodyIsUnzipped()
    {
        $this->assertSame([self::TURTLE, 'ttl', false], Download::storable(self::response(gzencode(self::TURTLE), 'application/gzip'), null, null));
    }

    public function testWhatIsNotRdfIsNotStored()
    {
        $this->assertSame([null, 'empty response (HTTP 200)', false], Download::storable(self::response("  \n"), null, null));
        $this->assertSame([null, 'not RDF (text/html)', false], Download::storable(self::response('<!DOCTYPE html><html></html>', 'text/html'), null, null));
        $this->assertSame([null, 'not RDF (no content type)', false], Download::storable(self::response('Not found.'), null, null));
    }

    public function testXmlCarryingAScriptIsNotStored()
    {
        $body = sprintf(self::RDF_XML, '<script xmlns="http://www.w3.org/1999/xhtml">alert(1)</script>');
        $this->assertSame([null, 'carries markup a browser would run on the site', false], Download::storable(self::response($body, 'application/rdf+xml'), null, null));
    }

    public function testAnRdfaPageIsStoredAsNTriples()
    {
        $page = '<html><body><div about="http://example.org/ns#Thing" typeof="http://www.w3.org/2000/01/rdf-schema#Class"><span property="http://www.w3.org/2000/01/rdf-schema#label">Thing</span></div></body></html>';
        [$data, $extension] = Download::storable(self::response($page, 'text/html'), 'rdfa', 'http://example.org/ns#');
        $this->assertSame('nt', $extension);
        $this->assertStringContainsString('<http://example.org/ns#Thing> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://www.w3.org/2000/01/rdf-schema#Class>', $data);
        $this->assertSame([null, 'the page carries no RDFa', false], Download::storable(self::response('<html><body><p>Nothing here.</p></body></html>', 'text/html'), 'rdfa', 'http://example.org/ns#'));
    }
}
