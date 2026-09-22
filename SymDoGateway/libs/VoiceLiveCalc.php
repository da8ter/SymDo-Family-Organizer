<?php

declare(strict_types=1);

/**
 * GPT-Live (23.09.2026): der IPS-freie Teil des zweiten Sprachwegs.
 *
 * GPT-Live trennt, was die Realtime-Schnittstelle in EINEM Modell tut: das
 * Live-Modell fuehrt nur das Gespraech und DELEGIERT Denken und Werkzeuge an
 * ein zweites Modell (Responses). Deshalb zwei Anweisungen — eine kurze fuer
 * die Stimme, die ausfuehrliche fuer das Backend — und die Werkzeuge stehen
 * beim Backend, nicht in der Sitzung.
 *
 * Die Sitzung entsteht nicht mehr ueber ein fluechtiges Geheimnis im Browser,
 * sondern das Gateway tauscht das SDP-Angebot des Browsers selbst gegen die
 * Antwort (POST /v1/live/sessions, nur WebRTC). Hier steht, WAS dabei
 * geschickt wird; die Kette drumherum bleibt in Voice.php.
 */
final class VoiceLiveCalc
{
    public const MODELL = 'gpt-live-1';

    /** Backend-Modelle fuer die Delegation, wie die Doku sie nennt. */
    public const BACKENDS = ['gpt-5.6-luna', 'gpt-5.6-terra'];

    /** Die Stimmen von GPT-Live — andere Namen als bei Realtime. */
    public const STIMMEN = ['quartz', 'ripple', 'vesper', 'willow', 'stone', 'gleam',
                            'beacon', 'delta', 'cinder', 'bossa', 'meridian', 'tempo'];

    public const BACKEND_VORGABE = 'gpt-5.6-luna';
    public const STIMME_VORGABE  = 'quartz';

    /** Ist das die Live-Auswahl? Alles andere geht den Realtime-Weg. */
    public static function IstLive(string $modell): bool
    {
        return trim($modell) === self::MODELL;
    }

    public static function Backend(string $roh): string
    {
        return in_array(trim($roh), self::BACKENDS, true) ? trim($roh) : self::BACKEND_VORGABE;
    }

    public static function Stimme(string $roh): string
    {
        return in_array(trim($roh), self::STIMMEN, true) ? trim($roh) : self::STIMME_VORGABE;
    }

    /**
     * Die kurze Anweisung fuer die STIMME. Fachliches steht beim Backend —
     * hier nur Ton, Sprache, Kuerze und die Regel, wann delegiert wird.
     */
    public static function SprechAnweisung(string $wer, string $datum): string
    {
        $zeilen = [
            'Du bist SymDo, der Sprachassistent dieses Haushalts. Sprich Deutsch, freundlich und knapp: ein bis zwei kurze Sätze.',
            'Heute ist ' . $datum . '.',
            'Alles, was Listen, Termine, Notizen, Rezepte, Essensplan, Stundenplan, Hausaufgaben, Ämtchen, Geräte, Mitteilungen oder das Symcon-Handbuch betrifft, delegierst du an das Backend und wartest auf dessen Ergebnis. Behaupte nie, etwas erledigt zu haben, bevor das Backend es bestätigt hat.',
            'Braucht das Backend eine Entscheidung oder Bestätigung, frag kurz nach und gib die Antwort weiter. Allgemeine Wissens- oder Rechenfragen beantwortest du nicht — lehne in einem Satz freundlich ab und sage, wobei du helfen kannst.',
        ];
        if ($wer !== '') {
            $zeilen[] = 'Du sprichst mit ' . $wer . '.';
        }
        return implode(' ', $zeilen);
    }

    /**
     * Der Rumpf fuer POST /v1/live/sessions.
     *
     * @param array{sprech:string, backend:string, backendAnweisung:string, werkzeuge:list<array<string,mixed>>, stimme:string} $e
     */
    public static function SitzungsRumpf(array $e, string $sdp): array
    {
        return [
            'session' => [
                'model'        => self::MODELL,
                'instructions' => (string)$e['sprech'],
                'audio'        => ['output' => ['voice' => self::Stimme((string)($e['stimme'] ?? ''))]],
                'delegation'   => [
                    'type'      => 'responses',
                    'responses' => [
                        'model'               => self::Backend((string)($e['backend'] ?? '')),
                        'instructions'        => (string)$e['backendAnweisung'],
                        'tools'               => array_values($e['werkzeuge'] ?? []),
                        'tool_choice'         => 'auto',
                        // Nacheinander: der Kern liefert Ergebnisse einzeln und
                        // setzt erst dann fort — parallel gaebe es Wettlaeufe.
                        'parallel_tool_calls' => false,
                    ],
                ],
            ],
            'transport' => ['type' => 'webrtc', 'sdp' => $sdp],
        ];
    }

    /**
     * Aus der Antwort des Anbieters: Antwort-SDP und Sitzungskennung.
     *
     * @return array{sdp:string, id:string}
     */
    public static function AntwortLesen(?array $antwort): array
    {
        $sdp = (string)(($antwort['transport'] ?? [])['sdp'] ?? '');
        $id  = (string)(($antwort['session'] ?? [])['id'] ?? ($antwort['id'] ?? ''));
        return ['sdp' => str_starts_with($sdp, 'v=') ? $sdp : '', 'id' => trim($id)];
    }
}
