<?php
namespace VocabularyLibrary\Stdlib;

/**
 * Publisher text as plain text, for vocabularies whose files put HTML in their
 * literals: the tags of the listed HTML elements are removed, keeping the text
 * between them, and entities decoded. Other angle brackets, as in "<= 5.0" or
 * "<subject>", are text.
 *
 * The build uses it for the titles, descriptions and licenses it reads, and
 * the module for term labels and comments, which it reads from the files.
 * The build's scripts load it without starting Omeka, so it must not use
 * Omeka's services, configuration or database.
 */
class PlainText
{
    /**
     * HTML elements found in vocabulary files' literals, with or without a
     * namespace prefix (as in html:div).
     */
    const HTML_TAG = '#</?(?:[a-z][a-z0-9.-]*:)?(a|abbr|b|blockquote|br|code|dd|div|dl|dt|em|figcaption|figure|h[1-6]|i|img|li|ol|p|pre|span|strong|sub|sup|table|td|th|tr|ul)\\b[^<>]*>#i';

    public static function from($value)
    {
        return html_entity_decode(preg_replace(self::HTML_TAG, '', (string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Publisher text as one line of plain text, its whitespace collapsed as
     * a browser shows it. A term's label is shown and indexed this way, so a
     * search for several words matches what the sidebar shows.
     */
    public static function line($value)
    {
        $text = self::from($value);
        // Text that isn't valid UTF-8 can't be matched as such.
        return trim(preg_replace('/\s+/u', ' ', $text) ?? preg_replace('/\s+/', ' ', $text));
    }
}
