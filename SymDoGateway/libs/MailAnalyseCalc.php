<?php

declare(strict_types=1);

/**
 * Was aus einer analysierten Nachricht wird — ohne Symcon.
 *
 * Die Analyse einer Mail oder einer Klassenseiten-Karte zerfaellt in drei
 * Teile: den Anbieter fragen (dauert Sekunden bis Minuten), das Ergebnis
 * ZAEHLEN und FORMEN (dauert nichts), und es in den Bestand legen (braucht
 * Attribute, Medienobjekte und Sperren). Nur der mittlere Teil steht hier.
 *
 * Er steht hier, weil er beim Umbau „Gateway frei halten" zwischen zwei Spuren
 * wandert: fragen soll bald ein SymDoScanner, ablegen bleibt im Gateway. Was
 * dazwischen liegt, muss beide Seiten ueberstehen — und pruefbar sein, ohne
 * dass ein Kernel laeuft.
 *
 * Zwei Zahlen sehen gleich aus und sind es nicht:
 *
 *  - Im PROTOKOLL werden Hausaufgaben von den Aufgaben abgezogen. Ohne eigenen
 *    Zaehler zaehlte die Differenz sie als Aufgaben mit, und die Zeile fuehrte
 *    in die Irre.
 *  - Im PUSH werden sie NICHT abgezogen. Wer beide Stellen auf dieselbe Zahl
 *    zieht, aendert stillschweigend den Text der Meldung, die beim Nutzer
 *    ankommt.
 *
 * Das ist keine Schlamperei, sondern gewachsener Stand — und genau deshalb
 * steht es hier festgenagelt statt zweimal ausgerechnet.
 */
final class MailAnalyseCalc
{
    /**
     * Die Funde auszaehlen.
     *
     * @param list<array<string,mixed>> $aufgaben
     * @return array{mitDatum:int,termine:int,notizen:int,hausaufgaben:int,
     *               aufgabenProtokoll:int,aufgabenPush:int}
     */
    public static function Zaehlen(array $aufgaben): array
    {
        $mitDatum = 0;
        $termine = 0;
        $notizen = 0;
        $hausaufgaben = 0;
        foreach ($aufgaben as $a) {
            if (!is_array($a)) {
                continue;
            }
            if (($a['due'] ?? null) !== null) {
                $mitDatum++;
            }
            $art = (string)($a['kind'] ?? 'task');
            if ($art === 'event') {
                $termine++;
            } elseif ($art === 'note') {
                $notizen++;
            } elseif ($art === 'homework') {
                $hausaufgaben++;
            }
        }
        $gesamt = count($aufgaben);
        return [
            'mitDatum'     => $mitDatum,
            'termine'      => $termine,
            'notizen'      => $notizen,
            'hausaufgaben' => $hausaufgaben,
            // Siehe Klassenkopf: die beiden Zahlen sind absichtlich verschieden.
            'aufgabenProtokoll' => $gesamt - $termine - $notizen - $hausaufgaben,
            'aufgabenPush'      => $gesamt - $termine - $notizen,
        ];
    }

    /**
     * Welche Arten die KI ueberhaupt vorschlagen darf.
     *
     * `homework` nur, wenn es Kinder gibt — sonst verwirft `AiValidateTodoRows`
     * die Zeile still, und die Hausaufgabe verschwindet zwischen Anbieter und
     * Bestand. Die Kinderliste kennt nur das Gateway (Eigenschaft `Users`); ein
     * Scanner muss sie also mitgereicht bekommen und darf sie nicht raten.
     *
     * @return list<string>
     */
    public static function Arten(bool $hatKinder): array
    {
        $arten = ['task', 'event', 'note'];
        if ($hatKinder) {
            $arten[] = 'homework';
        }
        return $arten;
    }

