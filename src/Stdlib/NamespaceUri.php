<?php
namespace VocabularyLibrary\Stdlib;

/**
 * A vocabulary's namespace URI without its scheme, case or trailing # or /,
 * for spotting the same namespace written two ways, such as
 * http://schema.org/ and https://schema.org/.
 *
 * The build uses it to refuse a namespace that is already in the collection or
 * excluded. It treats as one namespace exactly those that core's import
 * details find as one vocabulary (Vocabulary::getNamespaceUriForms(), in any
 * case, as Omeka's database compares namespaces), as its test checks, so the
 * collection never holds two vocabularies Omeka would take for one. The build's
 * scripts load it without starting Omeka, so it must not use Omeka's
 * services, configuration or database.
 */
class NamespaceUri
{
    public static function normalized($namespace)
    {
        return rtrim(preg_replace('#^https?://#i', '', mb_strtolower((string) $namespace)), '#/');
    }
}
