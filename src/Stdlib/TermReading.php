<?php
namespace VocabularyLibrary\Stdlib;

use Omeka\Stdlib\RdfImporter;

/**
 * How a term's label and comment are read from a vocabulary's file: from
 * which properties, and in which language by default.
 *
 * The module imports and shows terms with these, and the build
 * checks files and writes the term index with them, so the two always agree,
 * and term search shows the labels the sidebar shows. The build's scripts
 * load the class without starting Omeka, so it may use core's classes, as
 * the build's import check does, but not Omeka's services, configuration or
 * database.
 */
class TermReading
{
    /**
     * Where a term's label is read from, as core's importer takes it: a list
     * of properties, of which the first the term has wins. The library keeps
     * core's own default.
     */
    const LABEL_PROPERTY = RdfImporter::LABEL_PROPERTY;

    /**
     * Where a term's comment is read from, in the same form: a term's
     * definition, else its comment, else a description. Many files define
     * their terms in skos:definition, and where a term has both, the
     * definition is nearly always the
     * definition and the comment a note (BIBFRAME's suggested values, EDM's
     * usage notes). It is core's "Definition, else comment", by reference, so
     * core's review names it as that choice, which it finds by the exact
     * string.
     */
    const COMMENT_PROPERTY = RdfImporter::DEFINITION_COMMENT_PROPERTY;

    /**
     * The language a vocabulary's labels and comments are read in by default:
     * English if the file has it, else a regional English such as en-US, else
     * its first language, else none. Given the file's coverage, the English
     * tag that covers the most terms wins, as in matchingLanguage().
     *
     * @param string[] $languages The language tags of a file's labels
     * @param int[] $coverage How many terms have a label or comment in
     *   each, by tag, as core counts them (the build's language_coverage)
     * @return string|null
     */
    public static function preferredLanguage(array $languages, array $coverage = [])
    {
        return self::matchingLanguage('en', $languages, $coverage) ?? ($languages[0] ?? null);
    }

    /**
     * The one of a file's languages nearest a wanted one, as core's import
     * finds it (RdfImporter::getMatchingLanguage()). The wanted language's
     * tags are the wanted tag itself (fr-ca), its language alone (fr), and its
     * language in any region (fr-FR for fr). Of those, the one that covers the
     * most terms wins, the nearest where they tie: a file may tag a few labels
     * en-US for an American spelling and the rest en, and en-US would leave
     * most terms in whatever language comes first. Tags match in any case, and
     * "_" is read as "-", as in Omeka's locale setting (pt_BR).
     *
     * @param string|null $wanted A language tag or locale
     * @param string[] $languages The language tags of a file's labels
     * @param int[] $coverage How many terms have a label or comment in each,
     *   by tag, as core counts them (the build's language_coverage); without
     *   it, the nearest wins
     * @return string|null One of the file's languages, as the file has it;
     *   null where none is near
     */
    public static function matchingLanguage($wanted, array $languages, array $coverage = [])
    {
        $wanted = strtolower(str_replace('_', '-', (string) $wanted));
        if ('' === $wanted) {
            return null;
        }
        $primary = strtok($wanted, '-');
        // By nearness: 0 for the tag itself, 1 for its language alone, 2 for
        // its language in any region.
        $nearness = [];
        foreach ($languages as $language) {
            $tag = strtolower($language);
            if ($tag === $wanted) {
                $nearness[$language] = 0;
            } elseif ($tag === $primary) {
                $nearness[$language] = 1;
            } elseif (0 === strpos($tag, $primary . '-')) {
                $nearness[$language] = 2;
            }
        }
        if (!$nearness) {
            return null;
        }
        uksort($nearness, function ($a, $b) use ($nearness, $coverage) {
            return [$coverage[$b] ?? 0, $nearness[$a]] <=> [$coverage[$a] ?? 0, $nearness[$b]];
        });
        return array_key_first($nearness);
    }
}
