<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Polyfill\Intl\Grapheme;

\define('SYMFONY_GRAPHEME_CLUSTER_RX', ((float) \PCRE_VERSION < 10 ? (float) \PCRE_VERSION >= 8.32 : (float) \PCRE_VERSION >= 10.39) ? '\X' : Grapheme::GRAPHEME_CLUSTER_RX);

/**
 * Partial intl implementation in pure PHP.
 *
 * Implemented:
 * - grapheme_extract  - Extract a sequence of grapheme clusters from a text buffer, which must be encoded in UTF-8
 * - grapheme_stripos  - Find position (in grapheme units) of first occurrence of a case-insensitive string
 * - grapheme_stristr  - Returns part of haystack string from the first occurrence of case-insensitive needle to the end of haystack
 * - grapheme_strlen   - Get string length in grapheme units
 * - grapheme_strpos   - Find position (in grapheme units) of first occurrence of a string
 * - grapheme_strripos - Find position (in grapheme units) of last occurrence of a case-insensitive string
 * - grapheme_strrpos  - Find position (in grapheme units) of last occurrence of a string
 * - grapheme_strstr   - Returns part of haystack string from the first occurrence of needle to the end of haystack
 * - grapheme_substr   - Return part of a string
 * - grapheme_str_split - Splits a string into an array of individual or chunks of graphemes
 *
 * @author Nicolas Grekas <p@tchwork.com>
 *
 * @internal
 */
final class Grapheme
{
    // (CRLF|([ZWNJ-ZWJ]|T+|L*(LV?V+|LV|LVT)T*|L+|[^Control])[Extend]*|[Control])
    // This regular expression is a work around for http://bugs.exim.org/1279
    public const GRAPHEME_CLUSTER_RX = '(?:\r\n|(?:[ -~\x{200C}\x{200D}]|[á†¨-á‡¹]+|[á„€-á…Ÿ]*(?:[ê°€ê°œê°¸ê±”ê±°ê²Œê²¨ê³„ê³ ê³¼ê´˜ê´´êµêµ¬ê¶ˆê¶¤ê·€ê·œê·¸ê¸”ê¸°ê¹Œê¹¨êº„êº êº¼ê»˜ê»´ê¼ê¼¬ê½ˆê½¤ê¾€ê¾œê¾¸ê¿”ê¿°ë€Œë€¨ë„ë ë¼ë‚˜ë‚´ëƒëƒ¬ë„ˆë„¤ë…€ë…œë…¸ë†”ë†°ë‡Œë‡¨ëˆ„ëˆ ëˆ¼ë‰˜ë‰´ëŠëŠ¬ë‹ˆë‹¤ëŒ€ëŒœëŒ¸ë”ë°ëŽŒëŽ¨ë„ë ë¼ë˜ë´ë‘ë‘¬ë’ˆë’¤ë“€ë“œë“¸ë””ë”°ë•Œë•¨ë–„ë– ë–¼ë—˜ë—´ë˜ë˜¬ë™ˆë™¤ëš€ëšœëš¸ë›”ë›°ëœŒëœ¨ë„ë ë¼ëž˜ëž´ëŸëŸ¬ë ˆë ¤ë¡€ë¡œë¡¸ë¢”ë¢°ë£Œë£¨ë¤„ë¤ ë¤¼ë¥˜ë¥´ë¦ë¦¬ë§ˆë§¤ë¨€ë¨œë¨¸ë©”ë©°ëªŒëª¨ë«„ë« ë«¼ë¬˜ë¬´ë­ë­¬ë®ˆë®¤ë¯€ë¯œë¯¸ë°”ë°°ë±Œë±¨ë²„ë² ë²¼ë³˜ë³´ë´ë´¬ëµˆëµ¤ë¶€ë¶œë¶¸ë·”ë·°ë¸Œë¸¨ë¹„ë¹ ë¹¼ëº˜ëº´ë»ë»¬ë¼ˆë¼¤ë½€ë½œë½¸ë¾”ë¾°ë¿Œë¿¨ì€„ì€ ì€¼ì˜ì´ì‚ì‚¬ìƒˆìƒ¤ì„€ì„œì„¸ì…”ì…°ì†Œì†¨ì‡„ì‡ ì‡¼ìˆ˜ìˆ´ì‰ì‰¬ìŠˆìŠ¤ì‹€ì‹œì‹¸ìŒ”ìŒ°ìŒì¨ìŽ„ìŽ ìŽ¼ì˜ì´ìì¬ì‘ˆì‘¤ì’€ì’œì’¸ì“”ì“°ì”Œì”¨ì•„ì• ì•¼ì–˜ì–´ì—ì—¬ì˜ˆì˜¤ì™€ì™œì™¸ìš”ìš°ì›Œì›¨ìœ„ìœ ìœ¼ì˜ì´ìžìž¬ìŸˆìŸ¤ì €ì œì ¸ì¡”ì¡°ì¢Œì¢¨ì£„ì£ ì£¼ì¤˜ì¤´ì¥ì¥¬ì¦ˆì¦¤ì§€ì§œì§¸ì¨”ì¨°ì©Œì©¨ìª„ìª ìª¼ì«˜ì«´ì¬ì¬¬ì­ˆì­¤ì®€ì®œì®¸ì¯”ì¯°ì°Œì°¨ì±„ì± ì±¼ì²˜ì²´ì³ì³¬ì´ˆì´¤ìµ€ìµœìµ¸ì¶”ì¶°ì·Œì·¨ì¸„ì¸ ì¸¼ì¹˜ì¹´ìºìº¬ì»ˆì»¤ì¼€ì¼œì¼¸ì½”ì½°ì¾Œì¾¨ì¿„ì¿ ì¿¼í€˜í€´íí¬í‚ˆí‚¤íƒ€íƒœíƒ¸í„”í„°í…Œí…¨í†„í† í†¼í‡˜í‡´íˆíˆ¬í‰ˆí‰¤íŠ€íŠœíŠ¸í‹”í‹°íŒŒíŒ¨í„í í¼íŽ˜íŽ´íí¬íˆí¤í‘€í‘œí‘¸í’”í’°í“Œí“¨í”„í” í”¼í•˜í•´í–í–¬í—ˆí—¤í˜€í˜œí˜¸í™”í™°íšŒíš¨í›„í› í›¼íœ˜íœ´íí¬ížˆ]?[á… -á†¢]+|[ê°€-íž£])[á†¨-á‡¹]*|[á„€-á…Ÿ]+|[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}])[\p{Mn}\p{Me}\x{09BE}\x{09D7}\x{0B3E}\x{0B57}\x{0BBE}\x{0BD7}\x{0CC2}\x{0CD5}\x{0CD6}\x{0D3E}\x{0D57}\x{0DCF}\x{0DDF}\x{200C}\x{200D}\x{1D165}\x{1D16E}-\x{1D172}]*|[\p{Cc}\p{Cf}\p{Zl}\p{Zp}])';

