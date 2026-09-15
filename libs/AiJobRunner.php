<?php

declare(strict_types=1);

require_once __DIR__ . '/AiJobStore.php';
require_once __DIR__ . '/AiProvider.php';
require_once __DIR__ . '/AiRecipePage.php';

/**
 * Der Laeufer: nimmt Auftraege aus der Warteschlange und ruft den Anbieter.
 *
 * Er tut BEWUSST WENIG. Den Prompt hat das Gateway beim Einreihen gebaut — es
 * braucht dafuer seinen Bestand (Mitglieder, Kinder, erlaubte Kategorien), den
 * der Laeufer gar nicht kennt. Gedeutet wird die Antwort ebenfalls im Gateway,
 * beim Abholen; das kostet Millisekunden. Hier laeuft nur das Langsame: der
 * Anbieteraufruf, und beim Rezept-Weg das Holen der fremden Seite.
 *
 * Symcon-frei, alles wird hereingereicht. Der Laeufer weiss nicht, dass es
 * Instanzen gibt — er bekommt eine Uhr, eine Sperre, einen Weg, den Anbieter
 * zu bauen, und einen, das Ergebnis zu melden. Genau deshalb laesst sich seine
 * Politik pruefen, ohne dass ein Kernel laeuft: was bei „belegt" passiert, wie
 * oft er es noch einmal versucht, und dass nach einem Wurf niemals ein Auftrag
 * als „laeuft" liegen bleibt.
 *
 * Die Regel, an der am meisten haengt: **die Nutzlast verschwindet, sobald sie
 * gesendet ist.** Ein Foto von zwoelf Megabyte in einem Prozess mit 64 MB
 * Speichergrenze ist kein Detail.
 */
final class AiJobRunner
{
    /** So oft wird ein Auftrag hoechstens angefasst, bevor er aufgibt. */
    public const MAX_VERSUCHE = 3;

    /** Ist der Anbieter belegt oder bremst er, wartet der Auftrag so lange. */
    public const BREMSE_S = 30;

    /** Ist der Dienst nicht erreichbar, lohnt ein zweiter Anlauf schon frueher. */
    public const UNERREICHBAR_S = 10;

    /** So viele Auftraege in einem Durchgang — danach holt der Laeufer Luft. */
    public const RUNDE_MAX = 8;

    /** So lange wartet der Laeufer auf die Anbieter-Sperre, bevor er es vertagt. */
    public const SPERRE_WARTEN_MS = 5000;

    private AiJobStore $laden;
    /** @var \Closure(): AiProvider */
    private \Closure $anbieterBauen;
    /** @var \Closure(int): bool */
    private \Closure $sperreHolen;
    /** @var \Closure(): void */
    private \Closure $sperreGeben;
    /** @var \Closure(string): void */
    private \Closure $melden;
    /** @var \Closure(): int */
    private \Closure $uhr;

    /**
     * @param \Closure(): AiProvider $anbieterBauen frisch je Auftrag — die Notizen sollen sich nicht mischen
     * @param \Closure(int): bool    $sperreHolen   wartet die angegebenen Millisekunden
     * @param \Closure(): void       $sperreGeben
     * @param \Closure(string): void $melden        „dieser Auftrag hat eine Antwort"
     * @param \Closure(): int        $uhr
     */
    public function __construct(AiJobStore $laden, \Closure $anbieterBauen, \Closure $sperreHolen,
        \Closure $sperreGeben, \Closure $melden, \Closure $uhr)
    {
        $this->laden         = $laden;
        $this->anbieterBauen = $anbieterBauen;
        $this->sperreHolen   = $sperreHolen;
        $this->sperreGeben   = $sperreGeben;
        $this->melden        = $melden;
        $this->uhr           = $uhr;
    }

    /**
     * Die Warteschlange abarbeiten.
     *
     * Bricht ab, sobald einer vertagt wurde: ist der Anbieter belegt oder
     * bremst er, gilt das fuer den naechsten genauso — es haette keinen Sinn,
     * sich durch die ganze Schlange zu bremsen.
     *
     * @return int wie viele eine Antwort bekommen haben
     */
    public function abarbeiten(int $maxRunden = self::RUNDE_MAX): int
    {
        $fertig = 0;
        for ($runde = 0; $runde < $maxRunden; $runde++) {
            $kopf = $this->laden->naechsten(($this->uhr)());
            if ($kopf === null) {
                break;
            }
            if (!$this->einen($kopf)) {
                break;
            }
            $fertig++;
        }
        return $fertig;
    }

