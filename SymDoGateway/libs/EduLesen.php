<?php

declare(strict_types=1);

/**
 * Eine Klassenseite LESEN — vom HTML zur Karte, ohne Bestand.
 *
 * Diese Haelfte fasst nichts an, was einer Instanz gehoert: kein Attribut, kein
 * Medienobjekt, keine Sperre, keine Eigenschaft. Sie bekommt den Rumpf einer
 * Seite und gibt Karten zurueck. Genau deshalb kann sie in einer
 * SymDoScanner-Instanz laufen, waehrend das Gateway Hooks bedient — das Holen
 * einer Seite dauert eine halbe bis fuenfzehn Sekunden, und Symcon fuehrt je
 * Instanz genau eine Sache zur Zeit aus.
 *
 * Sie liegt trotzdem in `SymDoGateway/libs/` und nicht in `List/libs/`: sie ist
 * ein Trait, kein eigenstaendiger Rechenkern, und das Gateway braucht sie
 * weiterhin selbst — fuer den Rueckfallweg und fuer Moodle, das dieselbe
 * Weissliste benutzt. Vorbild ist `DokuGemein`.
 *
 * **`EduHtml()` ist die EINZIGE Stelle, die HTML fuer den Bestand saeubert.**
 * Das Feld `html` einer Karte landet ueber `/v1/edumaps` in einem `innerHTML`
 * der Web-App und der Kachel; dort steht ausdruecklich „hier wird nichts mehr
 * geprueft, weil hier nichts mehr geprueft werden kann", und einen CSP-Kopf
 * gibt es nicht. Wer auf einem neuen Weg `html` in den Bestand schreibt, muss
 * hier hindurch — sonst liefert er fremden Code in die App, mitsamt dem Token
 * der Sitzung im localStorage.
 */
trait EduLesen
{
    /** Laenger wird die formatierte Fassung nicht abgelegt — dann bleibt es beim
     *  Klartext, der ohnehin daneben steht. */
    private const EDU_HTML_MAX = 8000;

    /** Karten je Lauf — mehr ist ein Zeichen dafuer, dass etwas nicht stimmt. */
    private const EDU_KARTEN_MAX = 60;

    /**
     * Der Kartentext MIT seiner Formatierung — als sehr enge Auswahl an Tags.
     *
     * Der Klartext verliert, was die Karte ausmacht: die Klassenregeln sind auf
     * der Seite NUMMERIERT (`<ol class="list">`), Wichtiges steht fett. Nach
     * `strip_tags` stand dort eine Reihe gleichrangiger Zeilen.
     *
     * WEISSLISTE, kein Aufraeumen: erlaubt sind Absatz, Umbruch, Liste,
     * Aufzaehlung, fett, kursiv, unterstrichen und der Verweis. Alles andere
     * faellt weg, und von den Attributen ueberlebt nur `href` mit http, https
     * oder mailto. Das Ergebnis geht in der App durch `innerHTML` — deshalb
     * entscheidet DIESE Funktion ueber die Sicherheit, nicht der Browser.
     *
     * Die Dateizeilen fliegen raus: die Anhaenge stehen in der Karte ohnehin
     * darunter, mit Vorschau und Namen.
     */
    private function EduHtml(string $rumpf): string
    {
        if (!class_exists('DOMDocument')) {
            return '';
        }
        $doc = new \DOMDocument();
        // Ohne den Kopf haelt DOMDocument den Text fuer Latin-1 und macht aus
        // „für" ein „fÃ¼r". libxml meckert ueber jedes fremde Attribut — das
        // interessiert hier nicht, deshalb der Riegel davor.
        $vorher = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $rumpf . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        $wurzel = $doc->getElementsByTagName('div')->item(0);
        if ($wurzel === null) {
            return '';
        }
        $h = trim($this->EduHtmlKnoten($wurzel, false));
        $h = (string)preg_replace('#<p>(\s|&nbsp;|<br>)*</p>#i', '', $h);
        /* Edumaps haengt hinter jeden Verweis das Woertchen „LINK" und ein
           Icon-Element. Auf der Seite ist das Wort fuer den Screenreader da und
           per CSS versteckt; hier faellt das CSS weg, und es stand als nackter
           Text in der Notiz. Das Icon wiederum verliert in der Weissliste seine
           Klasse und blieb als leeres <i></i> uebrig — beides zusammen ergab
           „… </a> LINK". Also: das Wort raus, dem Icon seine Klasse zurueck.
           Am Bestand gemessen (06.09.2026): 10 von 10 Vorkommen folgen genau
           diesem Muster, keines steht ohne vorangehenden Verweis. Aendert
           Edumaps die Beschriftung, greift die Zeile nicht mehr — dann steht
           dort wieder das Wort, es geht aber nichts kaputt. */
        $h = (string)preg_replace(
            '#</a>\s*LINK\s*(?:<i>\s*</i>)?#u',
            '</a> <i class="fa-light fa-link"></i>',
            $h
        );
        // Uebrige leere Inline-Elemente sind ausgezogene Icons — sie zeigen
        // nichts und kosten nur Platz im Bestand.
        $h = (string)preg_replace('#<(i|b|u|em|strong)>\s*</\1>#u', '', $h);
        $h = trim((string)preg_replace('#\s+#u', ' ', $h));
        return mb_strlen($h) > self::EDU_HTML_MAX ? '' : $h;
    }

