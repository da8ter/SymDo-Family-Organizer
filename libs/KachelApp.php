<?php

declare(strict_types=1);

/**
 * Gemeinsames App-Skript der SymDo-Kacheln.
 *
 * Seit der Kachel-Modus ein Schalter ist (KACHEL in SymDoWebApp/module.html), ist der Skripttext aller
 * SymDo-Kacheln gleich. Statt ihn inline in jedes Kacheldokument zu schreiben (rund 790 kB je Öffnen),
 * verweisen die Kacheln auf PFAD, den das Gateway versioniert und lange cachebar ausliefert. Chrome
 * kompiliert das Skript dann einmal und teilt es zwischen den Kacheln (gemessen: je Kachel rund
 * 15-20 MB weniger Speicher und zwei Drittel weniger Skriptzeit, sobald es im Cache liegt).
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
}
