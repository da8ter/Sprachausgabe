<?php

declare(strict_types=1);

/**
 * Wochenpläne der Sprachausgabe: welche Aktion eines Symcon-Wochenplans gerade gilt. Symcons
 * LastActionID ist bei einem frisch angelegten Plan 0 (gemessen 08.10.2026, Symcon 9.1), deshalb
 * wird der Zustand aus den Schaltpunkten berechnet: der letzte Schaltpunkt vor $time in der Gruppe
 * des Tages, sonst der letzte des Vortags (bis zu sieben Tage zurück).
 */
final class SpeechSchedule
{
    public const SPEAK = 1;
    public const QUIET = 2;

    /**
     * @param array<int, array<string, mixed>> $groups ScheduleGroups aus IPS_GetEvent
     * @return int ID der geltenden Aktion, 0 wenn der Plan keine Schaltpunkte hat
     */
    public static function actionAt(array $groups, int $time): int
    {
        for ($back = 0; $back <= 7; $back++) {
            $day = $time - $back * 86400;
            $bit = 1 << ((int)date('N', $day) - 1); // 1 = Monday … 64 = Sunday
            $limit = $back === 0 ? (int)date('G', $day) * 3600 + (int)date('i', $day) * 60 + (int)date('s', $day) : 86400;
            $best = null;
            foreach ($groups as $g) {
                if (((int)($g['Days'] ?? 0) & $bit) === 0) {
                    continue;
                }
                foreach ((array)($g['Points'] ?? []) as $p) {
                    $s = $p['Start'] ?? [];
                    $at = (int)($s['Hour'] ?? 0) * 3600 + (int)($s['Minute'] ?? 0) * 60 + (int)($s['Second'] ?? 0);
                    if ($at <= $limit && ($best === null || $at >= $best[0])) {
                        $best = [$at, (int)($p['ActionID'] ?? 0)];
                    }
                }
            }
            if ($best !== null) {
                return $best[1];
            }
        }
        return 0;
    }

    /** Darf nach dem Plan gesprochen werden? Ein Plan ohne Schaltpunkte sperrt nichts. */
    public static function allows(array $groups, int $time): bool
    {
        $action = self::actionAt($groups, $time);
        return $action !== self::QUIET;
    }
}
