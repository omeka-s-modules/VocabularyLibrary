<?php
namespace VocabularyLibraryTest\Unit;

use Laminas\I18n\Translator\Translator;
use PHPUnit\Framework\TestCase;
use VocabularyLibrary\Stdlib\LanguageNames;

class LanguageNamesTest extends TestCase
{
    protected function languageNames($locale)
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('Naming languages needs PHP\'s intl extension.');
        }
        $translator = $this->createMock(Translator::class);
        $translator->method('getLocale')->willReturn($locale);
        return new LanguageNames($translator);
    }

    public function testPrimariesAreLanguagesWithoutRegionsOrRepeats()
    {
        $this->assertSame(['en', 'de', 'zh'], $this->languageNames('en_US')->primaries(['en-GB', 'en-us', 'de', 'EN', 'zh-Hant']));
    }

    public function testLanguagesAreNamedInTheAdminsLanguage()
    {
        $this->assertSame(['fr' => 'French', 'de' => 'German'], $this->languageNames('en_US')->names(['de', 'fr']));
        $this->assertSame('alemán', $this->languageNames('es')->names(['de'])['de']);
    }

    public function testNamesAreInTheOrderOfTheAdminsLanguage()
    {
        // By bytes, "árabe" would follow "vietnamita".
        $this->assertSame(
            ['alemán', 'árabe', 'chino', 'francés', 'inglés', 'vietnamita'],
            array_values($this->languageNames('es')->names(['vi', 'ar', 'de', 'en', 'zh', 'fr']))
        );
    }

    public function testALanguageThatCannotBeNamedKeepsItsCode()
    {
        $this->assertSame(['qqq' => 'qqq'], $this->languageNames('en_US')->names(['qqq']));
    }

    public function testOptionsNameEachTagWithTheTagItself()
    {
        $this->assertSame(
            ['en-US' => 'English (en-US)', 'de' => 'German (de)', 'qqq' => 'qqq'],
            $this->languageNames('en_US')->options(['de', 'qqq', 'en-US'])
        );
    }
}
