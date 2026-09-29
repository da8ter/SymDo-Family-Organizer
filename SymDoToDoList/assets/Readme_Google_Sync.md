# Google Tasks Synchronisation – Einrichtungsanleitung

## Voraussetzungen

- Ein Google-Konto
- Zugang zur [Google Cloud Console](https://console.cloud.google.com/)
- **Symcon Connect** muss konfiguriert und erreichbar sein (für OAuth-Callback)

## Schritt 1: Google Cloud Projekt erstellen

1. Öffne die [Google Cloud Console](https://console.cloud.google.com/)
2. Oben links auf das Projekt-Dropdown klicken → **Neues Projekt**
3. Name z.B. `Symcon ToDoList` → **Erstellen**
4. Sicherstellen, dass das neue Projekt ausgewählt ist

## Schritt 2: Google Tasks API aktivieren

1. Navigiere zu **APIs & Dienste** → **Bibliothek**
2. Suche nach **Tasks API**
3. Klicke auf **Google Tasks API** → **Aktivieren**

## Schritt 3: OAuth-Zustimmungsbildschirm einrichten

1. Navigiere links im Menü zu **OAuth-Zustimmungsbildschirm**
2. Wähle **Erste Schritte**
3. Fülle aus:
   - **Anwendungsname**: z.B. `Symcon ToDoList`
   - **E-Mail**: Deine E-Mail-Adresse
   - Rest kann leer bleiben
4. **Weiter**
5. **Zielgruppe** → Extern
6. **Weiter**
7. **Kontaktdaten** → E-Mail-Adresse eintragen
8. **Weiter**
9. **Erstellen**
10. Links im Menü auf **Datenzugriff**
11. **Bereiche hinzufügen oder entfernen**
    - Suche nach `auth/tasks` und aktiviere `https://www.googleapis.com/auth/tasks`
    - **Aktualisieren** → **Save**
12. Bei **Zielgruppe** → **Testnutzer**: Deine Google-E-Mail-Adresse hinzufügen → **Speichern**

> Solange die App im Status **„Testen"** ist, können nur die eingetragenen Testnutzer autorisieren. Das ist für den Privatgebrauch völlig ausreichend.

## Schritt 4: OAuth-Client-ID erstellen

1. Navigiere zu **APIs & Dienste** → **Anmeldedaten**
2. Klicke **Anmeldedaten erstellen** → **OAuth-Client-ID**
3. **Anwendungstyp**: Webanwendung
4. **Name**: z.B. `Symcon`
5. Unter **Autorisierte Weiterleitungs-URIs** klicke **URI hinzufügen**:
   ```
   Die komplette Redirect URI findest du im ToDo Gateway unter Google Tasks → Redirect URI
   (Format: https://<dein-symcon-connect>/hook/todogateway_google)
   ```
6. **Erstellen** klicken
7. **Client-ID** und **Client-Secret** kopieren! (Wichtig! Kann nach dem Schließen des Fensters nicht mehr angezeigt werden)

## Schritt 5: ToDo Gateway konfigurieren

1. Öffne die **ToDo Gateway**-Instanz
2. Im Bereich **Google Tasks** eintragen:
   - **Client ID**: Die kopierte Client-ID aus Schritt 4
   - **Client Secret**: Das kopierte Client-Secret aus Schritt 4
3. Klicke auf **Übernehmen** (wichtig – erst danach werden die Buttons aktiv!)

## Schritt 6: Mit Google verbinden

1. Klicke im **ToDo Gateway** auf **„Mit Google verbinden"**
2. Eine URL wird angezeigt → im Browser öffnen
3. Google-Konto auswählen und anmelden
4. Berechtigungen bestätigen → **Zulassen**
5. Du siehst **„Authorization successful"** → Fenster schließen

## Schritt 7: ToDoList-Instanz konfigurieren

1. Öffne die **ToDoList-Instanz**
2. Wähle bei **Synchronisations-Backend** → **Google Tasks**
3. Klicke auf **„Aufgabenlisten suchen"**
4. Wähle die gewünschte Aufgabenliste aus dem Dropdown
5. Klicke auf **Übernehmen**

## Schritt 8: Synchronisation aktivieren

1. Wähle ein **Sync-Intervall** (z.B. 5 oder 15 Minuten)
2. Wähle den **Konfliktmodus** (Standard: „Neuester gewinnt")
3. Optional: **Automatisch nach Änderungen synchronisieren** aktivieren
4. Klicke auf **Übernehmen**

## Synchronisierte Felder

| Lokales Feld | Google Tasks | Hinweis |
|---|---|---|
| Titel | `title` | ✅ bidirektional |
| Info | `notes` | ✅ bidirektional |
| Erledigt | `status` | ✅ completed / needsAction |
| Fälligkeit | `due` | ⚠️ **Nur Datum**, keine Uhrzeit! |

**Nicht synchronisiert bzw. nicht durch die API unterstützt:** Priorität, Wiederholungen, Benachrichtigungen, Menge – diese werden nur lokal verwaltet.

> **Wichtig:** Google Tasks speichert Fälligkeiten nur als Datum (immer `T00:00:00Z`). Wenn du eine Uhrzeit in Symcon setzt, bleibt diese lokal erhalten, wird aber nicht an Google übertragen.

## PHP-Befehle

### ToDo Gateway (Prefix: TGW)

```php
TGW_GoogleGetAuthUrl($id);           // Autorisierungs-URL anzeigen
TGW_GoogleTestConnection($id);       // Verbindung testen
TGW_GoogleDisconnect($id);           // Verbindung trennen
```

### ToDoList (Prefix: TDL)

```php
TDL_GoogleRefreshTaskListOptions($id); // Aufgabenlisten aktualisieren
TDL_GoogleTasksSync($id);             // Manuell synchronisieren
TDL_GoogleResetSync($id);             // Sync-Marker zurücksetzen
```