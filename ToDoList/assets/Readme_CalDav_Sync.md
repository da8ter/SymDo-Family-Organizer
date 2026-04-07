# CalDAV Synchronisation – Einrichtungsanleitung

## Voraussetzungen

- Ein CalDAV-kompatibler Server (z.B. Nextcloud, ownCloud, Synology)
- Zugangsdaten (Benutzername + Passwort)
- Ein Aufgaben-Kalender (VTODO-fähig)

## Schritt 1: ToDo Gateway konfigurieren

Die CalDAV-Zugangsdaten werden zentral im **ToDo Gateway** verwaltet.

1. Öffne die **ToDo Gateway**-Instanz
2. Im Bereich **CalDAV** eintragen:
   - **Server URL**: Die Basis-URL des CalDAV-Servers (siehe Tabelle unten)
   - **Benutzername**: CalDAV-Benutzername
   - **Passwort**: CalDAV-Passwort
3. Klicke auf **Übernehmen**

### Server-URL Beispiele

| Server | URL |
|--------|-----|
| Nextcloud | `https://cloud.example.com/remote.php/dav` |
| ownCloud | `https://cloud.example.com/remote.php/dav` |
| Synology | `https://nas.example.com:5001/caldav` |
| Baikal | `https://baikal.example.com/dav.php` |
| iCloud | `https://caldav.icloud.com` |

> **Wichtig:** Die Server URL ist nur der **DAV-Endpunkt**, nicht der vollständige Kalender-Pfad.

## Schritt 2: Verbindung testen

Klicke im **ToDo Gateway** auf **„Verbindung testen"**:

- ✅ **„Connection successful"** → weiter mit Schritt 3
- ❌ **„Authentication failed"** → Benutzername/Passwort prüfen
- ❌ **„Connection failed"** → Server-URL prüfen

## Schritt 3: ToDoList-Instanz konfigurieren

1. Öffne die **ToDoList-Instanz**
2. Wähle bei **Synchronisations-Backend** → **CalDAV**
3. Klicke auf **„Kalender suchen"** – die verfügbaren Kalender werden im Dropdown angezeigt
4. Wähle einen **VTODO**-fähigen Kalender aus dem Dropdown
5. Klicke auf **Übernehmen**

> Kalender mit **(VTODO)** im Namen unterstützen Aufgaben. Kalender mit **(Events only)** enthalten nur Termine und sind nicht für die Aufgaben-Synchronisation geeignet.

## Schritt 4: Sync aktivieren

1. Wähle ein **Sync-Intervall** (z.B. 5 oder 15 Minuten)
2. Wähle den **Konfliktmodus**:
   - **Server gewinnt** – Server-Daten überschreiben lokale Änderungen
   - **Lokal gewinnt** – Lokale Daten überschreiben Server-Änderungen
   - **Neuester gewinnt** – Die zuletzt geänderte Version wird übernommen
3. Optional: **Auto sync after changes** aktivieren
4. Klicke auf **Übernehmen**

## Synchronisierte Felder

| Lokales Feld | CalDAV (VTODO) | Hinweis |
|---|---|---|
| Titel | `SUMMARY` | ✅ bidirektional |
| Info | `DESCRIPTION` | ✅ bidirektional |
| Erledigt | `STATUS` | ✅ COMPLETED / NEEDS-ACTION |
| Fälligkeit | `DUE` | ✅ Datum + Uhrzeit (UTC) |
| Priorität | `PRIORITY` | ✅ 1–4 = Hoch, 5 = Normal, 6–9 = Niedrig |

**Nicht synchronisiert:** Wiederholungen, Benachrichtigungen, Menge – diese werden nur lokal verwaltet.

## PHP-Befehle

### ToDo Gateway (Prefix: TGW)

```php
TGW_CalDAVTestConnection($id);      // Verbindung testen
TGW_CalDAVDiscoverCalendars($id);   // Kalender suchen
```

### ToDoList (Prefix: TDL)

```php
TDL_CalDAVSync($id);                // Manuell synchronisieren
TDL_CalDAVResetSync($id);           // Sync-Marker zurücksetzen
```