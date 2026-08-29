# SymDo - Einkaufsliste

Dieses Modul stellt eine Einkaufsliste für die Tile-Visualisierung bereit.

- Artikel anlegen, bearbeiten, abhaken und löschen
- Suchvorschläge auf Basis häufig verwendeter Artikel
- Automatische Mengenerhöhung, wenn ein Artikel bereits auf der Liste steht
- Gruppierung nach Kategorien; Reihenfolge der Kategorien frei konfigurierbar
- Barcode-Scanner über die Gerätekamera mit automatischer Produkterkennung; alternativ Anbindung eines externen Scanners über eine Variable
- Favoritenlisten (z. B. Wocheneinkauf) — komplette Artikelgruppen mit einem Klick übernehmen
- Abgehakte Artikel wandern in den Bereich „Zuletzt benutzt" und lassen sich von dort wieder auf die Liste setzen
- Funktionen „Alles erledigt" und „Benutzte Artikel löschen" für die ganze Liste
- Optionale Produktbilder, im Backend abschaltbar
- Druckfunktion mit drei Layouts (kompakt bis detailliert mit Bildern und Notizen)
- Artikelanzahl als Variable für eigene Automationen

## Installation

1. Bibliothek über das Module Control installieren:
   - `https://github.com/da8ter/SymDo-Family-Organizer.git`
2. In Symcon eine Instanz vom Typ **SymDo - Einkaufsliste** anlegen
3. Instanz in der Kachel-Visualisierung einbinden
4. Optional im Backend Kategorien, Suchvorschläge und Favoritenlisten konfigurieren

## Funktionsumfang

- **Einkaufsliste** – Artikel anlegen, bearbeiten, löschen, abhaken
- **Suchvorschläge** – Häufig verwendete Artikel werden automatisch vorgeschlagen
- **Favoritenlisten** – Mehrere Favoritenlisten mit Artikeln verwalten, schnell zur Einkaufsliste hinzufügen
- **Kategorien** – Artikel nach Kategorien sortieren, Kategorie-Reihenfolge im Backend konfigurierbar; Artikel innerhalb einer Kategorie werden alphabetisch sortiert
- **Produktfotos** – Optionale generische Produktbilder in der Einkaufsliste, Suchvorschlägen und Favoritenlisten; im Backend deaktivierbar
- **Barcode-Scanner** – Live-Kamera-Scan mit animierter Scanline und Trefferanzeige direkt über dem Kamerabild; Produktabgleich über Open Food Facts und OpenGTINDB
- **Externe Einkaufslisten** – Zwei-Wege-Abgleich mit der **Amazon-Alexa**-Einkaufsliste („Alexa, setze Milch auf die Einkaufsliste“) und mit **Bring!**; beide gleichzeitig möglich, dann spiegeln sich alle drei Listen
- **Drucken** – Einkaufsliste drucken in drei Layouts: Ultra Kompakt (3 Spalten, ohne Kategorien), Kompakt (Checkliste mit Kategorien) und Detailliert (mit Bildern und Notizen). Auf iOS/Android in der Symcon App aus technischen Gründen nicht verfügbar.
## Bedienung

### Artikel hinzufügen

1. In das Suchfeld tippen — passende Vorschläge erscheinen automatisch
2. Auf einen Vorschlag tippen oder den Namen eingeben und mit Enter bestätigen
3. Wird ein bereits vorhandener Artikel erneut hinzugefügt, erhöht sich dessen Menge automatisch

### Artikel bearbeiten

- Auf das Stifticon eines Artikels in der Liste tippen, um das Bearbeitungs-Fenster zu öffnen
- Hier können **Name**, **Menge**, **Notiz** und **Kategorie** geändert werden

### Artikel abhaken / entfernen

