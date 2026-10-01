<?php

declare(strict_types=1);

/**
 * Auslöse-Regeln einer Ansage, ausgewertet auf einer VM_UPDATE-Meldung ($Data: neuer Wert,
 * geändert ja/nein, alter Wert). Ohne "auch bei Wiederholung" feuern die Wert-Regeln nur beim
 * Übergang, wie Symcons "Bei bestimmtem Wert" ohne nachfolgende Ausführung.
 */
final class SpeechTrigger
{
    public const ON_UPDATE = 0;
    public const ON_CHANGE = 1;
    public const EQUALS = 2;
    public const NOT_EQUALS = 3;
    public const ABOVE = 4;
    public const BELOW = 5;

    public static function fires(int $rule, string $configured, int $variableType, mixed $new, bool $changed, mixed $old, bool $repeat): bool
    {
        switch ($rule) {
            case self::ON_UPDATE:
                return true;
            case self::ON_CHANGE:
                return $changed;
            case self::EQUALS:
            case self::NOT_EQUALS:
                $want = self::coerce($configured, $variableType);
                $hit = self::same($new, $want);
                if ($rule === self::NOT_EQUALS) {
                    $hit = !$hit;
                }
                return $hit && ($changed || $repeat);
            case self::ABOVE:
            case self::BELOW:
                if (!is_numeric($configured) || !is_numeric($new)) {
                    return false;
                }
                $limit = (float)$configured;
                $above = $rule === self::ABOVE;
                $hit = $above ? (float)$new > $limit : (float)$new < $limit;
                if (!$hit) {
                    return false;
                }
                if ($repeat || !is_numeric($old)) {
                    return true;
                }
                return $above ? (float)$old <= $limit : (float)$old >= $limit; // nur beim Überschreiten
        }
        return false;
    }

    /** Der im Formular eingegebene Vergleichswert im Typ der Variable. */
    public static function coerce(string $configured, int $variableType): mixed
    {
        $v = trim($configured);
        switch ($variableType) {
            case VARIABLETYPE_BOOLEAN:
                return in_array(mb_strtolower($v), ['1', 'true', 'an', 'ein', 'on', 'ja', 'yes'], true);
            case VARIABLETYPE_INTEGER:
                return is_numeric($v) ? (int)$v : $v;
            case VARIABLETYPE_FLOAT:
                return is_numeric($v) ? (float)$v : $v;
        }
        return $configured;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_float($a) || is_float($b)) {
            return is_numeric($a) && is_numeric($b) && abs((float)$a - (float)$b) < 1e-9;
        }
        if (is_string($a) || is_string($b)) {
            return (string)$a === (string)$b;
        }
        return $a === $b;
    }
}
