<?php

declare(strict_types=1);

require_once __DIR__ . '/../../libs/AiHttp.php';

/**
 * Was Bau und Leser des Handbuch-Verzeichnisses GEMEINSAM brauchen.
 *
 * Der Bau ist im September 2026 aus dem Gateway in eine Scanner-Instanz
 * gezogen: er lief dort 3 Sekunden alle 8 Sekunden, eine Woche lang — 37 %
 * Dauerlast in genau der Spur, die auch die App bedient. Der Leser blieb, wo
 * er hingehoert: im Gateway, denn dort kommen die Fragen an.
 *
 * Beide Seiten binden DIESE Datei ein. Sie haelt die Masse, die Pfade und den
 * Zustand — ein Trait, der in zwei Ordnern liegt, laeuft auseinander; einer,
 * der einmal liegt, kann das nicht.
 *
 * Wichtig sind die beiden Kennungen aus `Konfig`: `DokuDatei()` und das
 * Medienobjekt haengen an `BestandID()`, nicht an der eigenen Instanz. Sonst
 * baute der Scanner ein Verzeichnis unter SEINER Kennung, und der Leser im
 * Gateway suchte weiter unter seiner — beide haetten recht und nichts
 * gefunden.
 */
trait DokuGemein
{
    private const DOKU_HOST      = 'https://www.symcon.de';
    private const DOKU_WURZEL    = '/de/service/dokumentation/';
    /** So lange gilt ein fertiges Verzeichnis (Sekunden). */
    /* Der LESER: ein Textmodell formuliert aus den Fundstellen die Antwort, und
       das Sprachmodell liest sie nur vor. Damit folgt das Handbuch derselben
       Regel wie jedes andere Werkzeug — der Server formuliert, das Modell
       spricht. Vorher war „sag" hier nur ein Satzanfang, und genau das ging
       schief: gpt-realtime-mini beantwortete „Skript alle 5 Minuten" mit dem
       Vorwort der FAQ-Seite, und ein kleines Modell schrieb sogar das Wort
       „sag" mit in den Text.

       Am 02.09.2026 auf derselben Werkzeugausgabe gemessen (Frist im Browser
       liegt bei 8 s, deshalb zaehlt jede Sekunde):
         gpt-4.1              1,1 s   64 Token   richtig, sauberster Satz
         gpt-5 (minimal)      1,5 s   65 Token   richtig, nennt mehr Details
         gpt-5-mini (minimal) 1,7 s   52 Token   richtig, aber holprig
         gpt-5-mini (low)     5,4 s  418 Token   320 davon nur Nachdenken
       Ohne `reasoning_effort` verbraucht die gpt-5-Reihe das ganze Token-
       Budget im Nachdenken und antwortet LEER. */
    private const DOKU_LESER_VORGABE = 'gpt-4.1';
    private const DOKU_LESER_FRIST   = 6;
    /* 400 statt 700: die Antwort sind drei bis fuenf Saetze (150–250 Token); der
       Rest des Budgets kostete nur Wartezeit, wenn das Modell weiterschrieb. */
    private const DOKU_LESER_TOKEN   = 400;

