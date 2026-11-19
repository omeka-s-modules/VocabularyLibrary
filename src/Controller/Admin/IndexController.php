<?php
namespace VocabularyLibrary\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Mvc\Exception\NotFoundException;
use Omeka\Mvc\Exception\PermissionDeniedException;
use Omeka\Stdlib\VocabularyImport;
use Omeka\Stdlib\VocabularyReview;
use VocabularyLibrary\Stdlib\Collation;
use VocabularyLibrary\Stdlib\Importer;
use VocabularyLibrary\Stdlib\LanguageNames;
use VocabularyLibrary\Stdlib\PlainText;
use VocabularyLibrary\Stdlib\SiteVocabularies;
use VocabularyLibrary\Stdlib\TermReading;
use VocabularyLibrary\Stdlib\TermSearch;

/**
 * The library's pages: the list of vocabularies with its details sidebar and
 * term search, and the file download; and Import, and Compare and update,
 * which hand the library's file to core's import details and review.
 */
class IndexController extends AbstractActionController
{
    /**
     * The library page's search, filters and sort, which its URL keeps: the
     * page gives the script this list, for saveState(). Import and Compare
     * carry them in "return", so their links back lead to the library as the
     * admin left it.
     */
    const LIBRARY_STATE = ['q', 'terms', 'tag', 'lang', 'show', 'sort_by', 'sort_order'];

    /**
     * The content type a vocabulary's file is sent with, by its extension.
     */
    const CONTENT_TYPES = [
        'jsonld' => 'application/ld+json',
        'nt' => 'application/n-triples',
        'owl' => 'application/rdf+xml',
        'rdf' => 'application/rdf+xml',
        'ttl' => 'text/turtle',
        'xml' => 'application/rdf+xml',
    ];

    protected $importer;
    protected $termSearch;
    protected $languageNames;
    protected $siteVocabularies;
    protected $vocabularyReview;
    protected $vocabularyImport;
    protected $collation;
    protected $vocabularies;
    protected $tags;

    /**
     * @param array $vocabularies The library, keyed by slug
     * @param array $tags Tags to filter by, key => label
     */
    public function __construct(Importer $importer, TermSearch $termSearch, LanguageNames $languageNames, SiteVocabularies $siteVocabularies, VocabularyReview $vocabularyReview, VocabularyImport $vocabularyImport, Collation $collation, array $vocabularies, array $tags)
    {
        $this->importer = $importer;
        $this->termSearch = $termSearch;
        $this->languageNames = $languageNames;
        $this->siteVocabularies = $siteVocabularies;
        $this->vocabularyReview = $vocabularyReview;
        $this->vocabularyImport = $vocabularyImport;
        $this->collation = $collation;
        // The name shown for a vocabulary: the library's alternative title,
        // else the file's title. The build requires one of them.
        foreach ($vocabularies as &$entry) {
            $entry['name'] = $entry['alt_title'] ?? $entry['title'];
        }
        unset($entry);
        $this->vocabularies = $vocabularies;
        $this->tags = $tags;
    }

    public function browseAction()
    {
        $rows = [];
        $tagCounts = array_fill_keys(array_keys($this->tags), 0);
        $languageCounts = [];
        foreach ($this->vocabularies as $slug => $entry) {
            $languages = $this->languageNames->primaries($entry['languages']);
            $rows[] = [
                'slug' => (string) $slug,
                'entry' => $entry,
                'installed' => $this->siteVocabularies->installed($entry),
                'prefixTakenBy' => $this->siteVocabularies->prefixHolder($entry['prefix']),
                'languages' => $languages,
            ];
            foreach ($entry['tags'] as $tag) {
                $tagCounts[$tag]++;
            }
            foreach ($languages as $language) {
                $languageCounts[$language] = ($languageCounts[$language] ?? 0) + 1;
            }
        }
        // The label language filter is for sites working in other languages.
        // Most vocabularies are in English, and many leave English untagged,
        // so an English option would hide most of them.
        unset($languageCounts['en']);
        // As the page's script sorts titles: without a leading English
        // article, so The Music Ontology files under M.
        $sortable = function ($name) {
            return preg_replace('/^(?:the|an?)\s+/i', '', $name);
        };
        usort($rows, function ($a, $b) use ($sortable) {
            return $this->collation->compare($sortable($a['entry']['name']), $sortable($b['entry']['name']));
        });

        $view = new ViewModel;
        $view->setVariable('rows', $rows);
        $view->setVariable('tagLabels', $this->tagLabels($this->tags));
        $view->setVariable('tagCounts', $tagCounts);
        $view->setVariable('languageCounts', $languageCounts);
        $view->setVariable('languageNames', $this->languageNames->names(array_keys($languageCounts)));
        // Only single values: a filter given as a list isn't one this page sets.
        $view->setVariable('query', array_filter($this->params()->fromQuery(), 'is_string'));
        $view->setVariable('termQueryMin', TermSearch::QUERY_MIN);
        $view->setVariable('stateNames', self::LIBRARY_STATE);
        return $view;
    }

