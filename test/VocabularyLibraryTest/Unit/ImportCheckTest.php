<?php
namespace VocabularyLibraryTest\Unit;

use VocabularyLibrary\Scripts\ImportCheck;
use VocabularyLibraryTest\TempDirTestCase;

class ImportCheckTest extends TempDirTestCase
{
    const NS = 'http://example.org/ns#';

    protected function check($fixture, $namespace = self::NS)
    {
        return (new ImportCheck)->check(self::fixture($fixture), $namespace);
    }

    public function testAFileThatImportsPasses()
    {
        $result = $this->check('basic.ttl');
        $this->assertTrue($result['parsed']);
        $this->assertNull(ImportCheck::failure($result));
        $this->assertSame(self::NS, $result['namespace']);
        $this->assertSame('given', $result['namespace_source']);
        $this->assertSame(1, $result['classes']);
        $this->assertSame(1, $result['properties']);
        $this->assertSame(['de', 'en'], $result['languages']);
        $this->assertSame([], $result['limits']);
    }

    public function testTermsAreReadAsTheModuleImportsThem()
    {
        $members = $this->check('basic.ttl')['members'];
        // The definition, not the comment, where a term has both.
        $this->assertSame(['label' => 'Thing', 'comment' => 'Anything at all.'], array_map('strval', $members['classes']['Thing']));
        $this->assertSame(['label' => 'has part', 'comment' => 'Relates a thing to a part of it.'], array_map('strval', $members['properties']['hasPart']));
    }

    public function testTheFilesOwnMetadataIsRead()
    {
        $result = $this->check('basic.ttl');
        $this->assertSame('Example Vocabulary', $result['title']);
        $this->assertSame("For describing things.\n\nSecond paragraph.", $result['description']);
        $this->assertSame('https://creativecommons.org/licenses/by/4.0/', $result['license']);
        $this->assertSame('ex', $result['prefix']);
    }

    public function testMetadataComesFromTheVocabularysOwnOntologyRecord()
    {
        // The file describes another vocabulary first.
        $result = $this->check('two-ontologies.ttl');
        $this->assertSame('Example Vocabulary', $result['title']);
        $this->assertSame('https://creativecommons.org/licenses/by/4.0/', $result['license']);
    }

    public function testATitleOverSeveralLinesIsOneLine()
    {
        $path = $this->dir . '/title.ttl';
        file_put_contents($path, "@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .\n@prefix dcterms: <http://purl.org/dc/terms/> .\n<http://example.org/ns#> a <http://www.w3.org/2002/07/owl#Ontology> ; dcterms:title \"\"\"The *Example*\n\n  Vocabulary\"\"\"@en .\n<http://example.org/ns#Thing> a rdfs:Class .\n");
        $this->assertSame('The Example Vocabulary', (new ImportCheck)->check($path, self::NS)['title']);
    }

    public function testAFileThatSaysNothingOfItselfGivesNoMetadata()
    {
        $result = $this->check('no-metadata.ttl');
        $this->assertNull(ImportCheck::failure($result));
        foreach (['title', 'description', 'license', 'prefix'] as $field) {
            $this->assertNull($result[$field], $field);
        }
    }

    public function testTermsAreReadInTheFilesLanguageWhereItHasNoEnglish()
    {
        $members = $this->check('no-metadata.ttl')['members'];
        $this->assertSame('Other', (string) $members['classes']['Other']['label']);
        // A term with no label gets its local name, as Omeka labels it.
        $this->assertSame('Thing', (string) $members['classes']['Thing']['label']);
    }

    public function testAnotherFormOfTheNamespaceIsFound()
    {
        $result = $this->check('basic.ttl', 'https://example.org/ns/');
        $this->assertSame(self::NS, $result['namespace']);
        $this->assertSame('variant', $result['namespace_source']);
        $this->assertNull(ImportCheck::failure($result));
    }

    public function testTheNamespaceIsFoundWhenNoneIsGiven()
    {
        $result = $this->check('basic.ttl', null);
        $this->assertSame(self::NS, $result['namespace']);
        $this->assertSame('ontology', $result['namespace_source']);
        $this->assertNull(ImportCheck::failure($result));
    }

