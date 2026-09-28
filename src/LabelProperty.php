<?php
namespace VocabularyLibrary;

/**
 * Where a term's label is read from, as core's importer takes it: a list of
 * properties, of which the first the term has wins. The library keeps core's
 * own default (RdfImporter::getMembers()).
 *
 * The module imports, shows and compares terms with it, and the build writes
 * the term index with it, so the two always agree. The build's scripts load
 * it without starting Omeka, so it must not use Omeka's services,
 * configuration or database.
 */
class LabelProperty
{
    const LIBRARY = 'skos:prefLabel|rdfs:label|foaf:name|rss:title|dc:title|dc11:title';
}