- Auf den Artikel tippen, um ihn in den Bereich „Zuletzt benutzt" zu verschieben
- Einen Artikel **nach links wischen**, um ihn direkt aus der Liste zu löschen — beim Wischen erscheint eine rote Lösch-Fläche mit Mülleimer-Symbol
- Über **„Alles erledigt"** werden alle offenen Artikel auf einmal in „Zuletzt benutzt" verschoben
- Im Bereich „Zuletzt benutzt" erneut tippen, um ihn wieder auf die aktive Liste zu setzen
- Über **„Benutzte Artikel löschen"** werden alle abgehakten Artikel entfernt

### Favoritenlisten

Favoritenlisten ermöglichen es, häufig benötigte Artikelgruppen (z. B. „Wocheneinkauf", „Grillabend") zu speichern und mit einem Klick zur Einkaufsliste hinzuzufügen.

**Liste erstellen:**
1. Auf das **Herz-Symbol** in der unteren Navigation tippen
2. Auf **+** tippen und einen Listennamen eingeben

**Artikel in Favoritenliste bearbeiten (Backend):**
1. In der Instanzkonfiguration im Bereich **Favoritenlisten-Artikel bearbeiten** die gewünschte Liste auswählen
2. Artikel hinzufügen, bearbeiten oder löschen
3. Die erste Favoritenliste ist direkt vorausgewählt; wenn noch keine Liste existiert, wird **„Keine Listen vorhanden“** angezeigt

**Artikel zur Favoritenliste hinzufügen:**
1. In der Suchvorschläge-Ansicht auf das **Herz** neben einem Artikel tippen
2. Im Bearbeitungs-Fenster die gewünschte Favoritenliste auswählen und speichern

**Favoritenliste zur Einkaufsliste hinzufügen:**
1. Herz-Symbol in der Navigation antippen
2. Eine Liste auswählen
3. Auf **„Artikel zur Einkaufsliste hinzufügen"** tippen — alle Artikel der Liste werden zur Einkaufsliste hinzugefügt. Bei bereits vorhandenen Artikeln erhöht sich deren Menge automatisch.

**Favoritenliste verwalten:**
- Liste umbenennen über das Stifticon in der Listenansicht
- Liste löschen über das Mülleimer-Icon in der Detailansicht
- Einzelne Favoritenartikel in der Detailansicht bearbeiten (Stift) oder entfernen (Herz)
- In der Detailansicht können neue Favoritenartikel direkt über das Suchfeld (Enter oder **+**) hinzugefügt werden

### Drucken (nur Desktop)

1. Auf das **Drucker-Symbol** in der unteren Navigation tippen
2. Zwischen drei Layouts wählen:
   - **Ultra Kompakt** — 3 Spalten, ohne Kategorien
   - **Kompakt** — Checkliste mit Kategorien zum Abhaken
   - **Detailliert** — Mit Produktbildern und Notizen
3. Der Druckdialog des Browsers öffnet sich automatisch

### Barcode scannen

1. In der Suchzeile auf das **Barcode-Symbol** tippen
2. Den Barcode vor die Kamera halten
3. Der erkannte Artikel wird als gut lesbares, zentriertes Overlay mittig im Kamerabild angezeigt, nach 3 Sekunden automatisch ausgeblendet und zur Liste hinzugefügt
4. Die Duplikatprüfung berücksichtigt nur aktive Listenartikel; Artikel in „Zuletzt benutzt“ gelten nicht als Duplikat

Alternativ bzw. zusätzlich kann eine String-Variable als externe Scanner-Quelle ausgewählt werden. Sobald diese Variable mit einer gültigen EAN (8–14 Ziffern) aktualisiert wird, verarbeitet das Modul den Barcode automatisch und fügt den gefundenen Artikel zur Einkaufsliste hinzu.

## Konfiguration

| Option | Beschreibung |
|---|---|
| **Kategorie-Reihenfolge** | Kategorien hinzufügen, entfernen und umsortieren |
| **Produktbilder anzeigen** | Checkbox zum Aktivieren/Deaktivieren der Produktbilder |
| **Suchvorschläge** | Liste der Artikel mit Kategorie-Zuordnung pflegen |
| **Favoritenlisten** | Favoritenlisten und deren Artikel verwalten |
| **Externe Scanner-Variable** | Optionale String-Variable, deren Wert als Barcode/EAN verarbeitet wird |

