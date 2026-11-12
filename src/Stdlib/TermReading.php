<?php
namespace VocabularyLibrary\Stdlib;

/**
 * How a term's label and comment are read from a vocabulary's file: from
 * which properties, and in which language by default.
 *
 * The module imports, shows and compares terms with these, and the build
 * checks files and writes the term index with them, so the two always agree,
 * and term search shows the labels the sidebar shows. The build's scripts
 * load the class without starting Omeka, so it must not use Omeka's services,
 * configuration or database.
 */
class TermReading
{
    /**
     * Where a term's label is read from, as core's importer takes it: a list
     * of properties, of which the first the term has wins. The library keeps
     * core's own default (RdfImporter::getMembers()).
     */
    const LABEL_PROPERTY = 'skos:prefLabel|rdfs:label|foaf:name|rss:title|dc:title|dc11:title';

    /**
     * Where a term's comment is read from, in the same form: a term's
     * definition, else its comment, else a description. Many files define
     * their terms in skos:definition, which Omeka's own import doesn't read,
     * and where a term has both, the definition is nearly always the
     * definition and the comment a note (MARC 21's field codes, BIBFRAME's
     * suggested values).
     */
    const COMMENT_PROPERTY = 'skos:definition|rdfs:comment|dcterms:description|dc11:description';

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
        if (in_array('en', $languages, true)) {
            return 'en';
        }
        return current(preg_grep('/^en-/i', $languages)) ?: ($languages[0] ?? null);
    }
}
