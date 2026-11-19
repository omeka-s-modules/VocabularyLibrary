<?php
namespace VocabularyLibraryTest\Database;

use Omeka\Entity\User;
use Omeka\Mvc\Exception\PermissionDeniedException;
use Omeka\Test\AbstractHttpControllerTestCase;
use VocabularyLibraryTest\DbTestCase;

/**
 * Sends requests to the library's pages, which read the tests' library.
 *
 * Each request runs in an application of its own, so what a request writes
 * is committed: a vocabulary a test imports is deleted after it, and the
 * users are made before the tests and deleted after them.
 */
class ControllerTest extends AbstractHttpControllerTestCase
{
    const NS = 'http://example.org/ns#';

    /**
     * The file store tokens of the imports a test started, removed after it.
     */
    protected $importFiles = [];

    public static function setUpBeforeClass(): void
    {
        DbTestCase::installOnce();
        DbTestCase::makeUser('library-supervisor@example.com', 'site_admin');
        DbTestCase::makeUser('library-editor@example.com', 'editor');
    }

    public static function tearDownAfterClass(): void
    {
        $em = DbTestCase::services()->get('Omeka\EntityManager');
        $em->clear();
        foreach (['library-supervisor@example.com', 'library-editor@example.com'] as $email) {
            $em->remove($em->getRepository(User::class)->findOneBy(['email' => $email]));
        }
        $em->flush();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        DbTestCase::signIn(DbTestCase::ADMIN);
        foreach ($this->importFiles as $token) {
            DbTestCase::services()->get('Omeka\VocabularyFileStore')->remove($token);
        }
        if ($vocabulary = $this->vocabulary()) {
            DbTestCase::services()->get('Omeka\ApiManager')->delete('vocabularies', $vocabulary->id());
        }
    }

    /**
     * The tests' vocabulary as it is in Omeka, or null if it isn't.
     */
    protected function vocabulary()
    {
        $services = DbTestCase::services();
        $services->get('Omeka\EntityManager')->clear();
        return $services->get('Omeka\ApiManager')->search('vocabularies', ['namespace_uri' => self::NS])->getContent()[0] ?? null;
    }

    /**
     * The sentence that follows the status in the details sidebar, as text.
     */
    protected function statusNote($page)
    {
        preg_match('#<p class="vocabulary-library-status-next">(.*?)</p>#s', $page, $match);
        return trim(strip_tags($match[1] ?? ''));
    }

    /**
     * A term's label and comment in Omeka.
     */
    protected function term($localName)
    {
        foreach (array_merge($this->vocabulary()->resourceClasses(), $this->vocabulary()->properties()) as $term) {
            if ($term->localName() === $localName) {
                return [$term->label(), $term->comment()];
            }
        }
        return null;
    }

    /**
     * Send a request, signed in as a user, keeping the session between
     * requests, as a browser would.
     *
     * @return array [HTTP status, page with its character references
     *   decoded, so text and attributes read as a browser shows them]. A
     *   request Omeka's access rules turn away is 403, as Omeka answers it.
     */
    protected function send($method, $path, array $data = [], $user = DbTestCase::ADMIN)
    {
        $this->reset(true);
        $services = $this->getApplication()->getServiceManager();
        // The tests' library in place of the module's.
        $config = $services->get('Config');
        $config['vocabulary_library']['data_dir'] = DbTestCase::libraryDir();
        $services->setAllowOverride(true);
        $services->setService('Config', $config);
        $services->setAllowOverride(false);
        $identity = $services->get('Omeka\EntityManager')->getRepository(User::class)->findOneBy(['email' => $user]);
        $services->get('Omeka\AuthenticationService')->getStorage()->write($identity);
        try {
            $this->dispatch($path, $method, $data);
        } catch (PermissionDeniedException $e) {
            // The test framework rethrows what Omeka would answer with 403.
            return [403, ''];
        }
        $page = $this->getResponse()->getContent();
        // Pages and the sidebar are markup; the file and term search are not.
        if (strpos(ltrim($page), '<') === 0) {
            $page = html_entity_decode($page, ENT_QUOTES | ENT_HTML5);
        }
        return [$this->getResponse()->getStatusCode(), $page];
    }