    public function testANamespaceThatImportsNothingFailsThoughTheTermsUseAnother()
    {
        $result = $this->check('basic.ttl', 'http://example.org/unrelated/');
        $this->assertSame(self::NS, $result['namespace']);
        $this->assertSame('terms', $result['namespace_source']);
        $this->assertStringContainsString('the namespace imports nothing', ImportCheck::failure($result));
    }

    public function testALabelTooLongInAnyLanguageFails()
    {
        // The English label is short; the German one is not.
        $result = $this->check('long-label.ttl');
        $this->assertSame(['1 labels longer than 255 characters in "de"'], $result['limits']);
        $this->assertSame('1 labels longer than 255 characters in "de"', ImportCheck::failure($result));
    }

    public function testAFileThatDoesNotParseFails()
    {
        $result = $this->check('broken.ttl');
        $this->assertFalse($result['parsed']);
        $this->assertStringStartsWith('does not parse: ', ImportCheck::failure($result));
    }

    public function testAFileWithNoTermsFails()
    {
        file_put_contents($this->dir . '/empty.ttl', "<http://example.org/ns#> a <http://www.w3.org/2002/07/owl#Ontology> .\n");
        $result = (new ImportCheck)->check($this->dir . '/empty.ttl', self::NS);
        $this->assertSame('imports no classes or properties', ImportCheck::failure($result));
    }

    /**
     * A check result for a copy of the basic fixture with some text replaced.
     */
    protected function changed(array $replacements, $append = '')
    {
        $path = $this->dir . '/changed-' . count(glob($this->dir . '/*')) . '.ttl';
        file_put_contents($path, strtr(file_get_contents(self::fixture('basic.ttl')), $replacements) . $append);
        return (new ImportCheck)->check($path, self::NS);
    }

    public function testAFileDoesNotDifferFromItself()
    {
        $result = $this->check('basic.ttl');
        $this->assertSame(['added' => 0, 'removed' => 0, 'labels' => 0, 'comments' => 0, 'details' => []], ImportCheck::differences($result, $this->changed([], "\n# Saved again.\n")));
    }

    public function testDifferencesCountTermsLabelsAndComments()
    {
        $new = $this->changed(['"has part"@en' => '"has a part"@en', 'Anything at all.' => 'Anything.', 'ex:hasPart a' => 'ex:hasPiece a'], "\nex:Extra a rdfs:Class .\n");
        $differences = ImportCheck::differences($this->check('basic.ttl'), $new);
        // hasPart is renamed, so it is removed and hasPiece added.
        $this->assertSame(['added' => 2, 'removed' => 1, 'labels' => 0, 'comments' => 1, 'details' => []], $differences);
    }

    public function testDifferencesAreFoundInEveryLanguage()
    {
        $differences = ImportCheck::differences($this->check('basic.ttl'), $this->changed(['"Ding"@de' => '"Sache"@de']));
        $this->assertSame(1, $differences['labels']);
    }

    public function testDifferencesAreExact()
    {
        $differences = ImportCheck::differences($this->check('basic.ttl'), $this->changed(['"Anything at all."@en' => '"Anything at all. "@en']));
        $this->assertSame(1, $differences['comments']);
    }

    public function testDifferencesNameWhatTheFileSaysOfTheVocabulary()
    {
        $new = $this->changed(['"Example Vocabulary"@en' => '"An Example"@en', 'licenses/by/4.0/' => 'licenses/by-sa/4.0/', ', "Ding"@de' => '', ', "hat Teil"@de' => '']);
        $this->assertSame(['title', 'license', 'languages'], ImportCheck::differences($this->check('basic.ttl'), $new)['details']);
    }

    public function testCandidatesAreTheNamespacesOtherForms()
    {
        $this->assertSame(
            ['http://example.org/ns#', 'http://example.org/ns/', 'https://example.org/ns#', 'https://example.org/ns/'],
            ImportCheck::candidates('http://example.org/ns#')
        );
    }
}
