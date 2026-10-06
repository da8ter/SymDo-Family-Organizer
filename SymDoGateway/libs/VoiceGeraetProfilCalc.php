<?php

declare(strict_types=1);

/**
 * Sprachprofil eines gekoppelten Sprachgeraets (ESP32, 06.10.2026).
 *
 * Ein Geraet ohne Bildschirm kann nicht waehlen, fuer wen es spricht — und darf
 * es auch nicht: ein Token in fremder Hand koennte sich sonst jedes Mitglied
 * aussuchen. Deshalb stehen Mitglied, Raum und die Erlaubnis zum Schalten am
 * GERAETEEINTRAG im Gateway und ueberschreiben, was im Rumpf der Anfrage steht.
 *
 * Geraete ohne Profil (Web-App, iOS) bleiben unberuehrt.
 *
 * Rein, ohne Symcon — Pruefstand: tests/VoiceGeraetProfilTest.php
 */
final class VoiceGeraetProfilCalc
{
    /**
     * Fertige Weckwoerter (ESP-SR WakeNet9, Modelle in der Firmware). Schluessel
     * = Kennung, die das Geraet versteht; Wert = Anzeige.
     */
    public const WECKWOERTER = ['hiesp' => 'Hi ESP', 'alexa' => 'Alexa', 'jarvis' => 'Jarvis', 'computer' => 'Computer'];
    public const WECKWORT_VORGABE = 'hiesp';

    /** Kachel-Kennungen der Sprachgeraete liegen oberhalb echter Objekt-IDs (max. 59999). */
    public const TILE_BASIS = 1000000;

    /**
     * Rohes Profil in die feste Form bringen.
     * @return array{userId:string, raum:string, geraete:bool, weckwort:string, mqtt:array{port:int, user:string, pass:string}|null}
     */
    public static function Normalisieren(mixed $roh): array
    {
        $p = is_array($roh) ? $roh : [];
        return [
            'userId'  => mb_substr(trim((string)($p['userId'] ?? '')), 0, 64),
            'raum'    => mb_substr(trim((string)($p['raum'] ?? '')), 0, 60),
            // Vorgabe: schalten erlaubt — es greifen ohnehin die Gateway-Riegel
            // (Geraetesteuerung an, Einwilligung, freigegebene Wurzeln).
            'geraete' => ($p['geraete'] ?? true) !== false,
            'weckwort' => self::Weckwort((string)($p['weckwort'] ?? '')),
            'mqtt'    => self::Mqtt($p['mqtt'] ?? null),
            // Schluessel, mit dem Symcon jeden MQTT-Befehl an GENAU dieses Geraet
            // signiert (HMAC-SHA256). Alle Geraete teilen sich den MQTT-Zugang —
            // ohne ihn koennte eines dem anderen "start" schicken (Mikrofon an).
            'cmdKey'  => preg_match('/^[0-9a-f]{64}$/', (string)($p['cmdKey'] ?? '')) === 1 ? (string)$p['cmdKey'] : '',
            // Frage/Antwort an Symcon melden? Aus, solange niemand es will:
            // alle Geraete am MQTT-Server koennten mitlesen.
            'mitschrift' => ($p['mitschrift'] ?? false) === true,
        ];
    }

