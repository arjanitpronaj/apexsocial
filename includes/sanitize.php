<?php
/**
 * Input validation (store raw UTF-8) + safe output escaping.
 */

function apexHasXssPayload(string $text): bool
{
    $t = $text;
    if (preg_match('/<script\b|javascript\s*:|on\w+\s*=\s*["\']|<iframe|<object|<embed/i', $t)) {
        return true;
    }
    if (preg_match('/<svg\b[^>]*\bon\w+\s*=/i', $t)) {
        return true;
    }
    if (preg_match('/<img\b[^>]*\bon\w+\s*=/i', $t)) {
        return true;
    }
    if (preg_match('/expression\s*\(/i', $t)) {
        return true;
    }
    if (preg_match('/data\s*:\s*text\/html/i', $t)) {
        return true;
    }
    return false;
}

function apexValidateUserText(string $text, int $maxLen = 8000): ?string
{
    $text = strip_control_chars_php($text);
    if ($text === '') {
        return 'Text cannot be empty.';
    }
    if (mb_strlen($text) > $maxLen) {
        return 'Text is too long.';
    }
    if (apexHasXssPayload($text)) {
        return 'Invalid content: scripts or unsafe HTML are not allowed.';
    }
    return null;
}

function strip_control_chars_php(string $text): string
{
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
}

/** Escape for HTML output only — never before DB insert. */
function apex_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
