<?php

declare(strict_types=1);

require_once __DIR__ . '/OriginalCalc.php';

/**
 * Die Ablage der Originale — ein Ordner je Original, kein Symcon (24.09.2026).
 *
 *   <verzeichnis>/<kennung>/original.json   Kopf, Text, Anhangsliste
 *   <verzeichnis>/<kennung>/<n>.<pdf|jpg|png>
 *
 * Warum Dateien und keine Medienobjekte: die Tagessicherung von Symcon kopiert
 * ohnehin nur die settings.json, Medien gehen nur als Base64 hinein und heraus,
 * und Text und Anhaenge teilen EINE Lebensdauer — ein Ordner ist ein
 * Loeschvorgang. Ordner 0700, Dateien 0600: Schulpost gehoert niemandem sonst
 * auf dem Geraet.
 *
 * Jede Kennung und jede Anhangsnummer wird geprueft, BEVOR ein Pfad entsteht —
 * die Kennung kommt aus dem Netz.
 */
final class OriginalStore
{
    private const SATZ_DATEI = 'original.json';
    private const SATZ_MAX = 262144;
    /** Halbfertige Ordner aus einem abgebrochenen Schreiben gelten nach so vielen Sekunden als Muell. */
    private const TMP_ALTER = 3600;

    private string $dir = '';

    public static function in(string $verzeichnis, bool $anlegen = true): self
    {
        $ablage = new self();
        $ablage->dir = rtrim($verzeichnis, '/\\') . DIRECTORY_SEPARATOR;
        if ($anlegen && !is_dir($ablage->dir)) {
            @mkdir($ablage->dir, 0700, true);
            @chmod($ablage->dir, 0700);
        }
        return $ablage;
    }

    public function verzeichnis(): string
    {
        return $this->dir;
    }

    /**
     * Ein Original anlegen (oder ersetzen). Geschrieben wird daneben und dann
     * umbenannt: ein halb geschriebenes Original gibt es nie.
     *
     * @param array<string,mixed>  $satz    aus OriginalCalc::Satz
     * @param array<int,string>    $dateien n ⇒ rohe Bytes (Art steht im Satz)
     */
    public function anlegen(string $id, array $satz, array $dateien): bool
    {
        if (!OriginalCalc::KennungGueltig($id) || !is_dir($this->dir)) {
            return false;
        }
        $arten = [];
        foreach ((array)($satz['atts'] ?? []) as $a) {
            $arten[(int)$a['n']] = (string)$a['kind'];
        }
        $tmp = $this->dir . '.tmp-' . $id . '-' . bin2hex(random_bytes(4));
        if (!@mkdir($tmp, 0700)) {
            return false;
        }
        @chmod($tmp, 0700);
        $ok = $this->satzSchreiben($tmp, $satz);
        foreach ($dateien as $n => $roh) {
            $art = $arten[(int)$n] ?? '';
            if (!$ok || $art === '') {
                continue;
            }
            $pfad = $tmp . DIRECTORY_SEPARATOR . (int)$n . '.' . $art;
            $ok = @file_put_contents($pfad, $roh) === strlen($roh);
            @chmod($pfad, 0600);
        }
        if (!$ok) {
            $this->ordnerWeg($tmp);
            return false;
        }
        return $this->einsetzen($tmp, $id);
    }