    /**
     * The details sidebar. It reads nothing from the vocabulary's file, so it
     * opens at once; its class and property lists come from showTerms().
     */
    public function showDetailsAction()
    {
        [$slug, $entry] = $this->entry();
        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setVariable('slug', $slug);
        $view->setVariable('entry', $entry);
        $view->setVariable('termFilter', $this->termFilter());
        $view->setVariable('tagLabels', $this->tagLabels(array_intersect_key($this->tags, array_flip($entry['tags']))));
        $view->setVariable('languageNames', $this->languageNames->names($this->languageNames->primaries($entry['languages'])));
        $view->setVariable('installed', $this->siteVocabularies->installed($entry));
        $view->setVariable('prefixTakenBy', $this->siteVocabularies->prefixHolder($entry['prefix']));
        return $view;
    }

    /**
     * Hands the library's file to core's import details, which fills in the
     * library's name, summary, namespace, and prefix, and starts on the
     * library's language and reading. The import then ends as any does in
     * core, on the vocabulary's page. A vocabulary Omeka already has, reached
     * from a stale tab or the Back button, says so instead.
     */
    public function importAction()
    {
        [$slug, $entry] = $this->entry();
        $installed = $this->siteVocabularies->installed($entry);
        if ($installed) {
            $view = new ViewModel;
            $view->setVariable('slug', $slug);
            $view->setVariable('entry', $entry);
            $view->setVariable('installed', $installed);
            $view->setVariable('libraryUrl', $this->libraryUrl());
            $view->setVariable('libraryState', http_build_query($this->libraryState()));
            return $view;
        }
        try {
            $url = $this->vocabularyImport->start(
                $this->importer->path($entry),
                [
                    'o:label' => $entry['name'],
                    'o:comment' => $entry['summary'],
                    'o:namespace_uri' => $entry['namespace'],
                    'o:prefix' => $entry['prefix'],
                ],
                [
                    'format' => $this->importer->format($entry),
                    'lang' => $this->importLanguage($entry),
                    'comment_property' => TermReading::COMMENT_PROPERTY,
                ]
            );
        } catch (\Exception $e) {
            $this->messenger()->addErrors($this->errorMessages($e));
            return $this->redirect()->toUrl($this->libraryUrl());
        }
        return $this->redirect()->toUrl($url);
    }

    /**
     * Starts core's review of the changes the library's file would make to
     * Omeka's vocabulary, and goes there. Core's review reads the file as the
     * vocabulary was read, or else guesses how, offers the choices, checks
     * the user may update it, and applies the changes on Accept. Where
     * Omeka's vocabulary has the library's namespace in another form, the
     * review changes it to the library's along with the rest. Accept and
     * Cancel end on the vocabulary's page, as any review does.
     */
    public function reviewAction()
    {
        [, $entry] = $this->entry();
        $installed = $this->siteVocabularies->installed($entry);
        if (!$installed) {
            throw new NotFoundException;
        }
        if (!$installed['updatable']) {
            throw new PermissionDeniedException('Cannot update this vocabulary');
        }
        try {
            $url = $this->vocabularyReview->start(
                $installed['vocabulary']->id(),
                $this->importer->path($entry),
                // The file's own format, never left to the one stored for
                // Omeka's vocabulary, which may be another file's.
                ['format' => $this->importer->format($entry)],
                [
                    // Compared exactly: a namespace that differs only in
                    // capitals is the same vocabulary to Omeka, but not to
                    // the review's reading of the file.
                    'new_namespace_uri' => $installed['vocabulary']->namespaceUri() === $entry['namespace'] ? null : $entry['namespace'],
                    'source_label' => $this->translate('the library\'s vocabulary'),
                ]
            );
        } catch (\Exception $e) {
            $this->messenger()->addErrors($this->errorMessages($e));
            return $this->redirect()->toUrl($this->libraryUrl());
        }
        return $this->redirect()->toUrl($url);
    }

