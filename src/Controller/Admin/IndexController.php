<?php
namespace VocabularyLibrary\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;
use Omeka\Mvc\Exception\NotFoundException;
use Omeka\Mvc\Exception\PermissionDeniedException;
use Omeka\Stdlib\Message;
use VocabularyLibrary\CommentProperty;
use VocabularyLibrary\Form\ImportForm;
use VocabularyLibrary\LabelLanguage;
use VocabularyLibrary\PlainText;
use VocabularyLibrary\Stdlib\Importer;
use VocabularyLibrary\Stdlib\LanguageNames;
use VocabularyLibrary\Stdlib\SiteVocabularies;
use VocabularyLibrary\Stdlib\TermSearch;

class IndexController extends AbstractActionController
{
    /**
     * About where the description is cut in the details sidebar.
     */
    const EXCERPT_LENGTH = 200;

    /**
     * The ways Compare can read terms' comments: as the library imports them,
     * or as Omeka's own import does.
     */
    const COMMENT_READINGS = [
        'definitions' => CommentProperty::LIBRARY,
        'comments' => CommentProperty::OMEKA,
    ];

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
    protected $vocabularies;
    protected $tags;

    /**
     * @param array $vocabularies The library, keyed by slug
     * @param array $tags Tags to filter by, key => label
     */
    public function __construct(Importer $importer, TermSearch $termSearch, LanguageNames $languageNames, SiteVocabularies $siteVocabularies, array $vocabularies, array $tags)
    {
        $this->importer = $importer;
        $this->termSearch = $termSearch;
        $this->languageNames = $languageNames;
        $this->siteVocabularies = $siteVocabularies;
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
            return strnatcasecmp($a['entry']['name'], $b['entry']['name']);
        });

        $view = new ViewModel;
        $view->setVariable('rows', $rows);
        $view->setVariable('tags', $this->tags);
        $view->setVariable('tagCounts', $tagCounts);
        $view->setVariable('languageCounts', $languageCounts);
        $view->setVariable('languageNames', $this->languageNames->names(array_keys($languageCounts)));
        // Only single values: a filter given as a list isn't one this page sets.
        $view->setVariable('query', array_filter($this->params()->fromQuery(), 'is_string'));
        $view->setVariable('termQueryMin', TermSearch::QUERY_MIN);
        return $view;
    }

    public function showDetailsAction()
    {
        [$slug, $entry] = $this->entry();
        $members = $this->importer->members($entry, LabelLanguage::preferred($entry['languages']));
        foreach ($members as &$terms) {
            // Some files put HTML in their labels and comments. Labels also
            // have their whitespace collapsed, as the term index has them and
            // the browser shows them, so the term filter matches what's shown.
            foreach ($terms as &$term) {
                $term['label'] = trim(preg_replace('/\s+/u', ' ', PlainText::from($term['label'])));
                if ($term['comment'] !== null) {
                    $term['comment'] = PlainText::from($term['comment']);
                }
            }
            unset($term);
            uasort($terms, function ($a, $b) {
                return strnatcasecmp($a['label'], $b['label']);
            });
        }
        unset($terms);

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setVariable('slug', $slug);
        $view->setVariable('entry', $entry);
        // Text to filter the terms by, when the sidebar opens from a term
        // search's matches.
        $filter = $this->params()->fromQuery('filter');
        $view->setVariable('termFilter', is_string($filter) ? trim($filter) : '');
        [$excerpt, $rest] = $this->excerpt($entry['description'], self::EXCERPT_LENGTH);
        $view->setVariable('excerpt', $excerpt);
        $view->setVariable('rest', $rest);
        $view->setVariable('tags', array_intersect_key($this->tags, array_flip($entry['tags'])));
        $view->setVariable('languageNames', $this->languageNames->names($this->languageNames->primaries($entry['languages'])));
        $view->setVariable('installed', $this->siteVocabularies->installed($entry));
        $view->setVariable('prefixTakenBy', $this->siteVocabularies->prefixHolder($entry['prefix']));
        $view->setVariable('classes', $members['classes']);
        $view->setVariable('properties', $members['properties']);
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
            'lang' => LabelLanguage::preferred($entry['languages']),
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
        $lang = $form->has('lang') ? $data['lang'] : LabelLanguage::preferred($entry['languages']);
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

        // Core's own message, already translated, linking back to the library.
        $message = new Message(
            'Vocabulary successfully imported. %s', // @translate
            sprintf(
                '<a href="%s">%s</a>',
                htmlspecialchars($this->url()->fromRoute('admin/vocabulary-library')),
                $this->translate('Import another vocabulary?')
            )
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
        return $this->redirect()->toUrl($this->siteVocabularies->url($vocabulary));
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
                : LabelLanguage::preferred($entry['languages']);
        }
        // Computed on every request, never taken from the form.
        [$reading, $diff] = $this->commentReading($entry, $lang);
        $form = $this->getForm(ConfirmForm::class);

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                try {
                    $this->importer->update($vocabulary->id(), $diff);
                } catch (\Exception $e) {
                    $this->messenger()->addError($e->getMessage());
                    return $this->redirect()->toRoute('admin/vocabulary-library');
                }
                // Core's message after applying a diff, then core's page for
                // the vocabulary, as after an import.
                $this->messenger()->addSuccess('Changes to the vocabulary successfully made'); // @translate
                return $this->redirect()->toUrl($installed['url']);
            }
            $this->messenger()->addFormErrors($form);
        }

        $view = new ViewModel;
        $view->setVariable('entry', $entry);
        $view->setVariable('vocabulary', $vocabulary);
        $view->setVariable('diff', $diff);
        $view->setVariable('missing', $this->importer->missingTerms($entry, $vocabulary, $lang));
        $view->setVariable('lang', $lang);
        $view->setVariable('languageOptions', $this->languageNames->options($entry['languages']));
        $view->setVariable('commentReading', $reading);
        $view->setVariable('form', $form);
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

    /**
     * The start of the description, and the rest. The start is its first
     * paragraph cut near $length characters, ending with a sentence where one
     * ends in time; a first sentence much longer than that is cut at a word,
     * and the rest continues from there. Some descriptions run to many
     * thousands of characters.
     *
     * A sentence ends at ".", "?" or "!" followed by a capital, a digit, a
     * quote or a parenthesis, so abbreviations such as "e.g." don't end one.
     *
     * @return array [the start, or null if there is no description, the rest
     *   as paragraphs]
     */
    protected function excerpt($description, $length)
    {
        $paragraphs = array_filter(explode("\n\n", (string) $description), 'strlen');
        if (!$paragraphs) {
            return [null, []];
        }
        $sentences = preg_split('/(?<=[.!?])\s+(?=[\p{Lu}\p{N}"“(])/u', array_shift($paragraphs));
        $excerpt = array_shift($sentences);
        while ($sentences && mb_strlen($excerpt . ' ' . $sentences[0]) <= $length) {
            $excerpt .= ' ' . array_shift($sentences);
        }
        if (mb_strlen($excerpt) > $length * 1.25) {
            $cut = mb_strrpos(mb_substr($excerpt, 0, $length), ' ') ?: $length;
            array_unshift($sentences, '…' . ltrim(mb_substr($excerpt, $cut)));
            $excerpt = rtrim(mb_substr($excerpt, 0, $cut)) . '…';
        }
        return [$excerpt, array_merge($sentences ? [implode(' ', $sentences)] : [], $paragraphs)];
    }

    /**
     * How Compare reads terms' comments, and the diff read that way.
     *
     * The library imports a term's definition where the file has one, and
     * Omeka's own import only its comment. Read the other way, every such
     * comment would show as changed, so the admin chooses. Until they do,
     * the page reads comments whichever way would change fewer of those in
     * Omeka, which is the way the vocabulary was imported; where both ways
     * read the same, there's no choice to offer.
     *
     * @return array [a key of COMMENT_READINGS, or null where both read the
     *   same, and the diff]
     */
    protected function commentReading(array $entry, $lang)
    {
        $reading = $this->params()->fromPost('comments', $this->params()->fromQuery('comments'));
        if (in_array($reading, array_keys(self::COMMENT_READINGS), true)) {
            return [$reading, $this->importer->diff($entry, $lang, self::COMMENT_READINGS[$reading])];
        }
        $diffs = [];
        foreach (self::COMMENT_READINGS as $key => $property) {
            $diffs[$key] = $this->importer->diff($entry, $lang, $property);
        }
        if ($diffs['definitions'] == $diffs['comments']) {
            return [null, $diffs['definitions']];
        }
        $reading = $this->changedComments($diffs['comments']) < $this->changedComments($diffs['definitions']) ? 'comments' : 'definitions';
        return [$reading, $diffs[$reading]];
    }

    /**
     * How many of a vocabulary's comments in Omeka a diff would change.
     */
    protected function changedComments(array $diff)
    {
        return count($diff['classes']['comment']) + count($diff['properties']['comment']);
    }
}