    private const CASE_FOLD = [
        ['Âµ', 'Å¿', "\xCD\x85", 'Ï‚', "\xCF\x90", "\xCF\x91", "\xCF\x95", "\xCF\x96", "\xCF\xB0", "\xCF\xB1", "\xCF\xB5", "\xE1\xBA\x9B", "\xE1\xBE\xBE"],
        ['Î¼', 's', 'Î¹',        'Ïƒ', 'Î²',        'Î¸',        'Ï†',        'Ï€',        'Îº',        'Ï',        'Îµ',        "\xE1\xB9\xA1", 'Î¹'],
    ];

    public static function grapheme_extract($s, $size, $type = \GRAPHEME_EXTR_COUNT, $start = 0, &$next = 0)
    {
        if (0 > $start) {
            $start = \strlen($s) + $start;
        }

        if (!\is_scalar($s)) {
            $hasError = false;
            set_error_handler(function () use (&$hasError) { $hasError = true; });
            $next = substr($s, $start);
            restore_error_handler();
            if ($hasError) {
                substr($s, $start);
                $s = '';
            } else {
                $s = $next;
            }
        } else {
            $s = substr($s, $start);
        }
        $size = (int) $size;
        $type = (int) $type;
        $start = (int) $start;

        if (\GRAPHEME_EXTR_COUNT !== $type && \GRAPHEME_EXTR_MAXBYTES !== $type && \GRAPHEME_EXTR_MAXCHARS !== $type) {
            if (80000 > \PHP_VERSION_ID) {
                return false;
            }

            throw new \ValueError('grapheme_extract(): Argument #3 ($type) must be one of GRAPHEME_EXTR_COUNT, GRAPHEME_EXTR_MAXBYTES, or GRAPHEME_EXTR_MAXCHARS');
        }

        if (!isset($s[0]) || 0 > $size || 0 > $start) {
            return false;
        }
        if (0 === $size) {
            return '';
        }

        $next = $start;

        $s = preg_split('/('.SYMFONY_GRAPHEME_CLUSTER_RX.')/u', "\r\n".$s, $size + 1, \PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_DELIM_CAPTURE);

        if (!isset($s[1])) {
            return false;
        }

        $i = 1;
        $ret = '';

        do {
            if (\GRAPHEME_EXTR_COUNT === $type) {
                --$size;
            } elseif (\GRAPHEME_EXTR_MAXBYTES === $type) {
                $size -= \strlen($s[$i]);
            } else {
                $size -= iconv_strlen($s[$i], 'UTF-8//IGNORE');
            }

            if ($size >= 0) {
                $ret .= $s[$i];
            }
        } while (isset($s[++$i]) && $size > 0);

        $next += \strlen($ret);

        return $ret;
    }

    public static function grapheme_strlen($s)
    {
        preg_replace('/'.SYMFONY_GRAPHEME_CLUSTER_RX.'/u', '', $s, -1, $len);

        return 0 === $len && '' !== $s ? null : $len;
    }

