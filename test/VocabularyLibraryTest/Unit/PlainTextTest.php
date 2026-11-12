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

    public function testALineHasNoMarkupAndItsWhitespaceCollapsed()
    {
        $this->assertSame('A label over two lines', PlainText::line("  A <b>label</b>\n   over\ttwo lines "));
        $this->assertSame('', PlainText::line(null));
        // Text that isn't valid UTF-8 keeps its text.
        $this->assertSame("Caf\xe9 au lait", PlainText::line("Caf\xe9  au\nlait"));
    }

    public function testAShortDescriptionIsItsOwnExcerpt()
    {
        $this->assertSame(['One sentence. Another.', []], PlainText::excerpt('One sentence. Another.', 200));
        $this->assertSame([null, []], PlainText::excerpt(null, 200));
        $this->assertSame([null, []], PlainText::excerpt('', 200));
    }

    public function testAnExcerptEndsWithTheLastSentenceThatFits()
    {
        $this->assertSame(
            ['First sentence here. Second one.', ['Third sentence is the rest.']],
            PlainText::excerpt('First sentence here. Second one. Third sentence is the rest.', 40)
        );
    }

    public function testAnAbbreviationDoesNotEndASentence()
    {
        $this->assertSame(
            ['It covers things, e.g. kilns and pots.', ['And much more besides, in a second sentence.']],
            PlainText::excerpt('It covers things, e.g. kilns and pots. And much more besides, in a second sentence.', 40)
        );
    }

    public function testLaterParagraphsAreTheRest()
    {
        $this->assertSame(
            ['First paragraph.', ['Second paragraph.', 'Third.']],
            PlainText::excerpt("First paragraph.\n\nSecond paragraph.\n\nThird.", 200)
        );
    }

    public function testAFirstSentenceMuchTooLongIsCutAtAWord()
    {
        [$start, $rest] = PlainText::excerpt('one two three four five six seven eight nine ten eleven twelve.', 20);
        $this->assertSame('one two three four…', $start);
        $this->assertSame(['…five six seven eight nine ten eleven twelve.'], $rest);
    }

    public function testANullValueIsEmptyText()
    {
        $this->assertSame('', PlainText::from(null));
    }
}
