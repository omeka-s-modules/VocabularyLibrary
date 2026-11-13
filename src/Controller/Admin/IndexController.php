<?php
namespace VocabularyLibrary\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Representation\VocabularyRepresentation;
use Omeka\Form\ConfirmForm;
use Omeka\Mvc\Exception\NotFoundException;
use Omeka\Mvc\Exception\PermissionDeniedException;
use Omeka\Stdlib\Message;
use VocabularyLibrary\Form\ImportForm;
use VocabularyLibrary\Stdlib\Collation;
use VocabularyLibrary\Stdlib\Importer;
use VocabularyLibrary\Stdlib\LanguageNames;
use VocabularyLibrary\Stdlib\PlainText;
use VocabularyLibrary\Stdlib\SiteVocabularies;
use VocabularyLibrary\Stdlib\TermReading;
use VocabularyLibrary\Stdlib\TermSearch;

/**
 * The library's pages: the list of vocabularies with its details sidebar and
 * term search, the import form, Compare and update, and the file download.
 */
class IndexController extends AbstractActionController
{
    /**
     * About where the description is cut in the details sidebar.
     */
    const EXCERPT_LENGTH = 200;

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
    protected $collation;
    protected $vocabularies;
    protected $tags;

    /**
     * @param array $vocabularies The library, keyed by slug
     * @param array $tags Tags to filter by, key => label
     */
    public function __construct(Importer $importer, TermSearch $termSearch, LanguageNames $languageNames, SiteVocabularies $siteVocabularies, Collation $collation, array $vocabularies, array $tags)
    {
        $this->importer = $importer;
        $this->termSearch = $termSearch;
        $this->languageNames = $languageNames;
        $this->siteVocabularies = $siteVocabularies;
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
        usort($rows, function ($a, $b) {
            return $this->collation->compare($a['entry']['name'], $b['entry']['name']);
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
        [$excerpt, $rest] = PlainText::excerpt($entry['description'], self::EXCERPT_LENGTH);
        $view->setVariable('excerpt', $excerpt);
        $view->setVariable('rest', $rest);
        $view->setVariable('tagLabels', $this->tagLabels(array_intersect_key($this->tags, array_flip($entry['tags']))));
        $view->setVariable('languageNames', $this->languageNames->names($this->languageNames->primaries($entry['languages'])));
        $view->setVariable('installed', $this->siteVocabularies->installed($entry));
        $view->setVariable('prefixTakenBy', $this->siteVocabularies->prefixHolder($entry['prefix']));
        return $view;
    }

    public function importAction()
    {
        [$slug, $entry] = $this->entry();
        $installed = $this->siteVocabularies->installed($entry);

        $view = new ViewModel;
        $view->setVariable('slug', $slug);
        $view->setVariable('entry', $entry);
        $view->setVariable('installed', $installed);
        $view->setVariable('libraryUrl', $this->libraryUrl());
        if ($installed) {
            return $view;
        }

        $form = $this->getForm(ImportForm::class, ['languages' => $this->languageNames->options($entry['languages'])]);
        // The label and comment the vocabulary gets in Omeka: the name shown in
        // the library, and the library's summary.
        $form->setData([
            'o:prefix' => $entry['prefix'],
            'o:label' => $entry['name'],
            'o:comment' => $entry['summary'],
            'lang' => TermReading::preferredLanguage($entry['languages']),
        ]);
        $view->setVariable('form', $form);
        $view->setVariable('installedPrefixes', $this->siteVocabularies->prefixes());

        if (!$this->getRequest()->isPost()) {
            return $view;
        }
        $form->setData($this->params()->fromPost());
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $view;
        }
        $data = $form->getData();
        $lang = $form->has('lang') ? $data['lang'] : TermReading::preferredLanguage($entry['languages']);
        try {
            $vocabulary = $this->importer->import($entry, [
                'o:prefix' => $data['o:prefix'],
                'o:label' => $data['o:label'],
                'o:comment' => $data['o:comment'],
            ], $lang);
        } catch (\Exception $e) {
            // As core's import does: the importer's message, and any from the
            // API's validation.
            $messages = [];
            if ($e->getMessage()) {
                $messages[] = $e->getMessage();
            }
            if (method_exists($e, 'getErrorStore')) {
                foreach ($e->getErrorStore()->getErrors() as $message) {
                    $messages[] = $message;
                }
            }
            $this->messenger()->addErrors($messages);
            return $view;
        }

        // Core's own message, already translated.
        $this->succeeded('Vocabulary successfully imported. %s', $vocabulary); // @translate
        return $this->redirect()->toUrl($this->libraryUrl());
    }

    /**
     * Shows how the vocabulary in Omeka differs from the library's file, and
     * applies the difference on request, with core's importer.
     *
     * Core's update adds classes and properties and changes labels and
     * comments; it never removes any. The module can't tell which side is
     * newer, so the admin decides.
     */
    public function compareAction()
    {
        [, $entry] = $this->entry();
        $installed = $this->siteVocabularies->installed($entry);
        if (!$installed || !$installed['exact']) {
            throw new NotFoundException;
        }
        if (!$installed['comparable']) {
            throw new PermissionDeniedException('Cannot update this vocabulary');
        }
        $vocabulary = $installed['vocabulary'];
        // An empty language is "First available": no preferred language, as
        // core's import form defaults to. Until the admin chooses, the page
        // reads the file in the language the vocabulary was imported in, where
        // the file has several, so only real changes show.
        $lang = $this->params()->fromPost('lang', $this->params()->fromQuery('lang'));
        if ($lang === '') {
            $lang = null;
        } elseif (!in_array($lang, $entry['languages'], true)) {
            $lang = count($entry['languages']) > 1
                ? $this->importer->matchingLanguage($entry, $vocabulary)
                : TermReading::preferredLanguage($entry['languages']);
        }
        // Computed on every request, never taken from the form.
        $reading = $this->params()->fromPost('comments', $this->params()->fromQuery('comments'));
        [$reading, $diff] = $this->importer->diffAsImported($entry, $lang, $reading);
        $form = $this->getForm(ConfirmForm::class);

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                try {
                    $this->importer->update($vocabulary->id(), $diff);
                } catch (\Exception $e) {
                    $this->messenger()->addError($e->getMessage());
                    return $this->redirect()->toUrl($this->libraryUrl());
                }
                $this->succeeded('Changes to the vocabulary successfully made. %s', $vocabulary); // @translate
                return $this->redirect()->toUrl($this->libraryUrl());
            }
            $this->messenger()->addFormErrors($form);
        }

