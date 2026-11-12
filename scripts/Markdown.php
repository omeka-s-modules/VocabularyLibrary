<?php
namespace VocabularyLibrary\Scripts;

/**
 * Turns the Markdown some publishers write their titles and descriptions in
 * into the plain text the library keeps.
 */
class Markdown
{
    /**
     * A text as one line, without its Markdown, for a title.
     */
    public static function line($text)
    {
        return implode(' ', self::paragraphs($text));
    }

    /**
     * A text's paragraphs, each on one line, without its Markdown.
     *
     * @return string[]
     */
    public static function paragraphs($text)
    {
        $paragraphs = [];
        foreach (preg_split('/\n\s*\n/u', self::toText($text)) as $block) {
            $block = trim(preg_replace('/\s+/u', ' ', $block));
            if ($block !== '') {
                $paragraphs[] = $block;
            }
        }
        return $paragraphs;
    }

    /**
     * Plain text from Markdown, as some publishers (the SPAR ontologies, for
     * one) write their descriptions: link text without the URL, emphasis
     * without its markers, and headings, list items and code blocks as their
     * own paragraphs. The patterns only match Markdown syntax, so names like
     * "E63_Beginning_of_Existence" are left alone.
     *
     * Plain prose wrapped onto several lines can start a line with a dash or
     * a number. Such a line is not a list item when the lines before and
     * after it are both prose, unless the one before ends with a colon, as a
     * line introducing a list does.
     */
    public static function toText($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $source = explode("\n", $text);
        $fence = '/^\s*(```|~~~)/';
        $rule = '/^\s*([-*_]\s*){3,}$/';
        $heading = '/^\s{0,3}#{1,6}\s+(.*?)\s*#*\s*$/';
        $bullet = '/^\s*[-*+]\s+(.*)$/';
        $number = '/^\s*(\d+[.)])\s+(.*)$/';
        // A line of ordinary text: not blank, and none of the above.
        $prose = function ($i) use ($source, $fence, $rule, $heading, $bullet, $number) {
            if (!isset($source[$i]) || trim($source[$i]) === '') {
                return false;
            }
            foreach ([$fence, $rule, $heading, $bullet, $number] as $pattern) {
                if (preg_match($pattern, $source[$i])) {
                    return false;
                }
            }
            return true;
        };
        $lines = [];
        foreach ($source as $i => $line) {
            // A line that looks like a list item but is wrapped prose.
            $wrapped = $prose($i - 1) && $prose($i + 1) && substr(rtrim($source[$i - 1]), -1) !== ':';
            if (preg_match($fence, $line) || preg_match($rule, $line)) {
                // Code fences and horizontal rules end a paragraph.
                $lines[] = '';
            } elseif (preg_match($heading, $line, $match)) {
                array_push($lines, '', $match[1], '');
            } elseif (!$wrapped && preg_match($bullet, $line, $match)) {
                array_push($lines, '', '• ' . $match[1], '');
            } elseif (!$wrapped && preg_match($number, $line, $match)) {
                array_push($lines, '', $match[1] . ' ' . $match[2], '');
            } else {
                $lines[] = $line;
            }
        }
        $text = implode("\n", $lines);
        $inline = [
            '/!\[([^\]]*)\]\([^)]*\)/' => '$1',
            '/\[([^\]]+)\]\(\s*<?[^)\s>]+>?(?:\s+"[^"]*")?\s*\)/' => '$1',
            '/<(https?:\/\/[^>\s]+)>/' => '$1',
            '/`([^`\n]+)`/' => '$1',
            '/(?<![\w*])\*\*(?=\S)(.+?)(?<=\S)\*\*(?![\w*])/s' => '$1',
            '/(?<!\w)__(?=\S)(.+?)(?<=\S)__(?!\w)/s' => '$1',
            '/(?<![\w*])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![\w*])/s' => '$1',
            '/(?<!\w)_(?=[^\s_])(.+?)(?<=[^\s_])_(?!\w)/s' => '$1',
        ];
        // Twice, for nested emphasis such as **_this_**.
        for ($i = 0; $i < 2; $i++) {
            $text = preg_replace(array_keys($inline), array_values($inline), $text);
        }
        return $text;
    }
}