    private const DOKU_HALTBAR   = 7 * 86400;
    /** Bis zu dieser Tiefe werden Seiten ABGERUFEN; Verweise darunter landen
     *  trotzdem im Verzeichnis. Muss bis 8 reichen: die Befehlsreferenz endet
     *  bei 6, der Entwicklerbereich geht tiefer (entwicklerbereich/sdk-tools/
     *  sdk-php/…). Mit 6 fehlten genau die SDK-Funktionen — RegisterTimer war
     *  nicht auffindbar. */
    private const DOKU_TIEFE     = 8;
    /** Zählt hoch, wenn sich am Aufbau oder an der Normierung etwas ändert —
     *  ein altes Verzeichnis wäre sonst eine Woche lang stillschweigend falsch. */
    private const DOKU_BAU       = 4;
    /* ── Bedeutungssuche (RAG) ────────────────────────────────────────────
       Die Slug-Suche bewertet nur die ADRESSE. Gemessen an sieben Fragen war
       das bei drei davon zu schwach: „Wie lasse ich mein Modul regelmäßig
       etwas tun?" landete bei SelectModule statt RegisterTimer, „Wie sichere
       ich meine Konfiguration?" bei Konfiguration statt Datensicherung, und
       ein Vergleich zweier Funktionen ist mit einer Seite gar nicht möglich.
       Deshalb werden die Seiten in Abschnitte zerlegt und eingebettet.

       Die Maße sind gemessen, nicht geraten: 978 Seiten, ⌀2736 Zeichen, daraus
       rund 3345 Abschnitte. Bei 512 Dimensionen als EIN BYTE je Zahl sind das
       1,7 MB, und die Suche darüber kostet in PHP 29 ms (Messung mit
       unpack je Abschnitt — sparsamer als ein unpack über alles: 20 statt
       52 MB Spitzenspeicher). */
    private const DOKU_DIM       = 512;
    private const DOKU_STUECK    = 800;
    private const DOKU_UEBERLAPP = 120;
    private const DOKU_MODELL    = 'text-embedding-3-small';
    /** Wie viele Abschnitte in die Antwort einfließen. */
    private const DOKU_TOP       = 4;
    /* Zeitbudget je Durchgang und Taktabstand. Symcon arbeitet Aufrufe je
       Instanz NACHEINANDER ab: 4 Sekunden Arbeit alle 5 Sekunden hiessen, dass
       das Gateway vier Fuenftel der Zeit belegt ist — jede Anfrage der Web-App,
       der Kachel und jeder Werkzeugaufruf muss dahinter warten. Deshalb jetzt
       kurze Haeppchen mit Luft dazwischen: rund ein Achtel Auslastung. Der
       Aufbau dauert damit laenger, ist aber eine Sache von Minuten pro Woche —
       die Bedienung ist jede Sekunde wichtig.
       Nachgemessen am 02.09.2026: bei 2 s alle 15 s antworteten die Hooks in
       10 bis 30 ms, der Aufbau brauchte aber 2,5 Stunden. Der Ausfall kam nicht
       vom Takt, sondern von einem haengenden Ueberrest-Timer. Deshalb jetzt
       3 s alle 8 s (rund 37 % Auslastung) — die Hook-Zeiten werden danach
       erneut gemessen. */
    private const DOKU_BUDGET    = 3.0;
    private const DOKU_TAKT      = 8000;
    /** So oft darf die Einbettung hintereinander scheitern, dann haelt der Bau an. */
    private const DOKU_FEHLER_MAX = 3;
    private const DOKU_SEITEN_MAX = 2500;
    /** So viel Text darf ein Auszug haben — der Rest ist für ein Gespräch ohnehin zu viel. */
    private const DOKU_AUSZUG    = 1800;


    /**
     * Wo die beiden Ablagen liegen — der Bau schreibt daneben in Zwischendateien.
     *
     * An `BestandID()`, NICHT an der eigenen Instanz: baut der Scanner, soll
     * das Verzeichnis dort entstehen, wo der Leser im Gateway es sucht. Mit
     * der eigenen Kennung haetten beide Seiten recht und faenden nichts.
     */
    private function DokuDatei(string $art): string
    {
        return rtrim((string) @IPS_GetKernelDir(), '/\\') . DIRECTORY_SEPARATOR
            . 'media' . DIRECTORY_SEPARATOR . 'symdo_doku_' . $art . '_' . $this->BestandID() . '.bin';
    }

    /**
     * Wo der BAUZUSTAND liegt.
     *
     * Frueher ein Attribut des Gateways. Das ging nicht mehr, sobald der Bau
     * in eine andere Instanz zog: Attribute einer fremden Instanz kann niemand
     * lesen. Eine Datei koennen beide — und sie ueberlebt auch einen
     * Modul-Neustart ohne Kernelstart, an dem ein frisch registriertes
     * Attribut noch gar nicht existiert.
     */
    private function DokuStandDatei(): string
    {
        return rtrim((string) @IPS_GetKernelDir(), '/\\') . DIRECTORY_SEPARATOR
            . 'media' . DIRECTORY_SEPARATOR . 'symdo_doku_stand_' . $this->BestandID() . '.json';
    }


    /**
     * Kann das Verzeichnis ueberhaupt gebaut werden?
     *
     * Die Einbettung laeuft ueber OpenAI; ohne Schluessel gibt DokuEinbetten()
     * sofort null zurueck, die Lesephase legt die Seite zurueck — und der Timer
     * holte dieselbe Seite alle acht Sekunden neu, fuer immer. Das sind rund
     * 10.000 Abrufe am Tag bei symcon.de, fuer nichts. Also gar nicht erst
     * anfangen. Die Stichwortsuche ueber die Adressen bleibt davon unberuehrt,
     * sie braucht kein Verzeichnis.
     */
    private function DokuBaubar(): bool
    {
        return trim((string) $this->AiProp('AiOpenAIKey')) !== '';
    }