    /**
     * Ein abgeleitetes Original fuer EINEN Eintrag: der Text und nur die
     * gewaehlten Anhaenge. Die Dateien werden hart verlinkt (kein doppelter
     * Platz), nur wenn das nicht geht, kopiert.
     *
     * @param array<string,mixed> $satz  schon auf die Auswahl gekuerzt
     * @param list<int>           $nummern
     */
    public function ableiten(string $quelle, string $neu, array $satz, array $nummern): bool
    {
        if (!OriginalCalc::KennungGueltig($quelle) || !OriginalCalc::KennungGueltig($neu)) {
            return false;
        }
        $alt = $this->lesen($quelle);
        if ($alt === null) {
            return false;
        }
        $arten = [];
        foreach ((array)($alt['atts'] ?? []) as $a) {
            $arten[(int)$a['n']] = (string)$a['kind'];
        }
        $tmp = $this->dir . '.tmp-' . $neu . '-' . bin2hex(random_bytes(4));
        if (!@mkdir($tmp, 0700)) {
            return false;
        }
        @chmod($tmp, 0700);
        $ok = $this->satzSchreiben($tmp, $satz);
        foreach ($nummern as $n) {
            $art = $arten[(int)$n] ?? '';
            if (!$ok || $art === '') {
                continue;
            }
            $von = $this->dir . $quelle . DIRECTORY_SEPARATOR . (int)$n . '.' . $art;
            $nach = $tmp . DIRECTORY_SEPARATOR . (int)$n . '.' . $art;
            $ok = is_file($von) && (@link($von, $nach) || @copy($von, $nach));
            @chmod($nach, 0600);
        }
        if (!$ok) {
            $this->ordnerWeg($tmp);
            return false;
        }
        return $this->einsetzen($tmp, $neu);
    }

    /** @return array<string,mixed>|null */
    public function lesen(string $id): ?array
    {
        if (!OriginalCalc::KennungGueltig($id)) {
            return null;
        }
        $pfad = $this->dir . $id . DIRECTORY_SEPARATOR . self::SATZ_DATEI;
        if (!is_file($pfad) || (int)@filesize($pfad) > self::SATZ_MAX) {
            return null;
        }
        $satz = json_decode((string)@file_get_contents($pfad), true);
        return (is_array($satz) && (string)($satz['id'] ?? '') === $id) ? $satz : null;
    }

    public function existiert(string $id): bool
    {
        return OriginalCalc::KennungGueltig($id) && is_file($this->dir . $id . DIRECTORY_SEPARATOR . self::SATZ_DATEI);
    }

    /**
     * Ein Stueck eines Anhangs — fuer Dateien, die groesser sind als eine
     * Antwort von Symcon sein darf.
     *
     * @return array{daten:string, teile:int, groesse:int, art:string, name:string}|null
     */
    public function teilLesen(string $id, int $n, int $teil, int $stueck): ?array
    {
        if ($n < 1 || $n > OriginalCalc::ANHAENGE_MAX || $teil < 0 || $stueck < 1) {
            return null;
        }
        $satz = $this->lesen($id);
        if ($satz === null) {
            return null;
        }
        $anhang = null;
        foreach ((array)($satz['atts'] ?? []) as $a) {
            if ((int)($a['n'] ?? 0) === $n) {
                $anhang = $a;
                break;
            }
        }
        if ($anhang === null || !in_array((string)$anhang['kind'], ['pdf', 'jpg', 'png'], true)) {
            return null;
        }
        $pfad = $this->dir . $id . DIRECTORY_SEPARATOR . $n . '.' . $anhang['kind'];
        $groesse = is_file($pfad) ? (int)@filesize($pfad) : -1;
        if ($groesse < 0) {
            return null;
        }
        $teile = OriginalCalc::Teile($groesse, $stueck);
        if ($teil >= $teile) {
            return null;
        }
        $fh = @fopen($pfad, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            if ($teil > 0 && @fseek($fh, $teil * $stueck) !== 0) {
                return null;
            }
            $daten = $groesse === 0 ? '' : (string)@fread($fh, $stueck);
        } finally {
            @fclose($fh);
        }
        return ['daten' => $daten, 'teile' => $teile, 'groesse' => $groesse,
                'art' => (string)$anhang['kind'], 'name' => (string)($anhang['name'] ?? '')];
    }

    /**
     * Einen ganzen Anhang lesen — nur bis `$max` Byte (die Notiz uebernimmt
     * keine Dateien, die sie spaeter nicht in einer Antwort ausliefern kann).
     *
     * @return array{daten:string, art:string, name:string}|null
     */
    public function dateiLesen(string $id, int $n, int $max): ?array
    {
        $satz = $this->lesen($id);
        if ($satz === null || $n < 1 || $n > OriginalCalc::ANHAENGE_MAX) {
            return null;
        }
        foreach ((array)($satz['atts'] ?? []) as $a) {
            if ((int)($a['n'] ?? 0) !== $n || !in_array((string)($a['kind'] ?? ''), ['pdf', 'jpg', 'png'], true)) {
                continue;
            }
            $pfad = $this->dir . $id . DIRECTORY_SEPARATOR . $n . '.' . $a['kind'];
            $groesse = is_file($pfad) ? (int)@filesize($pfad) : -1;
            if ($groesse < 0 || $groesse > $max) {
                return null;
            }
            $daten = @file_get_contents($pfad);
            return is_string($daten) ? ['daten' => $daten, 'art' => (string)$a['kind'], 'name' => (string)($a['name'] ?? '')] : null;
        }
        return null;
    }

