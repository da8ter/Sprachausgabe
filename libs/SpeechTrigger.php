<?php

declare(strict_types=1);

/**
 * Auslöser einer Ansage oder Push-Nachricht, ausgewertet auf einer VM_UPDATE-Meldung ($Data: neuer
 * Wert, geändert ja/nein, alter Wert).
 *
 * Seit 10/2026 ist der Auslöser eine Regel aus dem Bedingungs-Dialog der Konsole (SelectCondition,
 * eine Variablenregel: Variable, Vergleich, Wert) plus eine Auslöse-Art (MODE_*). "Wenn die Regel
 * erfüllt wird" feuert nur beim Übergang, wie Symcons "Bei bestimmtem Wert" ohne nachfolgende
 * Ausführung. Die alten Regeln (ON_UPDATE … BELOW, Wert als Text) bleiben für die Übernahme
 * bestehender Instanzen erhalten: legacyToCondition() übersetzt sie.
 */
final class SpeechTrigger
{
    public const ON_UPDATE = 0;
    public const ON_CHANGE = 1;
    public const EQUALS = 2;
    public const NOT_EQUALS = 3;
    public const ABOVE = 4;
    public const BELOW = 5;

    /** Auslöse-Arten zur Regel aus dem Bedingungs-Dialog. */
    public const MODE_BECOMES = 0;      // wenn die Regel erfüllt wird (Übergang)
    public const MODE_WHILE = 1;        // bei jeder Aktualisierung, solange die Regel erfüllt ist
    public const MODE_ANY_UPDATE = 2;   // bei jeder Aktualisierung der Variable (Regel egal)
    public const MODE_ANY_CHANGE = 3;   // bei jeder Änderung der Variable (Regel egal)

    /** Vergleiche des Bedingungs-Dialogs (EVENTCONDITIONCOMPARISON_*). */
    private const CMP_EQUAL = 0;
    private const CMP_NOTEQUAL = 1;
    private const CMP_GREATER = 2;
    private const CMP_GREATEROREQUAL = 3;
    private const CMP_SMALLER = 4;
    private const CMP_SMALLEROREQUAL = 5;

    /**
     * Die erste Variablenregel aus dem Wert eines SelectCondition (Liste von Bedingungen oder eine).
     *
     * @return array{variableID: int, comparison: int, value: mixed, type: int}|null
     */
    public static function rule(string $condition): ?array
    {
        $data = json_decode($condition, true);
        if (!is_array($data)) {
            return null;
        }
        $conditions = array_is_list($data) ? $data : [$data];
        foreach ($conditions as $c) {
            $r = $c['rules']['variable'][0] ?? null;
            if (is_array($r) && (int)($r['variableID'] ?? 0) > 0) {
                return ['variableID' => (int)$r['variableID'], 'comparison' => (int)($r['comparison'] ?? 0), 'value' => $r['value'] ?? null, 'type' => (int)($r['type'] ?? 0)];
            }
        }
        return null;
    }

    /** Erfüllt $value die Regel? Bei type 1 ist der Vergleichswert der Wert einer anderen Variable. */
    public static function passes(array $rule, mixed $value): bool
    {
        $want = $rule['value'];
        if ((int)$rule['type'] === 1) {
            $want = @IPS_VariableExists((int)$want) ? GetValue((int)$want) : null;
        }
        switch ((int)$rule['comparison']) {
            case self::CMP_EQUAL:
                return self::same($value, $want);
            case self::CMP_NOTEQUAL:
                return !self::same($value, $want);
        }
        if (!is_numeric($value) && !is_bool($value) || !is_numeric($want) && !is_bool($want)) {
            return false;
        }
        $a = (float)$value;
        $b = (float)$want;
        return match ((int)$rule['comparison']) {
            self::CMP_GREATER        => $a > $b,
            self::CMP_GREATEROREQUAL => $a >= $b,
            self::CMP_SMALLER        => $a < $b,
            self::CMP_SMALLEROREQUAL => $a <= $b,
            default                  => false,
        };
    }

    /** Feuert die Regel mit dieser Auslöse-Art auf eine VM_UPDATE-Meldung? */
    public static function firesRule(int $mode, array $rule, mixed $new, bool $changed, mixed $old): bool
    {
        switch ($mode) {
            case self::MODE_ANY_UPDATE:
                return true;
            case self::MODE_ANY_CHANGE:
                return $changed;
            case self::MODE_WHILE:
                return self::passes($rule, $new);
        }
        // MODE_BECOMES: only on the transition into the rule
        return $changed && self::passes($rule, $new) && ($old === null || !self::passes($rule, $old));
    }

    /** @return array<int, string> Beschriftungen der Auslöse-Arten (übersetzt über locale.json) */
    public static function modeCaptions(): array
    {
        return [
            self::MODE_BECOMES    => 'when the rule becomes true',
            self::MODE_WHILE      => 'on every update while the rule is true',
            self::MODE_ANY_UPDATE => 'on every update of the variable (rule ignored)',
            self::MODE_ANY_CHANGE => 'on every change of the variable (rule ignored)',
        ];
    }

    /** Regelarten mit Zustand: nur sie kennen Verzögerung und Wiederholung. */
    public static function isStateMode(int $mode): bool
    {
        return $mode === self::MODE_BECOMES || $mode === self::MODE_WHILE;
    }

    /** Eine Variablenregel im Format von SelectCondition. */
    public static function ruleJson(int $variableID, int $comparison, mixed $value): string
    {
        return (string)json_encode([['id' => 0, 'parentID' => 0, 'operation' => 0, 'rules' => [
            'variable' => [['id' => 0, 'variableID' => $variableID, 'comparison' => $comparison, 'value' => $value, 'type' => 0]],
            'date' => [], 'time' => [], 'dayOfTheWeek' => [],
        ]]], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Übersetzt den alten Auslöser (Variable, Regel, Wert als Text, auch bei Wiederholung).
     *
     * @return array{condition: string, mode: int}
     */
    public static function legacyToCondition(int $variableID, int $rule, string $value, bool $repeat, int $variableType): array
    {
        $current = @IPS_VariableExists($variableID) ? GetValue($variableID) : self::coerce($value, $variableType);
        $numeric = static fn(): mixed => $variableType === VARIABLETYPE_INTEGER ? (int)$value : (float)$value;
        [$comparison, $want, $mode] = match ($rule) {
            self::ON_UPDATE  => [self::CMP_EQUAL, $current, self::MODE_ANY_UPDATE],
            self::ON_CHANGE  => [self::CMP_EQUAL, $current, self::MODE_ANY_CHANGE],
            self::NOT_EQUALS => [self::CMP_NOTEQUAL, self::coerce($value, $variableType), $repeat ? self::MODE_WHILE : self::MODE_BECOMES],
            self::ABOVE      => [self::CMP_GREATER, $numeric(), $repeat ? self::MODE_WHILE : self::MODE_BECOMES],
            self::BELOW      => [self::CMP_SMALLER, $numeric(), $repeat ? self::MODE_WHILE : self::MODE_BECOMES],
            default          => [self::CMP_EQUAL, self::coerce($value, $variableType), $repeat ? self::MODE_WHILE : self::MODE_BECOMES],
        };
        return ['condition' => self::ruleJson($variableID, $comparison, $want), 'mode' => $mode];
    }

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
