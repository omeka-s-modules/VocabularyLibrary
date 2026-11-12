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

    public function testTheWordScriptIsNotAScriptElement()
    {
        $this->assertFalse((bool) Download::hasScript(sprintf(self::RDF_XML, 'A manuscript, or the description of a script.')));
        // Not XML, so only text.
        $this->assertFalse((bool) Download::hasScript("@prefix rdfs: <http://x/> .\n<http://a> rdfs:comment \"Use a <script> element\" ."));
    }

    public function testAFileIsStoredWithTheExtensionOfItsContentType()
    {
        $this->assertSame([self::TURTLE, 'ttl'], Download::storable(self::response(self::TURTLE, 'text/turtle'), null, null));
        $this->assertSame('nt', Download::storable(self::response(self::TURTLE, 'application/n-triples'), null, null)[1]);
    }

    public function testWithoutAKnownContentTypeTheFirstCharacterDecides()
    {
        $this->assertSame('ttl', Download::storable(self::response(self::TURTLE), null, null)[1]);
        $this->assertSame('rdf', Download::storable(self::response(sprintf(self::RDF_XML, 'A comment.')), null, null)[1]);
        $this->assertSame('jsonld', Download::storable(self::response('{"@context": {}, "@id": "http://example.org/a"}', 'text/plain'), null, null)[1]);
    }

    public function testAGzippedBodyIsUnzipped()
    {
        $this->assertSame([self::TURTLE, 'ttl'], Download::storable(self::response(gzencode(self::TURTLE), 'application/gzip'), null, null));
    }

    public function testWhatIsNotRdfIsNotStored()
    {
        $this->assertSame([null, 'empty response (HTTP 200)'], Download::storable(self::response("  \n"), null, null));
        $this->assertSame([null, 'not RDF (text/html)'], Download::storable(self::response('<!DOCTYPE html><html></html>', 'text/html'), null, null));
        $this->assertSame([null, 'not RDF (no content type)'], Download::storable(self::response('Not found.'), null, null));
    }

    public function testXmlCarryingAScriptIsNotStored()
    {
        $body = sprintf(self::RDF_XML, '<script xmlns="http://www.w3.org/1999/xhtml">alert(1)</script>');
        $this->assertSame([null, 'carries a script element, which would run on the site'], Download::storable(self::response($body, 'application/rdf+xml'), null, null));
    }

    public function testAnRdfaPageIsStoredAsNTriples()
    {
        $page = '<html><body><div about="http://example.org/ns#Thing" typeof="http://www.w3.org/2000/01/rdf-schema#Class"><span property="http://www.w3.org/2000/01/rdf-schema#label">Thing</span></div></body></html>';
        [$data, $extension] = Download::storable(self::response($page, 'text/html'), 'rdfa', 'http://example.org/ns#');
        $this->assertSame('nt', $extension);
        $this->assertStringContainsString('<http://example.org/ns#Thing> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://www.w3.org/2000/01/rdf-schema#Class>', $data);
        $this->assertSame([null, 'the page carries no RDFa'], Download::storable(self::response('<html><body><p>Nothing here.</p></body></html>', 'text/html'), 'rdfa', 'http://example.org/ns#'));
    }
}
