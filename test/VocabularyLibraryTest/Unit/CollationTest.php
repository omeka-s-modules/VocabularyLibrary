<?php
namespace VocabularyLibraryTest\Unit;

use Laminas\I18n\Translator\Translator;
use PHPUnit\Framework\TestCase;
use VocabularyLibrary\Stdlib\Collation;

class CollationTest extends TestCase
{
    protected function collation($locale = 'en_US')
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('Ordering by language needs PHP\'s intl extension.');
        }
        $translator = $this->createMock(Translator::class);
        $translator->method('getLocale')->willReturn($locale);
        return new Collation($translator);
    }

    public function testAnAccentedTextDoesNotFollowZ()
    {
        // By bytes, "Événement" would come last.
        $this->assertSame(
            ['b' => 'agent', 'c' => 'Événement', 'a' => 'Zone'],
            $this->collation('fr')->sorted(['a' => 'Zone', 'b' => 'agent', 'c' => 'Événement'])
        );
    }

    public function testNumbersAreOrderedByTheirValue()
    {
        $this->assertSame(['Field 2', 'Field 10'], array_values($this->collation()->sorted(['Field 10', 'Field 2'])));
    }

    public function testTextThatIsNotValidUtf8IsStillOrdered()
    {
        // The collator can't compare it, so it is ordered by its bytes.
        $collation = $this->collation();
        $this->assertLessThan(0, $collation->compare("a\xff", 'b'));
        $this->assertGreaterThan(0, $collation->compare('b', "a\xff"));
        $this->assertSame(['apple', "a\xff", 'b'], array_values($collation->sorted(['b', "a\xff", 'apple'])));
    }

    public function testCompareOrdersTwoTexts()
    {
        $collation = $this->collation();
        $this->assertLessThan(0, $collation->compare('apple', 'Banana'));
        $this->assertGreaterThan(0, $collation->compare('Zone', 'Événement'));
        $this->assertSame(0, $collation->compare('same', 'same'));
    }
}
