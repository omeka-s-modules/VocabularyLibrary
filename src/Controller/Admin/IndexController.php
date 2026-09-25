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
use Omeka\Stdlib\RdfImporter;
use VocabularyLibrary\Form\ImportForm;
use VocabularyLibrary\PlainText;

class IndexController extends AbstractActionController
{
    /**
     * Term search needs a few characters to be useful, and a cap keeps a
     * request cheap.
     */
    const TERM_QUERY_MIN = 3;
    const TERM_QUERY_MAX = 100;
    const TERM_MATCHES_SHOWN = 3;

    /**
     * About where a comment prefilled from the description is cut.
     */
    const COMMENT_LENGTH = 500;

    const CONTENT_TYPES = [
        'jsonld' => 'application/ld+json',
        'nt' => 'application/n-triples',
        'owl' => 'application/rdf+xml',
        'rdf' => 'application/rdf+xml',
        'ttl' => 'text/turtle',
        'xml' => 'application/rdf+xml',
    ];

    protected $rdfImporter;
    protected $vocabularies;
    protected $tags;
    protected $dataDir;

    /**
     * @param array $vocabularies The library, keyed by slug
     * @param array $tags Tags to filter by, key => label
     * @param string $dataDir The module's data directory
     */
    public function __construct(RdfImporter $rdfImporter, array $vocabularies, array $tags, $dataDir)
    {
        $this->rdfImporter = $rdfImporter;
        $this->vocabularies = $vocabularies;
        $this->tags = $tags;
        $this->dataDir = $dataDir;
    }

    public function browseAction()
    {
        $installedVocabularies = $this->installedVocabularies();
        $prefixes = $this->installedPrefixes($installedVocabularies);
        $rows = [];
        $tagCounts = array_fill_keys(array_keys($this->tags), 0);
        $languageCounts = [];
        foreach ($this->vocabularies as $slug => $entry) {
            $languages = $this->labelLanguages($entry);
            $rows[] = [
                'slug' => (string) $slug,
                'entry' => $entry,
                'installed' => $this->installed($entry, $installedVocabularies),
                'prefixTakenBy' => $prefixes[mb_strtolower($entry['prefix'])] ?? null,
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
            return strnatcasecmp($a['entry']['title'], $b['entry']['title']);
        });

        $view = new ViewModel;
        $view->setVariable('rows', $rows);
        $view->setVariable('tags', $this->tags);
        $view->setVariable('tagCounts', $tagCounts);
        $view->setVariable('languageCounts', $languageCounts);
        $view->setVariable('query', $this->params()->fromQuery());
        $view->setVariable('termQueryMin', self::TERM_QUERY_MIN);
        return $view;
    }

