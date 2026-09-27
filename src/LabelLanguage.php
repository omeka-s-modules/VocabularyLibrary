<?php
namespace VocabularyLibrary;

/**
 * The language a vocabulary's labels and comments are read in by default:
 * English if the file has it, else a regional English such as en-US, else its
 * first language, else none.
 *
 * The module imports and shows terms in it, and the build writes the term
 * index in it, so term search shows the labels the sidebar shows.
 */
class LabelLanguage
{
    /**
     * @param string[] $languages The language tags of a file's labels
     * @return string|null
     */
    public static function preferred(array $languages)
    {
        if (in_array('en', $languages, true)) {
            return 'en';
        }
        return current(preg_grep('/^en-/i', $languages)) ?: ($languages[0] ?? null);
    }
}
