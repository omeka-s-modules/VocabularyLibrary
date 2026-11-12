<?php
namespace VocabularyLibraryTest\Database;

use VocabularyLibrary\Stdlib\SiteVocabularies;
use VocabularyLibraryTest\DbTestCase;

/**
 * Matches library entries to the vocabularies a new Omeka has: Dublin Core,
 * Dublin Core Type, the Bibliographic Ontology and Friend of a Friend.
 */
class SiteVocabulariesTest extends DbTestCase
{
    const FOAF = 'http://xmlns.com/foaf/0.1/';

    protected function siteVocabularies()
    {
        $services = self::services();
        return new SiteVocabularies($services->get('Omeka\ApiManager'), $services->get('ViewHelperManager')->get('Url'));
    }

    public function testANamespaceOmekaDoesNotHaveIsNotInstalled()
    {
        $this->assertNull($this->siteVocabularies()->installed(['namespace' => 'http://example.org/ns#']));
    }

    public function testANamespaceWrittenTheSameWayIsInstalledAndComparable()
    {
        $installed = $this->siteVocabularies()->installed(['namespace' => self::FOAF]);
        $this->assertSame('foaf', $installed['vocabulary']->prefix());
        $this->assertTrue($installed['exact']);
        $this->assertTrue($installed['comparable']);
        // Core's Vocabularies page, showing only this one.
        $this->assertSame('/admin/vocabulary?namespace_uri=' . self::FOAF, $installed['url']);
    }

    /**
     * @dataProvider otherForms
     */
    public function testANamespaceWrittenAnotherWayIsInstalledButNotComparable($namespace)
    {
        $installed = $this->siteVocabularies()->installed(['namespace' => $namespace]);
        $this->assertSame('foaf', $installed['vocabulary']->prefix());
        $this->assertFalse($installed['exact']);
        $this->assertFalse($installed['comparable']);
    }

    public function otherForms()
    {
        return [
            'https' => ['https://xmlns.com/foaf/0.1/'],
            'no closing slash' => ['http://xmlns.com/foaf/0.1'],
            'hash for slash' => ['http://xmlns.com/foaf/0.1#'],
        ];
    }

    public function testCapitalsAloneAreTheSameWay()
    {
        $installed = $this->siteVocabularies()->installed(['namespace' => 'http://XMLNS.com/foaf/0.1/']);
        $this->assertTrue($installed['exact']);
    }

    public function testTheVocabulariesOmekaKeepsFixedAreNotComparable()
    {
        $installed = $this->siteVocabularies()->installed(['namespace' => 'http://purl.org/dc/terms/']);
        $this->assertTrue($installed['exact']);
        $this->assertFalse($installed['comparable']);
    }

    public function testASupervisorMayCompareOnlyTheirOwnVocabularies()
    {
        self::makeUser('supervisor@example.com', 'site_admin');
        self::signIn('supervisor@example.com');
        $this->assertFalse($this->siteVocabularies()->installed(['namespace' => self::FOAF])['comparable']);

        self::services()->get('Omeka\ApiManager')->create('vocabularies', [
            'o:namespace_uri' => 'http://example.org/mine#',
            'o:prefix' => 'mine',
            'o:label' => 'Mine',
        ]);
        $installed = $this->siteVocabularies()->installed(['namespace' => 'http://example.org/mine#']);
        $this->assertTrue($installed['comparable']);
        // A closing "#" is part of the namespace, not the end of the link.
        $this->assertSame('/admin/vocabulary?namespace_uri=http://example.org/mine%23', $installed['url']);
    }

    public function testPrefixesAreFoundInAnyCase()
    {
        $siteVocabularies = $this->siteVocabularies();
        $this->assertSame('Dublin Core', $siteVocabularies->prefixHolder('DCTerms'));
        $this->assertNull($siteVocabularies->prefixHolder('ex'));
        $prefixes = array_keys($siteVocabularies->prefixes());
        sort($prefixes);
        $this->assertSame(['bibo', 'dcterms', 'dctype', 'foaf'], $prefixes);
    }
}
