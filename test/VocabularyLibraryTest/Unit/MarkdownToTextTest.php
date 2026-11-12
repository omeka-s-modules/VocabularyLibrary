<?php
namespace VocabularyLibraryTest\Unit;

use PHPUnit\Framework\TestCase;
use VocabularyLibrary\Scripts\ImportCheck;

class MarkdownToTextTest extends TestCase
{
    /**
     * The text as the build keeps it: paragraphs, each on one line.
     */
    protected static function paragraphs($markdown)
    {
        $blocks = preg_split('/\n\s*\n/u', ImportCheck::markdownToText($markdown));
        return array_values(array_filter(array_map(function ($block) {
            return trim(preg_replace('/\s+/u', ' ', $block));
        }, $blocks), 'strlen'));
    }

    public function testLinksKeepTheirTextAndLoseTheirAddress()
    {
        $this->assertSame(['See the guide and http://example.org/b.'], self::paragraphs('See [the guide](http://example.org/a "Guide") and <http://example.org/b>.'));
    }

    public function testEmphasisAndCodeLoseTheirMarkers()
    {
        $this->assertSame(['A bold, an italic, a nested and a code word.'], self::paragraphs('A **bold**, an *italic*, a **_nested_** and a `code` word.'));
    }

    public function testNamesWithUnderscoresAreLeftAlone()
    {
        $this->assertSame(['For instance "E63_Beginning_of_Existence" or "P2i_is_type_of".'], self::paragraphs('For instance "E63_Beginning_of_Existence" or "P2i_is_type_of".'));
    }

    public function testHeadingsAreParagraphsOfTheirOwn()
    {
        $this->assertSame(['Title', 'Text below.'], self::paragraphs("# Title\nText below."));
    }

    public function testAListItemIsAParagraphOfItsOwn()
    {
        $this->assertSame(
            ['The classes are:', '• one', '• two', 'And then more.'],
            self::paragraphs("The classes are:\n- one\n* two\n\nAnd then more.")
        );
        $this->assertSame(['Rules:', '1. First', '2) Second'], self::paragraphs("Rules:\n1. First\n2) Second"));
    }

    public function testAListMayFollowProseThatEndsWithoutAColon()
    {
        $this->assertSame(
            ['Changes (J. Doe)', '• first change', '• second change'],
            self::paragraphs("Changes (J. Doe)\n- first change\n- second change")
        );
    }

    public function testWrappedProseThatStartsALineWithADashIsNotAList()
    {
        $this->assertSame(
            ['components in other models (among which - this is not an exhaustive list - PREMIS and RDA, CIDOC-CRM), or more.'],
            self::paragraphs("components in other models (among which\n- this is not an exhaustive list - PREMIS and RDA,\nCIDOC-CRM), or more.")
        );
    }

    public function testWrappedProseThatStartsALineWithANumberIsNotAList()
    {
        $this->assertSame(['It was first issued in 1999. It was revised later.'], self::paragraphs("It was first issued in\n1999. It was\nrevised later."));
    }

    public function testAHeadingBetweenLinesOfProseIsStillAHeading()
    {
        $this->assertSame(['Before', 'Title', 'After'], self::paragraphs("Before\n## Title\nAfter"));
    }

    public function testAListRightAfterAHeadingIsStillAList()
    {
        $this->assertSame(['Title', '• only item', 'After'], self::paragraphs("# Title\n- only item\nAfter"));
    }

    public function testCodeFencesAndRulesEndAParagraph()
    {
        $this->assertSame(['Before', 'code', 'After', 'Last'], self::paragraphs("Before\n```\ncode\n```\nAfter\n---\nLast"));
    }
}
