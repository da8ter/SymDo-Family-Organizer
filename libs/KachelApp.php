<?php

declare(strict_types=1);

/**
 * Gemeinsames App-Skript der SymDo-Kacheln.
 *
 * Seit der Kachel-Modus ein Schalter ist (KACHEL in SymDoWebApp/module.html), ist der Skripttext aller
 * SymDo-Kacheln gleich. Statt ihn inline in jedes Kacheldokument zu schreiben (rund 790 kB je Öffnen),
 * verweisen die Kacheln auf PFAD, den das Gateway versioniert und lange cachebar ausliefert. Chrome
 * kompiliert das Skript dann einmal und teilt es zwischen den Kacheln (gemessen: je Kachel rund
 * rund 7 MB weniger Speicher-Fußabdruck — ps-RSS zeigte 15-20 MB, überzeichnet aber — und zwei
 * Drittel weniger Skriptzeit, sobald es im Cache liegt).
 *
 * Ausgelagert wird nur, wenn das Skript der Kachel Zeichen für Zeichen dem des Gateways gleicht; sonst
 * (kein Gateway, Übernahme noch nicht gelaufen) bleibt es inline wie bisher.
 */
final class KachelApp
{
    /** Beginn des App-Skripts in SymDoWebApp/module.html und den Kacheln (erste Zeile im script-Element). */
    public const ANKER = "<script>\n// In einer IIFE gekapselt";
    /** Unterpfad des Web-App-Hooks am Gateway (ohne Token: derselbe Text steht in der tokenlosen Web-App-Seite). */
    public const PFAD = '/hook/lists/webapp/app.js';
    public const GATEWAY_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';

    /** Das App-Skript ohne script-Tags, '' wenn es nicht genau einmal vorkommt. */
    public static function Skript(string $html): string
    {
        $start = strpos($html, self::ANKER);
        if ($start === false || substr_count($html, self::ANKER) !== 1) {
            return '';
        }
        $inhalt = $start + strlen('<script>');
        $ende = strpos($html, '</script>', $inhalt);
        return $ende === false ? '' : substr($html, $inhalt, $ende - $inhalt);
    }

    /** Version für die Adresse und den ETag: neuer Skripttext ergibt eine neue Adresse. */
    public static function Version(string $skript): string
    {
        return substr(md5($skript), 0, 16);
    }

    /** Das Kacheldokument mit Verweis statt Inline-Skript, wenn beide Skripte gleich sind; sonst unverändert. */
    public static function Auslagern(string $html, string $gatewaySkript): string
    {
        $eigen = self::Skript($html);
        if ($eigen === '' || $gatewaySkript === '' || $eigen !== $gatewaySkript) {
            return $html;
        }
        $start = strpos($html, self::ANKER);
        $laenge = strlen('<script>') + strlen($eigen) + strlen('</script>');
        return substr_replace($html, '<script src="' . self::PFAD . '?v=' . self::Version($eigen) . '"></script>', (int) $start, $laenge);
    }

    /**
     * Das App-Skript, wie das Gateway es unter PFAD ausliefert - nur wenn es eine Gateway-Instanz gibt,
     * denn ihr Hook lebt nur mit ihr. $listenOrdner ist der Ordner der Bibliothek (List/).
     */
    public static function GatewaySkript(string $listenOrdner): string
    {
        if (!function_exists('IPS_GetInstanceListByModuleID') || IPS_GetInstanceListByModuleID(self::GATEWAY_GUID) === []) {
            return '';
        }
        $html = @file_get_contents($listenOrdner . '/SymDoWebApp/module.html');
        return is_string($html) ? self::Skript($html) : '';
    }

    /* ── Versionierte Dateien aus einem Hook ─────────────────────────────────────────────────────────
       Dasselbe Muster wie app.js am Gateway (AppCore::ServeWebAppScript), IPS-frei und ohne header():
       Datei() liefert Status, Kopfzeilen und Rumpf, der Hook gibt sie nur noch aus. So lässt sich die
       Antwort ohne Webserver prüfen - im CLI hinterlässt header() nichts. */

