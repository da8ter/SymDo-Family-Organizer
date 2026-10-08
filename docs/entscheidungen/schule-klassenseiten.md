# Schule: Klassenseiten (Edumaps)

Das Gateway liest Klassenseiten von Edumaps (und als zweite Quelle LOGINEO-Kurse) und legt sie in einem eigenen Bestand ab; geänderte Karten gehen optional durch die KI und werden zu Vorschlägen. Code: `SymDoGateway/libs/EduMaps.php` (Lauf, Ordner, Push), `EduLesen.php` (Parsen), `EduEinpflegen.php`, `EduStore.php` + `EduStoreCalc.php` (Bestand, Endpunkt `/edumaps`), Kachel-Modul `SymDoEdumaps` (Präfix `SDEM`).

## Begriffe (verbindlich)

**Klassenseite** = die Seite (bei Edumaps eine „Map"), **Karte** = ein Kasten darauf, **Edumaps** = nur der Anbietername. Früher hießen beide „Karte", und zwei fast gleich lautende Knöpfe taten Verschiedenes. Anzeigename des Moduls: „SymDo - Klassenseiten" (nur `locale.json`; `name`, Klasse, GUID und Präfix bleiben `SymDo Edumaps`/`SDEM`).

## Entscheidungen

- **Zwei Wege nebeneinander:** Spiegel (`EduToNotes`, „Karten in SymDo spiegeln") legt jede Karte 1:1 ab – Text, Bilder, PDFs mit Vorschau –, kostet nichts und läuft für jede Karte. Auswertung schickt geänderte Karten durch dieselbe Kette wie eine Schulmail (`MailAnalyseRecord`) und erzeugt Vorschläge; gedeckelt je Lauf (`EDU_JE_LAUF_MAX`) und durch das Tageslimit. Der erste Lauf vermerkt nur.
- **Verlinkte Seiten werden gespiegelt UND ausgewertet** (seit `6f8467a`, 10.09.2026), aber nur eine Ebene tief: von einer verlinkten Seite wird kein Verweis weiterverfolgt. Vorher wurden sie als „Nachschlagewerke" nur gespiegelt – das traf auf eine Grammatikseite zu, auf Elternbrief-Seiten daneben nicht. Wer den Schalter „Verlinkten Seiten folgen" setzt, will sie ausgewertet haben.
- **Eigener Bestand statt Untermieter der Notizen** (Attribut `EduStore`, eigene Sperre, Endpunkt `/edumaps` mit demselben Draht-Vertrag wie `/notes`, aber nur `list/get/attachData/folderRename/folderDelete/noteDelete`, sonst `read_only`). Zwei Ordnerebenen: Mitglied → Seite.
- **Ordner hängen an Schlüsseln, nicht an Namen:** Ebene 1 an der Mitgliedskennung (`eduKey = edu:<userId>`), Ebene 2 an der Adresse (`edupage:<md5(url)>`). Der Nutzer darf beide umbenennen.
- **Eine Seite darf das Mitglied wechseln:** wird sie in der Konfiguration einem anderen Mitglied zugeordnet, zieht der Seitenordner um; der alte Mitgliedsordner fällt nur weg, wenn er leer ist, einen eigenen `edu:`-Schlüssel trägt und nicht gesperrt ist. Ohne das blieben die Karten beim alten Mitglied und wären im Kindmodus unerreichbar. Verlinkte Seiten erben ihr Mitglied bei jedem Lesen frisch von der Herkunftsseite (`von` im Fundeintrag).
- **Weggefallene Karten gehen ins Archiv**, nichts wird von selbst gelöscht.
- **Medien-Aufräumer kennt beide Bestände:** `NotesLiveMediaIds()` vereinigt Notizen, Mail-Vorschläge und Karten; die Wache hat ein drittes Glied (`EduStorable()`), und der Aufräumer nimmt beide Sperren in fester Ordnung **Notizen → Klassenseiten**. Grund: `EduApplyChanges()` (Umzug) läuft in `module.php` vor `NotesApplyChanges()` (Aufräumer); ein unwissender Aufräumer hätte alle Kartenanhänge als Waisen gelöscht.
- **Datei-Route bleibt `/notes/media/<id>` für beide Bestände:** die Anhänge liegen in derselben Medienkategorie, und die Adresse ist in Web-App und iOS fest verdrahtet.
- **Umzug aus den Notizen:** eine Karte, in deren Ordner etwas Handgeschriebenes lag, bleibt als Notiz stehen – lieber ein übriger Ordner als eine unerreichbare Karte. Deshalb bleibt in `Notes.php` das Löschen von `html` beim Bearbeiten stehen.
- **Push:** eine Sammelmeldung je Durchgang und Mitglied (`EduPush`; aktualisierte Karten + wartende Vorschläge), nicht eine je Karte. Sie wird im Attribut `EduPushOffen` gesammelt, weil jeder fertige Scanner-Auftrag in einem eigenen `RequestAction` (eigenes Objekt) ankommt. Titel nennt die Seite, solange nur eine beteiligt war.
- **Aufbewahrung der Vorschläge zählt ab der Auswertung** (`created`, `54005ba`), nicht ab dem Dokumentdatum – sonst wäre ein Vorschlag aus einer älteren Karte im Moment seiner Entstehung schon zu alt.
- **Die Adresse einer Klassenseite wirkt wie ein Kennwort** (Zugang steckt im Link): sie steht in einer Eigenschaft, nie im Quelltext oder Protokoll.

## Fallen / gemessen

- Die Seite ist server-gerendertes HTML; jede Karte trägt `data-boxid` und `data-updated` – Änderungserkennung ohne Textvergleich. Kein ETag/Last-Modified.
- **Dateityp aus der Adresse, nicht aus dem Anzeigenamen:** das Etikett kann jeder Text sein („Einladung … 1. Hj" ohne Endung) – daraus abgeleitet fiel ein PDF aus der Anhangsliste. Der Datensatz führt `name` (Mensch) und `datei` (Technik).
- Der **Klarname** steht in `<span class="medialabel">`, nicht in der Adresse (die trägt nur eine Zahl).
- Der **Rumpf der Adresse** wird übernommen, nicht fest verdrahtet: Edumaps läuft je Bundesland unter eigenem Hostnamen.
- **PDF-Seitenvorschau** liefert Edumaps unter `…/preview` (180×255 JPEG, ~11 KB). Sie hängt AM Anhang (`thumb`), nicht daneben – sonst ist der Deckel von 5 Anhängen je Notiz nach zwei PDFs erreicht. Die Liste der lebenden Medien muss die `thumb`s mitzählen, sonst räumt der Aufräumer sie ab.
- **Formatierung über DOMDocument** (`EduHtml`), nicht über Ausdrücke: die Zeilen stecken in `<li class="itemline">` – das ist Layout, keine Aufzählung. Echte Listen erkennt man an `class="list"`, als ganzes Wort (`str_contains` traf auch „boxcontent-list"). `EduHtml` ist die einzige Weißliste für `html` im Bestand.
- **Farben:** Spaltenkopf `<h2 class="pathhead" style="background:#…">`, Karten eigene im Kopf; Schriftfarbe nach Helligkeit.
- Karten, deren ganzer Inhalt eine Bildunterschrift ist, liefern kein HTML – dort greift der Klartext.
- **Web-App:** Bestandswechsel über `notesB()` am aktiven Bereich, nicht über einen durchgereichten Parameter (ein Klick-Handler weiß nicht mehr, aus welchem Aufruf er stammt). Der Endpunkt nachzuladender Bilder hängt am Bild (`data-quelle`), weil Warteschlange und Zwischenspeicher gemeinsam sind. Die Anhangsart heißt `image`, nicht `jpg` (eine Fixture mit `jpg` zeichnet eine Zeile statt eines Bildes). Aktuelle Karten laden `eager` – ein `lazy`-Bild in einem zugeklappten Bereich lädt erst beim Aufklappen; das Archiv lädt `lazy`.
- Ein neu registriertes Attribut entstand im Test schon nach dem Neuladen des Moduls, nicht erst beim Kernelstart. (nicht am Code prüfbar)

## Offen

- Der Kommentar an `EduFollowLinks` in `EduCreate()` sagt noch „nur gespiegelt, nie ausgewertet"; maßgeblich ist der Lauf (siehe oben).

Stand: geprüft gegen den Code am 08.10.2026
