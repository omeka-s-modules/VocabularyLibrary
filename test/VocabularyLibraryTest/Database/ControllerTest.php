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
        $this->assertStringContainsString('Example Vocabulary', $page);
        $this->assertStringContainsString('Classes and properties for describing things and their parts.', $page);
        $this->assertStringContainsString('/admin/vocabulary-library/import/ex', $page);
        // A failed term search has its message, hidden until then.
        $this->assertMatchesRegularExpression('/<p class="vocabulary-library-term-search-failed[^"]*" role="alert" hidden>/', $page);
        // Only the filter's option says so; no vocabulary's status does.
        $this->assertSame(1, substr_count($page, 'Namespace in use'));
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
        $this->assertSame(404, $this->send('GET', '/admin/vocabulary-library/import/nothing')[0]);
        $this->assertSame(404, $this->send('GET', '/admin/vocabulary-library/file/nothing')[0]);
    }

    public function testTheDetailsShowTheVocabularyAndItsTerms()
    {
        [$status, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
        $this->assertSame(200, $status);
        foreach (['Example Vocabulary', 'http://example.org/ns#', 'https://creativecommons.org/licenses/by/4.0/', 'ex:hasPart', 'has part', 'Anything at all.', 'German'] as $text) {
            $this->assertStringContainsString($text, $page);
        }
        // The comment gives way to the definition.
        $this->assertStringNotContainsString('A note about things.', $page);
    }

    public function testTheTermFiltersResultHasSomewhereToBeReadOutFrom()
    {
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
        $this->assertMatchesRegularExpression('/<p class="[^"]*vocabulary-library-term-filter-status"[^>]*aria-live="polite"/', $page);
    }

    public function testTheLicenseLinkSaysItOpensInANewTab()
    {
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
        $this->assertMatchesRegularExpression('#<a href="https://creativecommons.org/licenses/by/4.0/" target="_blank">[^<]*<span class="vocabulary-library-sr-only">\(opens in a new tab\)</span></a>#', $page);
    }

    public function testALongDescriptionIsCutAtASentence()
    {
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
        // The second sentence's "e.g." doesn't end it, and the third
        // sentence would take the start past its length, so it and the
        // second paragraph are the rest, behind "Full description".
        $start = preg_quote('The Example Vocabulary describes things and their parts. It was written for tests, e.g. of how a description is shortened.', '#');
        $third = preg_quote('This third sentence takes the first paragraph well past the length at which the details sidebar cuts a description.', '#');
        $this->assertMatchesRegularExpression(
            "#$start\s*<div class=\"vocabulary-library-more\">\s*<a [^>]*>Full description</a>\s*<div class=\"collapsible\">\s*<p>$third</p>\s*<p>A second paragraph follows\.</p>\s*</div>#",
            $page
        );
    }

    public function testTheDetailsStartFilteredFromATermSearch()
    {
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex', ['filter' => ' has part ']);
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
        $this->assertMatchesRegularExpression('/<input[^>]*name="o:prefix"[^>]*value="ex"/', $page);
        // The prefix's warnings are read with the field.
        $this->assertMatchesRegularExpression('/<input[^>]*name="o:prefix"[^>]*aria-describedby="vocabulary-library-prefix-warnings"/', $page);
        $this->assertStringContainsString('<div id="vocabulary-library-prefix-warnings"', $page);
        $this->assertMatchesRegularExpression('/<input[^>]*name="o:label"[^>]*value="Example Vocabulary"/', $page);
        $this->assertStringContainsString('Classes and properties for describing things and their parts.</textarea>', $page);
        // Two languages to choose from, English chosen.
        $this->assertMatchesRegularExpression('/<option value="en" selected[^>]*>English \(en\)/', $page);
        $this->assertStringContainsString('German (de)', $page);
    }

    public function testImportingMakesTheVocabularyInOmeka()
    {
        [$status] = $this->import(['o:prefix' => 'example', 'o:label' => 'My label', 'lang' => 'de']);
        $this->assertSame(302, $status);
        $this->assertSame('/admin/vocabulary?namespace_uri=http://example.org/ns%23', $this->getResponse()->getHeaders()->get('Location')->getFieldValue());
        $vocabulary = $this->vocabulary();
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
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
        $this->assertStringContainsString('/admin/vocabulary-library/compare/ex', $page);
    }

    public function testAVocabularyInUseIsComparedFromItsDetailsNotItsRow()
    {
        $this->import();
        // On every row the link would read as something to do.
        [, $page] = $this->send('GET', '/admin/vocabulary-library');
        $this->assertStringContainsString('Namespace in use: Example Vocabulary', $page);
        $this->assertStringNotContainsString('/admin/vocabulary-library/compare/ex', $page);
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
        $this->assertMatchesRegularExpression('#<a class="button" href="/admin/vocabulary-library/compare/ex">Compare and update</a>#', $page);
        // Nor by a supervisor, who doesn't own it.
        [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex', [], 'library-supervisor@example.com');
        $this->assertStringNotContainsString('/admin/vocabulary-library/compare/ex', $page);
    }

    public function testANamespaceInAnotherFormIsInUseAndItsDetailsSayHowToChangeIt()
    {
        DbTestCase::signIn(DbTestCase::ADMIN);
        $vocabulary = DbTestCase::services()->get('Omeka\ApiManager')->create('vocabularies', [
            'o:namespace_uri' => 'https://example.org/ns/',
            'o:prefix' => 'variant',
            'o:label' => 'Variant',
        ])->getContent();
        $edit = '/admin/vocabulary/' . $vocabulary->id() . '/edit';
        try {
            // The table says it is in use, as of any other, and no more.
            [, $page] = $this->send('GET', '/admin/vocabulary-library');
            $this->assertMatchesRegularExpression('#<a aria-label="Namespace in use: Example Vocabulary" href="[^"]*">Namespace in use</a>#', $page);
            $this->assertStringNotContainsString('vocabulary-library-status-next', $page);
            $this->assertStringNotContainsString($edit, $page);
            $this->assertStringNotContainsString('/admin/vocabulary-library/import/ex', $page);

            [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex');
            $this->assertSame(
                "To compare and update Omeka's vocabulary, change its namespace from https://example.org/ns/ to http://example.org/ns#.",
                $this->statusNote($page)
            );
            $this->assertMatchesRegularExpression('#<a class="button" href="' . $edit . '">Edit vocabulary</a>#', $page);
            $this->assertStringNotContainsString('/admin/vocabulary-library/compare/ex', $page);

            // A supervisor, who can't change it, is told but not sent there.
            [, $page] = $this->send('GET', '/admin/vocabulary-library/show-details/ex', [], 'library-supervisor@example.com');
            $this->assertSame(
                "Omeka's vocabulary can't be compared or updated: its namespace is https://example.org/ns/, not http://example.org/ns#.",
                $this->statusNote($page)
            );
            $this->assertStringNotContainsString($edit, $page);

            $this->assertSame(404, $this->send('GET', '/admin/vocabulary-library/compare/ex')[0]);
        } finally {
            DbTestCase::signIn(DbTestCase::ADMIN);
            DbTestCase::services()->get('Omeka\ApiManager')->delete('vocabularies', $vocabulary->id());
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
        $this->assertSame(302, $status);
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

    public function testTermsOnlyInOmekaShareOneNote()
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
        $this->assertSame(1, substr_count($page, "Omeka's vocabulary has these and the library's doesn't."));
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