    public function loeschen(string $id): bool
    {
        if (!OriginalCalc::KennungGueltig($id)) {
            return false;
        }
        $pfad = $this->dir . $id;
        return !is_dir($pfad) || $this->ordnerWeg($pfad);
    }

    /**
     * Alle Originale: Kennung ⇒ Alter und belegter Platz. Raeumt nebenbei
     * halbfertige Ordner weg, die ein abgebrochenes Schreiben hinterliess.
     *
     * @return array<string,array{mtime:int,bytes:int}>
     */
    public function alle(): array
    {
        $raus = [];
        $eintraege = @scandir($this->dir);
        if (!is_array($eintraege)) {
            return [];
        }
        foreach ($eintraege as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $pfad = $this->dir . $name;
            if (str_starts_with($name, '.tmp-') || str_starts_with($name, '.alt-')) {
                if (is_dir($pfad) && (int)@filemtime($pfad) < time() - self::TMP_ALTER) {
                    $this->ordnerWeg($pfad);
                }
                continue;
            }
            if (!OriginalCalc::KennungGueltig($name) || !is_dir($pfad)) {
                continue;
            }
            $bytes = 0;
            $inhalt = @scandir($pfad);
            foreach (is_array($inhalt) ? $inhalt : [] as $datei) {
                if ($datei !== '.' && $datei !== '..') {
                    $bytes += (int)@filesize($pfad . DIRECTORY_SEPARATOR . $datei);
                }
            }
            $satz = $pfad . DIRECTORY_SEPARATOR . self::SATZ_DATEI;
            $raus[$name] = ['mtime' => (int)(is_file($satz) ? @filemtime($satz) : @filemtime($pfad)), 'bytes' => $bytes];
        }
        return $raus;
    }

    // ── intern ─────────────────────────────────────────────────────────────

    private function satzSchreiben(string $ordner, array $satz): bool
    {
        $json = json_encode($satz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json) || strlen($json) > self::SATZ_MAX) {
            return false;
        }
        $pfad = $ordner . DIRECTORY_SEPARATOR . self::SATZ_DATEI;
        $ok = @file_put_contents($pfad, $json) === strlen($json);
        @chmod($pfad, 0600);
        return $ok;
    }

    /** Den fertigen tmp-Ordner an seinen Platz — ein vorhandener alter wird ersetzt. */
    private function einsetzen(string $tmp, string $id): bool
    {
        $ziel = $this->dir . $id;
        $alt = '';
        if (is_dir($ziel)) {
            $alt = $this->dir . '.alt-' . $id . '-' . bin2hex(random_bytes(4));
            if (!@rename($ziel, $alt)) {
                $this->ordnerWeg($tmp);
                return false;
            }
        }
        if (!@rename($tmp, $ziel)) {
            if ($alt !== '') {
                @rename($alt, $ziel);
            }
            $this->ordnerWeg($tmp);
            return false;
        }
        if ($alt !== '') {
            $this->ordnerWeg($alt);
        }
        return true;
    }

    /** Einen Original-Ordner samt Inhalt entfernen — nur flache Ordner, nie rekursiv. */
    private function ordnerWeg(string $pfad): bool
    {
        $inhalt = @scandir($pfad);
        foreach (is_array($inhalt) ? $inhalt : [] as $datei) {
            if ($datei === '.' || $datei === '..') {
                continue;
            }
            $f = $pfad . DIRECTORY_SEPARATOR . $datei;
            if (is_file($f) || is_link($f)) {
                @unlink($f);
            }
        }
        return @rmdir($pfad);
    }
}
