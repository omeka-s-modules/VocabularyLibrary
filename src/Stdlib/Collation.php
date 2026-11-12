<?php
namespace VocabularyLibrary\Stdlib;

use Laminas\I18n\Translator\Translator;

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
     * @param Translator $translator The translator core's lang view helper
     *   gets, which holds the admin's locale
     */
    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    /**
     * Compares two texts, as a sort function does. Text that isn't valid
     * UTF-8, which a vocabulary's file may hold and the collator can't
     * compare, follows all that is, in the order of its bytes, so the order
     * is the same however a list is sorted.
     *
     * @return int Below, at or above zero
     */
    public function compare($a, $b)
    {
        [$a, $b] = [(string) $a, (string) $b];
        $collator = $this->collator();
        if (!$collator) {
            return strnatcasecmp($a, $b);
        }
        [$validA, $validB] = [mb_check_encoding($a, 'UTF-8'), mb_check_encoding($b, 'UTF-8')];
        if ($validA && $validB) {
            return (int) $collator->compare($a, $b);
        }
        return $validA === $validB ? strcmp($a, $b) : ($validA ? -1 : 1);
    }

    /**
     * Texts in alphabetical order, with their keys.
     *
     * @param string[] $texts
     * @return string[]
     */
    public function sorted(array $texts)
    {
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