    public static function grapheme_substr($s, $start, $len = null)
    {
        if (null === $len) {
            $len = 2147483647;
        }

        preg_match_all('/'.SYMFONY_GRAPHEME_CLUSTER_RX.'/u', $s, $s);

        $slen = \count($s[0]);
        $start = (int) $start;

        if (0 > $start) {
            $start += $slen;
        }
        if (0 > $start) {
            if (\PHP_VERSION_ID < 80000) {
                return false;
            }

            $start = 0;
        }
        if ($start >= $slen) {
            return \PHP_VERSION_ID >= 80000 ? '' : false;
        }

        $rem = $slen - $start;

        if (0 > $len) {
            $len += $rem;
        }
        if (0 === $len) {
            return '';
        }
        if (0 > $len) {
            return \PHP_VERSION_ID >= 80000 ? '' : false;
        }
        if ($len > $rem) {
            $len = $rem;
        }

        return implode('', \array_slice($s[0], $start, $len));
    }

    public static function grapheme_strpos($s, $needle, $offset = 0)
    {
        return self::grapheme_position($s, $needle, $offset, 0);
    }

    public static function grapheme_stripos($s, $needle, $offset = 0)
    {
        return self::grapheme_position($s, $needle, $offset, 1);
    }

    public static function grapheme_strrpos($s, $needle, $offset = 0)
    {
        return self::grapheme_position($s, $needle, $offset, 2);
    }

    public static function grapheme_strripos($s, $needle, $offset = 0)
    {
        return self::grapheme_position($s, $needle, $offset, 3);
    }

    public static function grapheme_stristr($s, $needle, $beforeNeedle = false)
    {
        return mb_stristr($s, $needle, $beforeNeedle, 'UTF-8');
    }

    public static function grapheme_strstr($s, $needle, $beforeNeedle = false)
    {
        return mb_strstr($s, $needle, $beforeNeedle, 'UTF-8');
    }

    public static function grapheme_str_split($s, $len = 1)
    {
        if (0 > $len || 1073741823 < $len) {
            if (80000 > \PHP_VERSION_ID) {
                return false;
            }

            throw new \ValueError('grapheme_str_split(): Argument #2 ($length) must be greater than 0 and less than or equal to 1073741823.');
        }

        if ('' === $s) {
            return [];
        }

        if (!preg_match_all('/('.SYMFONY_GRAPHEME_CLUSTER_RX.')/u', $s, $matches)) {
            return false;
        }

        if (1 === $len) {
            return $matches[0];
        }

        $chunks = array_chunk($matches[0], $len);

        foreach ($chunks as &$chunk) {
            $chunk = implode('', $chunk);
        }

        return $chunks;
    }

    private static function grapheme_position($s, $needle, $offset, $mode)
    {
        $needle = (string) $needle;
        if (80000 > \PHP_VERSION_ID && !preg_match('/./us', $needle)) {
            return false;
        }
        $s = (string) $s;
        if (!preg_match('/./us', $s)) {
            return false;
        }
        if ($offset > 0) {
            $s = self::grapheme_substr($s, $offset);
        } elseif ($offset < 0) {
            if (2 > $mode) {
                $offset += self::grapheme_strlen($s);
                $s = self::grapheme_substr($s, $offset);
                if (0 > $offset) {
                    $offset = 0;
                }
            } elseif (0 > $offset += self::grapheme_strlen($needle)) {
                $s = self::grapheme_substr($s, 0, $offset);
                $offset = 0;
            } else {
                $offset = 0;
            }
        }

        // As UTF-8 is self-synchronizing, and we have ensured the strings are valid UTF-8,
        // we can use normal binary string functions here. For case-insensitive searches,
        // case fold the strings first.
        $caseInsensitive = $mode & 1;
        $reverse = $mode & 2;
        if ($caseInsensitive) {
            // Use the same case folding mode as mbstring does for mb_stripos().
            // Stick to SIMPLE case folding to avoid changing the length of the string, which
            // might result in offsets being shifted.
            $mode = \defined('MB_CASE_FOLD_SIMPLE') ? \MB_CASE_FOLD_SIMPLE : \MB_CASE_LOWER;
            $s = mb_convert_case($s, $mode, 'UTF-8');
            $needle = mb_convert_case($needle, $mode, 'UTF-8');

            if (!\defined('MB_CASE_FOLD_SIMPLE')) {
                $s = str_replace(self::CASE_FOLD[0], self::CASE_FOLD[1], $s);
                $needle = str_replace(self::CASE_FOLD[0], self::CASE_FOLD[1], $needle);
            }
        }
        if ($reverse) {
            $needlePos = strrpos($s, $needle);
        } else {
            $needlePos = strpos($s, $needle);
        }

        return false !== $needlePos ? self::grapheme_strlen(substr($s, 0, $needlePos)) + $offset : false;
    }
}


