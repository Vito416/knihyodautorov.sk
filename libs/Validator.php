// File: libs/Validator.php
<?php
declare(strict_types=1);

/**
 * Validator - centralizované statické metódy pro validaci vstupů.
 * Dodržuje PSR-12 style guide a používá bezpečné PHP filtry + regex.
 */
class Validator
{
    public static function validateEmail(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function validateDate(string $date, string $format = 'Y-m-d'): bool
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    public static function validateDateTime(string $dateTime, string $format = 'Y-m-d H:i:s'): bool
    {
        $d = DateTime::createFromFormat($format, $dateTime);
        return $d && $d->format($format) === $dateTime;
    }

    public static function validateNumberInRange($value, float $min, float $max): bool
    {
        if (!is_numeric($value)) return false;
        $f = (float) $value;
        return $f >= $min && $f <= $max;
    }

    public static function validateCurrencyCode(string $code): bool
    {
        return (bool) preg_match('/^[A-Z]{3}$/', $code);
    }

    public static function validateJson(string $json): bool
    {
        if ($json === '') return false;
        json_decode($json);
        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function validatePasswordStrength(string $pw, int $minLength = 8): bool
    {
        if (mb_strlen($pw) < $minLength) return false;
        // at least one letter and one number
        if (!preg_match('/[a-zA-Z]/', $pw)) return false;
        if (!preg_match('/[0-9]/', $pw)) return false;
        return true;
    }

    public static function sanitizeString(string $s, int $maxLen = 0): string
    {
        $out = trim($s);
        if ($maxLen > 0) $out = mb_substr($out, 0, $maxLen);
        return $out;
    }

    public static function validateFileSize(int $sizeBytes, int $maxBytes): bool
    {
        return $sizeBytes <= $maxBytes && $sizeBytes > 0;
    }

    public static function validateMimeType(string $mime, array $allowed): bool
    {
        return in_array($mime, $allowed, true);
    }
}