        $view = new ViewModel;
        $view->setVariable('entry', $entry);
        $view->setVariable('vocabulary', $vocabulary);
        $view->setVariable('diff', $diff);
        $view->setVariable('collation', $this->collation);
        $labels = Importer::labels($vocabulary);
        $view->setVariable('labels', $labels);
        $view->setVariable('missing', $this->importer->missingTerms($entry, $labels, $lang));
        $view->setVariable('lang', $lang);
        $view->setVariable('languageOptions', $this->languageNames->options($entry['languages']));
        $view->setVariable('commentReading', $reading);
        $view->setVariable('form', $form);
        $view->setVariable('libraryUrl', $this->libraryUrl());
        $view->setVariable('libraryState', http_build_query($this->libraryState()));
        return $view;
    }

    /**
     * The details sidebar's class and property lists, read from the
     * vocabulary's file in the language the library shows it in.
     */
    public function showTermsAction()
    {
        [, $entry] = $this->entry();
        $members = $this->importer->members($entry, TermReading::preferredLanguage($entry['languages']));
        foreach ($members as &$terms) {
            // Some files put HTML in their labels and comments. Labels are
            // one line, as the term index has them, so the term filter
            // matches what's shown.
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
     * Says an import or update succeeded, with a link to the vocabulary in
     * Omeka. Both lead back to the library, as the admin left it, so that
     * choosing vocabularies isn't interrupted; the link is there for when
     * the vocabulary itself is wanted.
     *
     * @param string $message With %s for the link
     */
    protected function succeeded($message, VocabularyRepresentation $vocabulary)
    {
        $message = new Message($message, sprintf(
            '<a href="%s">%s</a>',
            htmlspecialchars($this->siteVocabularies->url($vocabulary)),
            htmlspecialchars($this->translate('View it in Omeka'))
        ));
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
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
