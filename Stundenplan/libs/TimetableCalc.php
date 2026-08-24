<?php

declare(strict_types=1);

/**
 * Reine Stundenplan-Rechnerei: Zeiten, Hoehen, Luecken, Konflikte, Samstag,
 * Betreuung. Keine Symcon-Aufrufe, kein Zustand — damit sie im Pruefstand ohne
 * Kernel laeuft.
 *
 * Portiert aus der Vorlage WuselPlan (Views/Child/TimetableLayout.swift). Die
 * dort dokumentierten Fallen sind uebernommen und unten jeweils benannt.
 *
 * Zeiten stehen ueberall als „HH:MM"-String. Die Vorlage speichert Date-Werte
 * und muss deshalb ueberall ueber minutesSinceMidnight rechnen, weil ihre Slots
 * uneinheitliche Kalendertage tragen — ein Umweg, den man nicht nachbauen muss.
 */
class TimetableCalc
{
    /** Pixel je Viertelstunde. Wert aus der Vorlage: eine 45-Minuten-Stunde
     *  fasst damit Symbol und zwei Textzeilen ohne Ueberlauf. */
    public const PIXEL_JE_VIERTEL = 18;

    /** Kuerzeste Stunde. Kuerzere Angaben werden auf diese Hoehe gestreckt,
     *  sonst rendert eine 30-Minuten-AG niedriger als ihr Text hoch ist. */
    public const MIN_DAUER = 45;

    public const MONTAG     = 1;
    public const SAMSTAG    = 6;

    /** @return list<int> Die Tage, die im Raster stehen. */
    public static function Wochentage(bool $samstag): array
    {
        return $samstag ? [1, 2, 3, 4, 5, 6] : [1, 2, 3, 4, 5];
    }

    public static function TagKurz(int $tag): string
    {
        return ['', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][$tag] ?? '';
    }

    // ────────────────────────────── Zeiten ──────────────────────────────