    /** Der Anfangszustand — an EINER Stelle, damit die drei Rücksetzwege nicht auseinanderlaufen. */
    private function DokuLeer(): array
    {
        @unlink($this->DokuDatei('bau_text'));
        @unlink($this->DokuDatei('bau_vek'));
        return ['phase' => 'crawl', 'pos' => 0, 'liste' => [], 'stuecke' => 0,
                'stand' => 0, 'fertig' => false, 'fehler' => 0,
                'seiten' => [self::DOKU_WURZEL => 1], 'schlange' => [self::DOKU_WURZEL]];
    }


    /**
     * Der Bauzustand.
     *
     * Gelesen wird ausschliesslich die DATEI. Das alte Attribut des Gateways
     * kommt genau einmal ins Spiel, naemlich in `DokuStandUebergeben()` —
     * dort, wo es auch hingehoert: nur das Gateway hat es, der Scanner kann
     * es gar nicht lesen. Haette dieser Griff einen Rueckfall darauf, sucht
     * er im Scanner nach einem Attribut, das es dort nie gab.
     *
     * @return array<string,mixed>
     */
    private function DokuStand(): array
    {
        $roh = json_decode((string)@file_get_contents($this->DokuStandDatei()), true);
        if (!is_array($roh) || !isset($roh['seiten']) || !is_array($roh['seiten'])) {
            return $this->DokuLeer();
        }
        /* Wurde mit einer ANDEREN Suchtiefe gebaut, ist es unbrauchbar: mit
           Tiefe 6 fehlten alle SDK-Seiten. Ohne diese Probe bliebe so ein
           Verzeichnis eine Woche lang stehen und keiner wüsste, warum die
           Hälfte fehlt. */
        if ((int)($roh['tiefe'] ?? 0) !== self::DOKU_TIEFE
            || (int)($roh['bau'] ?? 0) !== self::DOKU_BAU) {
            return $this->DokuLeer();
        }
        return [
            'phase'    => (string)($roh['phase'] ?? 'crawl'),
            'pos'      => (int)($roh['pos'] ?? 0),
            'liste'    => is_array($roh['liste'] ?? null) ? array_values($roh['liste']) : [],
            'stuecke'  => (int)($roh['stuecke'] ?? 0),
            'stand'    => (int)($roh['stand'] ?? 0),
            'fertig'   => ($roh['fertig'] ?? false) === true,
            // Fehlerkette der Einbettung; siehe DOKU_FEHLER_MAX.
            'fehler'   => (int)($roh['fehler'] ?? 0),
            'seiten'   => $roh['seiten'],
            'schlange' => is_array($roh['schlange'] ?? null) ? array_values($roh['schlange']) : [],
        ];
    }


