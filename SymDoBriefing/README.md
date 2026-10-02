# SymDo - Briefing

![SymDo — Briefing](https://raw.githubusercontent.com/da8ter/images/main/SymDo%20Briefing.png)

**Das Tagesbriefing der SymDo-App als eigene Kachel für die Tile-Visualisierung — mit Wiedergabe.**

Morgens fasst das Briefing zusammen, was heute zählt: Termine, fällige Aufgaben, Schulzeiten, Einkauf und Geburtstage. Am Abend zeigt es die Vorschau auf morgen. Die Kachel zeigt nur diese Karte, im ganzen Text, mit dem Abspielknopf — passend für das Tablet im Flur oder in der Küche.

Einzurichten ist nichts: Das Briefing entsteht im **SymDo Gateway**, die Kachel findet es von selbst.

## Inhalt

- **1. Funktionsumfang**
- **2. Voraussetzungen**
- **3. Installation**
- **4. Konfiguration**
- **5. Abspielen aus Skripten**
- **6. Funktionsweise**

## 1. Funktionsumfang

- **Briefing des Tages** bzw. am Abend die **Vorschau auf morgen**, aktualisiert im Minutentakt
- **Vorlesen** per Knopf — mit der Stimme und dem Ton, die im Gateway eingestellt sind
- **Fernstart** aus Symcon: `SDBR_PlayBriefing(<InstanzID>)` spielt das Briefing in genau dieser Kachel ab, etwa morgens per Ereignis oder über einen Bewegungsmelder

## 2. Voraussetzungen

- Symcon ab Version **8.1**
- Nutzung in der **Kachel-Visualisierung** (Tile-Visualisierung)
- Eine **SymDo - Gateway**-Instanz mit eingeschaltetem Briefing; für das Vorlesen dort eine eingerichtete Sprachausgabe

## 3. Installation

1. Bibliothek über das Module Control installieren: `https://github.com/da8ter/SymDo-Family-Organizer.git`
2. Falls noch nicht vorhanden: eine Instanz **SymDo - Gateway** anlegen und das Briefing einrichten
3. Eine Instanz **SymDo - Briefing** anlegen und in der Kachel-Visualisierung einbinden

Der **SymDo - Installationsassistent** legt die Kachel auf Wunsch mit an.

## 4. Konfiguration

Die Instanz hat keine eigenen Einstellungen. Inhalt, Uhrzeit, Ton und Stimme des Briefings werden im Gateway eingestellt. Im Formular stehen das gefundene Gateway, ein Knopf **Briefing abspielen** zum Ausprobieren und **Kachel neu laden**.

## 5. Abspielen aus Skripten

```php
SDBR_PlayBriefing(12345); // Instanz-ID der Briefing-Kachel
```

Die Nachricht erreicht nur die Betrachter dieser einen Kachel. Zwei Dinge verspricht der Rückgabewert nicht: Die Kachel muss gerade offen sein, und ein Browser darf Ton ohne vorherige Berührung abweisen — dann zeigt die Kachel einen Hinweis, einmal antippen genügt.

## 6. Funktionsweise

Die Kachel hat **keine eigene Oberfläche**: `module.html` ist die Web-App aus **SymDo - Web App**, wortgleich übernommen (`tools/uebernahme-webapp.py`). Der Zustand der Kachel nennt nur die Übersicht und schaltet sie auf die Briefing-Karte um. Text und Tonschnipsel kommen über das Relay des Gateways — dieselbe Wiedergabe wie in der Web-App.
