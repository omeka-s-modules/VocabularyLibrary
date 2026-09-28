<?php
namespace VocabularyLibrary;

/**
 * A vocabulary's namespace URI without its scheme, case or trailing # or /,
 * for spotting the same namespace written two ways, such as
 * http://schema.org/ and https://schema.org/.
 *
 * The build uses it to refuse a namespace that is already in the collection or
 * excluded, and the module to find a site's vocabulary however the site
 * writes its namespace, so the two always agree. The build's scripts load it
 * without starting Omeka, so it must not use Omeka's services, configuration
 * or database.
 */
class NamespaceUri
{
    public static function normalized($namespace)
    {
        return rtrim(preg_replace('#^https?://#i', '', mb_strtolower((string) $namespace)), '#/');
    }
}
