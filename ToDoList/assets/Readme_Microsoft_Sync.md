# Microsoft To Do Synchronisation – Einrichtungsanleitung

## Voraussetzungen

- Ein Microsoft-Konto (persönlich oder Organisations-/Geschäftskonto)
- Zugang zum [Microsoft Entra Admin Center](https://entra.microsoft.com/) (oder Azure Portal)
- **IP-Symcon Connect** muss konfiguriert und erreichbar sein (für OAuth-Callback)

## Schritt 1: App in Microsoft Entra ID registrieren

1. Öffne [https://entra.microsoft.com](https://entra.microsoft.com)
   - Alternativ: [https://portal.azure.com](https://portal.azure.com) → **Microsoft Entra ID**
2. Navigiere zu **App-Registrierungen** → **Neue Registrierung**
3. Fülle das Formular aus:
   - **Name**: z.B. `Symcon ToDoList`
   - **Unterstützte Kontotypen**: `Alle Konten von Entra ID-Mandanten und persönliche Microsoft-Konten`
   - **Umleitungs-URI**:
     - Plattform wählen: **Web**
     - URI: Die Redirect URI findest du im **ToDo Gateway** unter Microsoft To Do → Redirect URI
       (Format: `https://<dein-symcon-connect>/hook/todogateway_microsoft`)
4. Klicke auf **Registrieren**

## Schritt 2: Client-ID kopieren

Nach der Registrierung siehst du die **Übersicht** der App:

- **Anwendungs-ID (Client)**: Diesen Wert kopieren!

## Schritt 3: Client-Secret erstellen

1. Gehe links zu **Zertifikate & Geheimnisse**
2. Klicke auf **Neuer geheimer Clientschlüssel**
3. Beschreibung: z.B. `Symcon`
4. Ablauf: wähle z.B. **24 Monate**
5. Klicke auf **Hinzufügen**
6. **Sofort den Wert kopieren und speichern!** (wird danach nicht mehr angezeigt)

> **Wichtig:** Der **Wert** (nicht die „Geheime-ID"!) ist das Client-Secret.

## Schritt 4: API-Berechtigung hinzufügen

1. Gehe links zu **API-Berechtigungen**
2. Klicke auf **Berechtigung hinzufügen**
3. Wähle **Microsoft Graph**
4. Wähle **Delegierte Berechtigungen**
5. Suche nach `Tasks.ReadWrite` und aktiviere es
6. Klicke auf **Berechtigungen hinzufügen**

> Bei einem Organisationskonto: Optional auf **Administratorzustimmung erteilen** klicken.

## Schritt 5: ToDo Gateway konfigurieren

1. Öffne die **ToDo Gateway**-Instanz (wird automatisch mit der ToDoList angelegt)
2. Im Bereich **Microsoft To Do** eintragen:
   - **Client ID**: Die kopierte Anwendungs-ID aus Schritt 2
   - **Client Secret**: Der kopierte Wert aus Schritt 3
   - **Tenant**: `common` (Standard – passend für die meisten Fälle)
3. Klicke auf **Übernehmen** (wichtig – erst danach werden die Buttons aktiv!)

### Tenant-Werte

| Wert | Verwendung |
|------|-----------|
| `common` | Persönliche + Organisationskonten (Standard) |
| `organizations` | Nur Organisationskonten |
| `consumers` | Nur persönliche Microsoft-Konten |
| `<Tenant-ID>` | Spezifische Organisation |

## Schritt 6: Mit Microsoft verbinden

1. Klicke im **ToDo Gateway** auf **„Mit Microsoft verbinden"**
2. Eine URL wird angezeigt → im Browser öffnen
3. Mit Microsoft-Konto anmelden
4. Die angeforderten Berechtigungen bestätigen → **Akzeptieren**
5. Du siehst **„Autorisierung erfolgreich"** → Fenster schließen

## Schritt 7: ToDoList-Instanz konfigurieren

1. Öffne die **ToDoList-Instanz**
2. Wähle bei **Synchronisations-Backend** → **Microsoft To Do**
3. Klicke auf **„Listen aktualisieren"**
4. Wähle die gewünschte Aufgabenliste aus dem Dropdown
5. Klicke auf **Übernehmen**

## Schritt 8: Synchronisation aktivieren

1. Wähle ein **Sync-Intervall** (z.B. 5 oder 15 Minuten)
2. Wähle den **Konfliktmodus** (Standard: „Neuester gewinnt")
3. Optional: **Auto sync after changes** aktivieren
4. Klicke auf **Übernehmen**

## Synchronisierte Felder

| Lokales Feld | Microsoft To Do | Hinweis |
|---|---|---|
| Titel | `title` | ✅ bidirektional |
| Info | `body.content` | ✅ bidirektional (Plaintext) |
| Erledigt | `status` | ✅ completed / notStarted |
| Fälligkeit | `dueDateTime` | ✅ Datum + Uhrzeit (mit Zeitzone) |
| Priorität | `importance` | ✅ low / normal / high |
| Benachrichtigung | `isReminderOn` + `reminderDateTime` | ✅ bidirektional |
| Wiederholung | `recurrence` | ✅ täglich / wöchentlich / monatlich / jährlich |

**Nicht synchronisiert bzw. nicht durch die API unterstützt:** Menge und Sortierung – wird nur lokal verwaltet.

> **Hinweis:** Microsoft To Do unterstützt keine stündlichen Wiederholungen. Die Einheit „Stunden" wird bei Microsoft-Sync automatisch ausgeblendet.

## PHP-Befehle

### ToDo Gateway (Prefix: TGW)

```php
TGW_MicrosoftGetAuthUrl($id);        // Autorisierungs-URL anzeigen
TGW_MicrosoftTestConnection($id);    // Verbindung testen
TGW_MicrosoftDisconnect($id);        // Verbindung trennen
```

### ToDoList (Prefix: TDL)

```php
TDL_MicrosoftRefreshListOptions($id); // Listen aktualisieren
TDL_MicrosoftToDoSync($id);           // Manuell synchronisieren
TDL_MicrosoftResetSync($id);          // Sync-Marker zurücksetzen
```