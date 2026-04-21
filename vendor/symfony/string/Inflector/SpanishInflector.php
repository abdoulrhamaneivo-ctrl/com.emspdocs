<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\String\Inflector;

final class SpanishInflector implements InflectorInterface
{
    /**
     * A list of all rules for pluralise.
     *
     * @see https://www.spanishdict.com/guide/spanish-plural-noun-forms
     * @see https://www.rae.es/gram%C3%A1tica/morfolog%C3%ADa/la-formaci%C3%B3n-del-plural-plurales-en-s-y-plurales-en-es-reglas-generales
     */
    // First entry: regex
    // Second entry: replacement
    private const PLURALIZE_REGEXP = [
        // Specials sÃ­, no
        ['/(sÃ­|no)$/i', '\1es'],

        // Words ending with vowel must use -s (RAE 3.2a, 3.2c)
        ['/(a|e|i|o|u|Ã¡|Ã©|Ã­|Ã³|Ãº)$/i', '\1s'],

        // Word ending in s or x and the previous letter is accented (RAE 3.2n)
        ['/Ã¡s$/i', 'ases'],
        ['/Ã©s$/i', 'eses'],
        ['/Ã­s$/i', 'ises'],
        ['/Ã³s$/i', 'oses'],
        ['/Ãºs$/i', 'uses'],

        // Words ending in -iÃ³n must changed to -iones
        ['/iÃ³n$/i', '\1iones'],

        // Words ending in some consonants must use -es (RAE 3.2k)
        ['/(l|r|n|d|j|s|x|ch|y)$/i', '\1es'],

        // Word ending in z, must changed to ces
        ['/(z)$/i', 'ces'],
    ];

    /**
     * A list of all rules for singularize.
     */
    private const SINGULARIZE_REGEXP = [
        // Specials sÃ­, no
        ['/(sÃ­|no)es$/i', '\1'],

        // Words ending in -iÃ³n must changed to -iones
        ['/iones$/i', '\1iÃ³n'],

        // Word ending in z, must changed to ces
        ['/ces$/i', 'z'],

        // Word ending in s or x and the previous letter is accented (RAE 3.2n)
        ['/(\w)ases$/i', '\1Ã¡s'],
        ['/eses$/i', 'Ã©s'],
        ['/ises$/i', 'Ã­s'],
        ['/(\w{2,})oses$/i', '\1Ã³s'],
        ['/(\w)uses$/i', '\1Ãºs'],

        // Words ending in some consonants and -es, must be the consonants
        ['/(l|r|n|d|j|s|x|ch|y)e?s$/i', '\1'],

        // Words ended with vowel and s, must be vowel
        ['/(a|e|i|o|u|Ã¡|Ã©|Ã³|Ã­|Ãº)s$/i', '\1'],
    ];

    private const UNINFLECTED_RULES = [
        // Words ending with pies (RAE 3.2n)
        '/.*(piÃ©s)$/i',
    ];

    private const UNINFLECTED = '/^(lunes|martes|miÃ©rcoles|jueves|viernes|anÃ¡lisis|torax|yo|pies)$/i';

    public function singularize(string $plural): array
    {
        if ($this->isInflectedWord($plural)) {
            return [$plural];
        }

        foreach (self::SINGULARIZE_REGEXP as $rule) {
            [$regexp, $replace] = $rule;

            if (1 === preg_match($regexp, $plural)) {
                return [preg_replace($regexp, $replace, $plural)];
            }
        }

        return [$plural];
    }

    public function pluralize(string $singular): array
    {
        if ($this->isInflectedWord($singular)) {
            return [$singular];
        }

        foreach (self::PLURALIZE_REGEXP as $rule) {
            [$regexp, $replace] = $rule;

            if (1 === preg_match($regexp, $singular)) {
                return [preg_replace($regexp, $replace, $singular)];
            }
        }

        return [$singular.'s'];
    }

    private function isInflectedWord(string $word): bool
    {
        foreach (self::UNINFLECTED_RULES as $rule) {
            if (1 === preg_match($rule, $word)) {
                return true;
            }
        }

        return 1 === preg_match(self::UNINFLECTED, $word);
    }
}