    /**
     * Den Bauzustand sichern — in die Datei, unteilbar.
     *
     * Erst daneben schreiben, dann umbenennen: ein halb geschriebener Stand
     * waere schlimmer als keiner, denn er wuerde beim naechsten Lesen als
     * „kaputt" gelten und den ganzen Bau von vorn anstossen.
     */
    private function DokuStandSchreiben(array $s): void
    {
        /* Der Versionsstempel wird HIER gesetzt, nicht an den Ausgaengen.
           Einer davon vergass ihn — der Ausgang nach einer gescheiterten
           Einbettung —, und weil DokuStand() einen Stand ohne Stempel fuer
           unbrauchbar haelt, warf der naechste Griff den ganzen Bau weg: Phase
           zurueck auf „crawl", Zaehlerstand null, Zwischendateien geloescht.
           Mit dem Zustand verschwand auch die Fehlerkette, und damit war die
           Bremse nach drei Fehlversuchen nie erreichbar — genau die Bremse
           gegen zehntausend Abrufe bei symcon.de am Tag. */
        $s['tiefe'] = self::DOKU_TIEFE;
        $s['bau']   = self::DOKU_BAU;

        $ziel = $this->DokuStandDatei();
        // In einer frischen Symcon gibt es media/ immer; in einem Pruefstand nicht.
        if (!@is_dir(dirname($ziel))) {
            @mkdir(dirname($ziel), 0755, true);
        }
        $tmp  = $ziel . '.tmp';
        $roh  = (string)json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($tmp, $roh) === strlen($roh) && @rename($tmp, $ziel)) {
            return;
        }
        @unlink($tmp);
    }


    /**
     * Abschnitte einbetten. Ein Aufruf je Seite (alle ihre Abschnitte auf
     * einmal) — das sind rund tausend Aufrufe für die ganze Doku und kostet
     * etwa anderthalb Cent.
     *
     * @param list<string> $texte
     * @return list<list<float>>|null null = fehlgeschlagen
     */
    private function DokuEinbetten(array $texte): ?array
    {
        $key = trim((string) $this->AiProp('AiOpenAIKey'));
        if ($key === '' || $texte === []) {
            return null;
        }
        /* Direkt ueber AiHttp, nicht ueber AiHttpPost: der Scanner bindet die
           KI-Fassade nicht ein, und die Einbettung braucht von ihr nichts als
           diesen einen Griff. */
        $antwort = AiHttp::post('https://api.openai.com/v1/embeddings',
            ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
            (string)json_encode(['model' => self::DOKU_MODELL, 'input' => array_values($texte),
                                 'dimensions' => self::DOKU_DIM], JSON_UNESCAPED_UNICODE),
            30);
        if ((int)($antwort['status'] ?? 0) !== 200) {
            $this->SendDebug('Doku', 'Einbettung fehlgeschlagen: ' . (string)($antwort['err'] ?? $antwort['status'] ?? '?'), 0);
            return null;
        }
        $daten = json_decode((string)($antwort['body'] ?? ''), true);
        $raus = [];
        foreach ((array)($daten['data'] ?? []) as $d) {
            if (is_array($d['embedding'] ?? null)) {
                $raus[] = $d['embedding'];
            }
        }
        return count($raus) === count($texte) ? $raus : null;
    }

    /* Die beiden folgenden Griffe braucht auch der LESER: er holt die beste
       Seite live und schaelt ihren Fliesstext heraus. Sie stehen deshalb hier
       und nicht im Bau — sonst faehrt der Leser gegen eine Wand, sobald der
       Rueckfall im Gateway entfaellt.


    /**
     * Eine Doku-Seite holen. Bewusst schlank und ohne Weiterleitungs-Ketten in
     * fremde Hosts: die Adresse wird immer selbst zusammengesetzt.
     */
    private function DokuHol(string $pfad, int $timeout = 12): string
    {
        if (!str_starts_with($pfad, self::DOKU_WURZEL)) {
            return '';
        }
        $ch = curl_init(self::DOKU_HOST . $pfad);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SymDo/1.0 (Symcon-Modul; Handbuch-Abfrage)');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: text/html']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200 && is_string($body)) ? $body : '';
    }


    /**
     * Den Fließtext einer Seite herausschälen. Der echte Titel ist die LETZTE
     * <h1> — davor stehen der Feedback-Kasten und das Wort „Dokumentation".
     *
     * @return array{titel:string,text:string}
     */
    private function DokuInhalt(string $html): array
    {
        if (preg_match_all('#<h1[^>]*>(.*?)</h1>#is', $html, $m, PREG_OFFSET_CAPTURE) < 1) {
            return ['titel' => '', 'text' => ''];
        }
        $letzte = end($m[0]);
        $titel  = trim(strip_tags((string)end($m[1])[0]));
        $rest   = substr($html, (int)$letzte[1] + strlen((string)$letzte[0]));
        foreach (['<footer', 'Was können wir verbessern', 'id="footer"', '<h4'] as $marke) {
            $i = strpos($rest, $marke);
            if ($i !== false && $i > 0) {
                $rest = substr($rest, 0, $i);
                break;
            }
        }
        // Signatur und Beispiele erhalten — OHNE spitze Klammern als Marke,
        // die fräse der Tag-Filter gleich wieder weg.
        $rest = preg_replace_callback('#<pre[^>]*>(.*?)</pre>#is',
            static fn(array $t): string => "\n" . trim(strip_tags($t[1])) . "\n", $rest) ?? $rest;
        $rest = preg_replace('#<script.*?</script>|<style.*?</style>#is', '', $rest) ?? $rest;
        $rest = preg_replace_callback('#<h([2-4])[^>]*>(.*?)</h\1>#is',
            static fn(array $t): string => "\n" . trim(strip_tags($t[2])) . ': ', $rest) ?? $rest;
        $rest = preg_replace('#</(p|tr|li|div)>#i', "\n", $rest) ?? $rest;
        $rest = preg_replace('#</td>#i', ' — ', $rest) ?? $rest;
        $rest = html_entity_decode(strip_tags($rest), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rest = preg_replace('/[ \t]+/', ' ', $rest) ?? $rest;
        $rest = preg_replace("/\n\s*\n\s*/", "\n", $rest) ?? $rest;
        return ['titel' => $titel, 'text' => trim($rest)];
    }
}
