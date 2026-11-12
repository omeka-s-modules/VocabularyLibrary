<?php
namespace VocabularyLibrary\Stdlib;

use Laminas\I18n\Translator\TranslatorInterface;

/**
 * Alphabetical order as the admin's language has it, for what the library
 * lists: vocabularies, their terms, tags and language names. Where PHP's
 * intl extension is loaded, an accented name doesn't follow "z"; otherwise
 * texts are ordered by their bytes, without regard to case.
 */
class Collation
{
    protected $translator;

    /**
     * @var \Collator|null
     */
    protected $collator;

    /**
     * @param TranslatorInterface $translator The translator core's lang view
     *   helper gets, which holds the admin's locale
     */
    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * Compares two texts, as a sort function does.
     *
     * @return int Below, at or above zero
     */
    public function compare($a, $b)
    {
        $collator = $this->collator();
        $order = $collator ? $collator->compare((string) $a, (string) $b) : false;
        // The collator can't compare text that isn't valid UTF-8, which a
        // vocabulary's file may hold.
        return $order === false ? strnatcasecmp((string) $a, (string) $b) : $order;
    }

    /**
     * Texts in alphabetical order, with their keys.
     *
     * @param string[] $texts
     * @return string[]
     */
    public function sorted(array $texts)
    {
        $collator = $this->collator();
        $sorted = $texts;
        if ($collator && $collator->asort($sorted)) {
            return $sorted;
        }
        uasort($texts, [$this, 'compare']);
        return $texts;
    }

    /**
     * @return \Collator|null Null without PHP's intl extension
     */
    protected function collator()
    {
        if ($this->collator === null && extension_loaded('intl')) {
            // As core's lang view helper writes the locale.
            $this->collator = new \Collator(str_replace('_', '-', $this->translator->getLocale()));
            $this->collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
        }
        return $this->collator;
    }
}