    public function showDetailsAction()
    {
        [$slug, $entry] = $this->entry();
        $members = $this->rdfImporter->getMembers('file', $entry['namespace'], $this->importerOptions($entry, $this->defaultLanguage($entry)));
        foreach ($members as &$terms) {
            // Some files put HTML in their labels and comments.
            foreach ($terms as &$term) {
                $term['label'] = PlainText::from($term['label']);
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
        $view->setVariable('tags', array_intersect_key($this->tags, array_flip($entry['tags'])));
        $installedVocabularies = $this->installedVocabularies();
        $view->setVariable('installed', $this->installed($entry, $installedVocabularies));
        $view->setVariable('prefixTakenBy', $this->installedPrefixes($installedVocabularies)[mb_strtolower($entry['prefix'])] ?? null);
        $view->setVariable('classes', $members['classes']);
        $view->setVariable('properties', $members['properties']);
        return $view;
    }

    public function importAction()
    {
        [$slug, $entry] = $this->entry();
        $installedVocabularies = $this->installedVocabularies();
        $installed = $this->installed($entry, $installedVocabularies);

        $view = new ViewModel;
        $view->setVariable('slug', $slug);
        $view->setVariable('entry', $entry);
        $view->setVariable('installed', $installed);
        if ($installed) {
            return $view;
        }

        $form = $this->getForm(ImportForm::class, ['languages' => $entry['languages']]);
        $form->setData([
            'o:prefix' => $entry['prefix'],
            'o:label' => $entry['title'],
            'o:comment' => $this->commentExcerpt($entry['description']),
            'lang' => $this->defaultLanguage($entry),
        ]);
        $view->setVariable('form', $form);
        $view->setVariable('installedPrefixes', $this->installedPrefixes($installedVocabularies));

        if (!$this->getRequest()->isPost()) {
            return $view;
        }
        $form->setData($this->params()->fromPost());
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $view;
        }
        $data = $form->getData();
        $lang = $form->has('lang') ? $data['lang'] : $this->defaultLanguage($entry);
        try {
            $vocabulary = $this->rdfImporter->import('file', [
                'o:namespace_uri' => $entry['namespace'],
                'o:prefix' => $data['o:prefix'],
                'o:label' => $data['o:label'],
                'o:comment' => $data['o:comment'],
            ], $this->importerOptions($entry, $lang))->getContent();
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
        return $this->redirect()->toUrl($this->vocabularyUrl($vocabulary));
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
        $installed = $this->installed($entry, $this->installedVocabularies());
        if (!$installed || !$installed['exact']) {
            throw new NotFoundException;
        }
        if (!$installed['comparable']) {
            throw new PermissionDeniedException('Cannot update this vocabulary');
        }
        $vocabulary = $installed['vocabulary'];
        // An empty language is "First available": no preferred language, as
        // core's import form defaults to.
        $lang = $this->params()->fromPost('lang', $this->params()->fromQuery('lang'));
        if ($lang === '') {
            $lang = null;
        } elseif (!in_array($lang, $entry['languages'], true)) {
            $lang = $this->defaultLanguage($entry);
        }
        $options = $this->importerOptions($entry, $lang);
        // Computed on every request, never taken from the form.
        $diff = $this->rdfImporter->getDiff('file', $entry['namespace'], $options);
        $form = $this->getForm(ConfirmForm::class);

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                try {
                    $this->rdfImporter->update($vocabulary->id(), $diff);
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

        // Terms Omeka has and the library's file doesn't. Core's update keeps
        // them, and they can mean the vocabulary in Omeka is newer.
        $members = $this->rdfImporter->getMembers('file', $entry['namespace'], $options);
        $missing = ['classes' => [], 'properties' => []];
        foreach (['classes' => $vocabulary->resourceClasses(), 'properties' => $vocabulary->properties()] as $kind => $terms) {
            foreach ($terms as $term) {
                if (!isset($members[$kind][$term->localName()])) {
                    $missing[$kind][$term->localName()] = $term->label();
                }
            }
        }

        $view = new ViewModel;
        $view->setVariable('entry', $entry);
        $view->setVariable('vocabulary', $vocabulary);
        $view->setVariable('diff', $diff);
        $view->setVariable('missing', $missing);
        $view->setVariable('lang', $lang);
        $view->setVariable('form', $form);
        return $view;
    }

    /**
     * Finds the vocabularies with a class or property matching the query.
     *
     * @return JsonModel {"matches": {slug: ["Label (prefix:localName)", …]}},
     *   with at most TERM_MATCHES_SHOWN terms per vocabulary. Terms are
     *   written as Omeka writes them, with the library's default prefix.
     */
    public function termSearchAction()
    {
        $query = trim((string) $this->params()->fromQuery('q'));
        if (mb_strlen($query) < self::TERM_QUERY_MIN || mb_strlen($query) > self::TERM_QUERY_MAX) {
            return new JsonModel(['matches' => new \stdClass]);
        }
        $index = json_decode(file_get_contents($this->dataDir . '/term-index.json'), true);
        $matches = [];
        foreach ($index as $slug => $terms) {
            $prefix = $this->vocabularies[$slug]['prefix'] ?? null;
            if ($prefix === null) {
                continue;
            }
            foreach ($terms as $term) {
                [$localName, $label] = is_array($term) ? $term : [$term, $term];
                if (mb_stripos($label, $query) === false && mb_stripos($localName, $query) === false) {
                    continue;
                }
                // A term with no label in its file shows its local name, as
                // Omeka labels it on import.
                $matches[$slug][] = sprintf('%s (%s:%s)', $label, $prefix, $localName);
                if (count($matches[$slug]) === self::TERM_MATCHES_SHOWN) {
                    break;
                }
            }
        }
        return new JsonModel(['matches' => $matches ?: new \stdClass]);
    }

    /**
     * Sends the vocabulary's file as a download.
     */
    public function fileAction()
    {
        [$slug, $entry] = $this->entry();
        $path = $this->dataDir . '/' . $entry['file'];
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
     * Options for core's importer to read an entry's file. A null format is
     * one the build check found by autodetecting.
     */
    protected function importerOptions(array $entry, $lang)
    {
        return [
            'file' => $this->dataDir . '/' . $entry['file'],
            'format' => $entry['format'] ?? 'guess',
            'lang' => $lang,
        ];
    }

    /**
     * The languages of a vocabulary's labels, as primary language subtags
     * (en-GB and en-us are both en).
     *
     * @return string[]
     */
    protected function labelLanguages(array $entry)
    {
        $languages = [];
        foreach ($entry['languages'] as $tag) {
            $languages[strtolower(explode('-', $tag)[0])] = true;
        }
        return array_keys($languages);
    }

    /**
     * The language to import labels and comments in: English if the file has
     * it, else its only or first language, else none.
     */
    protected function defaultLanguage(array $entry)
    {
        $languages = $entry['languages'];
        return in_array('en', $languages, true) ? 'en' : ($languages[0] ?? null);
    }

    /**
     * The description's first paragraph, cut near COMMENT_LENGTH characters
     * at the end of a sentence. Some descriptions run to many thousands of
     * characters, and core shows the comment above the vocabulary's terms.
     */
    protected function commentExcerpt($description)
    {
        $paragraph = explode("\n\n", (string) $description)[0];
        if (mb_strlen($paragraph) <= self::COMMENT_LENGTH) {
            return $paragraph;
        }
        $cut = mb_substr($paragraph, 0, self::COMMENT_LENGTH);
        $end = max(mb_strrpos($cut, '. ') ?: 0, mb_strrpos($cut, '? ') ?: 0, mb_strrpos($cut, '! ') ?: 0);
        return $end ? mb_substr($cut, 0, $end + 1) : rtrim($cut) . '…';
    }

    /**
     * @return VocabularyRepresentation[] The site's vocabularies
     */
    protected function installedVocabularies()
    {
        return $this->api()->search('vocabularies')->getContent();
    }

    /**
     * The site's vocabulary prefixes, lowercased, with the label of the
     * vocabulary that holds each. Omeka compares prefixes without regard to
     * case.
     *
     * @param VocabularyRepresentation[] $installedVocabularies
     * @return array prefix => label
     */
    protected function installedPrefixes(array $installedVocabularies)
    {
        $prefixes = [];
        foreach ($installedVocabularies as $vocabulary) {
            $prefixes[mb_strtolower($vocabulary->prefix())] = $vocabulary->label();
        }
        return $prefixes;
    }

    /**
     * The site's vocabulary with this entry's namespace, written the same way
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
    protected function installed(array $entry, array $installedVocabularies)
    {
        $namespace = $entry['namespace'];
        $match = null;
        foreach ($installedVocabularies as $vocabulary) {
            if (mb_strtolower($vocabulary->namespaceUri()) === mb_strtolower($namespace)) {
                $match = $vocabulary;
                break;
            }
            if (!$match && $this->normalizedNamespace($vocabulary->namespaceUri()) === $this->normalizedNamespace($namespace)) {
                $match = $vocabulary;
            }
        }
        if (!$match) {
            return null;
        }
        $exact = mb_strtolower($match->namespaceUri()) === mb_strtolower($namespace);
        return [
            'vocabulary' => $match,
            'url' => $this->vocabularyUrl($match),
            'exact' => $exact,
            'comparable' => $exact && !$match->isPermanent() && $match->userIsAllowed('update'),
        ];
    }

    /**
     * Core's Vocabularies page, showing only this vocabulary. Core has no
     * vocabulary show page, and its browse passes the query to the API, which
     * filters by namespace_uri.
     */
    protected function vocabularyUrl(VocabularyRepresentation $vocabulary)
    {
        return $this->url()->fromRoute('admin/default', ['controller' => 'vocabulary', 'action' => 'browse'], ['query' => ['namespace_uri' => $vocabulary->namespaceUri()]]);
    }

    /**
     * A namespace without its scheme, case or trailing #//, so a vocabulary
     * installed as http://schema.org/ matches the library's
     * https://schema.org/.
     */
    protected function normalizedNamespace($namespace)
    {
        return rtrim(preg_replace('#^https?://#i', '', mb_strtolower((string) $namespace)), '#/');
    }
}
