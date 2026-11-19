<?php
namespace VocabularyLibraryTest\Unit;

use PHPUnit\Framework\TestCase;
use VocabularyLibrary\Stdlib\TermReading;

class TermReadingTest extends TestCase
{
    public function testEnglishIsPreferred()
    {
        $this->assertSame('en', TermReading::preferredLanguage(['de', 'en', 'en-US']));
        // Of English's tags, the one covering the most terms: DCAT tags two
        // labels en-US and 90 en.
        $this->assertSame('en', TermReading::preferredLanguage(['en', 'en-GB', 'en-US', 'fr'], ['en' => 90, 'en-GB' => 2, 'en-US' => 2, 'fr' => 32]));
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

    public function testItMatchesAsCoresImportDetailsDoes()
    {
        // The library copies core's protected rule, so it can't drift from
        // it unseen, and core's tests are what say the rule is right. Core
        // falls back to English itself, where the library goes on to
        // preferredLanguage().
        $core = new \ReflectionMethod(\Omeka\Stdlib\RdfImporter::class, 'getMatchingLanguage');
        $core->setAccessible(true);
        $importer = (new \ReflectionClass(\Omeka\Stdlib\RdfImporter::class))->newInstanceWithoutConstructor();
        $cases = [
            ['en_US', ['en', 'en-GB', 'en-US', 'fr'], ['en' => 90, 'en-GB' => 2, 'en-US' => 2, 'fr' => 32]],
            ['en_US', ['en', 'en-US'], ['en' => 5, 'en-US' => 5]],
            ['fr', ['fr', 'fr-CA'], ['fr' => 3, 'fr-CA' => 40]],
            ['fr-ca', ['fr', 'fr-CA'], []],
            ['fr-ca', ['fr', 'fr-FR'], []],
            ['fr', ['de', 'fr-fr'], []],
            ['zh', ['en', 'zh-Hans-CN'], []],
            ['en_US', ['en-US', 'fr'], ['en-US' => 1, 'fr' => 90]],
            ['zh_CN', ['en', 'zh-Hans-CN'], ['en' => 10, 'zh-Hans-CN' => 10]],
            ['pt_BR', ['en', 'pt', 'pt-BR'], ['en' => 1, 'pt' => 4, 'pt-BR' => 9]],
            ['de', ['EN', 'fr'], ['EN' => 3, 'fr' => 3]],
            ['it', ['de', 'fr'], []],
        ];
        foreach ($cases as [$wanted, $languages, $coverage]) {
            $library = TermReading::matchingLanguage($wanted, $languages, $coverage)
                ?? TermReading::matchingLanguage('en', $languages, $coverage);
            $this->assertSame($core->invoke($importer, $wanted, $languages, $coverage), $library, $wanted . ' in ' . implode(' ', $languages));
        }
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
