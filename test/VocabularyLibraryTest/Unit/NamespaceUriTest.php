<?php
namespace VocabularyLibraryTest\Unit;

use Omeka\Entity\Vocabulary;
use PHPUnit\Framework\TestCase;
use VocabularyLibrary\Stdlib\NamespaceUri;

class NamespaceUriTest extends TestCase
{
    /**
     * @dataProvider sameNamespaces
     */
    public function testTheSameNamespaceWrittenTwoWaysNormalizesTheSame($a, $b)
    {
        $this->assertSame(NamespaceUri::normalized($a), NamespaceUri::normalized($b));
    }

    public function sameNamespaces()
    {
        return [
            'scheme' => ['http://schema.org/', 'https://schema.org/'],
            'closing slash' => ['http://example.org/ns', 'http://example.org/ns/'],
            'closing hash' => ['http://example.org/ns#', 'http://example.org/ns'],
            'hash for slash' => ['http://example.org/ns#', 'http://example.org/ns/'],
            'scheme and closing' => ['https://example.org/ns#', 'http://example.org/ns/'],
            'capitals' => ['http://Example.org/NS#', 'http://example.org/ns#'],
        ];
    }

    /**
     * @dataProvider differentNamespaces
     */
    public function testDifferentNamespacesStayDifferent($a, $b)
    {
        $this->assertNotSame(NamespaceUri::normalized($a), NamespaceUri::normalized($b));
    }

    public function differentNamespaces()
    {
        return [
            'another path' => ['http://example.org/ns/a#', 'http://example.org/ns/b#'],
            'a longer path' => ['http://example.org/ns', 'http://example.org/ns/a'],
            'another host' => ['http://example.org/ns', 'http://www.example.org/ns'],
        ];
    }

    /**
     * The build's key treats as one namespace exactly those that core's import
     * details find as one vocabulary: a namespace's forms, in any case, as
     * Omeka's database compares namespaces.
     *
     * @dataProvider sameNamespaces
     */
    public function testWhatTheKeyTreatsAsOneCoreFindsAsOne($a, $b)
    {
        $this->assertContains(mb_strtolower($b), $this->coreForms($a));
    }

    /**
     * @dataProvider differentNamespaces
     */
    public function testWhatTheKeyKeepsApartCoreKeepsApart($a, $b)
    {
        $this->assertNotContains(mb_strtolower($b), $this->coreForms($a));
    }

    public function testTheNormalizedFormHasNoSchemeOrClosingCharacter()
    {
        $this->assertSame('schema.org', NamespaceUri::normalized('https://Schema.org/'));
        $this->assertSame('', NamespaceUri::normalized(null));
    }

    protected function coreForms($namespace)
    {
        return array_map('mb_strtolower', Vocabulary::getNamespaceUriForms(mb_strtolower($namespace)));
    }
}
