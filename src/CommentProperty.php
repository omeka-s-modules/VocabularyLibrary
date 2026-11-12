<?php
namespace VocabularyLibrary;

/**
 * Where a term's comment is read from, as core's importer takes it: a list
 * of properties, of which the first the term has wins.
 *
 * The module imports, shows and compares terms with LIBRARY, and the build
 * checks files with it, so the two always agree. The build's scripts
 * load it without starting Omeka, so it must not use Omeka's services,
 * configuration or database.
 */
class CommentProperty
{
    /**
     * The library's: a term's definition, else its comment, else a
     * description. Many files define their terms in skos:definition, which
     * Omeka's own import doesn't read, and where a term has both, the
     * definition is nearly always the definition and the comment a note
     * (MARC 21's field codes, BIBFRAME's suggested values).
     */
    const LIBRARY = 'skos:definition|rdfs:comment|dcterms:description|dc11:description';

    /**
     * Omeka's own import form's default, for comparing a vocabulary that was
     * imported outside the library.
     */
    const OMEKA = 'rdfs:comment';
}