    /**
     * A page's CSRF tokens, as its forms' hidden fields.
     */
    protected function csrf($path, $user = DbTestCase::ADMIN)
    {
        [, $page] = $this->send('GET', $path, [], $user);
        preg_match_all('/<input type="hidden" name="([a-z_]*csrf)" value="([^"]+)"/i', $page, $matches);
        $this->assertNotEmpty($matches[1], "No CSRF token on $path");
        return array_combine($matches[1], $matches[2]);
    }

    /**
     * Start the tests' vocabulary's import, as the library's Import does: on
     * core's import details, which it hands the file to.
     *
     * @param array $query The library's query, as "return"
     * @return string The import details page's URL
     */
    protected function startImport(array $query = [])
    {
        [$status] = $this->send('GET', '/admin/vocabulary-library/import/ex', $query);
        $this->assertSame(302, $status);
        $url = $this->getResponse()->getHeaders()->get('Location')->getUri();
        $this->assertStringStartsWith('/admin/vocabulary/import-details?file_token=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        $this->importFiles[] = $params['file_token'];
        return $url;
    }

    /**
     * Import the tests' vocabulary through core's import details.
     *
     * @param array $data o:prefix, o:label, o:comment, and lang, as entered
     * @param string|null $url Of an import already started
     */
    protected function import(array $data = [], $url = null)
    {
        $url ??= $this->startImport();
        $data += ['o:prefix' => 'ex', 'o:label' => 'Example Vocabulary', 'o:comment' => 'Summary.', 'lang' => 'en'];
        return $this->send('POST', $url, [
            'vocabulary-info' => ['o:label' => $data['o:label'], 'o:comment' => $data['o:comment'], 'o:namespace_uri' => self::NS, 'o:prefix' => $data['o:prefix']],
            'vocabulary-advanced' => ['lang' => $data['lang'], 'comments' => 'definition', 'label_property' => '', 'comment_property' => ''],
        ] + $this->csrf($url));
    }

    public function testTheLibraryListsItsVocabularies()
    {
        [$status, $page] = $this->send('GET', '/admin/vocabulary-library');
        $this->assertSame(200, $status);
        // One sidebar, the details': confirming changes is core's review's.
        $this->assertSame(1, substr_count($page, 'class="sidebar"'));
        $this->assertStringContainsString('Example Vocabulary', $page);
        $this->assertStringContainsString('Classes and properties for describing things and their parts.', $page);
        $this->assertStringContainsString('/admin/vocabulary-library/import/ex', $page);
        // The page tells the script which controls its address keeps, as
        // the controller reads them back.
        $this->assertStringContainsString('data-state-names="q terms tag lang show sort_by sort_order"', $page);
        // Clear filters is beside the filters, shown by the script while one
        // is set, and not repeated in the message for no matches.
        $this->assertMatchesRegularExpression('#</select>\s*<button type="button" class="vocabulary-library-clear-filters" hidden>Clear filters</button>\s*</div>#', $page);
        $this->assertSame(1, substr_count($page, 'vocabulary-library-clear-filters'));
        // A failed term search has its message, hidden until then.
        $this->assertMatchesRegularExpression('/<p class="vocabulary-library-term-search-failed[^"]*" role="alert" hidden>/', $page);
        // Only the filter's option says so; no vocabulary's status does.
        $this->assertSame(1, substr_count($page, 'Namespace in use'));
        // A search finds a vocabulary by its tags' names too.
        $this->assertMatchesRegularExpression('/data-search="[^"]*General"/', $page);
    }

    public function testTheLibraryIsForAdministratorsAndSupervisors()
    {
        $this->assertSame(200, $this->send('GET', '/admin/vocabulary-library', [], 'library-supervisor@example.com')[0]);
        $this->assertSame(403, $this->send('GET', '/admin/vocabulary-library', [], 'library-editor@example.com')[0]);
        $this->assertSame(403, $this->send('GET', '/admin/vocabulary-library/import/ex', [], 'library-editor@example.com')[0]);
    }

    public function testCoresImportPagePointsToTheLibrary()
    {
        [$status, $page] = $this->send('GET', '/admin/vocabulary/import');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('href="/admin/vocabulary-library"', $page);
    }

    public function testAVocabularyNotInTheLibraryIsNotFound()
    {
        $this->assertSame(404, $this->send('GET', '/admin/vocabulary-library/show-details/nothing')[0]);
        $this->assertSame(404, $this->send('GET', '/admin/vocabulary-library/show-terms/nothing')[0]);
        $this->assertSame(404, $this->send('GET', '/admin/vocabulary-library/import/nothing')[0]);
        $this->assertSame(404, $this->send('GET', '/admin/vocabulary-library/file/nothing')[0]);
    }

    public function testTheDetailsShowTheVocabularyAndLeaveItsTermsToLoad()
    {
        [$status, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
        $this->assertSame(200, $status);
        foreach (['Example Vocabulary', 'http://example.org/ns#', 'https://creativecommons.org/licenses/by/4.0/', 'German'] as $text) {
            $this->assertStringContainsString($text, $page);
        }
        // What the vocabulary is comes first, and its languages are one line.
        $this->assertLessThan(strpos($page, '<h4>Status</h4>'), strpos($page, '<h4>Summary</h4>'));
        $this->assertStringContainsString('<div class="value">English, German</div>', $page);
        // The status is a state, with the next step under it.
        $this->assertMatchesRegularExpression('#Namespace not in use\s*<p class="vocabulary-library-status-next"><a class="button vocabulary-library-keep-place" href="/admin/vocabulary-library/import/ex">Import</a></p>#', $page);
        // The lists, read from the file, load when their tab is shown.
        $this->assertStringNotContainsString('ex:hasPart', $page);
        $this->assertMatchesRegularExpression('#<div class="sidebar-section" id="vocabulary-library-term-lists"[^>]*data-terms-url="/admin/vocabulary-library/show-terms/ex"\s+data-filter="">#', $page);
        $this->assertStringContainsString('<p class="vocabulary-library-terms-loading">Loading classes and properties…</p>', $page);
        $this->assertMatchesRegularExpression('#<div class="vocabulary-library-terms-failed" hidden>\s*<p class="error" role="alert">Could not load classes and properties.</p>\s*<button type="button" class="vocabulary-library-terms-retry">Try again</button>#', $page);
    }

    public function testTheDownloadLinkSaysWhatItDoes()
    {
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
        $this->assertStringContainsString('<a href="/admin/vocabulary-library/file/ex">Download ex.ttl</a>', $page);
    }

    public function testTheTermsAreTheVocabularysClassesAndProperties()
    {
        [$status, $page] = $this->send('GET', '/admin/vocabulary-library/show-terms/ex');
        $this->assertSame(200, $status);
        foreach (['ex:hasPart', 'has part', 'Anything at all.', 'Classes (2)', 'Properties (2)'] as $text) {
            $this->assertStringContainsString($text, $page);
        }
        // The comment gives way to the definition.
        $this->assertStringNotContainsString('A note about things.', $page);
        // Only the lists, for the tab, not a page.
        $this->assertStringNotContainsString('<html', $page);
        $this->assertSame(403, $this->send('GET', '/admin/vocabulary-library/show-terms/ex', [], 'library-editor@example.com')[0]);
    }

    public function testTheTermFiltersResultHasSomewhereToBeReadOutFrom()
    {
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-terms/ex');
        $this->assertMatchesRegularExpression('/<p class="[^"]*vocabulary-library-term-filter-status"[^>]*aria-live="polite"/', $page);
    }

    public function testTheLicenseLinkSaysItOpensInANewTab()
    {
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
        $this->assertMatchesRegularExpression('#<a href="https://creativecommons.org/licenses/by/4.0/" target="_blank">[^<]*<span class="vocabulary-library-sr-only">\(opens in a new tab\)</span></a>#', $page);
    }

    public function testTheDetailsStartFilteredFromATermSearch()
    {
        // The details pass the search on to the lists, which start filtered.
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex', ['filter' => ' has part ']);
        $this->assertMatchesRegularExpression('#data-terms-url="/admin/vocabulary-library/show-terms/ex\?filter=has%20part"\s+data-filter="has part"#', $page);
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-terms/ex', ['filter' => 'has part']);
        $this->assertMatchesRegularExpression('/<input[^>]*value="has part"/', $page);
    }

    public function testTermSearchFindsVocabulariesByTheirTerms()
    {
        [$status, $body] = $this->send('GET', '/admin/vocabulary-library/term-search', ['q' => 'part is']);
        $this->assertSame(200, $status);
        $this->assertSame(
            ['matches' => ['ex' => ['terms' => [['label' => 'is part of', 'term' => 'ex:isPartOf']], 'count' => 1]]],
            json_decode($body, true)
        );
        [, $body] = $this->send('GET', '/admin/vocabulary-library/term-search', ['q' => 'pa']);
        $this->assertSame('{"matches":{}}', $body);
    }

    public function testTheFileIsSentAsADownload()
    {
        [$status, $body] = $this->send('GET', '/admin/vocabulary-library/file/ex');
        $this->assertSame(200, $status);
        $this->assertSame(file_get_contents(DbTestCase::libraryDir() . '/vocabs/ex.ttl'), $body);
        $headers = $this->getResponse()->getHeaders();
        $this->assertSame('text/turtle', $headers->get('Content-Type')->getFieldValue());
        $this->assertSame('attachment; filename="ex.ttl"', $headers->get('Content-Disposition')->getFieldValue());
    }

    public function testImportHandsTheLibrarysFileToCoresImportDetails()
    {
        [$status, $page] = $this->send('GET', $this->startImport());
        $this->assertSame(200, $status);
        // The library's name, summary, namespace, and prefix.
        $this->assertMatchesRegularExpression('/<input[^>]*name="vocabulary-info\[o:label\]"[^>]*value="Example Vocabulary"/', $page);
        $this->assertStringContainsString('Classes and properties for describing things and their parts.</textarea>', $page);
        $this->assertMatchesRegularExpression('/<input[^>]*name="vocabulary-info\[o:namespace_uri\]"[^>]*value="http:\/\/example.org\/ns#"/', $page);
        $this->assertMatchesRegularExpression('/<input[^>]*name="vocabulary-info\[o:prefix\]"[^>]*value="ex"/', $page);
        // Its language and reading.
        $this->assertMatchesRegularExpression('/<option value="en" selected/', $page);
        $this->assertStringContainsString('<option value="definition" selected>', $page);
        // Cancel leads where any import's does.
        $this->assertStringContainsString('<a class="button" href="/admin/vocabulary">Cancel</a>', $page);
    }

    public function testTheImportStartsOnTheLanguageTheLibraryWasFilteredBy()
    {
        [, $page] = $this->send('GET', $this->startImport(['return' => 'lang=de']));
        $this->assertMatchesRegularExpression('/<option value="de" selected/', $page);
        // Not one the vocabulary doesn't have.
        [, $page] = $this->send('GET', $this->startImport(['return' => 'lang=fr']));
        $this->assertMatchesRegularExpression('/<option value="en" selected/', $page);
    }

    public function testTheImportThenStartsOnTheInstallationsLanguage()
    {
        $settings = DbTestCase::services()->get('Omeka\Settings');
        $locale = $settings->get('locale');
        // A locale in Omeka's form, with a region the file doesn't have.
        $settings->set('locale', 'de_AT');
        try {
            [, $page] = $this->send('GET', $this->startImport());
            $this->assertMatchesRegularExpression('/<option value="de" selected/', $page);
            // The library's filter comes first.
            [, $page] = $this->send('GET', $this->startImport(['return' => 'lang=en']));
            $this->assertMatchesRegularExpression('/<option value="en" selected/', $page);
        } finally {
            $settings->set('locale', $locale);
        }
    }

    public function testImportingMakesTheVocabularyInOmeka()
    {
        [$status] = $this->import(['o:prefix' => 'example', 'o:label' => 'My label', 'lang' => 'de']);
        // On the vocabulary's page, as any import ends.
        $this->assertSame(302, $status);
        $vocabulary = $this->vocabulary();
        $this->assertSame('/admin/vocabulary/' . $vocabulary->id(), $this->getResponse()->getHeaders()->get('Location')->getFieldValue());
        $this->assertSame('example', $vocabulary->prefix());
        $this->assertSame('My label', $vocabulary->label());
        $this->assertSame('Summary.', $vocabulary->comment());
        // In German, with the definition where a term has one and a comment.
        $this->assertSame(['Ding', 'Anything at all.'], $this->term('Thing'));
        $this->assertCount(2, $vocabulary->properties());
        // Core keeps the reading, so its review of the library's file starts
        // on it.
        $this->assertSame('de', $vocabulary->importOptions()['lang']);
        $this->assertSame(\Omeka\Stdlib\RdfImporter::DEFINITION_COMMENT_PROPERTY, $vocabulary->importOptions()['comment_property']);
    }

    /**
     * @dataProvider refusedImports
     */
    public function testAnImportThatCannotBeMadeMakesNothing(array $data, $message)
    {
        [$status, $page] = $this->import($data);
        $this->assertSame(200, $status);
        $this->assertStringContainsString($message, $page);
        $this->assertNull($this->vocabulary());
    }

    public function refusedImports()
    {
        return [
            'a prefix Omeka has' => [['o:prefix' => 'dcterms'], 'The prefix "dcterms" is already taken'],
            'a prefix with a space' => [['o:prefix' => 'my ex'], 'The prefix may only contain letters, numbers, hyphens, and underscores.'],
            'no label' => [['o:label' => ' '], "Value is required and can't be empty"],
            'a language the file lacks' => [['lang' => 'fr'], 'The input was not found in the haystack'],
        ];
    }

    public function testTheLibraryIsReturnedToAsTheAdminLeftIt()
    {
        $return = http_build_query(['q' => 'part', 'tag' => 'general', 'sort_order' => 'desc', 'evil' => 'x', 'show' => ['a']]);
        [, $page] = $this->send('GET', '/admin/vocabulary-library');
        $this->assertStringContainsString('class="button vocabulary-library-keep-place"', $page);
        // Reached again once Omeka has it, the import page leads back with
        // only the library's own state.
        $this->import();
        [, $page] = $this->send('GET', '/admin/vocabulary-library/import/ex', ['return' => $return]);
        $this->assertStringContainsString('href="/admin/vocabulary-library?q=part&tag=general&sort_order=desc">Back to the library</a>', $page);
    }

    public function testWithoutAPlaceToReturnToTheLibraryIsAsItFirstShows()
    {
        $this->import();
        [, $page] = $this->send('GET', '/admin/vocabulary-library/import/ex', ['return' => 'http://example.com/elsewhere']);
        $this->assertStringContainsString('href="/admin/vocabulary-library">Back to the library</a>', $page);
    }

    public function testAnImportWithoutItsCsrfTokenMakesNothing()
    {
        $url = $this->startImport();
        [$status] = $this->send('POST', $url, [
            'vocabulary-info' => ['o:label' => 'Example Vocabulary', 'o:comment' => '', 'o:namespace_uri' => self::NS, 'o:prefix' => 'ex'],
            'vocabulary-advanced' => ['lang' => 'en', 'comments' => 'definition', 'label_property' => '', 'comment_property' => ''],
        ]);
        $this->assertSame(200, $status);
        $this->assertNull($this->vocabulary());
    }

    public function testAVocabularyInOmekaIsInUseAndCannotBeImportedAgain()
    {
        $this->import();
        [, $page] = $this->send('GET', '/admin/vocabulary-library');
        // More than the filter's option: the vocabulary's status says so.
        $this->assertGreaterThan(1, substr_count($page, 'Namespace in use'));
        $this->assertStringNotContainsString('/admin/vocabulary-library/import/ex', $page);
        [$status, $page] = $this->send('GET', '/admin/vocabulary-library/import/ex');
        $this->assertSame(200, $status);
        $this->assertStringNotContainsString('name="o:prefix"', $page);
        // Reached anyway, the page names Omeka's vocabulary and the next step.
        $this->assertMatchesRegularExpression('#<a href="[^"]*">Example Vocabulary</a> already uses this vocabulary\S*s namespace URI\.#', $page);
        $this->assertStringContainsString('/admin/vocabulary-library/review/ex', $page);
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
        $this->assertStringContainsString('/admin/vocabulary-library/review/ex', $page);
        // Where the library's script doesn't run, Compare and update keeps
        // the library's place itself.
        [, $page] = $this->send('GET', '/admin/vocabulary-library/import/ex', ['return' => 'tag=general']);
        $this->assertStringContainsString('href="/admin/vocabulary-library/review/ex?return=tag%3Dgeneral"', $page);
    }

    public function testAVocabularyInUseIsComparedFromItsDetailsNotItsRow()
    {
        $this->import();
        // On every row the link would read as something to do.
        [, $page] = $this->send('GET', '/admin/vocabulary-library');
        $this->assertStringContainsString('Namespace in use: Example Vocabulary', $page);
        $this->assertStringNotContainsString('/admin/vocabulary-library/review/ex', $page);
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
        $this->assertMatchesRegularExpression('#<a class="button vocabulary-library-keep-place" href="/admin/vocabulary-library/review/ex">Compare and update</a>#', $page);
        // The status names Omeka's vocabulary, linked to its page in core;
        // its namespace is the library's, so it isn't repeated.
        $this->assertMatchesRegularExpression('#Namespace in use by <a href="/admin/vocabulary/' . $this->vocabulary()->id() . '">Example Vocabulary \(ex\)</a>#', $page);
        $this->assertSame(1, substr_count($page, '>http://example.org/ns#</div>'));
        // Nor by a supervisor, who doesn't own it.
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex', [], 'library-supervisor@example.com');
        $this->assertStringNotContainsString('/admin/vocabulary-library/review/ex', $page);
        $this->assertSame(403, $this->send('GET', '/admin/vocabulary-library/review/ex', [], 'library-supervisor@example.com')[0]);
    }

    public function testCompareAndUpdateOpensCoresReviewOfTheLibrarysFile()
    {
        $this->import();
        $id = $this->vocabulary()->id();
        [$status] = $this->send('GET', '/admin/vocabulary-library/review/ex');
        $this->assertSame(302, $status);
        $review = $this->getResponse()->getHeaders()->get('Location')->getUri();
        $this->assertMatchesRegularExpression('#^/admin/vocabulary/' . $id . '/review\?review=[0-9a-f]{32}$#', $review);

        // Core's review calls the file the library's vocabulary.
        [$status, $page] = $this->send('GET', $review);
        $this->assertSame(200, $status);
        $this->assertMatchesRegularExpression('#This vocabulary already matches the library\S*s vocabulary, read with the choices above\.#', $page);

        // Leaving it ends on the vocabulary's page, as any review does.
        parse_str((string) parse_url($review, PHP_URL_QUERY), $query);
        $tokens = $this->csrf($review);
        [$status] = $this->send('POST', '/admin/vocabulary/' . $id . '/cancel-review', ['review' => $query['review']] + $tokens);
        $this->assertSame(302, $status);
        $this->assertSame('/admin/vocabulary/' . $id, $this->getResponse()->getHeaders()->get('Location')->getUri());
    }

    public function testCompareAndUpdateNeedsTheVocabularyInOmeka()
    {
        $this->assertSame(404, $this->send('GET', '/admin/vocabulary-library/review/ex')[0]);
    }

    public function testCompareAndUpdateReadsTheLibrarysFileInItsOwnFormat()
    {
        // Omeka's vocabulary was read from another file, in another format,
        // as core's installer stores RDF/XML for BIBO, where the library's
        // BIBO is Turtle.
        $this->import();
        DbTestCase::services()->get('Omeka\ApiManager')->update('vocabularies', $this->vocabulary()->id(), ['o:import_options' => ['format' => 'rdfxml']], [], ['isPartial' => true]);
        $this->send('GET', '/admin/vocabulary-library/review/ex');
        $this->assertMatchesRegularExpression('#^/admin/vocabulary/\d+/review\?review=#', $this->getResponse()->getHeaders()->get('Location')->getUri());
    }

    public function testCompareAndUpdateOfANamespaceDifferingInCapitalsReviewsChangingIt()
    {
        DbTestCase::signIn(DbTestCase::ADMIN);
        $api = DbTestCase::services()->get('Omeka\ApiManager');
        // The same vocabulary to Omeka, which ignores capitals, but not to
        // the file's terms.
        $vocabulary = $api->create('vocabularies', [
            'o:namespace_uri' => 'http://EXAMPLE.org/ns#',
            'o:prefix' => 'capitals',
            'o:label' => 'Capitals',
        ])->getContent();
        try {
            $this->send('GET', '/admin/vocabulary-library/review/ex');
            $review = $this->getResponse()->getHeaders()->get('Location')->getUri();
            $this->assertMatchesRegularExpression('#^/admin/vocabulary/\d+/review\?review=#', $review);
            [, $page] = $this->send('GET', $review);
            $this->assertMatchesRegularExpression('#<span class="review-value">http://EXAMPLE.org/ns\#</span></td>\s*<td><span class="review-value">http://example.org/ns\#</span>#', $page);
        } finally {
            DbTestCase::signIn(DbTestCase::ADMIN);
            $api->delete('vocabularies', $vocabulary->id());
        }
    }

    public function testCompareAndUpdateOfANamespaceInAnotherFormReviewsChangingIt()
    {
        DbTestCase::signIn(DbTestCase::ADMIN);
        $api = DbTestCase::services()->get('Omeka\ApiManager');
        $vocabulary = $api->create('vocabularies', [
            'o:namespace_uri' => 'https://example.org/ns/',
            'o:prefix' => 'variant',
            'o:label' => 'Variant',
        ])->getContent();
        try {
            [$status] = $this->send('GET', '/admin/vocabulary-library/review/ex');
            $this->assertSame(302, $status);
            // The review changes the namespace to the library's, with the
            // classes and properties, on one Accept.
            [, $page] = $this->send('GET', $this->getResponse()->getHeaders()->get('Location')->getUri());
            $this->assertMatchesRegularExpression('#<span class="review-value">https://example.org/ns/</span></td>\s*<td><span class="review-value">http://example.org/ns\#</span>#', $page);
            $this->assertMatchesRegularExpression('#Accepting makes the changes below, from the library\S*s vocabulary\.#', $page);
            // Nothing changes until it's accepted.
            DbTestCase::services()->get('Omeka\EntityManager')->clear();
            $this->assertSame('https://example.org/ns/', $api->read('vocabularies', $vocabulary->id())->getContent()->namespaceUri());
        } finally {
            DbTestCase::signIn(DbTestCase::ADMIN);
            $api->delete('vocabularies', $vocabulary->id());
        }
    }

    public function testANamespaceInAnotherFormIsInUseAndItsDetailsSayComparingChangesIt()
    {
        DbTestCase::signIn(DbTestCase::ADMIN);
        $vocabulary = DbTestCase::services()->get('Omeka\ApiManager')->create('vocabularies', [
            'o:namespace_uri' => 'https://example.org/ns/',
            'o:prefix' => 'variant',
            'o:label' => 'Variant',
        ])->getContent();
        try {
            // The table says it is in use, as of any other, and no more.
            [, $page] = $this->send('GET', '/admin/vocabulary-library');
            $this->assertMatchesRegularExpression('#<a aria-label="Namespace in use: Example Vocabulary" href="[^"]*">Namespace in use</a>#', $page);
            $this->assertStringNotContainsString('vocabulary-library-status-next', $page);
            $this->assertStringNotContainsString('/admin/vocabulary-library/import/ex', $page);

            [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
            $this->assertSame(
                "Omeka's vocabulary has a slightly different namespace. Comparing and updating it also changes its namespace to the library's.",
                $this->statusNote($page)
            );
            // The status names Omeka's vocabulary, with its namespace under
            // it, so the difference shows.
            $this->assertMatchesRegularExpression('#Namespace in use by <a href="[^"]*">Variant \(variant\)</a>\s*<div class="vocabulary-library-namespace">https://example.org/ns/</div>#', $page);
            $this->assertStringNotContainsString('<h4>Omeka', $page);
            // Compare and update opens core's review, as for any vocabulary
            // in use, which changes the namespace with the rest.
            $this->assertMatchesRegularExpression('#<a class="button vocabulary-library-keep-place" href="/admin/vocabulary-library/review/ex">Compare and update</a>#', $page);

            // A supervisor, who can't update it, is told it differs, and no
            // more.
            [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex', [], 'library-supervisor@example.com');
            $this->assertSame("Omeka's vocabulary has a slightly different namespace.", $this->statusNote($page));
            $this->assertStringNotContainsString('/admin/vocabulary-library/review/ex', $page);
            $this->assertSame(403, $this->send('GET', '/admin/vocabulary-library/review/ex', [], 'library-supervisor@example.com')[0]);
        } finally {
            DbTestCase::signIn(DbTestCase::ADMIN);
            DbTestCase::services()->get('Omeka\ApiManager')->delete('vocabularies', $vocabulary->id());
        }
    }
}
