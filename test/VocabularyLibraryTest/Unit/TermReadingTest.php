<?php
namespace VocabularyLibraryTest\Unit;

use PHPUnit\Framework\TestCase;
use VocabularyLibrary\Stdlib\TermReading;

class TermReadingTest extends TestCase
{
    public function testEnglishIsPreferred()
    {
        $this->assertSame('en', TermReading::preferredLanguage(['de', 'en', 'en-US']));
    }

    public function testARegionalEnglishComesNext()
    {
        $this->assertSame('en-US', TermReading::preferredLanguage(['de', 'en-US', 'fr']));
        $this->assertSame('EN-gb', TermReading::preferredLanguage(['de', 'EN-gb']));
    }

    public function testOtherwiseTheFirstLanguage()
    {
        $this->assertSame('de', TermReading::preferredLanguage(['de', 'fr']));
        // "eng" is not English's tag.
        $this->assertSame('de', TermReading::preferredLanguage(['de', 'eng']));
    }

    public function testNoLanguagesGiveNone()
    {
        $this->assertNull(TermReading::preferredLanguage([]));
    }

    public function testTheLibraryReadsDefinitionsBeforeComments()
    {
        $this->assertSame('skos:definition', explode('|', TermReading::COMMENT_PROPERTY)[0]);
        $this->assertContains(TermReading::OMEKA_COMMENT_PROPERTY, explode('|', TermReading::COMMENT_PROPERTY));
    }
}