    /** Lange cachebar nur unter der aktuellen Version; eine alte Adresse bekommt den aktuellen Inhalt ungecacht. */
    public const EWIG = 'max-age=31536000, immutable';
    /** gzip-Stufe: die Dateien werden selten geholt (ein Jahr im Cache), also lieber klein als schnell. */
    private const GZIP_STUFE = 6;

    /**
     * Darf gepackt werden? Nur wenn der Client gzip ANBIETET (Symcon packt Hook-Antworten nicht selbst);
     * `gzip;q=0` heißt ausdrücklich nein. Dieselbe Regel wie AppCore::GzipErlaubt.
     */
    public static function GzipErlaubt(string $acceptEncoding): bool
    {
        if (!function_exists('gzencode')) {
            return false;
        }
        $angebot = strtolower(trim($acceptEncoding));
        if ($angebot === '' || !str_contains($angebot, 'gzip')) {
            return false;
        }
        return preg_match('/gzip\s*;\s*q\s*=\s*0(?:\.0+)?(?![.\d])/', $angebot) !== 1;
    }

    /** Passt einer der mitgeschickten ETags? Proxys schwächen auf `W/"…"` ab, ein Client darf mehrere schicken. */
    public static function EtagTrifft(string $etag, string $ifNoneMatch): bool
    {
        foreach (explode(',', $ifNoneMatch) as $kandidat) {
            $kandidat = trim($kandidat);
            if (str_starts_with($kandidat, 'W/')) {
                $kandidat = trim(substr($kandidat, 2));
            }
            if ($kandidat !== '' && ($kandidat === $etag || $kandidat === '*')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Größte Ausgabe, die Symcon unverändert ausliefert: ScriptOutputBufferLimit (ab Werk 1 MiB), abzüglich
     * Luft für die Kopfzeilen. Darüber ERSETZT Symcon die Antwort still durch einen Fehlertext, bei HTTP 200 -
     * jeder Riegel muss deshalb VOR der Ausgabe greifen. Abgelesen, damit ein höherer Wert auch wirkt.
     */
    public static function Ausgabegrenze(): int
    {
        $grenze = 1048576;
        try {
            $o = function_exists('IPS_GetOption') ? (int) @IPS_GetOption('ScriptOutputBufferLimit') : 0;
            if ($o > 0) {
                $grenze = $o;
            }
        } catch (\Throwable $e) {
            // Ältere Fassung ohne die Option: bei der Vorgabe bleiben.
        }
        return max(200000, $grenze - 100000);
    }

    /**
     * Antwort für eine versionierte Datei: `private` für Inhalte hinter einem Token, sonst `public`.
     * ETag je Auslieferung (die gepackte Fassung trägt `-gz`), deshalb auch immer `Vary: Accept-Encoding`.
     * Passt der Rumpf nicht unter $grenze, kommt 503 statt einer still ersetzten Antwort.
     *
     * @return array{status: int, kopf: list<string>, rumpf: string}
     */
    public static function Datei(string $rumpf, string $typ, string $version, bool $aktuell, bool $privat,
        string $acceptEncoding, string $ifNoneMatch, int $grenze): array
    {
        $gz = self::GzipErlaubt($acceptEncoding) ? @gzencode($rumpf, self::GZIP_STUFE) : false;
        $gepackt = is_string($gz) && $gz !== '' && strlen($gz) < strlen($rumpf);
        $etag = '"' . $version . ($gepackt ? '-gz' : '') . '"';
        $kopf = [
            'Content-Type: ' . $typ,
            'Cache-Control: ' . ($aktuell ? ($privat ? 'private, ' : 'public, ') . self::EWIG : 'no-cache'),
            'Vary: Accept-Encoding',
            'ETag: ' . $etag,
            'X-Content-Type-Options: nosniff',
        ];
        if (self::EtagTrifft($etag, $ifNoneMatch)) {
            return ['status' => 304, 'kopf' => $kopf, 'rumpf' => ''];
        }
        $aus = $gepackt ? (string) $gz : $rumpf;
        if (strlen($aus) > $grenze) {
            return ['status' => 503, 'kopf' => ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store'],
                'rumpf' => 'Too large for ScriptOutputBufferLimit.'];
        }
        if ($gepackt) {
            $kopf[] = 'Content-Encoding: gzip';
        }
        return ['status' => 200, 'kopf' => $kopf, 'rumpf' => $aus];
    }
}
