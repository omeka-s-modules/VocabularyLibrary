<?php
namespace VocabularyLibraryTest\Unit;

use PHPUnit\Framework\TestCase;
use VocabularyLibrary\Scripts\Markdown;

class MarkdownTest extends TestCase
{
    public function testLinksKeepTheirTextAndLoseTheirAddress()
    {
        $this->assertSame(['See the guide and http://example.org/b.'], Markdown::paragraphs('See [the guide](http://example.org/a "Guide") and <http://example.org/b>.'));
    }

    public function testEmphasisAndCodeLoseTheirMarkers()
    {
        $this->assertSame(['A bold, an italic, a nested and a code word.'], Markdown::paragraphs('A **bold**, an *italic*, a **_nested_** and a `code` word.'));
    }

    public function testNamesWithUnderscoresAreLeftAlone()
    {
        $this->assertSame(['For instance "E63_Beginning_of_Existence" or "P2i_is_type_of".'], Markdown::paragraphs('For instance "E63_Beginning_of_Existence" or "P2i_is_type_of".'));
    }

    public function testHeadingsAreParagraphsOfTheirOwn()
    {
        $this->assertSame(['Title', 'Text below.'], Markdown::paragraphs("# Title\nText below."));
    }

    public function testAListItemIsAParagraphOfItsOwn()
    {
        $this->assertSame(
            ['The classes are:', '• one', '• two', 'And then more.'],
            Markdown::paragraphs("The classes are:\n- one\n* two\n\nAnd then more.")
        );
        $this->assertSame(['Rules:', '1. First', '2) Second'], Markdown::paragraphs("Rules:\n1. First\n2) Second"));
    }

    public function testAListMayFollowProseThatEndsWithoutAColon()
    {
        $this->assertSame(
            ['Changes (J. Doe)', '• first change', '• second change'],
            Markdown::paragraphs("Changes (J. Doe)\n- first change\n- second change")
        );
    }

    public function testWrappedProseThatStartsALineWithADashIsNotAList()
    {
        $this->assertSame(
            ['components in other models (among which - this is not an exhaustive list - PREMIS and RDA, CIDOC-CRM), or more.'],
            Markdown::paragraphs("components in other models (among which\n- this is not an exhaustive list - PREMIS and RDA,\nCIDOC-CRM), or more.")
        );
    }

    public function testWrappedProseThatStartsALineWithANumberIsNotAList()
    {
        $this->assertSame(['It was first issued in 1999. It was revised later.'], Markdown::paragraphs("It was first issued in\n1999. It was\nrevised later."));
    }

    public function testAHeadingBetweenLinesOfProseIsStillAHeading()
    {
        $this->assertSame(['Before', 'Title', 'After'], Markdown::paragraphs("Before\n## Title\nAfter"));
    }

    public function testAListRightAfterAHeadingIsStillAList()
    {
        $this->assertSame(['Title', '• only item', 'After'], Markdown::paragraphs("# Title\n- only item\nAfter"));
    }

    public function testALineIsTheWholeTextOnOneLine()
    {
        $this->assertSame('A title over two lines', Markdown::line("A **title**\n  over two lines"));
        $this->assertSame('A Title and its subtitle', Markdown::line("# A Title\n\nand its subtitle"));
        $this->assertSame('', Markdown::line("  \n"));
    }

    public function testCodeFencesAndRulesEndAParagraph()
    {
        $this->assertSame(['Before', 'code', 'After', 'Last'], Markdown::paragraphs("Before\n```\ncode\n```\nAfter\n---\nLast"));
    }
}
