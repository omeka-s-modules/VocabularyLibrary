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
     * About where the description is cut in the details sidebar.
     */
    const EXCERPT_LENGTH = 200;

    /**
     * Memory to read a vocabulary's file in. Core's importer holds the whole
     * file in memory, and the largest files need more than PHP's default of
     * 128M to compare.
     */
    const MEMORY_LIMIT = '256M';

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
        // The name shown for a vocabulary: the library's alternative title,
        // else the file's title. The build requires one of them.
        foreach ($vocabularies as &$entry) {
            $entry['name'] = $entry['alt_title'] ?? $entry['title'];
        }
        unset($entry);
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
            return strnatcasecmp($a['entry']['name'], $b['entry']['name']);
        });

        $view = new ViewModel;
        $view->setVariable('rows', $rows);
        $view->setVariable('tags', $this->tags);
        $view->setVariable('tagCounts', $tagCounts);
        $view->setVariable('languageCounts', $languageCounts);
        // Only single values: a filter given as a list isn't one this page sets.
        $view->setVariable('query', array_filter($this->params()->fromQuery(), 'is_string'));
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
        // Text to filter the terms by, when the sidebar opens from a term
        // search's matches.
        $filter = $this->params()->fromQuery('filter');
        $view->setVariable('termFilter', is_string($filter) ? trim($filter) : '');
        [$excerpt, $rest] = $this->excerpt($entry['description'], self::EXCERPT_LENGTH);
        $view->setVariable('excerpt', $excerpt);
        $view->setVariable('rest', $rest);
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
        // The label and comment the vocabulary gets in Omeka: the name shown in
        // the library, and the library's summary.
        $form->setData([
            'o:prefix' => $entry['prefix'],
            'o:label' => $entry['name'],
            'o:comment' => $entry['summary'],
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
     * Terms are ranked: a label or local name that is the query itself, then
     * one with a word that starts with it ("date" in "Date created" or
     * "startDate"), then any other; within a rank, file order.
     *
     * @return JsonModel {"matches": {slug: {"terms": [{"label": …, "term":
     *   "prefix:localName"}, …], "count": number}}}: the best
     *   TERM_MATCHES_SHOWN terms per vocabulary, and how many match in all.
     *   Terms are written as Omeka writes them, with the library's default
     *   prefix.
     */
    public function termSearchAction()
    {
        $query = $this->params()->fromQuery('q');
        $query = is_string($query) ? trim($query) : '';
        if (mb_strlen($query) < self::TERM_QUERY_MIN || mb_strlen($query) > self::TERM_QUERY_MAX) {
            return new JsonModel(['matches' => new \stdClass]);
        }
        $lowerQuery = mb_strtolower($query);
        $wordStart = '/(?:^|[^\p{L}\p{N}])' . preg_quote($lowerQuery, '/') . '/u';
        $index = json_decode(file_get_contents($this->dataDir . '/term-index.json'), true);
        $matches = [];
        foreach ($index as $slug => $terms) {
            $prefix = $this->vocabularies[$slug]['prefix'] ?? null;
            if ($prefix === null) {
                continue;
            }
            $found = [];
            foreach ($terms as $term) {
                [$localName, $label] = is_array($term) ? $term : [$term, $term];
                if (mb_stripos($label, $query) === false && mb_stripos($localName, $query) === false) {
                    continue;
                }
                if (mb_strtolower($label) === $lowerQuery || mb_strtolower($localName) === $lowerQuery) {
                    $rank = 0;
                } elseif (preg_match($wordStart, $this->words($label)) || preg_match($wordStart, $this->words($localName))) {
                    $rank = 1;
                } else {
                    $rank = 2;
                }
                // A term with no label in its file shows its local name, as
                // Omeka labels it on import.
                $found[] = [$rank, count($found), ['label' => $label, 'term' => "$prefix:$localName"]];
            }
            if ($found) {
                sort($found);
                $matches[$slug] = [
                    'terms' => array_column(array_slice($found, 0, self::TERM_MATCHES_SHOWN), 2),
                    'count' => count($found),
                ];
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
     * one the build check found by autodetecting. Every read of a file gets
     * its options here, so this is also where the memory limit is raised.
     */
    protected function importerOptions(array $entry, $lang)
    {
        $this->raiseMemoryLimit();
        return [
            'file' => $this->dataDir . '/' . $entry['file'],
            'format' => $entry['format'] ?? 'guess',
            'lang' => $lang,
        ];
    }

    /**
     * Raises PHP's memory limit to MEMORY_LIMIT where it is lower, as Omeka's
     * own Datascribe and OSII modules do for their jobs. A host that doesn't
     * allow it keeps its limit.
     */
    protected function raiseMemoryLimit()
    {
        $limit = (string) ini_get('memory_limit');
        if ($limit !== '-1' && self::bytes($limit) < self::bytes(self::MEMORY_LIMIT)) {
            ini_set('memory_limit', self::MEMORY_LIMIT);
        }
    }

    /**
     * Bytes in a PHP memory size, such as "128M".
     */
    protected static function bytes($size)
    {
        $size = trim($size);
        $units = ['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3];
        return (int) $size * ($units[strtolower(substr($size, -1))] ?? 1);
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
     * it, else a regional English such as en-US, else its only or first
     * language, else none.
     */
    protected function defaultLanguage(array $entry)
    {
        $languages = $entry['languages'];
        if (in_array('en', $languages, true)) {
            return 'en';
        }
        return current(preg_grep('/^en-/i', $languages)) ?: ($languages[0] ?? null);
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
     * Text as lowercase words, splitting names written in camel case:
     * startDate, GrandSonOf and URLTemplate are "start date", "grand son of"
     * and "url template".
     */
    protected function words($text)
    {
        return mb_strtolower(preg_replace('/(?<=\p{Ll})(?=\p{Lu})|(?<=\p{Lu})(?=\p{Lu}\p{Ll})/u', ' ', $text));
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