    /**
     * MQTT-Zugang des Geraets zum Symcon-MQTT-Server (setzt das Modul
     * SymDoESPVoice aus seinem uebergeordneten MQTT-Server). null = keiner.
     * @return array{port:int, user:string, pass:string}|null
     */
    public static function Mqtt(mixed $roh): ?array
    {
        if (!is_array($roh)) {
            return null;
        }
        $port = (int)($roh['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            return null;
        }
        return [
            'port' => $port,
            'user' => mb_substr((string)($roh['user'] ?? ''), 0, 64),
            'pass' => mb_substr((string)($roh['pass'] ?? ''), 0, 64),
        ];
    }

    /**
     * Antwort der Aktion `profil` an das Sprachgeraet selbst.
     * Das Weckwort nur, wenn freihaendiges Sprechen eingewilligt ist — sonst
     * "aus" (nur Taste). Mitglied und Raum verlaesst das Gateway nicht.
     *
     * @param array<string,mixed> $body nach Anwenden()
     * @return array<string,mixed>
     */
    public static function ProfilAntwort(array $body, string $deviceId, bool $freihandOk): array
    {
        return [
            'ok'       => true,
            'deviceId' => $deviceId,
            'weckwort' => $freihandOk ? (string)($body['_weckwort'] ?? self::WECKWORT_VORGABE) : 'aus',
            'freihand' => $freihandOk,
            'mqtt'     => $body['_mqtt'] ?? null,
            'cmdKey'   => (string)($body['_cmdKey'] ?? ''),
            'mitschrift' => ($body['_mitschrift'] ?? false) === true,
        ];
    }

    /**
     * Weckwort in die Form bringen, die das Geraet versteht:
     *   "hiesp" | "alexa" | "jarvis" | "computer" — fertiges WakeNet-Modell
     *   "aus"                                    — nur Taste
     *   "eigen:<ausdruck>"                       — freier englischer Ausdruck (MultiNet)
     * Der eigene Ausdruck wird auf Kleinbuchstaben a-z und Leerzeichen
     * gestutzt (die Lautumsetzung auf dem Geraet kennt nur Englisch), 2 bis 5
     * Woerter, hoechstens 40 Zeichen. Alles Unbrauchbare faellt auf die Vorgabe.
     */
    public static function Weckwort(string $roh): string
    {
        $roh = strtolower(trim($roh));
        if ($roh === 'aus' || isset(self::WECKWOERTER[$roh])) {
            return $roh;
        }
        if (str_starts_with($roh, 'eigen:')) {
            $text = (string)preg_replace('/[^a-z ]+/', ' ', substr($roh, 6));
            $text = trim((string)preg_replace('/\s+/', ' ', $text));
            $woerter = $text === '' ? 0 : count(explode(' ', $text));
            if ($woerter >= 2 && $woerter <= 5 && strlen($text) <= 40) {
                return 'eigen:' . $text;
            }
        }
        return self::WECKWORT_VORGABE;
    }

    /** Hat dieses Geraet ein Sprachprofil? */
    public static function HatProfil(?array $device): bool
    {
        return is_array($device) && is_array($device['voice'] ?? null);
    }

    /** Feste, positive Kachel-Kennung je Geraet — "ein Gespraech je Kachel" gilt je Geraet. */
    public static function TileVon(string $deviceId): int
    {
        return self::TILE_BASIS + (crc32($deviceId) % 1000000);
    }

    /**
     * Den Anfragerumpf mit dem Profil ueberschreiben. Ohne Profil unveraendert.
     * Ergaenzt `_raum` und `_geraete` fuer den weiteren Weg (Anweisung, Werkzeuge).
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function Anwenden(array $body, ?array $device): array
    {
        /* Die internen Felder setzt NUR das Profil. Kaeme `_raum` aus dem Rumpf
           einer App, stuende fremder Text ungeprueft in der Anweisung an das
           Modell (Prompt-Injection). */
        unset($body['_raum'], $body['_geraete'], $body['_weckwort'], $body['_mqtt'], $body['_profil'],
              $body['_cmdKey'], $body['_mitschrift']);
        if (!self::HatProfil($device)) {
            return $body;
        }
        $p = self::Normalisieren($device['voice']);
        $body['userId']   = $p['userId'];
        $body['tile']     = self::TileVon((string)($device['id'] ?? ''));
        $body['_raum']    = $p['raum'];
        $body['_geraete'] = $p['geraete'];
        $body['_weckwort'] = $p['weckwort'];
        $body['_mqtt']     = $p['mqtt'];
        $body['_profil']   = true;
        $body['_cmdKey']   = $p['cmdKey'];
        $body['_mitschrift'] = $p['mitschrift'];
        // Vorgaben fuer Listen bleiben Sache des Gateways, nicht des Geraets.
        unset($body['defaults']);
        return $body;
    }

    /** Satz fuer die Anweisung: wo das Geraet steht. Leer ohne Raum. */
    public static function RaumHinweis(string $raum): string
    {
        $raum = trim($raum);
        if ($raum === '') {
            return '';
        }
        return 'Du sprichst gerade über ein Sprachgerät im Raum „' . $raum . '". '
            . 'Nennt jemand bei einem Gerät keinen Raum (etwa „mach das Licht an"), meint er dieses Zimmer: '
            . 'gib dann raum „' . $raum . '" mit.';
    }
}
