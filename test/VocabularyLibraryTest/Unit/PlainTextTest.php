<?php
namespace VocabularyLibraryTest\Unit;

use PHPUnit\Framework\TestCase;
use VocabularyLibrary\Stdlib\PlainText;

class PlainTextTest extends TestCase
{
    public function testTagsAreRemovedAndTheirTextKept()
    {
        $this->assertSame('A bold claim, with a link.', PlainText::from('<p>A <b>bold</b> claim, with <a href="http://example.org/">a link</a>.</p>'));
    }

    public function testPrefixedTagsAreRemoved()
    {
        $this->assertSame('In a division', PlainText::from('<html:div class="x">In a division</html:div>'));
    }

    public function testEntitiesAreDecoded()
    {
        $this->assertSame('Fish & chips, "quoted", café', PlainText::from('Fish &amp; chips, &quot;quoted&quot;, caf&eacute;'));
    }

    public function testOtherAngleBracketsAreText()
    {
        $this->assertSame('a value <= 5.0, or a <subject>', PlainText::from('a value <= 5.0, or a <subject>'));
    }

    public function testAnElementNotListedIsLeftAlone()
    {
        $this->assertSame('<script>alert(1)</script>', PlainText::from('<script>alert(1)</script>'));
    }

    public function testANullValueIsEmptyText()
    {
        $this->assertSame('', PlainText::from(null));
    }
}
