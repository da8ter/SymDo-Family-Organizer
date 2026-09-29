<?php

declare(strict_types=1);

require_once __DIR__ . '/VoiceVerlaufCalc.php';

/**
 * Sprachdialog: Verlaufsfragen aus dem Symcon-Archiv (29.09.2026).
 *
 * „Wie warm war es gestern Nacht im Kinderzimmer?", „Wie viel Strom diese
 * Woche?", „Wie lange war die Heizung gestern an?" — ein einziges, NUR lesendes
 * Werkzeug `verlauf_lesen`. Anlass war Symcons eigener MCP-Server: dessen
 * `symcon_history` kann das, aber seine 311 Werkzeuge (Löschen, Skripte,
 * Module schreiben) gehören nicht in die Hand eines Sprachmodells.
 *
 * Grenzen wie bei `geraete_lesen`: nur Variablen aus dem freigegebenen
 * Geräte-Katalog (VoiceGeraeteKatalog), nur archivierte, und hinter demselben
 * Tor (`tor => geraete`). Sensoren ohne Aktion sind lesbar — anders als beim
 * Schalten gibt es hier keinen akt-Filter.
 *
 * Die Rechnung steht in VoiceVerlaufCalc (rein, ohne Symcon).
 */
trait VoiceVerlauf
{
    private const VOICE_ARCHIV_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    /** @return array<string,mixed> */
    private function VoiceToolVerlauf(array $args, array $ctx): array
    {
        $katalog = $this->VoiceGeraeteKatalog();
        if ($katalog === []) {
            return $this->VoiceErr('nicht_erlaubt', $this->Translate('No devices are released for voice control.'));
        }
        $zeit = VoiceVerlaufCalc::Zeitraum((string)($args['zeitraum'] ?? ''),
            isset($args['datum']) && is_string($args['datum']) ? $args['datum'] : null, time());
        if (!$zeit['ok']) {
            return $this->VoiceErr('zeitraum', match ($zeit['fehler'] ?? '') {
                'zukunft' => $this->Translate('That lies in the future — I can only look back.'),
                'datum'   => $this->Translate('I did not understand the date.'),
                default   => $this->Translate('I did not understand the period.'),
            });
        }

        // Das Gerät: wie bei geraete_lesen — Kennung aus einer Kandidatenliste oder Name (+ Raum).
        $geraet = trim((string)($args['geraet'] ?? ''));
        $raum   = trim((string)($args['raum'] ?? ''));
        $id     = (int)($args['id'] ?? 0);
        if ($id > 0) {
            $e = $this->VoiceGeraetNachId($katalog, $id);
            if ($e === null) {
                return $this->VoiceErr('nicht_gefunden', $this->Translate('That device is not released for voice control.'));
            }
        } elseif ($geraet !== '' || $raum !== '') {
            $such = $geraet !== '' ? $geraet : $raum;
            $erg = $this->VoiceGeraeteAufloesen($such, $katalog, $geraet !== '' ? $raum : '');
            $fehler = $this->VoiceAufloeseFehler($erg, $such, $this->Translate('devices'));
            if ($fehler !== null) {
                $fehler['kandidaten'] = $this->VoiceGeraetKandidaten($such, $geraet !== '' ? $raum : '', $katalog, 8, 55);
                return $fehler;
            }
            $e = $erg['treffer'][0]['schluessel'];
        } else {
            return $this->VoiceErr('geraet_fehlt', $this->Translate('Which device or sensor do you mean?'));
        }
        if (($e['typ'] ?? '') !== 'var') {
            return ['ok' => true, 'geraet' => $e['titel'],
                    'sag' => sprintf($this->Translate('%s is a scene or script — it has no history.'), $e['titel'])];
        }
        $vid = (int)$e['id'];

        $archive = @IPS_GetInstanceListByModuleID(self::VOICE_ARCHIV_GUID);
        $ac = is_array($archive) && $archive !== [] ? (int)$archive[0] : 0;
        if ($ac <= 0) {
            return $this->VoiceErr('kein_archiv', $this->Translate('There is no archive in this Symcon, so there is no history.'));
        }
        try {
            $protokolliert = (bool)@AC_GetLoggingStatus($ac, $vid);
        } catch (\Throwable $ex) {
            $protokolliert = false;
        }
        if (!$protokolliert) {
            return ['ok' => true, 'geraet' => $e['titel'], 'protokolliert' => false,
                    'sag' => sprintf($this->Translate('No history is recorded for %s.'), $e['titel'])];
        }
        try {
            $zaehler = (int)@AC_GetAggregationType($ac, $vid) === 1;
        } catch (\Throwable $ex) {
            $zaehler = false;
        }
        try {
            $roh = @AC_GetAggregatedValues($ac, $vid, $zeit['stufe'], $zeit['von'], $zeit['bis'], 0);
        } catch (\Throwable $ex) {
            // Das Archiv wirft, wenn es für diese Stufe nichts hat — dasselbe wie leer.
            $this->SendDebug('Voice', 'Verlauf ' . $vid . ': ' . $ex->getMessage(), 0);
            $roh = [];
        }
        $z = VoiceVerlaufCalc::Zusammenfassen(is_array($roh) ? $roh : [], $zaehler);

        $spec = $this->VoiceGeraetSpezifikation($vid);
        $bool = (int)($e['vt'] ?? -1) === 0;
        $wort = str_starts_with($zeit['wort'], 'am ') ? $zeit['wort'] : $this->Translate($zeit['wort']);
        $sag = VoiceVerlaufCalc::Satz($e['titel'], $wort, $z, $bool,
            // Mit Leerzeichen vor der Einheit („12,0 kWh"); die Darstellung liefert sie getrimmt.
            fn(float $v): string => ($spec['suffix'] !== '' && !str_starts_with($spec['suffix'], '%'))
                ? $this->VoiceGeraetZahlText($v, ['suffix' => ''] + $spec) . ' ' . $spec['suffix']
                : $this->VoiceGeraetZahlText($v, $spec),
            fn(int $ts): string => $this->VoiceGesprocheneUhrzeit($ts),
            $zeit['stufe'],
            fn(string $s): string => $this->Translate($s));
        $raus = ['ok' => true, 'geraet' => $e['titel'], 'zeitraum' => $wort,
                 'von' => date('Y-m-d H:i', $zeit['von']), 'bis' => date('Y-m-d H:i', $zeit['bis']), 'sag' => $sag];
        if (!$z['leer']) {
            $raus += $z['zaehler']
                ? ['summe' => round((float)$z['summe'], 3)]
                : ['mittel' => round((float)$z['mittel'], 3), 'min' => $z['min'], 'max' => $z['max']];
        }
        return $raus;
    }
}