**Lookup-Reihenfolge:** Externer Produkt-API → Open Food Facts → OpenGTINDB.

### Externe Einkaufslisten (Amazon Alexa, Bring)

Per Sprache auf die Liste setzen — „Alexa, setze Milch auf die Einkaufsliste" —
und die Liste bleibt in beide Richtungen gleich: was gesprochen wird, erscheint
hier; was hier entsteht, erscheint bei Alexa, sodass „Alexa, was steht auf
meiner Einkaufsliste?" vollständig vorliest. Abhaken wirkt auf beiden Seiten.

Zwei getrennte Auswahlfelder für **Amazon Alexa** und **Bring!** dürfen
gleichzeitig belegt sein. Dann spiegeln sich alle drei Listen gegenseitig: ein bei
Alexa gesprochener Artikel landet auch bei Bring, und umgekehrt.

**Was installiert und angelegt werden muss** — getrennt nach Dienst:

*ALEXA* (Sprachliste eines Amazon-Kontos)

1. Bibliothek „Echo Remote" über das Module Control: `https://github.com/roastedelectrons/IPSymconEchoRemote`
2. Instanz **Echo IO** — meldet sich am Amazon-Konto an (einmal für alles)
3. Instanz **AlexaList** — eine **je Amazon-Liste**: die Einkaufsliste und die Aufgabenliste brauchen **getrennte Instanzen**
4. Dort das Aktualisierungsintervall auf **2 Minuten** stellen (Vorgabe ist 60) — dieser Takt entscheidet, wie schnell Gesprochenes ankommt
5. **Nicht kürzer.** Das Echo-Modul drosselt seinen eigenen Aktivitätsabruf auf **60 Anfragen pro Stunde** (`CheckRateLimit`, `Echo IO/module.php:1386`), und die Anfragen zählen **je Amazon-Konto**, nicht je Instanz. Bei 1 Minute ist dieses Budget schon von einer Instanz aufgebraucht, und unsere Abgleiche kommen obendrauf. Der Listen-Abruf selbst ist im Fremdmodul **gar nicht** gedrosselt — dort landet ein `429` nur im Protokoll. Wer zwei AlexaList-Instanzen betreibt (Einkaufen + Aufgaben): **je 3 Minuten**

*BRING* (die Einkaufs-App Bring!, **kein** Sprachassistent)

1. Bibliothek „Bring!" über das Module Control: `https://github.com/Nall-chan/bring-symcon`
2. Instanz **Bring! Konto** — fragt E-Mail und Passwort des Bring-Kontos ab
3. Instanz **Bring List** — eine je Bring-Liste, dort die Liste auswählen
4. In dieser Instanz **„Textbox-Variable erstellen" einschalten**: diese Variable ist unser Auslöser, und das Modul beschreibt sie nur, wenn der Schalter an ist
5. Das Aktualisierungsintervall dort setzen — das sind **Sekunden** (60–120 ist sinnvoll), anders als bei Alexa

**Einrichtung:** Im Bereich *Externe Einkaufslisten* den Abgleich einschalten und
darunter die Instanz im jeweiligen Unterbereich wählen — *Amazon Alexa
Einkaufsliste*, *Bring Einkaufsliste* oder beide. Jeder Unterbereich trägt den
Hinweis, was dafür zu installieren ist.

**Grenzen, ehrlich benannt:**

- Der Verzug ist der Takt des Fremdmoduls. Sofort geht nur über den Knopf.
- Beide Schnittstellen sind **inoffiziell** und können ohne Ankündigung wegfallen.
- Alexa dedupliziert nicht: „Milch" und „3 Milch" stehen dort gleichzeitig. Bei uns
  ist das **ein** Artikel — die Zeile trägt dann beide Alexa-Kennungen, und Abhaken
  oder Löschen wirkt auf beide Einträge.
