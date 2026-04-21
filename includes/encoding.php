<?php

if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null)
    {
        return strlen($string);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null)
    {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($string, $encoding = null)
    {
        return strtoupper($string);
    }
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null)
    {
        return strtolower($string);
    }
}

if (!function_exists('emsp_mojibake_score')) {
    function emsp_mojibake_score(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        if (preg_match('//u', $text) !== 1) {
            return 100;
        }

        $score = 0;

        if (strpos($text, 'Ã¯Â¿Â½') !== false) {
            $score += 10 * substr_count($text, 'Ã¯Â¿Â½');
        }
        if (strpos($text, 'ÃƒÂ¯Ã‚Â¿Ã‚Â½') !== false) {
            $score += 10 * substr_count($text, 'ÃƒÂ¯Ã‚Â¿Ã‚Â½');
        }

        foreach (['ÃƒÆ’', 'Ãƒâ€š', 'Ãƒ', 'Ã‚'] as $prefix) {
            $pattern = '/' . preg_quote($prefix, '/') . '[\x{0080}-\x{00BF}]/u';
            if (preg_match_all($pattern, $text, $matches)) {
                $score += count($matches[0]);
            }
        }

        foreach (['ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢', 'ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œ', 'ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â', 'ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“', 'ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â', 'ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦', 'ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢', 'ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢'] as $needle) {
            if (strpos($text, $needle) !== false) {
                $score += 2 * substr_count($text, $needle);
            }
        }

        if (strpos($text, 'ÃƒÆ’') !== false) {
            $score += substr_count($text, 'ÃƒÆ’');
        }
        if (strpos($text, 'Ãƒâ€š') !== false) {
            $score += substr_count($text, 'Ãƒâ€š');
        }

        if (preg_match_all('/(?:\x{00C3}[\x{0080}-\x{00FF}]|\x{00C2}[\x{00A0}-\x{00FF}]|\x{00E2}[\x{0080}-\x{00BF}]|\x{FFFD})/u', $text, $markers)) {
            $score += 2 * count($markers[0]);
        }

        return $score;
    }
}

if (!function_exists('emsp_fix_mojibake')) {
    /**
     * Shared encoding repair helper used across front/admin rendering.
     * Keep it outside dbcon.php so layout helpers and tests can rely on one source of truth.
     */
    function emsp_fix_mojibake(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $text = str_replace("\xEF\xBB\xBF", '', $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;

        $best = $text;
        $bestScore = emsp_mojibake_score($best);
        if ($bestScore === 0) {
            if (preg_match('/(?:\x{00C3}[\x{0080}-\x{00FF}]|\x{00C2}[\x{00A0}-\x{00FF}]|\x{00E2}[\x{0080}-\x{00BF}]|\x{FFFD})/u', $best) === 1) {
                $bestScore = 1;
            } else {
                return $best;
            }
        }

        for ($pass = 0; $pass < 3 && $bestScore > 0; $pass++) {
            $isValidUtf8 = (preg_match('//u', $best) === 1);
            $candidates = [];

            if ($isValidUtf8) {
                if (function_exists('mb_convert_encoding')) {
                    $c1 = @mb_convert_encoding($best, 'Windows-1252', 'UTF-8');
                    if ($c1 !== false && $c1 !== '') {
                        $candidates[] = $c1;
                    }
                    $c2 = @mb_convert_encoding($best, 'ISO-8859-1', 'UTF-8');
                    if ($c2 !== false && $c2 !== '') {
                        $candidates[] = $c2;
                    }
                } elseif (function_exists('iconv')) {
                    $c1 = @iconv('UTF-8', 'Windows-1252//IGNORE', $best);
                    if ($c1 !== false && $c1 !== '') {
                        $candidates[] = $c1;
                    }
                    $c2 = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $best);
                    if ($c2 !== false && $c2 !== '') {
                        $candidates[] = $c2;
                    }
                } else {
                    break;
                }
            } else {
                if (function_exists('mb_convert_encoding')) {
                    $c1 = @mb_convert_encoding($best, 'UTF-8', 'Windows-1252');
                    if ($c1 !== false && $c1 !== '') {
                        $candidates[] = $c1;
                    }
                    $c2 = @mb_convert_encoding($best, 'UTF-8', 'ISO-8859-1');
                    if ($c2 !== false && $c2 !== '') {
                        $candidates[] = $c2;
                    }
                } elseif (function_exists('iconv')) {
                    $c1 = @iconv('Windows-1252', 'UTF-8//IGNORE', $best);
                    if ($c1 !== false && $c1 !== '') {
                        $candidates[] = $c1;
                    }
                    $c2 = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $best);
                    if ($c2 !== false && $c2 !== '') {
                        $candidates[] = $c2;
                    }
                } else {
                    break;
                }
            }

            $improved = false;
            foreach ($candidates as $candidate) {
                if ($candidate === $best || preg_match('//u', $candidate) !== 1) {
                    continue;
                }

                $score = emsp_mojibake_score($candidate);
                if ($score < $bestScore) {
                    $best = $candidate;
                    $bestScore = $score;
                    $improved = true;
                    if ($bestScore === 0) {
                        break;
                    }
                }
            }

            if (!$improved) {
                break;
            }
        }

        return $best;
    }
}