    /**
     * Einen Auftrag ausfuehren.
     *
     * @param array<string,mixed> $kopf bereits auf „laeuft" gesetzt
     * @return bool false = vertagt, der Aufrufer soll aufhoeren
     */
    public function einen(array $kopf): bool
    {
        $id = (string)($kopf['id'] ?? '');

        /* Ist er inzwischen weg? Das passiert, wenn der Nutzer seine
           Einwilligung in die KI zurueckzieht — dann wird die ganze
           Warteschlange geleert, auch mitten im Lauf. Dann NICHT anrufen:
           das waere genau der Aufruf, den er gerade untersagt hat. */
        if ($this->laden->lesen($id) === null) {
            return true;
        }

        if (!($this->sperreHolen)(self::SPERRE_WARTEN_MS)) {
            return $this->vertagen($kopf, 'ai_busy', self::BREMSE_S);
        }

        $anbieter = null;
        try {
            /* NOCH EINMAL nachsehen, jetzt unter der Sperre.
               Auf die Sperre wird bis zu fuenf Sekunden gewartet, und der
               Widerruf der Einwilligung leert die Warteschlange — faellt er in
               dieses Fenster, haette der Aufruf oben ihn nicht mehr gesehen und
               der Text ginge trotzdem an den Anbieter. Genau der Aufruf, den
               der Nutzer gerade untersagt hat. Die Probe VOR der Wartezeit
               allein genuegt also nicht; von einem externen Codereview
               gemeldet (F7, 14.09.2026). */
            if ($this->laden->lesen($id) === null) {
                return true;
            }
            $anbieter = ($this->anbieterBauen)();
            $roh = $this->ausfuehren($kopf, $anbieter);
        } catch (\Throwable $e) {
            /* Ein Wurf darf NIE einen Auftrag als „laeuft" liegen lassen: er
               waere dann bis zum naechsten Aufraeumen unsichtbar, und der
               Nutzer wartete auf ein Ergebnis, das niemand mehr holt. */
            $roh = ['ok' => false, 'code' => 'internal', 'grund' => '',
                    'detail' => mb_substr($e->getMessage(), 0, 200), 'debug' => []];
        } finally {
            ($this->sperreGeben)();
        }
        if ($anbieter !== null && !isset($roh['debug'])) {
            $roh['debug'] = $anbieter->debugZeilen();
        }

        // Vertagen statt aufgeben — aber nur, solange noch Versuche uebrig sind.
        $code = (string)($roh['code'] ?? '');
        $versuche = (int)($kopf['attempts'] ?? 1);
        if (($roh['ok'] ?? false) !== true && $versuche < self::MAX_VERSUCHE) {
            if ($code === 'ai_busy' || $code === 'ai_rate_limited') {
                return $this->vertagen($kopf, $code, self::BREMSE_S);
            }
            if ($code === 'ai_unreachable' && $versuche < 2) {
                return $this->vertagen($kopf, $code, self::UNERREICHBAR_S);
            }
        }

        /* Ab hier ist der Auftrag durch — die Nutzlast hat nichts mehr zu
           suchen. Sie ist das Grosse an einem Auftrag: ein Foto, ein PDF,
           eine Tonaufnahme. */
        $this->laden->nutzlastLoeschen($id);

        $kopf['state']      = AiJobStore::ROH;
        $kopf['raw']        = $roh;
        $kopf['finishedAt'] = ($this->uhr)();
        /* `aktualisieren` statt `schreiben`: ist der Auftrag waehrend des
           Aufrufs verschwunden, hat der Nutzer seine Einwilligung
           zurueckgezogen. Weg heisst weg — nicht wieder anlegen, nicht melden.
           Sonst kaeme die Antwort samt Inhalt des Fotos zurueck, das der
           Widerruf gerade vernichten sollte. */
        if (!$this->laden->aktualisieren($kopf)) {
            return true;
        }

        /* Melden erst NACH dem Schreiben: der Gerufene liest die Datei, und
           sie muss dann schon dastehen. */
        ($this->melden)($id);
        return true;
    }

    /**
     * Den Auftrag zurueck in die Schlange legen.
     *
     * @param array<string,mixed> $kopf
     * @return bool immer false — der Aufrufer soll diesen Durchgang beenden
     */
    private function vertagen(array $kopf, string $code, int $sekunden): bool
    {
        $kopf['lastCode'] = $code;
        $this->laden->zurueckstellen($kopf, ($this->uhr)() + $sekunden);
        return false;
    }

    /**
     * Der eigentliche Aufruf.
     *
     * @param array<string,mixed> $kopf
     * @return array<string,mixed> {ok:true,text} | {ok:false,code,grund,detail}
     */
    private function ausfuehren(array $kopf, AiProvider $anbieter): array
    {
        $id  = (string)($kopf['id'] ?? '');
        $job = is_array($kopf['job'] ?? null) ? $kopf['job'] : [];

        if ((string)($kopf['kind'] ?? '') === 'transcribe') {
            $ton = $this->laden->nutzlastLesen($id);
            $r = $anbieter->transcribe($ton, (string)($job['mime'] ?? 'audio/webm'));
            unset($ton);
            return $r;
        }

        $nutzertext = (string)($job['user'] ?? '');

        /* Der Rezept-Weg: die fremde Seite holen gehoert HIERHER und nicht in
           den Hook — sie darf bis zu fuenfzehn Sekunden brauchen. */
        $url = (string)($job['url'] ?? '');
        if ($url !== '') {
            $seite = AiRecipePage::holen($url);
            if (($seite['ok'] ?? false) !== true) {
                return ['ok' => false, 'code' => (string)($seite['code'] ?? 'ai_url_fetch'),
                        'grund' => '', 'detail' => (string)($seite['detail'] ?? '')];
            }
            $text = AiRecipePage::text((string)$seite['body']);
            unset($seite);
            if ($text === '') {
                return ['ok' => false, 'code' => 'ai_url_empty', 'grund' => '', 'detail' => ''];
            }
            $nutzertext .= $text;
            unset($text);
        }

        $bild = null;
        $pdf  = null;
        $art  = (string)($job['payloadKind'] ?? '');
        if ($art === 'image' || $art === 'pdf') {
            $roh = $this->laden->nutzlastLesen($id);
            if ($roh === '') {
                return ['ok' => false, 'code' => 'invalid_payload', 'grund' => '', 'detail' => ''];
            }
            if ($art === 'image') {
                $bild = $roh;
            } else {
                $pdf = $roh;
            }
            unset($roh);
        }

        $r = $anbieter->complete((string)($job['system'] ?? ''), $nutzertext, $bild, $pdf);
        unset($bild, $pdf, $nutzertext);
        return $r;
    }
}