    /**
     * The details sidebar's class and property lists, read from the
     * vocabulary's file in the language the library shows it in.
     */
    public function showTermsAction()
    {
        [, $entry] = $this->entry();
        $members = $this->importer->members($entry, TermReading::preferredLanguage($entry['languages'], $entry['language_coverage']));
        foreach ($members as &$terms) {
            // Some files put HTML in their labels and comments, which is
            // shown here as text; import stores them as they are, as Omeka's
            // own import does. Labels are one line, as the term index has
            // them, so the term filter matches what's shown.
            foreach ($terms as &$term) {
                $term['label'] = PlainText::line($term['label']);
                if ($term['comment'] !== null) {
                    $term['comment'] = PlainText::from($term['comment']);
                }
            }
            unset($term);
            uasort($terms, function ($a, $b) {
                return $this->collation->compare($a['label'], $b['label']);
            });
        }
        unset($terms);

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setVariable('entry', $entry);
        $view->setVariable('termFilter', $this->termFilter());
        $view->setVariable('classes', $members['classes']);
        $view->setVariable('properties', $members['properties']);
        return $view;
    }

    /**
     * Finds the vocabularies with a class or property matching the query.
     *
     * @return JsonModel {"matches": {slug: {"terms": [{"label": …, "term":
     *   "prefix:localName"}, …], "count": number}}}, from TermSearch::search()
     */
    public function termSearchAction()
    {
        $query = $this->params()->fromQuery('q');
        $matches = $this->termSearch->search(is_string($query) ? $query : '');
        // An object even when empty, as the script reads it.
        return new JsonModel(['matches' => $matches ?: new \stdClass]);
    }

    /**
     * Sends the vocabulary's file as a download.
     */
    public function fileAction()
    {
        [$slug, $entry] = $this->entry();
        $path = $this->importer->path($entry);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $response = $this->getResponse();
        $response->getHeaders()
            ->addHeaderLine('Content-Type', self::CONTENT_TYPES[$extension] ?? 'application/octet-stream')
            ->addHeaderLine('Content-Disposition', sprintf('attachment; filename="%s.%s"', $slug, $extension))
            ->addHeaderLine('Content-Length', filesize($path));
        $response->setContent(file_get_contents($path));
        return $response;
    }

    /**
     * Tags' labels in the admin's language, in alphabetical order.
     *
     * @param array $tags tag => label, as in the module's config
     * @return array tag => label
     */
    protected function tagLabels(array $tags)
    {
        return $this->collation->sorted(array_map([$this, 'translate'], $tags));
    }

    /**
     * The messages for an error from core's importer, as core's import shows
     * them: the importer's own, and any from the API's validation.
     *
     * @return string[]
     */
    protected function errorMessages(\Exception $e)
    {
        $messages = [];
        if ($e->getMessage()) {
            $messages[] = $e->getMessage();
        }
        if (method_exists($e, 'getErrorStore')) {
            foreach ($e->getErrorStore()->getErrors() as $message) {
                $messages[] = $message;
            }
        }
        return $messages;
    }

    /**
     * Text to filter the class and property lists by, when the sidebar opens
     * from a term search's matches.
     */
    protected function termFilter()
    {
        $filter = $this->params()->fromQuery('filter');
        return is_string($filter) ? trim($filter) : '';
    }

    /**
     * The library page's state carried in "return", as only the names it
     * keeps, each a single value.
     *
     * @return array name => value
     */
    protected function libraryState()
    {
        $return = $this->params()->fromQuery('return');
        if (!is_string($return)) {
            return [];
        }
        parse_str($return, $params);
        $state = [];
        foreach (self::LIBRARY_STATE as $name) {
            if (isset($params[$name]) && is_string($params[$name]) && $params[$name] !== '') {
                $state[$name] = $params[$name];
            }
        }
        return $state;
    }

    /**
     * The language the import form starts on: the one the library was
     * filtered by, else the installation's, each matched to the vocabulary's
     * languages as core's import matches them, so the filter's "zh" finds
     * "zh-Hans-CN", and an en_US site starts on "en" where "en-US" tags only
     * a few labels; else the library's default for the vocabulary.
     */
    protected function importLanguage(array $entry)
    {
        foreach ([$this->libraryState()['lang'] ?? null, $this->settings()->get('locale')] as $wanted) {
            $lang = TermReading::matchingLanguage($wanted, $entry['languages'], $entry['language_coverage']);
            if (null !== $lang) {
                return $lang;
            }
        }
        return TermReading::preferredLanguage($entry['languages'], $entry['language_coverage']);
    }

    /**
     * The library page, as the admin left it when they came here from it.
     */
    protected function libraryUrl()
    {
        $state = $this->libraryState();
        return $this->url()->fromRoute('admin/vocabulary-library', [], $state ? ['query' => $state] : []);
    }

    /**
     * The library entry named in the route.
     *
     * @return array [slug, entry]
     */
    protected function entry()
    {
        $slug = (string) $this->params('slug');
        if (!isset($this->vocabularies[$slug])) {
            throw new NotFoundException;
        }
        return [$slug, $this->vocabularies[$slug]];
    }
}