- Bring kennt kein Abhaken und kein Löschen: ein hier abgehakter oder gelöschter
  Artikel wird dort nach „kürzlich gekauft" umgebucht. Das ist Brings eigener Weg
  (sein Modul macht es genauso) und bedeutet dasselbe. Umgekehrt heißt das: „dort
  gekauft" und „dort gelöscht" sind bei Bring nicht zu unterscheiden.
- **Abhaken und Löschen wirken verschieden:** dort abgehakt → hier erledigt
  (bei Einkäufen: in den Wagen), dort **gelöscht** → hier ebenfalls gelöscht.
  Gelöscht wird nur bei nachweislich vollständiger Antwort, siehe die
  100-Einträge-Grenze unten.
- Steht in der Alexa-Instanz *Lösche erledigte Einträge* auf an, ist „abgehakt"
  von „gelöscht" nicht mehr unterscheidbar — dann wird ein abgehakter Eintrag
  hier gelöscht statt erledigt.
- Ein fehlender Eintrag gilt nur dann als „von der Liste genommen", wenn die
  Antwort vollständig war. Alexa liefert höchstens 100 Einträge ohne Hinweis auf
  weitere — bei einer längeren Liste wird deshalb nichts abgehakt.
- Hier gelöschte Einträge werden an der externen Liste entfernt. Erkannt wird das
  am Vergleich mit dem letzten Lauf — läuft der Abgleich nie, bleibt die Löschung
  dort stehen.
- Ist die Liste gerade nicht lesbar (Netz, Anmeldung), wird **nichts** geändert.
- Neue Einstellungen brauchen einen **Kernel-Neustart**, bevor sie sich
  speichern lassen.

**Mengen** kommen wie bei Bring als freier Text an: eine führende Zahl mit
optionaler Einheit wird vom Namen getrennt — „3 Milch" wird *Milch* mit Menge 3,
„2 Liter Milch" wird *Milch* mit Menge „2 Liter". Erkannt werden nur bekannte
Einheiten; „Cola 2 Liter" bleibt unangetastet. Steht der Artikel schon offen auf
der Liste, **gewinnt die gesprochene Zahl** (aus Menge 1 wird Menge 3) — ohne
gesprochene Zahl bleibt es beim gewohnten Erhöhen um 1. Die Aufteilung ist
abschaltbar.

## Produktfotos

- Artikel ohne passendes Bild zeigen den Anfangsbuchstaben als Platzhalter
- Die Anzeige der Bilder kann im Backend über die Checkbox „Produktbilder anzeigen" deaktiviert werden

## Statusvariablen

| Variable | Beschreibung |
|---|---|
| **ItemCount** | Anzahl der Artikel auf der Einkaufsliste |
| **LastUsed** | Anzahl der Artikel im Bereich „Zuletzt benutzt" |

## PHP-Befehlsreferenz

Alle Funktionen erwarten als ersten Wert die Objekt-ID der Instanz.

| Funktion | Rückgabe | Wofür |
|---|---|---|
| `SL_AddItem($id, $Name, $Category, $Amount)` | `bool` | Artikel auf die Liste setzen |
| `SL_RemoveItem($id, $Name)` | `bool` | Artikel über seinen **Namen** entfernen |
| `SL_DeleteItem($id, $Id)` | `bool` | Artikel über seine **ID** entfernen |
| `SL_ClearCart($id)` | `void` | alle abgehakten Artikel löschen |
| `SL_GetItems($id)` | `string` | die komplette Liste als JSON |
| `SL_GetSuggestions($id)` | `string` | Vorschläge als JSON, nach Häufigkeit sortiert |
| `SL_GetPurchaseHistory($id)` | `string` | was wie oft gekauft wurde, als JSON |

Drei Eigenheiten, die man beim Skripten kennen sollte:

- **`SL_AddItem` legt keinen zweiten Eintrag an**, wenn derselbe Name schon
  offen auf der Liste steht — statt dessen wird die **Menge erhöht**. Genau wie
  beim Tippen in der Oberfläche.
