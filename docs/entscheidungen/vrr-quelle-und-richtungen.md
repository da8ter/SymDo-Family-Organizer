# VRR: Datenquelle und Richtungsfilter

Das Modul `SymDoVRRTransit` zeigt Abfahrten und Verbindungen aus der Fahrplanauskunft (EFA) des Verkehrsverbunds Rhein-Ruhr. Diese Datei hält fest, warum genau diese Quelle und dieser Zugang verwendet werden und warum der Richtungsfilter nicht auf der Tafel erscheint.

## Entscheidungen

- **Nur der VRR als Quelle, über den dokumentierten offenen Zugang** (`Efa::BASIS`, die Adresse mit `-test` im Namen; `1c80be8`, 16.09.2026). Drei VRR-Adressen antworten technisch gleich, mit derselben EFA-Fassung und auf alle sieben Abrufe des Moduls. Die Frage ist also nicht, welche man benutzen kann, sondern welche man benutzen darf. Laut Open-Data-Portal ist nur der Test-Zugang ohne Anmeldung offen, und zwar für Forschung, Test, Entwicklung und Hobby. Wer eine Anwendung öffentlich anbietet, braucht den Produktivzugang mit Registrierung. Zwischenzeitlich stand der Produktivname im Code, abgeleitet aus einer Messung statt aus der Doku. `1c80be8` hat das korrigiert.
- **Quellenangabe „Fahrplandaten: Verkehrsverbund Rhein-Ruhr (VRR)“ in Kachel, Formular und Handbuch** (`7a57143`), weil die Daten unter CC BY 4.0 stehen.
- **Andere Anbieter wurden geprüft und vorerst verworfen** (16.09.2026):
  - Fremde EFA-Instanzen anderer Verbünde antworten zwar auf dieselbe Anfrage. Ihre Echtzeitabdeckung schwankt aber stark, von vollständig bis null, und keine ist als offene Schnittstelle dokumentiert. Die Open-Data-Portale dieser Verbünde veröffentlichen GTFS-Datensätze, keine Live-API.
  - Transitous (MOTIS) deckt Deutschland aus einer Quelle ab und lieferte an einer Testhaltestelle mehr Echtzeit als die VRR-EFA. Es verlangt aber ein quelloffenes, nicht kommerzielles Projekt, sparsame Nutzung, einen User-Agent mit Kontakt und vorherige Rücksprache. Dem Repo fehlt dafür unter anderem eine LICENSE-Datei.
  - Ob das wieder aufgemacht wird, ist eine Entscheidung des Projekts und folgt nicht aus einem Messbefund.
- **Der Richtungsfilter wirkt unsichtbar, die Tafel zeigt ihn nicht an.** Kopfzeilen wie „Haltestelle → Ziel“ wurden ausdrücklich abgelehnt. Der Filter ist eine Einrichtungsfrage und keine Anzeige, und jede Kopfzeile müsste in Kachel, Web-App und iOS-App gleich gezogen werden. Umgesetzt ist er heute als Liste „Linien und Richtungen“ je Haltestelle: je Linie und Ziel ein Haken, und weg ist nur, was keinen Haken hat (`7ca36ca`). Die ursprüngliche Textspalte „Richtung“ (Ziel oder Steig, `38194fb`) ist damit abgelöst. Änderungen an der Tafel nur gemeinsam in Web-App und App.
- **Eigene Namen für Start und Ziel** (`fromName`, `toName`) überschreiben nur das erste `from` und das letzte `to` einer Verbindung. Auf dem Rückweg tauschen sie mit.
- **Die Haltestellensuche nimmt auch Adressen.** Ohne Haltestellentreffer sucht sie im Umkreis von 1200 m um die Koordinate der Straße (`XML_COORD_REQUEST`).

## Fallen

- **Ohne `coordOutputFormat=WGS84[dd.ddddd]` liefert die EFA Mercator-Koordinaten.** Mit dem Parameter kommen sie als [Breite, Länge].
- **Eine unbekannte Haltestelle antwortet mit HTTP 200 und einer leeren Liste.** Der Status allein taugt nicht als Prüfung.
- **Der Test-Zugang ist nicht zugesichert.** Der VRR testet dort selbst mit, zeitweise kann Unplausibles kommen. Deshalb gibt es kurze Fristen (20 s), einen erkennbaren User-Agent und einen zurückhaltenden Takt.
- **Die Spalte „Uhrzeit“ der Strecken ist ein `SelectTime`.** Die Konsole legt die Zelle als JSON-TEXT ab, nicht als Feld. 00:00 heißt „nicht gesetzt“. Alte Textwerte werden einmalig umgeschrieben. Der Nachtrag läuft über `RegisterOnceTimer`, weil `IPS_ApplyChanges` aus `ApplyChanges` heraus nicht wirkt.

## Offen

- Antrag auf den Produktivzugang beim VRR gestellt, Antwort ausstehend (nicht am Code prüfbar). Mit Zusage wird nur `Efa::BASIS` umgestellt.

Stand: geprüft gegen den Code am 08.10.2026
