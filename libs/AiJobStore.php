<?php

declare(strict_types=1);

/**
 * Die Warteschlange der KI-Auftraege — Dateien, kein Symcon.
 *
 * Warum ueberhaupt eine Warteschlange: ein KI-Aufruf dauert bis zu 45 Sekunden,
 * bei einem lokalen Server bis zu 300. Bisher lief er im Webhook des Gateways,
 * und Symcon fuehrt je Instanz genau EINE Sache zur Zeit aus — waehrend ein
 * Foto ausgewertet wurde, stand die ganze App. Jetzt legt der Hook den Auftrag
 * hier ab, antwortet mit einer Kennung, und eine andere Instanz arbeitet ihn ab.
 *
 * Warum Dateien und keine Attribute: ein Attribut existiert nach einem
 * Modul-Neuladen ohne Kernelstart noch gar nicht, es liegt weltlesbar in der
 * settings.json, und vor allem kann eine FREMDE Instanz es nicht lesen. Der
 * Laeufer ist eine fremde Instanz.
 *
 * Zwei Dateien je Auftrag, und das mit Absicht:
 *
 *   <id>.json      der Kopf — klein, wird oft gelesen und geschrieben
 *   <id>.payload   die Nutzlast — ein Foto, ein PDF, eine Tonaufnahme
 *
 * Die Nutzlast ist bis zu zwoelf Megabyte gross und wird SOFORT nach dem
 * Anbieteraufruf geloescht. Laege sie im Kopf, muesste jeder Blick in die
 * Warteschlange sie mitlesen — bei 64 MB Speichergrenze im PHP-Prozess ist das
 * der Unterschied zwischen „geht" und „geht nicht".
 *
 * Geschrieben wird immer unteilbar: erst daneben, dann umbenennen. Ein halb
 * geschriebener Kopf waere schlimmer als keiner, denn er gaelte beim naechsten
 * Lesen als kaputt — und der Nutzer wartete auf ein Ergebnis, das nie kommt.
 */
final class AiJobStore
{
    /** Zustaende, die ein Auftrag durchlaeuft. */
    public const OFFEN    = 'queued';    // wartet auf den Laeufer
    public const LAEUFT   = 'running';   // der Laeufer hat ihn
    public const ROH      = 'raw';       // Anbieter hat geantwortet, noch nicht gedeutet
    public const FERTIG   = 'done';      // gedeutet, abholbereit
    public const GESCHEITERT = 'failed'; // endgueltig schiefgegangen

    /** So gross darf ein Kopf werden — ohne Nutzlast ist das reichlich. */
    private const KOPF_MAX = 262144;

    private string $dir;

    private function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * Der Laden in einem Verzeichnis. Es wird angelegt, wenn es fehlt — aber
     * nur hier, nicht beim blossen Hinsehen: ein Formularaufbau soll nichts
     * hinterlassen.
     */
    public static function in(string $verzeichnis, bool $anlegen = true): self
    {
        $laden = new self($verzeichnis);
        if ($anlegen && !@is_dir($laden->dir)) {
            @mkdir($laden->dir, 0700, true);
            @chmod($laden->dir, 0700);
        }
        return $laden;
    }

    public function nutzbar(): bool
    {
        return @is_dir($this->dir) && @is_writable($this->dir);
    }

    public function verzeichnis(): string
    {
        return $this->dir;
    }

    /** Eine Kennung, die niemand raten kann — sie ist der einzige Schluessel zum Ergebnis. */
    public static function neueKennung(): string
    {
        return bin2hex(random_bytes(12));
    }