    /**
     * Ein Knoten und seine Kinder, auf die Weissliste reduziert.
     *
     * Die Zeilen der Seite (`li.itemline` in einer Huelle) sind KEINE
     * Aufzaehlung, sondern das Layout — sie werden zu Absaetzen. Eine echte
     * Liste erkennt man an `class="list"`; nur ihre `li` bleiben `li`. Ohne
     * diese Unterscheidung bekaeme jede Zeile der Karte einen Punkt.
     *
     * @param bool $inListe steht dieser Knoten in einer ECHTEN Liste?
     */
    private function EduHtmlKnoten(\DOMNode $knoten, bool $inListe): string
    {
        $raus = '';
        foreach ($knoten->childNodes as $kind) {
            if ($kind->nodeType === XML_TEXT_NODE) {
                $raus .= htmlspecialchars((string)$kind->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }
            if (!($kind instanceof \DOMElement)) {
                continue;
            }
            $tag = strtolower($kind->tagName);
            $klasse = strtolower((string)$kind->getAttribute('class'));
            // Dateiblöcke fallen ganz weg — sie stehen als Anhang unter der Karte.
            if (str_contains($kind->C14N(), '/file/')
                && ($tag === 'a' || str_contains($klasse, 'media') || str_contains($klasse, 'pdf'))) {
                continue;
            }
            switch ($tag) {
                case 'script': case 'style': case 'iframe': case 'img': case 'h3':
                    break;                                   // ohne Inhalt weiter
                case 'br':
                    $raus .= '<br>';
                    break;
                case 'b': case 'strong': case 'i': case 'em': case 'u':
                    $raus .= '<' . $tag . '>' . $this->EduHtmlKnoten($kind, $inListe) . '</' . $tag . '>';
                    break;
                case 'a':
                    $ziel = (string)$kind->getAttribute('href');
                    $inhalt = $this->EduHtmlKnoten($kind, $inListe);
                    $raus .= preg_match('#^(https?://|mailto:)#i', $ziel) === 1
                        ? '<a href="' . htmlspecialchars($ziel, ENT_QUOTES, 'UTF-8')
                          . '" target="_blank" rel="noopener noreferrer">' . $inhalt . '</a>'
                        : $inhalt;
                    break;
                case 'ol': case 'ul':
                    /* Nur die ECHTE Liste bleibt eine; die Zeilenhuelle nicht.
                       Die Klasse muss GENAU „list" heissen — mit str_contains
                       passte auch die Huelle („boxcontent-list"), und dann
                       bekam jede Zeile der Karte einen Punkt. */
                    if (preg_match('/(^|\s)list(\s|$)/', $klasse) === 1) {
                        $raus .= '<' . $tag . '>' . $this->EduHtmlKnoten($kind, true) . '</' . $tag . '>';
                    } else {
                        $raus .= $this->EduHtmlKnoten($kind, false);
                    }
                    break;
                case 'li':
                    $raus .= $inListe
                        ? '<li>' . $this->EduHtmlKnoten($kind, true) . '</li>'
                        : $this->EduHtmlKnoten($kind, false);
                    break;
                case 'p':
                    $raus .= '<p>' . $this->EduHtmlKnoten($kind, $inListe) . '</p>';
                    break;
                case 'div':
                    // Die Textzeile der Seite wird ein Absatz, jede andere Huelle
                    // reicht ihren Inhalt nur durch.
                    $raus .= str_contains($klasse, 'line-puretext')
                        ? '<p>' . $this->EduHtmlKnoten($kind, $inListe) . '</p>'
                        : $this->EduHtmlKnoten($kind, $inListe);
                    break;
                default:
                    $raus .= $this->EduHtmlKnoten($kind, $inListe);
            }
        }
        return $raus;
    }

    /** Bild oder PDF? Alles andere geht die KI nichts an. */
    private function EduArt(string $name): string
    {
        $endung = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if ($endung === 'pdf') {
            return 'pdf';
        }
        return in_array($endung, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) ? 'image' : '';
    }

    // ──────────────────────────────── Zerleger ────────────────────────────────

    /**
     * Die Karten einer Edumaps-Seite.
     *
     * Bewusst mit Ausdruecken statt DOM: die Seite ist gross, wir brauchen nur
     * fuenf Angaben je Karte, und die Anker sind eindeutig
     * (`data-boxid`, `data-updated`, `h3.boxlabel`, `.boxmaterial-wrap`).
     *
     * @return list<array{boxid:string,updated:int,abschnitt:string,titel:string,text:string,anhaenge:list<array{name:string,url:string}>}>
     */
    private function EduKarten(string $html): array
    {
        if ($html === '') {
            return [];
        }
        /* Abschnittsnamen stehen in der Spalte UEBER den Karten. Ihre Position im
           Text entscheidet, welche Karte dazugehoert. */
        /* Der Spaltenkopf traegt seine Farbe als Inline-Stil:
           <h2 class="pathhead" style="background:#FF851B;"><span class="pathlabel">…
           Sie kommt mit, damit die App die Abschnitte so faerben kann wie die
           Seite selbst — sonst waeren alle Bereiche gleich grau. */
        $spalten = [];
        if (preg_match_all('/<h2[^>]*class="pathhead"[^>]*>\s*<span class="pathlabel">(.*?)<\/span>/su',
                $html, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[1] as $k => $treffer) {
                $kopf = (string)$m[0][$k][0];
                $farbe = preg_match('/background:\s*(#[0-9A-Fa-f]{6})/', $kopf, $f) === 1 ? strtoupper($f[1]) : '';
                $spalten[] = ['pos' => (int)$treffer[1], 'name' => $this->EduText((string)$treffer[0]),
                              'farbe' => $farbe];
            }
        }
        $karten = [];
        $teile = preg_split('/(?=<div class="box-item")/s', $html);
        foreach ((array)$teile as $teil) {
            if (!is_string($teil) || !str_contains($teil, 'data-boxid=')) {
                continue;
            }
            if (preg_match('/data-boxid="(\d+)"/', $teil, $b) !== 1) {
                continue;
            }
            $boxid = $b[1];
            /* `data-updated` ist LEER, solange eine Karte seit dem Anlegen nicht
               angefasst wurde — auf der Probeseite bei drei von fuenfzehn
               (Klassenkasse, Klassenfahrt, Hausaufgabenbetreuung). Dann gilt
               das Anlegedatum. Ohne diesen Rueckfall trugen alle drei die
               gleiche 0 und waeren am Merker nicht auseinanderzuhalten. */
            $updated = 0;
            if (preg_match('/data-updated="(\d+)"/', $teil, $u) === 1) {
                $updated = (int)$u[1];
            } elseif (preg_match('/data-created="(\d+)"/', $teil, $c) === 1) {
                $updated = (int)$c[1];
            }
            $titel = preg_match('/<h3[^>]*class="[^"]*boxlabel[^"]*"[^>]*>(.*?)<\/h3>/su', $teil, $t) === 1
                ? $this->EduText($t[1]) : '';
            if ($titel === '') {
                continue;
            }
            // Der Rumpf endet an der Fusszeile der Karte; alles danach ist Beiwerk.
            $rumpf = $teil;
            $ende = strpos($rumpf, '<div class="box-meta-wrap"');
            if ($ende !== false) {
                $rumpf = substr($rumpf, 0, $ende);
            }
            /* Der Buchungskasten fliegt aus dem TEXT: „Buchen 12 / 16" stand
               sonst als erste Zeile der Notiz — und dieselbe Angabe steht
               darunter noch einmal als eigene Zeile (EduBuchung → booking).
               Die Zahlen holt EduBuchung aus den data-Attributen der Karte,
               nicht von hier; das Ausschneiden nimmt ihr also nichts weg. */
            $rumpf = $this->EduBlockRaus($rumpf, 'booking-wrap');
            $anhaenge = [];
            foreach ($this->EduDateien($rumpf) as $datei) {
                $anhaenge[] = $datei;
            }
            $pos = strpos($html, 'data-boxid="' . $boxid . '"');
            $abschnitt = '';
            $abschnittFarbe = '';
            foreach ($spalten as $sp) {
                if ($pos !== false && $sp['pos'] < $pos) {
                    $abschnitt = $sp['name'];
                    $abschnittFarbe = (string)$sp['farbe'];
                }
            }
            /* Auch die Karte selbst hat eine Farbe („boxlabel customcolor").
               Meist ist es die der Spalte, aber nicht immer — genommen wird die
               ERSTE im Kartenkopf, also vor dem Inhalt. */
            $kopfTeil = ($e = strpos($teil, 'boxcontent-wrap')) !== false ? substr($teil, 0, $e) : $teil;
            $eigeneFarbe = preg_match('/background:\s*(#[0-9A-Fa-f]{6})/', $kopfTeil, $ff) === 1
                ? strtoupper($ff[1]) : '';
            $karten[] = [
                'boxid'     => $boxid,
                'updated'   => $updated,
                'html'      => $this->EduHtml($rumpf),
                'abschnitt' => $abschnitt,
                'farbe'     => $eigeneFarbe !== '' ? $eigeneFarbe : $abschnittFarbe,
                'abschnittFarbe' => $abschnittFarbe,
                'titel'     => $titel,
                'text'      => $this->EduText($rumpf),
                'anhaenge'  => $anhaenge,
                'buchung'   => $this->EduBuchung($teil),
            ];
            if (count($karten) >= self::EDU_KARTEN_MAX) {
                break;
            }
        }
        return $karten;
    }

    /**
     * Ein `div` samt Inhalt aus dem Markup schneiden, erkannt an seiner Klasse.
     *
     * BEWUSST kein regulaerer Ausdruck: die Kaesten von Edumaps sind
     * verschachtelt, und `<div class="x">.*?</div>` schnitte am ERSTEN
     * schliessenden Tag ab — der Rest des Blocks bliebe stehen, und die Karte
     * verloere dazu ihr schliessendes Tag. Deshalb wird gezaehlt: von der
     * Fundstelle an jedes `<div` hoch, jedes `</div>` runter, und beim
     * Nullpunkt ist der Block zu Ende.
     */
    private function EduBlockRaus(string $html, string $klasse): string
    {
        while (preg_match('#<div[^>]*class="[^"]*\b' . preg_quote($klasse, '#') . '\b[^"]*"[^>]*>#i',
                $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $start = (int)$m[0][1];
            $pos   = $start + strlen((string)$m[0][0]);
            $tiefe = 1;
            $laenge = strlen($html);
            while ($tiefe > 0 && $pos < $laenge) {
                $auf = stripos($html, '<div', $pos);
                $zu  = stripos($html, '</div>', $pos);
                if ($zu === false) {
                    return $html;                 // unausgeglichen — lieber nichts anfassen
                }
                if ($auf !== false && $auf < $zu) {
                    $tiefe++;
                    $pos = $auf + 4;
                } else {
                    $tiefe--;
                    $pos = $zu + 6;
                }
            }
            if ($tiefe !== 0) {
                return $html;
            }
            $html = substr($html, 0, $start) . substr($html, $pos);
        }
        return $html;
    }

    /**
     * Die Buchungslage einer Karte — Plaetze, Preis, Zeit.
     *
     * Manche Karten sind buchbar (AG-Wahl, Elternsprechtag). Der „Buchen"-Knopf
     * der Seite ist ein <span> mit JavaScript dahinter, kein Verweis; buchen
     * kann man nur DORT. Die Zahlen dazu stehen aber offen im Markup, und genau
     * die sind beim Waehlen interessant: „12 von 16" sagt, dass es eng wird,
     * „16 von 16", dass man sich den Weg sparen kann.
     *
     * Uebernommen wird nur, was eine echte Buchung kennzeichnet: ein Limit > 0.
     * „Ansprechpartnerin" traegt zwar dieselben Attribute, aber alle auf 0 — das
     * ist keine buchbare Karte, sondern nur dasselbe Kastenformat.
     *
     * @return array{anzahl:int,limit:int,preis:float,zeit:string}|null
     */
    private function EduBuchung(string $teil): ?array
    {
        $zahl = static function (string $name) use ($teil): string {
            return preg_match('/data-book' . $name . '="([^"]*)"/', $teil, $m) === 1 ? trim($m[1]) : '';
        };
        $limit = (int)$zahl('limit');
        if ($limit <= 0) {
            return null;
        }
        return [
            'anzahl' => max(0, (int)$zahl('count')),
            'limit'  => $limit,
            'preis'  => (float)str_replace(',', '.', $zahl('price')),
            'zeit'   => mb_substr($zahl('time'), 0, 60),
        ];
    }

    /**
     * Die Dateien einer Karte. Edumaps bietet dieselbe Datei dreifach an
     * (Anzeige, `/preview`, `/fd`) — genommen wird die nackte Adresse, und jede
     * Datei nur EINMAL.
     *
     * @return list<array{name:string,url:string}>
     */
    private function EduDateien(string $rumpf): array
    {
        /* Der SICHTBARE Name steht nicht in der Adresse — die traegt nur eine
           Zahl —, sondern im Etikett unter der Vorschau:
           <span class="medialabel"><a href="…/file/<id>/<token>">Regeln.pdf</a></span>.
           Ohne ihn hiess die Datei in der App „2284251677130317565.pdf". */
        $namen = [];
        if (preg_match_all('#<span class="medialabel">\s*<a[^>]*/file/([^"\'/]+)/([a-z0-9]+)[^>]*>(.*?)</a>#su',
                $rumpf, $mn, PREG_SET_ORDER) > 0) {
            foreach ($mn as $t) {
                $klar = $this->EduText($t[3]);
                if ($klar !== '') {
                    $namen[$t[1] . '/' . $t[2]] = $klar;
                }
            }
        }
        $raus = [];
        $gesehen = [];
        /* Der Rumpf der Adresse wird MITGENOMMEN statt fest verdrahtet: edumaps
           laeuft je Bundesland auf einem eigenen Namen (nrw., hh., …). Mit fest
           „nrw." zeigten Datei- und Vorschauadresse anderswo ins Leere — und
           zwar lautlos, denn gefunden wurde die Datei ja. */
        if (preg_match_all('#(https://[^"\']+?)/file/([^"\'/]+)/([a-z0-9]+)(?:/(?:preview|fd))?#i',
                $rumpf, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $treffer) {
                $basis = rtrim((string)$treffer[1], '/');
                $name = urldecode((string)$treffer[2]);
                $schluessel = $name . '/' . $treffer[3];
                if (isset($gesehen[$schluessel])) {
                    continue;
                }
                $gesehen[$schluessel] = true;
                // „/fd" liefert die Datei mit Dateinamen; die nackte Adresse
                // liefert eine Ansichtsseite.
                $raus[] = [/* Anzeigename fuer den Menschen … */
                           'name' => $namen[$schluessel] ?? $name,
                           /* … und der technische aus der Adresse. NUR er traegt
                              die Endung: das Etikett der Seite kann jeder Text
                              sein („Einladung Pflegschaftssitzung 1. Hj"), und
                              wer daraus den Dateityp ableitet, haelt ein PDF
                              fuer nichts und laesst es weg. Genau so ist die
                              Einladung ohne Vorschau und ohne Namen geblieben. */
                           'datei' => $name,
                           'url'  => $basis . '/file/' . $treffer[2] . '/' . $treffer[3] . '/fd',
                           /* edumaps rendert von jedem PDF eine Seitenvorschau
                              und liefert sie unter „/preview" (gemessen:
                              180×255 JPEG, rund 11 KB). Selbst rendern koennten
                              wir sie nicht — dafuer fehlt in Symcon ein
                              PDF-Renderer. */
                           'preview' => $basis . '/file/' . $treffer[2] . '/' . $treffer[3] . '/preview'];
            }
        }
        return $raus;
    }

    /** Markup zu lesbarem Text: Umbrueche erhalten, Entities aufloesen. */
    private function EduText(string $html): string
    {
        $t = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/su', ' ', $html) ?? $html;
        $t = preg_replace('/<br\s*\/?>|<\/(p|div|li|tr|h[1-6])>/i', "\n", $t) ?? $t;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\xC2\xA0", ' ', $t);
        $t = preg_replace('/[ \t]+/u', ' ', $t) ?? $t;
        /* Dasselbe „LINK" wie in EduHtml(): auf der Seite eine per CSS
           versteckte Beschriftung fuer den Screenreader, im Klartext ein
           sinnloses Wort hinter jedem Verweis. Ein Icon gibt es hier nicht — es
           faellt ersatzlos weg.
           Geprueft wird die ZEILE, nicht die Nachbarschaft zum </a>: im
           Rohmarkup steht die Beschriftung in einer eigenen Huelle, das </a>
           ist also nicht der direkte Vorgaenger (in der bereinigten Fassung
           schon — daher die andere Regel dort). Nach dem Umbruch-Ersatz steht
           sie als eigene Zeile da. Eine Zeile, die nur aus diesem Wort besteht,
           traegt keine Aussage; ein „LINK" im Satz bleibt unberuehrt. */
        $t = preg_replace('/^[ \t]*LINK[ \t]*$/mu', '', $t) ?? $t;
        // Mehr als eine Leerzeile bringt nichts und kostet Tokens.
        $t = preg_replace('/\n\s*\n\s*\n+/u', "\n\n", $t) ?? $t;
        return trim($t);
    }

    /**
     * Verweise auf ANDERE Karten derselben Anlage.
     *
     * Eine Adresse hat vier Abschnitte („/176181/94492/qmz5o2s2ctwn/yfq8mzyd…").
     * Dateien (`/file/…`) und die eigene Seite bleiben draussen.
     *
     * @return list<string>
     */
    private function EduKartenLinks(string $html, string $eigene): array
    {
        // Jeder edumaps-Name, nicht nur „nrw." — jedes Bundesland hat seinen
        // eigenen, und eine Anlage aus Hamburg verwies sonst auf nichts.
        if (preg_match_all('#https://[a-z0-9.-]+\.edumaps\.de/(\d+)/(\d+)/([a-z0-9]+)/([a-z0-9]+)#i',
                $html, $m, PREG_SET_ORDER) === 0) {
            return [];
        }
        $raus = [];
        foreach ($m as $t) {
            $url = rtrim($t[0], '/');
            if ($url !== rtrim($eigene, '/') && !isset($raus[$url])) {
                $raus[$url] = true;
            }
        }
        return array_keys($raus);
    }
}
