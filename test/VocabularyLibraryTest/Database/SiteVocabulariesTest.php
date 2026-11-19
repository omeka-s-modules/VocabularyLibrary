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

    public function testANamespaceWrittenTheSameWayIsInstalledAndUpdatable()
    {
        $installed = $this->siteVocabularies()->installed(['namespace' => self::FOAF]);
        $this->assertSame('foaf', $installed['vocabulary']->prefix());
        $this->assertTrue($installed['exact']);
        $this->assertTrue($installed['updatable']);
        // Core's page for the vocabulary.
        $this->assertSame('/admin/vocabulary/' . $installed['vocabulary']->id(), $installed['url']);
    }

    /**
     * @dataProvider otherForms
     */
    public function testANamespaceWrittenAnotherWayIsInstalledAndUpdatable($namespace)
    {
        $installed = $this->siteVocabularies()->installed(['namespace' => $namespace]);
        $this->assertSame('foaf', $installed['vocabulary']->prefix());
        $this->assertFalse($installed['exact']);
        // Core's review changes its namespace to the library's along with
        // the rest.
        $this->assertTrue($installed['updatable']);
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

    public function testTheVocabulariesOmekaKeepsFixedAreNotUpdatable()
    {
        $installed = $this->siteVocabularies()->installed(['namespace' => 'http://purl.org/dc/terms/']);
        $this->assertTrue($installed['exact']);
        $this->assertFalse($installed['updatable']);
    }

    public function testASupervisorMayUpdateOnlyTheirOwnVocabularies()
    {
        self::makeUser('supervisor@example.com', 'site_admin');
        self::signIn('supervisor@example.com');
        $this->assertFalse($this->siteVocabularies()->installed(['namespace' => self::FOAF])['updatable']);
        // Written another way too.
        $this->assertFalse($this->siteVocabularies()->installed(['namespace' => 'https://xmlns.com/foaf/0.1/'])['updatable']);

        self::services()->get('Omeka\ApiManager')->create('vocabularies', [
            'o:namespace_uri' => 'http://example.org/mine#',
            'o:prefix' => 'mine',
            'o:label' => 'Mine',
        ]);
        $installed = $this->siteVocabularies()->installed(['namespace' => 'http://example.org/mine#']);
        $this->assertTrue($installed['updatable']);
        $this->assertSame('/admin/vocabulary/' . $installed['vocabulary']->id(), $installed['url']);
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
