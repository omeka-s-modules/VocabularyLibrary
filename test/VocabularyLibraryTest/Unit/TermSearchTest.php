<?php
namespace VocabularyLibraryTest\Unit;

use VocabularyLibrary\Stdlib\TermSearch;
use VocabularyLibraryTest\TempDirTestCase;

class TermSearchTest extends TempDirTestCase
{
    protected function search($query, ?array $index = null)
    {
        $index = $index ?? [
            'ex' => [
                'Agent',
                ['created', 'Date Created'],
                ['hasPart', 'Has Part'],
                ['isPartOf', 'Is Part Of'],
                'startDate',
                ['update', 'Candidate'],
                ['date', 'Date'],
            ],
            'other' => [['partner', 'Partner']],
            'unlisted' => [['hasPart', 'Has Part']],
        ];
        file_put_contents($this->dir . '/term-index.json', json_encode($index));
        $search = new TermSearch($this->dir . '/term-index.json', ['ex' => ['prefix' => 'ex'], 'other' => ['prefix' => 'o2']]);
        return $search->search($query);
    }

    protected static function terms(array $match)
    {
        return array_column($match['terms'], 'term');
    }

    public function testAQueryTooShortOrTooLongFindsNothing()
    {
        $this->assertSame([], $this->search('da'));
        $this->assertSame([], $this->search('  da  '));
        $this->assertSame([], $this->search(str_repeat('a', TermSearch::QUERY_MAX + 1)));
    }

    public function testATermMatchesByItsLabelOrItsLocalName()
    {
        $matches = $this->search('created');
        $this->assertSame(['ex'], array_keys($matches));
        $this->assertSame([['label' => 'Date Created', 'term' => 'ex:created']], $matches['ex']['terms']);
        $this->assertSame(['ex:Agent'], self::terms($this->search('agent')['ex']));
    }

    public function testATermWithNoLabelShowsItsLocalName()
    {
        $this->assertSame([['label' => 'Agent', 'term' => 'ex:Agent']], $this->search('Agent')['ex']['terms']);
    }

    public function testEveryWordMustMatchInAnyOrder()
    {
        $this->assertSame(['ex:created'], self::terms($this->search('created date')['ex']));
        $this->assertSame(['ex:created'], self::terms($this->search('date created')['ex']));
        $this->assertSame(['ex:hasPart'], self::terms($this->search('part has')['ex']));
        $this->assertArrayNotHasKey('ex', $this->search('created agent'));
    }

    public function testAWordMayMatchTheLabelAndAnotherTheLocalName()
    {
        // "created" is the local name, "date" only in the label.
        $this->assertSame(['ex:created'], self::terms($this->search('created date')['ex']));
        // "update" is the local name, "candidate" the label.
        $this->assertSame(['ex:update'], self::terms($this->search('update candidate')['ex']));
    }

    public function testTermsAreRankedExactThenWordStartThenAnyOther()
    {
        // "date" itself, then words that start with it in file order
        // ("Date Created", "startDate"), then "update" and "Candidate", which
        // only contain it.
        $this->assertSame(
            ['ex:date', 'ex:created', 'ex:startDate'],
            self::terms($this->search('date')['ex'])
        );
        $this->assertSame(4, $this->search('date')['ex']['count']);
    }

    public function testOnlyTheBestFewTermsAreReturnedWithTheCountOfAll()
    {
        $terms = [];
        for ($i = 1; $i <= 10; $i++) {
            $terms[] = "thing$i";
        }
        $match = $this->search('thing', ['ex' => $terms])['ex'];
        $this->assertCount(TermSearch::MATCHES_SHOWN, $match['terms']);
        $this->assertSame(10, $match['count']);
    }

    public function testTermsAreWrittenWithTheLibrarysPrefix()
    {
        $this->assertSame(['o2:partner'], self::terms($this->search('partner')['other']));
    }

    public function testAVocabularyNotInTheLibraryIsLeftOut()
    {
        $this->assertSame(['ex', 'other'], array_keys($this->search('part')));
    }

    public function testMatchingIgnoresCase()
    {
        $this->assertSame(['ex:hasPart'], self::terms($this->search('HAS PART')['ex']));
    }
}
