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
     * Import the tests' vocabulary through the import form.
     */
    protected function import(array $data = [])
    {
        $path = '/admin/vocabulary-library/import/ex';
        $data += ['o:prefix' => 'ex', 'o:label' => 'Example Vocabulary', 'o:comment' => 'Summary.', 'lang' => 'en'];
        return $this->send('POST', $path, $data + $this->csrf($path));
    }

    public function testTheLibraryListsItsVocabularies()
    {
        [$status, $page] = $this->send('GET', '/admin/vocabulary-library');
        $this->assertSame(200, $status);
        // A sidebar for confirmations, which isn't one of the content's own,
        // so core's sidebar links don't load into it by default.
        $this->assertMatchesRegularExpression('#<div class="vocabulary-library-confirm">\s*<div class="sidebar" id="vocabulary-library-confirm-sidebar">#', $page);
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

    public function testTheImportFormStartsWithTheLibrarysValues()
    {
        [$status, $page] = $this->send('GET', '/admin/vocabulary-library/import/ex');
        $this->assertSame(200, $status);
        // In core's import details order: label, comment, namespace,
        // prefix, language.
        $order = array_map(function ($marker) use ($page) {
            return strpos($page, $marker);
        }, ['name="o:label"', 'name="o:comment"', '>http://example.org/ns#</div>', 'name="o:prefix"', 'name="lang"']);
        $sorted = $order;
        sort($sorted);
        $this->assertNotContains(false, $order);
        $this->assertSame($sorted, $order);
        $this->assertMatchesRegularExpression('/<input[^>]*name="o:prefix"[^>]*value="ex"/', $page);
        // The prefix's warnings are read with the field.
        $this->assertMatchesRegularExpression('/<input[^>]*name="o:prefix"[^>]*aria-describedby="vocabulary-library-prefix-warnings"/', $page);
        $this->assertStringContainsString('<div id="vocabulary-library-prefix-warnings"', $page);
        $this->assertMatchesRegularExpression('/<input[^>]*name="o:label"[^>]*value="Example Vocabulary"/', $page);
        $this->assertStringContainsString('Classes and properties for describing things and their parts.</textarea>', $page);
        // Two languages to choose from, English chosen, under core's label
        // for the choice.
        $this->assertMatchesRegularExpression('#<fieldset>\s*<legend>Classes and properties</legend>\s*.*?name="lang".*?</fieldset>#s', $page);
        $this->assertMatchesRegularExpression('/<option value="en" selected[^>]*>English \(en\)/', $page);
        $this->assertStringContainsString('German (de)', $page);
    }

    public function testTheImportFormStartsOnTheLanguageTheLibraryWasFilteredBy()
    {
        [, $page] = $this->send('GET', '/admin/vocabulary-library/import/ex', ['return' => 'lang=de']);
        $this->assertMatchesRegularExpression('/<option value="de" selected[^>]*>German \(de\)/', $page);
        // Not one the vocabulary doesn't have.
        [, $page] = $this->send('GET', '/admin/vocabulary-library/import/ex', ['return' => 'lang=fr']);
        $this->assertMatchesRegularExpression('/<option value="en" selected[^>]*>English \(en\)/', $page);
        // The language required only adds a marker: the select always has a
        // value, and the server checks it is one of the vocabulary's.
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="lang"[^>]*required/', $page);
    }

    public function testTheImportFormThenStartsOnTheInstallationsLanguage()
    {
        $settings = DbTestCase::services()->get('Omeka\Settings');
        $locale = $settings->get('locale');
        // A locale in Omeka's form, with a region the file doesn't have.
        $settings->set('locale', 'de_AT');
        try {
            [, $page] = $this->send('GET', '/admin/vocabulary-library/import/ex');
            $this->assertMatchesRegularExpression('/<option value="de" selected/', $page);
            // The library's filter comes first.
            [, $page] = $this->send('GET', '/admin/vocabulary-library/import/ex', ['return' => 'lang=en']);
            $this->assertMatchesRegularExpression('/<option value="en" selected/', $page);
        } finally {
            $settings->set('locale', $locale);
        }
    }

    public function testImportingMakesTheVocabularyInOmeka()
    {
        [$status] = $this->import(['o:prefix' => 'example', 'o:label' => 'My label', 'lang' => 'de']);
        // Back to the library, which says so and links to the vocabulary.
        $this->assertSame(302, $status);
        $this->assertSame('/admin/vocabulary-library', $this->getResponse()->getHeaders()->get('Location')->getFieldValue());
        [, $page] = $this->send('GET', '/admin/vocabulary-library');
        $vocabulary = $this->vocabulary();
        $this->assertStringContainsString('Vocabulary successfully imported. <a href="/admin/vocabulary/' . $vocabulary->id() . '">View it in Omeka</a>', $page);
        $this->assertSame('example', $vocabulary->prefix());
        $this->assertSame('My label', $vocabulary->label());
        $this->assertSame('Summary.', $vocabulary->comment());
        $this->assertSame(['Ding', 'Anything at all.'], $this->term('Thing'));
        $this->assertCount(2, $vocabulary->properties());
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
        $back = '/admin/vocabulary-library?q=part&tag=general&sort_order=desc';
        $path = '/admin/vocabulary-library/import/ex?return=' . rawurlencode($return);
        [, $page] = $this->send('GET', '/admin/vocabulary-library');
        $this->assertStringContainsString('class="button vocabulary-library-keep-place"', $page);

        // Cancel leads back with only the library's own state, and the form
        // posts to where it came from, keeping it.
        [, $page] = $this->send('GET', $path);
        $this->assertStringContainsString('href="' . $back . '">Cancel</a>', $page);
        $this->assertMatchesRegularExpression('#<form[^>]*action=""#', $page);

        // An import leads there too.
        $tokens = $this->csrf($path);
        [$status] = $this->send('POST', $path, ['o:prefix' => 'ex', 'o:label' => 'Example Vocabulary', 'lang' => 'en'] + $tokens);
        $this->assertSame(302, $status);
        $this->assertSame($back, $this->getResponse()->getHeaders()->get('Location')->getFieldValue());

        // Compare keeps it through a change of reading, and leads back.
        [, $page] = $this->send('GET', '/admin/vocabulary-library/compare/ex', ['return' => $return]);
        $this->assertStringContainsString('<input type="hidden" name="return" value="q=part&tag=general&sort_order=desc">', $page);
        $this->assertStringContainsString('href="' . $back . '"', $page);
    }

    public function testWithoutAPlaceToReturnToTheLibraryIsAsItFirstShows()
    {
        [, $page] = $this->send('GET', '/admin/vocabulary-library/import/ex', ['return' => 'http://example.com/elsewhere']);
        $this->assertStringContainsString('href="/admin/vocabulary-library">Cancel</a>', $page);
    }

    public function testAnImportWithoutItsCsrfTokenMakesNothing()
    {
        [$status] = $this->send('POST', '/admin/vocabulary-library/import/ex', ['o:prefix' => 'ex', 'o:label' => 'Example Vocabulary', 'lang' => 'en']);
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
        $this->assertMatchesRegularExpression('#Omeka already has this vocabulary: <a href="[^"]*">Example Vocabulary \(ex\)</a>\.#', $page);
        $this->assertStringContainsString('/admin/vocabulary-library/review/ex', $page);
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
        $this->assertStringContainsString('/admin/vocabulary-library/review/ex', $page);
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
        [$status] = $this->send('GET', '/admin/vocabulary-library/review/ex', ['return' => 'tag=general']);
        $this->assertSame(302, $status);
        $review = $this->getResponse()->getHeaders()->get('Location')->getUri();
        $this->assertMatchesRegularExpression('#^/admin/vocabulary/' . $id . '/review\?review=[0-9a-f]{32}$#', $review);

        // Core's review calls the file the library's vocabulary.
        [$status, $page] = $this->send('GET', $review);
        $this->assertSame(200, $status);
        $this->assertMatchesRegularExpression('#This vocabulary already matches the library\S*s vocabulary, read with the choices above\.#', $page);

        // Leaving it returns to the library as the admin left it.
        parse_str((string) parse_url($review, PHP_URL_QUERY), $query);
        $tokens = $this->csrf($review);
        [$status] = $this->send('POST', '/admin/vocabulary/' . $id . '/cancel-review', ['review' => $query['review']] + $tokens);
        $this->assertSame(302, $status);
        $this->assertSame('/admin/vocabulary-library?tag=general', $this->getResponse()->getHeaders()->get('Location')->getUri());
    }

    public function testCompareAndUpdateNeedsTheVocabularyInOmeka()
    {
        $this->assertSame(404, $this->send('GET', '/admin/vocabulary-library/review/ex')[0]);
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
        $change = '/admin/vocabulary-library/change-namespace/ex';
        try {
            // The table says it is in use, as of any other, and no more.
            [, $page] = $this->send('GET', '/admin/vocabulary-library');
            $this->assertMatchesRegularExpression('#<a aria-label="Namespace in use: Example Vocabulary" href="[^"]*">Namespace in use</a>#', $page);
            $this->assertStringNotContainsString('vocabulary-library-status-next', $page);
            $this->assertStringNotContainsString($change, $page);
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
            $this->assertStringNotContainsString($change, $page);

            // A supervisor, who can't update it, is told it differs, and no
            // more.
            [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex', [], 'library-supervisor@example.com');
            $this->assertSame("Omeka's vocabulary has a slightly different namespace.", $this->statusNote($page));
            $this->assertStringNotContainsString('/admin/vocabulary-library/review/ex', $page);
            $this->assertSame(403, $this->send('GET', '/admin/vocabulary-library/review/ex', [], 'library-supervisor@example.com')[0]);

            $this->assertSame(404, $this->send('GET', '/admin/vocabulary-library/compare/ex')[0]);
        } finally {
            DbTestCase::signIn(DbTestCase::ADMIN);
            DbTestCase::services()->get('Omeka\ApiManager')->delete('vocabularies', $vocabulary->id());
        }
    }

    public function testANamespaceInAnotherFormIsChangedToTheLibrarysThenCompared()
    {
        DbTestCase::signIn(DbTestCase::ADMIN);
        $api = DbTestCase::services()->get('Omeka\ApiManager');
        $vocabulary = $api->create('vocabularies', [
            'o:namespace_uri' => 'https://example.org/ns/',
            'o:prefix' => 'variant',
            'o:label' => 'Variant',
        ])->getContent();
        $path = '/admin/vocabulary-library/change-namespace/ex';
        try {
            // The confirmation, for the sidebar, says which vocabulary, from
            // what, to what, and what changing it does.
            [$status, $page] = $this->send('GET', $path);
            $this->assertSame(200, $status);
            $this->assertStringNotContainsString('<html', $page);
            $this->assertMatchesRegularExpression('#<a href="[^"]*">Variant \(variant\)</a>#', $page);
            $this->assertMatchesRegularExpression('#<h4>From</h4>\s*<div class="value"><span class="vocabulary-library-namespace">https://example.org/ns/</span>#', $page);
            $this->assertMatchesRegularExpression('#<h4>To</h4>\s*<div class="value"><span class="vocabulary-library-namespace">http://example.org/ns\#</span>#', $page);
            // Why, in the details' own words, and what it does, together,
            // under the heading and before the namespaces.
            $this->assertMatchesRegularExpression('#</h3>\s*<p>Omeka\S*s vocabulary has a slightly different namespace, so the library can\S*t compare it. Changing the namespace gives the vocabulary\S*s classes and properties new addresses everywhere Omeka publishes them. Nothing is deleted, and values stay attached to their properties.</p>\s*<div class="meta-group">#', $page);
            $this->assertMatchesRegularExpression('#<form [^>]*action="/admin/vocabulary-library/change-namespace/ex"[^>]*class="vocabulary-library-keep-place"|<form [^>]*class="vocabulary-library-keep-place"[^>]*action="/admin/vocabulary-library/change-namespace/ex"#', $page);
            $this->assertStringContainsString('Confirm change', $page);
            $this->assertSame('https://example.org/ns/', $api->read('vocabularies', $vocabulary->id())->getContent()->namespaceUri());
            // Not core's confirmation box, whose button is red as for a delete.
            $this->assertStringNotContainsString('id="sidebar-confirm"', $page);

            // With a stale token, as from a form left open too long, nothing
            // changes, and the admin is back in the library as they left it,
            // told why.
            $stale = array_fill_keys(array_keys($this->csrf($path)), 'stale');
            [$status] = $this->send('POST', $path . '?return=tag%3Dgeneral', $stale);
            $this->assertSame(302, $status);
            $this->assertSame('/admin/vocabulary-library?tag=general', $this->getResponse()->getHeaders()->get('Location')->getFieldValue());
            DbTestCase::services()->get('Omeka\EntityManager')->clear();
            $this->assertSame('https://example.org/ns/', $api->read('vocabularies', $vocabulary->id())->getContent()->namespaceUri());
            [, $page] = $this->send('GET', '/admin/vocabulary-library');
            $this->assertStringContainsString('Invalid or missing CSRF token', $page);

            // Confirmed, it has the library's namespace, and Compare follows,
            // with the library as the admin left it to return to.
            $tokens = $this->csrf($path . '?return=tag%3Dgeneral');
            [$status] = $this->send('POST', $path . '?return=tag%3Dgeneral', $tokens);
            $this->assertSame(302, $status);
            $this->assertSame('/admin/vocabulary-library/compare/ex?return=tag%3Dgeneral', $this->getResponse()->getHeaders()->get('Location')->getUri());
            DbTestCase::services()->get('Omeka\EntityManager')->clear();
            $this->assertSame('http://example.org/ns#', $api->read('vocabularies', $vocabulary->id())->getContent()->namespaceUri());
            [, $page] = $this->send('GET', '/admin/vocabulary-library/compare/ex');
            $this->assertStringContainsString('Namespace successfully changed', $page);

            // Nothing left to change.
            $this->assertSame(404, $this->send('GET', $path)[0]);
        } finally {
            DbTestCase::signIn(DbTestCase::ADMIN);
            $api->delete('vocabularies', $vocabulary->id());
        }
    }

    public function testCompareShowsNoChangesForAVocabularyJustImported()
    {
        $this->import(['lang' => 'de']);
        // With no language asked, the one it was imported in is found.
        [$status, $page] = $this->send('GET', '/admin/vocabulary-library/compare/ex');
        $this->assertSame(200, $status);
        $this->assertMatchesRegularExpression('/<option value="de" selected/', $page);
        $this->assertStringNotContainsString('Ding', $page);
    }

    public function testCompareShowsWhatAnotherLanguageWouldChangeAndUpdateAppliesIt()
    {
        $this->import(['lang' => 'de']);
        $path = '/admin/vocabulary-library/compare/ex';
        [$status, $page] = $this->send('GET', $path, ['lang' => 'en']);
        $this->assertSame(200, $status);
        // Sections are headed one level below the page's title, and listed
        // first, each linked.
        $this->assertMatchesRegularExpression('#<h2 id="vocabulary-library-changed-class-labels"[^>]*>Changed class labels \(2\)</h2>#', $page);
        $this->assertStringNotContainsString('<h3>', $page);
        $this->assertMatchesRegularExpression('#<nav class="vocabulary-library-compare-summary"[^>]*>\s*<ul>\s*<li><a href="\#vocabulary-library-changed-property-labels">Changed property labels \(2\)</a></li>\s*<li><a href="\#vocabulary-library-changed-class-labels">Changed class labels \(2\)</a></li>\s*</ul>#', $page);
        // The first column is named for the section's kind of term.
        $this->assertMatchesRegularExpression('#<th>Class</th>\s*<th>From</th>\s*<th>To</th>#', $page);
        $this->assertStringNotContainsString('<th>Type</th>', $page);
        $this->assertStringNotContainsString('<th>Term</th>', $page);
        // The term heads its row, with its label in Omeka, and what differs
        // in the value is marked.
        $this->assertMatchesRegularExpression('#<td role="rowheader"><span class="vocabulary-library-term-label">Ding</span> <span class="field-term">ex:Thing</span></td>\s*<td><span class="vocabulary-library-value"><del>Ding</del></span></td>\s*<td><span class="vocabulary-library-value"><ins>Thing</ins></span></td>#', $page);

        $tokens = $this->csrf($path);
        [$status] = $this->send('POST', $path, ['lang' => 'en'] + $tokens);
        // Back to the library, as after an import, which says so and links
        // to the vocabulary.
        $this->assertSame(302, $status);
        $this->assertSame('/admin/vocabulary-library', $this->getResponse()->getHeaders()->get('Location')->getFieldValue());
        [, $page] = $this->send('GET', '/admin/vocabulary-library');
        $this->assertStringContainsString('Changes to the vocabulary successfully made. <a href="/admin/vocabulary/' . $this->vocabulary()->id() . '">View it in Omeka</a>', $page);
        $this->assertSame(['Thing', 'Anything at all.'], $this->term('Thing'));
        // Its definition in the file is empty.
        $this->assertSame('is part of', $this->term('isPartOf')[0]);
        $this->assertSame('', (string) $this->term('isPartOf')[1]);
    }

    public function testLabelsComeBeforeComments()
    {
        $this->import(['lang' => 'de']);
        [, $page] = $this->send('GET', '/admin/vocabulary-library/compare/ex', ['lang' => 'en', 'comments' => 'comments']);
        preg_match_all('#<h2 id="vocabulary-library-([a-z-]+)"#', $page, $sections);
        $this->assertSame(['changed-property-labels', 'changed-class-labels', 'changed-class-comments'], $sections[1]);
        // A comment, like a label, from what, to what.
        $this->assertMatchesRegularExpression('#<span class="field-term">ex:Thing</span></td>\s*<td><span class="vocabulary-library-value"><del>Anything at all.</del></span></td>\s*<td><span class="vocabulary-library-value"><ins>A note about things.</ins></span></td>#', $page);
        $this->assertStringContainsString('>Show changes</button>', $page);
    }

    public function testEachSectionOfTermsOnlyInOmekaHasItsNote()
    {
        $this->import();
        // vocabulary() clears what Doctrine holds, so sign in after it.
        $id = $this->vocabulary()->id();
        DbTestCase::signIn(DbTestCase::ADMIN);
        $api = DbTestCase::services()->get('Omeka\ApiManager');
        $api->create('resource_classes', ['o:vocabulary' => ['o:id' => $id], 'o:local_name' => 'Extra', 'o:label' => 'Extra']);
        $api->create('properties', ['o:vocabulary' => ['o:id' => $id], 'o:local_name' => 'extra', 'o:label' => 'extra']);
        [, $page] = $this->send('GET', '/admin/vocabulary-library/compare/ex');
        $this->assertStringContainsString('Properties only in Omeka (1)', $page);
        $this->assertStringContainsString('Classes only in Omeka (1)', $page);
        // The section links lead to either, so each says so.
        $this->assertSame(2, substr_count($page, "Omeka's vocabulary has these and the library's doesn't."));
    }

    public function testCompareOffersTheOtherReadingOfComments()
    {
        $this->import();
        $path = '/admin/vocabulary-library/compare/ex';
        // As imported, definitions; read as Omeka's own import reads them,
        // the definition would give way to the comment.
        [, $page] = $this->send('GET', $path);
        $this->assertStringNotContainsString('A note about things.', $page);
        // Nothing to list, so no list of sections.
        $this->assertStringNotContainsString('vocabulary-library-compare-summary', $page);
        [, $page] = $this->send('GET', $path, ['comments' => 'comments']);
        $this->assertStringContainsString('A note about things.', $page);

        [$status] = $this->send('POST', $path, ['comments' => 'comments', 'lang' => 'en'] + $this->csrf($path));
        $this->assertSame(302, $status);
        $this->assertSame(['Thing', 'A note about things.'], $this->term('Thing'));
    }

    public function testAVocabularyNotInOmekaCannotBeCompared()
    {
        $this->assertSame(404, $this->send('GET', '/admin/vocabulary-library/compare/ex')[0]);
    }

    public function testASupervisorCannotUpdateAVocabularyTheyDoNotOwn()
    {
        $this->import();
        $this->assertSame(403, $this->send('GET', '/admin/vocabulary-library/compare/ex', [], 'library-supervisor@example.com')[0]);
    }
}
