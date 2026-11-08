<?php

declare(strict_types=1);

/**
 * Escape a value for HTML text and attribute contexts.
 *
 * Accepts mixed so templates can call it on any value without a cast; anything
 * that is not a string, number, boolean or Stringable renders as an empty
 * string rather than "Array" or a fatal error.
 */
function e(mixed $value): string
{
    $string = match (true) {
        is_string($value) => $value,
        is_int($value), is_float($value) => (string) $value,
        $value instanceof \Stringable => (string) $value,
        is_bool($value) => $value ? '1' : '',
        default => '',
    };

    return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
