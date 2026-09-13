<?php

declare(strict_types=1);

/**
 * Ein POST ins Netz, ohne Symcon.
 *
 * Die eine Stelle, an der die Bibliothek mit einem HTTP-Dienst spricht, wenn
 * sie dafuer keine Instanz braucht. Herausgeloest aus `AiExtract`, weil der
 * Handbuch-Bau in die Scanner-Instanz gezogen ist: der Scanner bindet
 * bewusst nur wenige Dateien ein, und die 2700 Zeilen der KI-Fassade gehoeren
 * nicht dazu — er braucht davon genau diese zwoelf.
 *
 * Der Rueckgabewert ist absichtlich dieselbe Form wie bisher
 * (`status`/`body`/`err`), damit der alte Griff `AiHttpPost()` eine
 * Durchreiche bleiben kann und keiner seiner dreizehn Aufrufer sich aendert.
 *
 * Bewusst ohne Umleitungen und ohne eigene Pruefung der Adresse: wer hier
 * hereinruft, hat sein Ziel selbst gewaehlt (ein Anbieter-Endpunkt aus der
 * Konfiguration). Fuer das Holen FREMDER Seiten gibt es den Weg ueber
 * `AiFetchPublicPage`, der jede Weiterleitung einzeln prueft.
 */
final class AiHttp
{
    /** Wie lange auf die Verbindung gewartet wird, bevor aufgegeben wird. */
    public const VERBINDEN = 5;

    /**
     * @param list<string> $headers
     * @return array{status:int,body:string,err:string}
     */
    public static function post(string $url, array $headers, string $bodyJson,
        int $timeout = 45, int $verbinden = self::VERBINDEN): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $verbinden);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        $err  = ($body === false) ? curl_error($ch) : '';
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $code, 'body' => is_string($body) ? $body : '', 'err' => $err];
    }
}
