<?php
namespace VocabularyLibrary\Stdlib;

use Laminas\View\Helper\Url;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\VocabularyRepresentation;
use VocabularyLibrary\NamespaceUri;

/**
 * The site's vocabularies, matched to the library's: which library
 * vocabulary's namespace is in use, and which prefixes are taken. The site's
 * vocabularies are looked up once per request.
 */
class SiteVocabularies
{
    protected $api;
    protected $url;

    /**
     * @var VocabularyRepresentation[]|null
     */
    protected $vocabularies;

    /**
     * @var array|null prefix => label
     */
    protected $prefixes;

    /**
     * @param Url $url Core's url view helper
     */
    public function __construct(ApiManager $api, Url $url)
    {
        $this->api = $api;
        $this->url = $url;
    }

    /**
     * The site's vocabulary with an entry's namespace, written the same way
     * or not. A site can have both forms, for example http://schema.org/ and
     * https://schema.org/; an exact match wins.
     *
     * @return array|null ['vocabulary' => representation, 'url' => link,
     *   'exact' => whether the namespace is written the same way,
     *   'comparable' => whether this user can compare it with the library's
     *   file and update it: written the same way, not one Omeka keeps fixed
     *   (Dublin Core and its types), and one the user may change (site
     *   administrators may change only their own)]
     */
    public function installed(array $entry)
    {
        $namespace = $entry['namespace'];
        $match = null;
        foreach ($this->vocabularies() as $vocabulary) {
            if (mb_strtolower($vocabulary->namespaceUri()) === mb_strtolower($namespace)) {
                $match = $vocabulary;
                break;
            }
            if (!$match && NamespaceUri::normalized($vocabulary->namespaceUri()) === NamespaceUri::normalized($namespace)) {
                $match = $vocabulary;
            }
        }
        if (!$match) {
            return null;
        }
        $exact = mb_strtolower($match->namespaceUri()) === mb_strtolower($namespace);
        return [
            'vocabulary' => $match,
            'url' => $this->url($match),
            'exact' => $exact,
            'comparable' => $exact && !$match->isPermanent() && $match->userIsAllowed('update'),
        ];
    }

    /**
     * The site's vocabulary prefixes, lowercased, with the label of the
     * vocabulary that holds each. Omeka compares prefixes without regard to
     * case.
     *
     * @return array prefix => label
     */
    public function prefixes()
    {
        if ($this->prefixes === null) {
            $this->prefixes = [];
            foreach ($this->vocabularies() as $vocabulary) {
                $this->prefixes[mb_strtolower($vocabulary->prefix())] = $vocabulary->label();
            }
        }
        return $this->prefixes;
    }

    /**
     * The label of the site's vocabulary that has a prefix, in any case.
     *
     * @return string|null Null if no vocabulary has it
     */
    public function prefixHolder($prefix)
    {
        return $this->prefixes()[mb_strtolower($prefix)] ?? null;
    }

    /**
     * Core's Vocabularies page, showing only this vocabulary. Core has no
     * vocabulary show page, and its browse passes the query to the API, which
     * filters by namespace_uri.
     */
    public function url(VocabularyRepresentation $vocabulary)
    {
        return ($this->url)('admin/default', ['controller' => 'vocabulary', 'action' => 'browse'], ['query' => ['namespace_uri' => $vocabulary->namespaceUri()]]);
    }

    /**
     * @return VocabularyRepresentation[]
     */
    protected function vocabularies()
    {
        if ($this->vocabularies === null) {
            $this->vocabularies = $this->api->search('vocabularies')->getContent();
        }
        return $this->vocabularies;
    }
}
