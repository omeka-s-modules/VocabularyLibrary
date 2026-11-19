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
        $this->assertContains('rdfs:comment', explode('|', TermReading::COMMENT_PROPERTY));
    }

    public function testTheLibrarysReadingIsOneOfCoresChoices()
    {
        // Core's review finds a stored reading among its choices by the
        // exact string.
        $this->assertContains(TermReading::COMMENT_PROPERTY, \Omeka\Stdlib\RdfImporter::COMMENT_PROPERTY_CHOICES);
        $this->assertSame(\Omeka\Stdlib\RdfImporter::LABEL_PROPERTY, TermReading::LABEL_PROPERTY);
    }

    public function testALanguageMatchesItsTagThenItsLanguageThenItsLanguageInAnyRegion()
    {
        $this->assertSame('fr-CA', TermReading::matchingLanguage('fr-ca', ['fr', 'fr-CA']));
        $this->assertSame('fr', TermReading::matchingLanguage('fr-ca', ['fr', 'fr-FR']));
        $this->assertSame('fr-fr', TermReading::matchingLanguage('fr', ['de', 'fr-fr']));
        $this->assertSame('zh-Hans-CN', TermReading::matchingLanguage('zh', ['en', 'zh-Hans-CN']));
        // Omeka's locale setting writes a region with "_".
        $this->assertSame('pt-BR', TermReading::matchingLanguage('pt_BR', ['en', 'pt-BR']));
    }

    public function testOfALanguagesTagsTheOneCoveringTheMostTermsWins()
    {
        // As core's import picks: DCAT tags two labels en-US and 90 en, so an
        // en_US site starts on en.
        $languages = ['en', 'en-GB', 'en-US', 'fr'];
        $coverage = ['en' => 90, 'en-GB' => 2, 'en-US' => 2, 'fr' => 32];
        $this->assertSame('en', TermReading::matchingLanguage('en_US', $languages, $coverage));
        $this->assertSame('en', TermReading::preferredLanguage($languages, $coverage));
        // A regional tag covering more wins over the language alone.
        $this->assertSame('fr-CA', TermReading::matchingLanguage('fr', ['fr', 'fr-CA'], ['fr' => 3, 'fr-CA' => 40]));
        // Where they tie, the nearest wins.
        $this->assertSame('en-US', TermReading::matchingLanguage('en_US', ['en', 'en-US'], ['en' => 5, 'en-US' => 5]));
        // Not another language, however much it covers.
        $this->assertSame('en-US', TermReading::matchingLanguage('en_US', ['en-US', 'fr'], ['en-US' => 1, 'fr' => 90]));
    }

    public function testNoNearLanguageOrNoneWantedMatchesNothing()
    {
        $this->assertNull(TermReading::matchingLanguage('it', ['de', 'fr']));
        // "f" is no language's primary subtag, so not "fr"'s.
        $this->assertNull(TermReading::matchingLanguage('f', ['fr']));
        $this->assertNull(TermReading::matchingLanguage(null, ['en']));
        $this->assertNull(TermReading::matchingLanguage('', ['en']));
    }
}
