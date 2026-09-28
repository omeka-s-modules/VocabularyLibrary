<?php
namespace VocabularyLibrary\Stdlib;

use Laminas\I18n\Translator\TranslatorInterface;

/**
 * The language tags of vocabularies' labels, named in the admin's language,
 * for the library's label language filter, the details sidebar, and the
 * language menus on the import form and Compare page.
 */
class LanguageNames
{
    protected $translator;

    /**
     * @param TranslatorInterface $translator The translator core's lang view
     *   helper gets, which holds the admin's locale
     */
    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
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
        uasort($names, 'strnatcasecmp');
        return $names;
    }

    /**
     * A menu of language tags, each named as the filter and sidebar name it,
     * with the tag itself, which a regional one needs and core's forms use:
     * "German (de)", "English (en-US)". A language that can't be named is its
     * tag alone. In alphabetical order of the text.
     *
     * @param string[] $tags
     * @return array tag => option text
     */
    public function options(array $tags)
    {
        $names = $this->names($this->primaries($tags));
        $options = [];
        foreach ($tags as $tag) {
            $primary = strtolower(explode('-', $tag)[0]);
            $options[$tag] = $names[$primary] === $primary ? $tag : sprintf('%s (%s)', $names[$primary], $tag);
        }
        uasort($options, 'strnatcasecmp');
        return $options;
    }
}
