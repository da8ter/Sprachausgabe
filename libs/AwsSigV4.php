<?php

declare(strict_types=1);

/**
 * AWS Signature Version 4 — die Unterschrift, ohne die Amazon jede Anfrage
 * ablehnt.
 *
 * Bewusst eine EIGENE, reine Klasse: kein Symcon, kein Netz, kein Zustand. Nur so
 * laesst sich die Rechnung gegen die offiziellen Pruefwerte von AWS belegen, ohne
 * dass jemand ein Konto haben muss. Ein falsches Zeichen in der kanonischen Form
 * ergibt eine Unterschrift, die sich von einer richtigen nicht unterscheiden
 * laesst — man sieht nur ein „403 SignatureDoesNotMatch" und raet.
 *
 * Bezug: AWS, „Signature Version 4 signing process", und die Pruefwerte aus
 * aws-sig-v4-test-suite (get-vanilla).
 */
class AwsSigV4
{
    /** Der feste Bezeichner des Verfahrens. Steht in Kopfzeile UND Signatur. */
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * Die Kopfzeilen einer Anfrage, fertig unterschrieben.
     *
     * @param string               $method   GET oder POST
     * @param string               $host     z. B. polly.eu-central-1.amazonaws.com
     * @param string               $pfad     z. B. /v1/speech (ohne Abfrage)
     * @param string               $abfrage  kanonische Abfragezeichenfolge, sonst ''
     * @param string               $rumpf    der Rumpf, bei GET ''
     * @param array<string,string> $kopf     zusaetzliche Kopfzeilen (etwa Content-Type)
     * @param string               $zeit     Zeitstempel „Ymd\THis\Z"; leer = jetzt
     *
     * @return array<string,string> Kopfzeilen, die MIT gesendet werden muessen
     */
    public static function Headers(string $method, string $host, string $pfad, string $abfrage,
                                   string $rumpf, array $kopf, string $region, string $dienst,
                                   string $schluessel, string $geheim, string $zeit = ''): array
    {
        if ($zeit === '') {
            $zeit = gmdate('Ymd\THis\Z');
        }
        $tag = substr($zeit, 0, 8);

        // Host und x-amz-date MUESSEN mit unterschrieben werden; der Hash des
        // Rumpfes gehoert bei Polly ebenfalls dazu.
        $alle = $kopf;
        $alle['host']         = $host;
        $alle['x-amz-date']   = $zeit;
        $rumpfHash            = hash('sha256', $rumpf);

        // Kanonische Kopfzeilen: Namen klein, nach Namen sortiert, Werte gestutzt.
        $klein = [];
        foreach ($alle as $name => $wert) {
            $klein[strtolower(trim($name))] = self::Falten((string)$wert);
        }
        ksort($klein);
        $kopfZeilen = '';
        foreach ($klein as $name => $wert) {
            $kopfZeilen .= $name . ':' . $wert . "\n";
        }
        $unterschriebene = implode(';', array_keys($klein));

        $kanonisch = $method . "\n"
            . self::PfadKanonisch($pfad) . "\n"
            . $abfrage . "\n"
            . $kopfZeilen . "\n"
            . $unterschriebene . "\n"
            . $rumpfHash;

        $bereich = $tag . '/' . $region . '/' . $dienst . '/aws4_request';
        $zuSignieren = self::ALGORITHM . "\n" . $zeit . "\n" . $bereich . "\n"
            . hash('sha256', $kanonisch);

        $unterschrift = hash_hmac('sha256', $zuSignieren, self::SigningKey($geheim, $tag, $region, $dienst));

        $fertig = $kopf;
        $fertig['X-Amz-Date']    = $zeit;
        $fertig['Authorization'] = self::ALGORITHM
            . ' Credential=' . $schluessel . '/' . $bereich
            . ', SignedHeaders=' . $unterschriebene
            . ', Signature=' . $unterschrift;
        return $fertig;
    }

    /**
     * Der abgeleitete Schluessel: viermal HMAC, jedes Mal mit dem Ergebnis des
     * vorigen Schrittes als Schluessel. Roh (binary), nicht hexadezimal — der
     * haeufigste Fehler an dieser Stelle.
     */
    public static function SigningKey(string $geheim, string $tag, string $region, string $dienst): string
    {
        $k = hash_hmac('sha256', $tag,           'AWS4' . $geheim, true);
        $k = hash_hmac('sha256', $region,        $k, true);
        $k = hash_hmac('sha256', $dienst,        $k, true);
        return hash_hmac('sha256', 'aws4_request', $k, true);
    }

    /**
     * Mehrfache Leerzeichen in einem Kopfzeilenwert zaehlen als eines, Rand weg.
     * Steht so in der Vorschrift; ohne das stimmt die Unterschrift bei Werten mit
     * doppeltem Leerzeichen nicht.
     */
    private static function Falten(string $wert): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $wert));
    }

    /**
     * Der Pfad, wie AWS ihn erwartet: jedes Segment einzeln kodiert, der Schraegstrich
     * bleibt. Ein leerer Pfad ist „/".
     *
     * Polly kommt mit schlichten Pfaden aus; die Regel steht hier trotzdem, weil
     * eine spaetere Anfrage mit einem Namen im Pfad sonst still falsch unterschriebe.
     */
    private static function PfadKanonisch(string $pfad): string
    {
        if ($pfad === '' || $pfad === '/') {
            return '/';
        }
        $teile = explode('/', $pfad);
        foreach ($teile as $i => $t) {
            // rawurlencode kodiert auch „~", das AWS unkodiert erwartet.
            $teile[$i] = str_replace('%7E', '~', rawurlencode($t));
        }
        return implode('/', $teile);
    }
}