    /**
     * Die Zeile fuers Statusprotokoll.
     *
     * Bewusst ins Statusprotokoll und nicht nur ins Debug: die Analyse laeuft
     * unbeobachtet im Timer und kostet Geld beim Anbieter. Ohne diese Zeile
     * waere im Nachhinein nicht feststellbar, welche Nachricht wann verarbeitet
     * wurde. Und bewusst nur Zahlen, keine Titel — das Protokoll ist kein Ort
     * fuer Inhalte.
     *
     * @param array<string,mixed>       $zahlen aus `Zaehlen()`
     * @param list<array<string,mixed>> $anhaenge
     */
    /**
     * @param string $absender schon fertig — der Aufrufer bringt den Ersatz mit
     */
    public static function Meldung(string $betreff, string $absender, string $quelle,
        array $anhaenge, array $zahlen): string
    {
        return sprintf(
            'SymDo: E-Mail „%s" von %s analysiert%s%s → %d Aufgabe(n), %d Termin(e), %d Notiz(en), '
            . '%d Hausaufgabe(n), davon %d mit Datum',
            $betreff !== '' ? $betreff : '(ohne Betreff)',
            /* KEIN Ersatz fuer Leerstellen hier. Alt stand `?? '?'` an der
               Fundstelle: das greift nur, wenn der Schluessel FEHLT. Eine
               vorhandene, aber leere Adresse — der Webhook-Weg setzt sie so,
               wenn Mailgun weder From noch sender liefert — druckte sich als
               Leerstelle. Wer hier `!== ''` schreibt, aendert den Wortlaut
               bestehender Protokollzeilen. */
            $absender,
            // Der IMAP-Weg bleibt wortgleich wie bisher; nur ein anderer Eingang
            // nennt sich, damit im Protokoll unterscheidbar ist, woher es kam.
            $quelle === 'IMAP' ? '' : ' (' . $quelle . ')',
            $anhaenge === [] ? '' : sprintf(
                ' (mit %d Anhang/Anhaengen: %s)',
                count($anhaenge),
                implode(', ', array_map(
                    static fn(array $a): string => ($a['name'] ?? '') !== '' ? (string)$a['name'] : (string)($a['kind'] ?? ''),
                    $anhaenge
                ))
            ),
            (int)$zahlen['aufgabenProtokoll'],
            (int)$zahlen['termine'],
            (int)$zahlen['notizen'],
            (int)$zahlen['hausaufgaben'],
            (int)$zahlen['mitDatum']
        );
    }

    /**
     * Der Vorschlag, wie er in den Bestand geht.
     *
     * OHNE `atts`/`mediaId` und OHNE `created` — beides gehoert der Seite, die
     * schreibt:
     *
     *  - Die Medien-Kennungen entstehen erst beim Ablegen, und ob das gelingt,
     *    entscheidet dort die Quote. Ein Satz, der sie schon traegt, naehme
     *    Kennungen vorweg, die es vielleicht nie gibt.
     *  - `created` traegt die Aufbewahrung: nach 21 Tagen faellt ein Vorschlag
     *    aus der Liste. Wird beim RECHNEN gestempelt und liegt der Auftrag dann
     *    noch in einer Warteschlange, faellt er frueher heraus als heute.
     *
     * @param array<string,mixed>                                  $kopf
     * @param array{name:string,address:string,subject:string}      $herkunft
     *        aus `MailDetectOrigin` — ein OBJEKT, kein Text. Die Web-App liest
     *        `typeof p.origin === 'object'` und faellt sonst auf den aeusseren
     *        „Fwd:"-Kopf zurueck, also auf das weiterleitende Familienmitglied.
     * @param list<array<string,mixed>>                             $aufgaben
     * @return array<string,mixed>
     */
    public static function Satz(string $vorschlagsId, array $kopf, string $betreff,
        string $userId, array $herkunft, array $aufgaben, int $jetzt): array
    {
        return [
            'id'        => $vorschlagsId,
            // Datum des DOKUMENTS — es steht in der App und sortiert die Liste.
            'at'        => (int)($kopf['Date'] ?? $jetzt),
            'from'      => (string)($kopf['SenderAddress'] ?? ''),
            'fromName'  => (string)($kopf['SenderName'] ?? ''),
            'subject'   => $betreff,
            'recipient' => (string)($kopf['Recipient'] ?? ''),
            'userId'    => $userId,
            /* Wer die Nachricht urspruenglich geschrieben hat und wie sie damals
               hiess. Der aeussere Kopf nennt immer nur das weiterleitende
               Familienmitglied und ein „Fwd:" davor — beides sagt dem Nutzer
               nichts. */
            'origin'    => $herkunft,
            'items'     => array_map(static fn(array $a): array => $a + ['taken' => false], $aufgaben),
        ];
    }

    /**
     * Die abgelegten Anhaenge in die NOTIZEN des Vorschlags einhaengen.
     *
     * @param list<array<string,mixed>> $aufgaben
     * @param list<array<string,mixed>> $abgelegt was wirklich abgelegt wurde
     * @return list<array<string,mixed>>
     */
    public static function AnhaengeEinhaengen(array $aufgaben, array $abgelegt): array
    {
        if ($abgelegt === []) {
            return $aufgaben;
        }
        foreach ($aufgaben as $k => $a) {
            if (!is_array($a) || (string)($a['kind'] ?? '') !== 'note') {
                continue;
            }
            /* Die Liste ist das Neue; „mediaId" bleibt daneben stehen, damit
               Vorschlaege aus der Zeit davor weiter uebernommen werden koennen —
               NotesAdopt liest beides. */
            $aufgaben[$k]['atts']    = $abgelegt;
            $aufgaben[$k]['mediaId'] = (int)$abgelegt[0]['id'];
        }
        return $aufgaben;
    }
}
