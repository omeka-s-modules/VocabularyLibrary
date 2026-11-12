<?php
namespace VocabularyLibrary\Stdlib;

/**
 * Finds the library's vocabularies with a class or property matching a query,
 * in the term index the build writes.
 */
class TermSearch
{
    /**
     * A query needs a few characters to be useful, and a cap keeps a search
     * cheap.
     */
    const QUERY_MIN = 3;
    const QUERY_MAX = 100;

    /**
     * The best matching terms returned per vocabulary.
     */
    const MATCHES_SHOWN = 3;

    protected $indexPath;
    protected $vocabularies;

    /**
     * @param string $indexPath The term index, data/term-index.json
     * @param array $vocabularies The library, keyed by slug
     */
    public function __construct($indexPath, array $vocabularies)
    {
        $this->indexPath = $indexPath;
        $this->vocabularies = $vocabularies;
    }

    /**
     * A term matches when its label or local name contains every word of the
     * query, in any order, as the details sidebar's term filter matches them.
     *
     * Terms are ranked: a label or local name that is the query itself, then
     * one with a word that starts with each of the query's words ("date" in
     * "Date created" or "startDate"), then any other; within a rank, file
     * order.
     *
     * @param string $query
     * @return array [slug => ['terms' => [['label' => …, 'term' =>
     *   "prefix:localName"], …], 'count' => number], …]: the best
     *   MATCHES_SHOWN terms per vocabulary, and how many match in all, or none
     *   for a query shorter than QUERY_MIN or longer than QUERY_MAX. Terms
     *   are written as Omeka writes them, with the library's default prefix.
     */
    public function search($query)
    {
        $query = trim($query);
        if (mb_strlen($query) < self::QUERY_MIN || mb_strlen($query) > self::QUERY_MAX) {
            return [];
        }
        $lowerQuery = mb_strtolower($query);
        $words = preg_split('/\s+/u', $lowerQuery);
        $wordStarts = [];
        foreach ($words as $word) {
            $wordStarts[] = '/(?:^|[^\p{L}\p{N}])' . preg_quote($word, '/') . '/u';
        }
        $index = json_decode(file_get_contents($this->indexPath), true);
        $matches = [];
        foreach ($index as $slug => $terms) {
            $prefix = $this->vocabularies[$slug]['prefix'] ?? null;
            if ($prefix === null) {
                continue;
            }
            $found = [];
            foreach ($terms as $term) {
                [$localName, $label] = is_array($term) ? $term : [$term, $term];
                foreach ($words as $word) {
                    if (mb_stripos($label, $word) === false && mb_stripos($localName, $word) === false) {
                        continue 2;
                    }
                }
                if (mb_strtolower($label) === $lowerQuery || mb_strtolower($localName) === $lowerQuery) {
                    $rank = 0;
                } else {
                    $names = $this->words($label) . ' ' . $this->words($localName);
                    $rank = 1;
                    foreach ($wordStarts as $wordStart) {
                        if (!preg_match($wordStart, $names)) {
                            $rank = 2;
                            break;
                        }
                    }
                }
                // A term with no label in its file shows its local name, as
                // Omeka labels it on import.
                $found[] = [$rank, count($found), ['label' => $label, 'term' => "$prefix:$localName"]];
            }
            if ($found) {
                sort($found);
                $matches[$slug] = [
                    'terms' => array_column(array_slice($found, 0, self::MATCHES_SHOWN), 2),
                    'count' => count($found),
                ];
            }
        }
        return $matches;
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
}