    /**
     * Einen Auftrag einreihen.
     *
     * Die Nutzlast wird ZUERST geschrieben und der Kopf ZULETZT: erst mit dem
     * Kopf wird der Auftrag sichtbar, und dann ist alles da, was er braucht.
     * Umgekehrt gaebe es ein Zeitfenster, in dem der Laeufer einen Auftrag ohne
     * Nutzlast faende und ihn als leer abschloesse.
     *
     * @param array<string,mixed> $kopf
     * @return string|null die Kennung, oder null wenn nichts geschrieben werden konnte
     */
    public function anlegen(array $kopf, string $nutzlast = ''): ?string
    {
        $id = (string)($kopf['id'] ?? '');
        if (!self::kennungGueltig($id) || !$this->nutzbar()) {
            return null;
        }
        if ($nutzlast !== '' && !$this->atomar($this->dir . $id . '.payload', $nutzlast)) {
            return null;
        }
        if (!$this->schreiben($kopf)) {
            @unlink($this->dir . $id . '.payload');
            return null;
        }
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function lesen(string $id): ?array
    {
        if (!self::kennungGueltig($id)) {
            return null;
        }
        $pfad = $this->dir . $id . '.json';
        if (!@is_file($pfad) || (int)@filesize($pfad) > self::KOPF_MAX) {
            return null;
        }
        $roh = json_decode((string)@file_get_contents($pfad), true);
        return (is_array($roh) && (string)($roh['id'] ?? '') === $id) ? $roh : null;
    }

    /** @param array<string,mixed> $kopf */
    public function schreiben(array $kopf): bool
    {
        $id = (string)($kopf['id'] ?? '');
        if (!self::kennungGueltig($id)) {
            return false;
        }
        return $this->atomar($this->dir . $id . '.json',
            (string)json_encode($kopf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Schreiben, ABER nur wenn es den Auftrag noch gibt.
     *
     * Der Unterschied zu `schreiben()` entscheidet ueber einen Widerruf: zieht
     * der Nutzer waehrend eines laufenden Anbieteraufrufs seine Einwilligung
     * zurueck, wird die ganze Schlange geleert. Ein blindes Zurueckschreiben
     * legte den Auftrag danach WIEDER an — samt der Antwort, die der Widerruf
     * gerade vernichten sollte.
     *
     * @param array<string,mixed> $kopf
     */
    public function aktualisieren(array $kopf): bool
    {
        $id = (string)($kopf['id'] ?? '');
        if (!self::kennungGueltig($id) || !@is_file($this->dir . $id . '.json')) {
            return false;
        }
        return $this->schreiben($kopf);
    }

    /**
     * Den naechsten Auftrag nehmen: den AELTESTEN, der offen ist und dessen
     * Sperrfrist abgelaufen ist.
     *
     * Genommen heisst: Zustand auf „laeuft", Versuch hochgezaehlt, Startzeit
     * gesetzt — und das steht auf der Platte, bevor dieser Griff zurueckkehrt.
     * Sonst faende ein Neustart mitten im Aufruf einen Auftrag, der ewig offen
     * aussieht und immer wieder von vorn beginnt.
     *
     * @return array<string,mixed>|null
     */
    public function naechsten(int $jetzt): ?array
    {
        $bester = null;
        foreach ($this->koepfe() as $kopf) {
            if ((string)($kopf['state'] ?? '') !== self::OFFEN) {
                continue;
            }
            if ((int)($kopf['notBefore'] ?? 0) > $jetzt) {
                continue;
            }
            if ($bester === null || (int)($kopf['createdAt'] ?? 0) < (int)($bester['createdAt'] ?? 0)) {
                $bester = $kopf;
            }
        }
        if ($bester === null) {
            return null;
        }
        $bester['state']     = self::LAEUFT;
        $bester['startedAt'] = $jetzt;
        $bester['attempts']  = (int)($bester['attempts'] ?? 0) + 1;
        return $this->schreiben($bester) ? $bester : null;
    }

    /**
     * Einen genommenen Auftrag wieder freigeben — der Anbieter war belegt oder
     * hat gebremst. Er wird NICHT sofort wieder genommen, sonst drehte der
     * Laeufer sich im Kreis.
     *
     * @param array<string,mixed> $kopf
     */
    public function zurueckstellen(array $kopf, int $nichtVor): bool
    {
        $kopf['state']     = self::OFFEN;
        $kopf['notBefore'] = $nichtVor;
        $kopf['startedAt'] = 0;
        return $this->aktualisieren($kopf);
    }

    public function nutzlastLesen(string $id): string
    {
        if (!self::kennungGueltig($id)) {
            return '';
        }
        return (string)@file_get_contents($this->dir . $id . '.payload');
    }

    /**
     * Die Nutzlast wegraeumen. Sofort nach dem Anbieteraufruf: ein Foto von
     * zwoelf Megabyte hat nichts mehr zu suchen, sobald es gesendet ist.
     */
    public function nutzlastLoeschen(string $id): void
    {
        if (self::kennungGueltig($id)) {
            @unlink($this->dir . $id . '.payload');
        }
    }

    /**
     * Der wievielte in der Schlange? Eins heisst „als naechstes dran".
     *
     * Daraus macht die App ihre Wartezeit-Schaetzung — ohne die Zahl muesste
     * sie raten, ob sie fuenf Sekunden oder fuenf Minuten wartet.
     */
    public function position(string $id): int
    {
        $kopf = $this->lesen($id);
        if ($kopf === null) {
            return 0;
        }
        $zustand = (string)($kopf['state'] ?? '');
        if ($zustand === self::LAEUFT) {
            return 1;
        }
        if ($zustand !== self::OFFEN) {
            return 0;
        }
        $vor = 0;
        foreach ($this->koepfe() as $k) {
            $z = (string)($k['state'] ?? '');
            if ($z === self::LAEUFT) {
                $vor++;
            } elseif ($z === self::OFFEN
                && (int)($k['createdAt'] ?? 0) < (int)($kopf['createdAt'] ?? 0)) {
                $vor++;
            }
        }
        return $vor + 1;
    }

    /**
     * Wie viele warten (oder laufen gerade)?
     *
     * Mit `herkunft` nur die eines Absenders — daran haengt der Deckel, der
     * verhindert, dass ein Geraet die Schlange fuer alle anderen fuellt.
     */
    public function zaehleWartende(string $herkunft = ''): int
    {
        $n = 0;
        foreach ($this->koepfe() as $kopf) {
            $z = (string)($kopf['state'] ?? '');
            if ($z !== self::OFFEN && $z !== self::LAEUFT) {
                continue;
            }
            if ($herkunft !== '' && self::herkunftVon($kopf) !== $herkunft) {
                continue;
            }
            $n++;
        }
        return $n;
    }

    public function hatWartende(): bool
    {
        foreach ($this->koepfe() as $kopf) {
            $z = (string)($kopf['state'] ?? '');
            if ($z === self::OFFEN || $z === self::LAEUFT || $z === self::ROH) {
                return true;
            }
        }
        return false;
    }

    /** Wer den Auftrag gestellt hat — Geraet oder Kachel. */
    public static function herkunftVon(array $kopf): string
    {
        $h = is_array($kopf['origin'] ?? null) ? $kopf['origin'] : [];
        $art = (string)($h['type'] ?? 'rest');
        return $art === 'tile'
            ? 'tile:' . (string)($h['sdwa'] ?? '')
            : 'rest:' . (string)($kopf['device'] ?? '');
    }

    public function loeschen(string $id): void
    {
        if (!self::kennungGueltig($id)) {
            return;
        }
        @unlink($this->dir . $id . '.json');
        @unlink($this->dir . $id . '.payload');
    }

    /**
     * Alles wegraeumen. Gebraucht, wenn der Nutzer seine Einwilligung in die
     * KI zurueckzieht: dann darf kein Foto mehr herumliegen, auch kein
     * eingereihtes.
     */
    public function alleLoeschen(): int
    {
        $n = 0;
        foreach ((array)@glob($this->dir . '*') as $pfad) {
            if (@unlink((string)$pfad)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Aufraeumen — vier verschiedene Arten von Leiche:
     *
     *  1. Fertige, die niemand mehr abholt (der Nutzer hat die App geschlossen).
     *  2. Offene, die zu lange warten (niemand laeuft mehr).
     *  3. Laufende, die nie zurueckkamen (Kernelstart mitten im Aufruf). Sie
     *     gehen EINMAL zurueck in die Schlange, statt weggeworfen zu werden —
     *     der Nutzer hat sein Foto geschickt und soll ein Ergebnis bekommen.
     *  4. Nutzlasten ohne Kopf. Die entstehen, wenn zwischen den beiden
     *     Schreibvorgaengen etwas abbricht.
     *
     * @return int wie viele Auftraege angefasst wurden
     */
    public function aufraeumen(int $jetzt, int $haltbarS, int $schlangeMaxS, int $laufMaxS): int
    {
        $n = 0;
        $bekannt = [];
        foreach ($this->koepfe() as $kopf) {
            $id = (string)($kopf['id'] ?? '');
            $bekannt[$id] = true;
            $zustand = (string)($kopf['state'] ?? '');
            $alter   = $jetzt - (int)($kopf['createdAt'] ?? 0);

            if ($zustand === self::FERTIG || $zustand === self::GESCHEITERT) {
                if ($jetzt - (int)($kopf['finishedAt'] ?? 0) > $haltbarS) {
                    $this->loeschen($id);
                    $n++;
                }
                continue;
            }
            if ($zustand === self::LAEUFT) {
                $laeuft = $jetzt - (int)($kopf['startedAt'] ?? 0);
                if ($laeuft > $laufMaxS) {
                    /* Einmal zurueck in die Schlange, danach aufgeben: sonst
                       liefe ein Auftrag, der den Laeufer jedes Mal umbringt,
                       fuer immer im Kreis. */
                    if ((int)($kopf['attempts'] ?? 0) < 2) {
                        $this->zurueckstellen($kopf, $jetzt);
                    } else {
                        $kopf['state']      = self::GESCHEITERT;
                        $kopf['finishedAt'] = $jetzt;
                        $kopf['raw']        = ['ok' => false, 'code' => 'ai_timeout', 'grund' => '', 'detail' => ''];
                        $this->schreiben($kopf);
                        $this->nutzlastLoeschen($id);
                    }
                    $n++;
                }
                continue;
            }
            if ($alter > $schlangeMaxS) {
                $kopf['state']      = self::GESCHEITERT;
                $kopf['finishedAt'] = $jetzt;
                $kopf['raw']        = ['ok' => false, 'code' => 'ai_timeout', 'grund' => '', 'detail' => ''];
                $this->schreiben($kopf);
                $this->nutzlastLoeschen($id);
                $n++;
            }
        }
        // Nutzlasten, deren Kopf fehlt.
        foreach ((array)@glob($this->dir . '*.payload') as $pfad) {
            $id = basename((string)$pfad, '.payload');
            if (!isset($bekannt[$id])) {
                @unlink((string)$pfad);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Alle Koepfe. Bewusst ohne Zwischenspeicher: die Warteschlange ist kurz
     * (ein Deckel von acht), und ein veralteter Blick waere hier schlimmer als
     * ein zweites Lesen.
     *
     * @return list<array<string,mixed>>
     */
    public function koepfe(): array
    {
        $raus = [];
        foreach ((array)@glob($this->dir . '*.json') as $pfad) {
            $kopf = $this->lesen(basename((string)$pfad, '.json'));
            if ($kopf !== null) {
                $raus[] = $kopf;
            }
        }
        return $raus;
    }

    /** 24 Hexziffern und sonst nichts — daraus kann kein Pfad werden. */
    public static function kennungGueltig(string $id): bool
    {
        return (bool)preg_match('/^[0-9a-f]{24}$/', $id);
    }

    /**
     * Schreiben, das entweder ganz oder gar nicht geschieht. Erst in eine
     * Nebendatei mit fuehrendem Punkt (damit sie kein Leser einsammelt), dann
     * umbenennen — das ist im selben Verzeichnis unteilbar.
     */
    private function atomar(string $ziel, string $inhalt): bool
    {
        $tmp = dirname($ziel) . DIRECTORY_SEPARATOR . '.' . basename($ziel) . '.tmp';
        if (@file_put_contents($tmp, $inhalt) !== strlen($inhalt)) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $ziel)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