    /** „07:45" → 465. Unbrauchbares ergibt -1, NICHT 0: 0 waere Mitternacht
     *  und damit eine gueltige Zeit, die Spannen und Sortierung verzerrt. */
    public static function Minuten(string $zeit): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($zeit), $t)) {
            return -1;
        }
        $std = (int)$t[1];
        $min = (int)$t[2];
        if ($std > 23 || $min > 59) {
            return -1;
        }
        return $std * 60 + $min;
    }

    /** 465 → „07:45". Fuehrende Null bleibt: „7:45" bricht die Spaltenbreite. */
    public static function Zeit(int $minuten): string
    {
        $minuten = max(0, min(24 * 60, $minuten));
        return sprintf('%02d:%02d', intdiv($minuten, 60), $minuten % 60);
    }

    /** „07:45 – 08:30" fuer die Karte im Raster. */
    public static function Spanne(array $slot): string
    {
        return self::Zeit(self::Minuten((string)($slot['start'] ?? '')))
            . ' – ' . self::Zeit(self::Minuten((string)($slot['end'] ?? '')));
    }

    // ────────────────────────── Hoehen und Luecken ──────────────────────────

    /** Hoehe einer Stunde in Pixeln, auf volle Viertelstunden aufgerundet. */
    public static function SlotHoehe(array $slot): int
    {
        $von = self::Minuten((string)($slot['start'] ?? ''));
        $bis = self::Minuten((string)($slot['end'] ?? ''));
        if ($von < 0) {
            return self::MIN_DAUER / 15 * self::PIXEL_JE_VIERTEL;
        }
        $dauer = max($bis - $von, self::MIN_DAUER);
        return max((int)ceil($dauer / 15), 1) * self::PIXEL_JE_VIERTEL;
    }

    /** Hoehe des grauen „frei"-Blocks zwischen zwei Stunden. 0 heisst: kein
     *  Block. Negative Abstaende (Ueberlappung) ergeben ebenfalls 0 — die
     *  Ueberlappung meldet der Konflikt-Pruefer, das Raster verbiegt sich nicht. */
    public static function LueckeHoehe(int $vorherEnde, int $beginn): int
    {
        $dauer = max($beginn - $vorherEnde, 0);
        return max((int)ceil($dauer / 15), 0) * self::PIXEL_JE_VIERTEL;
    }

    // ─────────────────────────────── Konflikte ───────────────────────────────

    /**
     * Erste Stunde aus $bestand, die mit $kandidat kollidiert: gleiches Kind,
     * gleicher Tag, echte Ueberlappung.
     *
     * Beruehrungen sind KEIN Konflikt (Ende == Beginn) — der strikte Vergleich
     * laesst sie durch. Das ist der Normalfall im Stundenplan: 07:45–08:30
     * gefolgt von 08:30–09:15.
     */
    public static function Konflikt(array $kandidat, array $bestand): ?array
    {
        $kVon = self::Minuten((string)($kandidat['start'] ?? ''));
        if ($kVon < 0) {
            return null;
        }
        $kBis = max(self::Minuten((string)($kandidat['end'] ?? '')), $kVon);
        foreach ($bestand as $anderer) {
            if ((string)($anderer['id'] ?? '') === (string)($kandidat['id'] ?? '')) {
                continue;   // dieselbe Zeile beim Bearbeiten
            }
            if ((string)($anderer['childId'] ?? '') !== (string)($kandidat['childId'] ?? '')) {
                continue;
            }
            if ((int)($anderer['weekday'] ?? 0) !== (int)($kandidat['weekday'] ?? 0)) {
                continue;
            }
            $aVon = self::Minuten((string)($anderer['start'] ?? ''));
            $aBis = self::Minuten((string)($anderer['end'] ?? ''));
            if ($aVon < 0) {
                continue;
            }
            if ($kVon < $aBis && $aVon < $kBis) {
                return $anderer;
            }
        }
        return null;
    }

    // ──────────────────────────────── Samstag ────────────────────────────────

    /**
     * ISO-Kalenderwoche. Deutschland rechnet nach ISO; die Zeitzone wird fest
     * gesetzt, damit das Ergebnis nicht an der Servereinstellung haengt.
     */
    public static function IsoWoche(string $datum): int
    {
        $d = new \DateTimeImmutable($datum . ' 12:00:00', new \DateTimeZone('Europe/Berlin'));
        return (int)$d->format('W');
    }

    /**
     * Findet an diesem Samstag Unterricht statt?
     *
     * Bei „nur alle zwei Wochen" entscheidet die Paritaet der ISO-KW, NICHT ein
     * strikter 14-Tage-Rhythmus. Uebernommen aus der Vorlage: in Jahren mit 53
     * Wochen wiederholt sich die Paritaet am Jahreswechsel (KW 53 und KW 1 sind
     * beide ungerade) — genau wie im Schulaushang „Unterricht in ungeraden KWs".
     */
    public static function SamstagUnterricht(array $samstag, string $datum): bool
    {
        if (!(bool)($samstag['enabled'] ?? false)) {
            return false;
        }
        if (!(bool)($samstag['biweekly'] ?? false)) {
            return true;
        }
        $gerade = self::IsoWoche($datum) % 2 === 0;
        return $gerade === ((string)($samstag['parity'] ?? 'even') === 'even');
    }

    /**
     * Wochentag dieses Datums AUS SICHT DIESES KINDES. null heisst: kein
     * Schultag — sonntags immer, samstags wenn abgeschaltet oder die Paritaet
     * nicht passt. Einzige Quelle fuer alles Datumsabhaengige, damit die Kachel
     * am unterrichtsfreien Samstag keine Stunden vortaeuscht.
     */
    public static function Schultag(string $datum, array $samstag): ?int
    {
        $wt = (int)(new \DateTimeImmutable($datum . ' 12:00:00', new \DateTimeZone('Europe/Berlin')))->format('N');
        if ($wt === 7) {
            return null;
        }
        if ($wt === self::SAMSTAG) {
            return self::SamstagUnterricht($samstag, $datum) ? self::SAMSTAG : null;
        }
        return $wt;
    }

    // ─────────────────────────────── Betreuung ───────────────────────────────

    /**
     * Virtueller Betreuungs-Block: vom Ende der letzten Stunde bis zur
     * gepflegten Endzeit. Entsteht NUR, wenn an dem Tag ueberhaupt Unterricht
     * war und die Endzeit danach liegt — sonst stuende an einem freien Tag ein
     * Betreuungsbalken ohne Schule davor.
     *
     * Wird nur berechnet, nie gespeichert.
     */
    public static function BetreuungSlot(int $tag, array $kind, array $tagSlots): ?array
    {
        $ende = '';
        foreach ((array)($kind['care'] ?? []) as $e) {
            if ((int)($e['weekday'] ?? 0) === $tag) {
                $ende = (string)($e['end'] ?? '');
                break;
            }
        }
        $endeMin = self::Minuten($ende);
        if ($endeMin < 0 || $tagSlots === []) {
            return null;
        }
        $letztes = -1;
        foreach ($tagSlots as $s) {
            $letztes = max($letztes, self::Minuten((string)($s['end'] ?? '')));
        }
        if ($letztes < 0 || $endeMin <= $letztes) {
            return null;
        }
        return [
            'id'        => 'care_' . (string)($kind['id'] ?? '') . '_' . $tag,
            'childId'   => (string)($kind['id'] ?? ''),
            'weekday'   => $tag,
            'subjectId' => '',
            'subject'   => 'Betreuung',
            'start'     => self::Zeit($letztes),
            'end'       => self::Zeit($endeMin),
            'color'     => '#9E9E9E',
            'care'      => true,
        ];
    }

    /** Stunden eines Tages, sortiert, mit Betreuung. Eine Quelle fuer beide
     *  Darstellungen — sonst zeigt das Raster etwas anderes als die Timeline. */
    public static function TagesSlots(int $tag, array $kind, array $slots): array
    {
        $tages = [];
        foreach ($slots as $s) {
            if ((string)($s['childId'] ?? '') === (string)($kind['id'] ?? '')
                && (int)($s['weekday'] ?? 0) === $tag) {
                $tages[] = $s;
            }
        }
        usort($tages, static fn(array $a, array $b): int
            => self::Minuten((string)$a['start']) <=> self::Minuten((string)$b['start']));
        $betreuung = self::BetreuungSlot($tag, $kind, $tages);
        if ($betreuung !== null) {
            $tages[] = $betreuung;
        }
        return $tages;
    }

    // ────────────────────────────── Wochenspanne ──────────────────────────────

    /**
     * Fruehester Beginn und spaetestes Ende der ganzen Woche — der Massstab,
     * auf den Raster und Timeline abbilden. Die Betreuung zaehlt mit, sonst
     * reicht die Zeitachse nicht bis zum Ende der laengsten Spalte.
     *
     * Ohne Stunden ein brauchbarer Vorgabebereich statt 0–0: eine Spanne von
     * null Minuten laesst jede Division durch die Spanne auffliegen.
     *
     * @return array{0:int,1:int}
     */
    public static function Wochenspanne(array $slots, array $kinder): array
    {
        $von = PHP_INT_MAX;
        $bis = PHP_INT_MIN;
        foreach ($slots as $s) {
            $a = self::Minuten((string)($s['start'] ?? ''));
            $b = self::Minuten((string)($s['end'] ?? ''));
            if ($a >= 0) {
                $von = min($von, $a);
            }
            if ($b >= 0) {
                $bis = max($bis, $b);
            }
        }
        foreach ($kinder as $k) {
            foreach ((array)($k['care'] ?? []) as $e) {
                $b = self::Minuten((string)($e['end'] ?? ''));
                if ($b >= 0) {
                    $bis = max($bis, $b);
                }
            }
        }
        if ($von === PHP_INT_MAX || $bis === PHP_INT_MIN || $bis <= $von) {
            return [8 * 60, 16 * 60];
        }
        return [$von, $bis];
    }

    /**
     * Die naechste Stunde heute: die erste, die noch nicht vorbei ist. Laufende
     * Stunden zaehlen mit (Ende >= jetzt) — waehrend Mathe laeuft ist Mathe die
     * Antwort auf „was ist gerade", nicht das Fach danach.
     */
    public static function NaechsteStunde(array $tagSlots, string $jetzt): ?array
    {
        $nun = self::Minuten($jetzt);
        if ($nun < 0) {
            return null;
        }
        foreach ($tagSlots as $s) {
            if (self::Minuten((string)($s['end'] ?? '')) >= $nun) {
                return $s;
            }
        }
        return null;
    }

    /** Unterrichtsdauer eines Tages in Minuten: vom Beginn der ersten bis zum
     *  Ende der letzten Stunde. Freistunden zaehlen mit, Betreuung nicht. */
    public static function TagesDauer(array $tagSlots): int
    {
        $unterricht = array_values(array_filter($tagSlots,
            static fn(array $s): bool => !(bool)($s['care'] ?? false)));
        if ($unterricht === []) {
            return 0;
        }
        $von = self::Minuten((string)$unterricht[0]['start']);
        $bis = self::Minuten((string)$unterricht[count($unterricht) - 1]['end']);
        return max($bis - $von, 0);
    }
}
