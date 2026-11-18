<?php
namespace VocabularyLibrary\Stdlib;

use Omeka\Stdlib\RdfImporter;

/**
 * How a term's label and comment are read from a vocabulary's file: from
 * which properties, and in which language by default.
 *
 * The module imports, shows and compares terms with these, and the build
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
     * their terms in skos:definition, which Omeka's own import doesn't read,
     * and where a term has both, the definition is nearly always the
     * definition and the comment a note (BIBFRAME's suggested values, EDM's
     * usage notes). It is core's "Definition, else comment", by reference, so
     * core's review names it as that choice, which it finds by the exact
     * string.
     */
    const COMMENT_PROPERTY = RdfImporter::DEFINITION_COMMENT_PROPERTY;

    /**
     * Omeka's own import form's default, for comparing a vocabulary that was
     * imported outside the library.
     */
    const OMEKA_COMMENT_PROPERTY = 'rdfs:comment';

    /**
     * The language a vocabulary's labels and comments are read in by default:
     * English if the file has it, else a regional English such as en-US, else
     * its first language, else none.
     *
     * @param string[] $languages The language tags of a file's labels
     * @return string|null
     */
    public static function preferredLanguage(array $languages)
    {
        return self::matchingLanguage('en', $languages) ?? ($languages[0] ?? null);
    }

    /**
     * The one of a file's languages nearest a wanted one, as core's import
     * finds it (RdfImporter::getMatchingLanguage()): the wanted tag itself
     * (fr-ca), its language alone (fr), then its language in any region
     * (fr-FR for fr). Tags match in any case, and "_" is read as "-", as in
     * Omeka's locale setting (pt_BR).
     *
     * @param string|null $wanted A language tag or locale
     * @param string[] $languages The language tags of a file's labels
     * @return string|null One of the file's languages, as the file has it;
     *   null where none is near
     */
    public static function matchingLanguage($wanted, array $languages)
    {
        $wanted = strtolower(str_replace('_', '-', (string) $wanted));
        if ('' === $wanted) {
            return null;
        }
        $primary = strtok($wanted, '-');
        // A wanted tag ending in "-" matches its language in any region.
        foreach (array_unique([$wanted, $primary, $primary . '-']) as $candidate) {
            foreach ($languages as $language) {
                $tag = strtolower($language);
                if ($tag === $candidate || ('-' === substr($candidate, -1) && 0 === strpos($tag, $candidate))) {
                    return $language;
                }
            }
        }
        return null;
    }
}
