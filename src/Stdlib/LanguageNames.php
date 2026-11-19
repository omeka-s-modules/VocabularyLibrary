<?php
namespace VocabularyLibrary\Stdlib;

use Laminas\I18n\Translator\Translator;

/**
 * The language tags of vocabularies' labels, named in the admin's language,
 * for the library's label language filter, the details sidebar, and the
 * import form's language menu.
 */
class LanguageNames
{
    protected $translator;
    protected $collation;

    /**
     * @param Translator $translator The translator core's lang view helper
     *   gets, which holds the admin's locale
     */
    public function __construct(Translator $translator, Collation $collation)
    {
        $this->translator = $translator;
        $this->collation = $collation;
    }

    /**
     * The languages of language tags, as primary language subtags (en-GB and
     * en-us are both en), without repeats.
     *
     * @param string[] $tags
     * @return string[]
     */
    public function primaries(array $tags)
    {
        $languages = [];
        foreach ($tags as $tag) {
            $languages[strtolower(explode('-', $tag)[0])] = true;
        }
        return array_keys($languages);
    }

    /**
     * Language codes named in the admin's language, where PHP's intl
     * extension can name them, otherwise by code, in alphabetical order of
     * their names.
     *
     * @param string[] $codes
     * @return array code => name
     */
    public function names(array $codes)
    {
        // As core's lang view helper writes the locale.
        $locale = str_replace('_', '-', $this->translator->getLocale());
        $names = [];
        foreach ($codes as $code) {
            $name = extension_loaded('intl') ? \Locale::getDisplayLanguage($code, $locale) : $code;
            $names[$code] = ($name === '' || $name === $code) ? $code : $name;
        }
        return $this->collation->sorted($names);
    }

}
