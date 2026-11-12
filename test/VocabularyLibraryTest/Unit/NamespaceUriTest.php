<?php
namespace VocabularyLibraryTest\Unit;

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
            'capitals' => ['http://Example.org/NS#', 'http://example.org/ns#'],
        ];
    }

    public function testDifferentNamespacesStayDifferent()
    {
        $this->assertNotSame(NamespaceUri::normalized('http://example.org/ns/a#'), NamespaceUri::normalized('http://example.org/ns/b#'));
        $this->assertNotSame(NamespaceUri::normalized('http://example.org/ns'), NamespaceUri::normalized('http://www.example.org/ns'));
    }

    public function testTheNormalizedFormHasNoSchemeOrClosingCharacter()
    {
        $this->assertSame('schema.org', NamespaceUri::normalized('https://Schema.org/'));
        $this->assertSame('', NamespaceUri::normalized(null));
    }
}