- **Die Kategorie darf leer bleiben.** Dann sucht das Modul sie selbst
  (Artikelkatalog und gelernte Zuordnungen). Nur wenn du sie ausdrücklich
  setzt, gewinnt dein Wert.
- **`false` heißt nicht immer „Fehler".** Es kommt bei leerem Namen — und wenn
  gerade jemand anderes schreibt und die Sperre nach 500 ms nicht frei wird. Im
  zweiten Fall steht eine Warnung im Meldungsprotokoll.

Ein Artikel aus `SL_GetItems` sieht so aus:

| Feld | Typ | Bedeutung |
|---|---|---|
| `id` | string | eindeutige Kennung, für `SL_DeleteItem` |
| `name` | string | Artikelname |
| `category` | string | Kategorie (gesetzt oder selbst ermittelt) |
| `amount` | string | Menge als Text — `2`, aber auch `500 g` |
| `notes` | string | Notiz zum Artikel |
| `inCart` | bool | `true` = abgehakt, liegt im Wagen |
| `addedAt` | int | Unix-Zeitstempel des Hinzufügens |
| `boughtAt` | int | Zeitpunkt des Abhakens (erst danach vorhanden — mit `?? 0` lesen) |

### Beispiel: Wochenendeinkauf aus einem Skript füllen

```php
<?php
// >>> HIER ANPASSEN <<<
$listeID = 12345;   // Objekt-ID der SymDo - Einkaufsliste

// Kategorie leer lassen — die Liste ordnet selbst ein.
$einkauf = [
    ['Milch',      '', '2'],
    ['Butter',     '', '1'],
    ['Mehl',       '', '500 g'],
    ['Kaffee',     'Getränke', '1'],   // Kategorie ausdrücklich gesetzt
];

$gesetzt = 0;
foreach ($einkauf as [$name, $kategorie, $menge]) {
    if (SL_AddItem($listeID, $name, $kategorie, $menge)) {
        $gesetzt++;
    } else {
        IPS_LogMessage('Einkauf', $name . ' konnte nicht gesetzt werden');
    }
}
IPS_LogMessage('Einkauf', "$gesetzt von " . count($einkauf) . ' Artikeln gesetzt');
```

### Beispiel: abgehakte Artikel aufräumen und melden, was noch fehlt

```php
<?php
$listeID = 12345;

// Erst zählen, dann räumen: nach ClearCart sind die abgehakten weg.
$artikel   = json_decode(SL_GetItems($listeID), true) ?: [];
$erledigt  = array_filter($artikel, fn($a) => !empty($a['inCart']));
$offen     = array_filter($artikel, fn($a) => empty($a['inCart']));

SL_ClearCart($listeID);

$namen = implode(', ', array_column($offen, 'name'));
IPS_LogMessage('Einkauf', count($erledigt) . ' erledigt, es fehlen noch: '
    . ($namen !== '' ? $namen : 'nichts'));
```

### Beispiel: Vorratsartikel nachlegen, der seit vier Wochen nicht gekauft wurde

```php
<?php
$listeID = 12345;

$historie = json_decode(SL_GetPurchaseHistory($listeID), true) ?: [];
$vorrat   = ['Kaffee', 'Spülmaschinentabs', 'Katzenfutter'];

foreach ($historie as $eintrag) {
    if (!in_array($eintrag['name'], $vorrat, true)) {
        continue;
    }
    // 'last' ist der Zeitpunkt des letzten Kaufs, 'count' die Zahl der Käufe.
    if (time() - (int)$eintrag['last'] > 28 * 86400) {
        SL_AddItem($listeID, $eintrag['name'], (string)($eintrag['category'] ?? ''),
                   (string)($eintrag['amount'] ?? '1'));
        IPS_LogMessage('Einkauf', $eintrag['name'] . ' seit vier Wochen nicht gekauft — nachgelegt');
    }
}
```

> Die Favoriten-Funktionen (`SL_LoadFavoriteItems`, `SL_SaveFavoriteItems`)
> bedienen das Konfigurationsformular und erwarten dessen JSON-Aufbau. Für
> eigene Skripte sind sie nicht gedacht.
