# Gateway: Aufbau und Vertrag zu den Apps

Das Modul „SymDo Gateway" ist die eine Instanz, über die iOS-App, Web-App und Kacheln mit der Bibliothek sprechen. Diese Datei hält fest, warum es so zusammengesetzt ist und was dabei nie geändert werden darf.

## Entscheidungen

- **Die frühere AppBridge ist im Gateway aufgegangen, nicht umgekehrt.** Das Gateway behielt GUID, Präfix `TGW` und Modultyp 2. Grund: die OAuth-Tokens (Google, Microsoft) liegen als Attribute an der Gateway-Instanz und sind mit einem Schlüssel aus der Instanz-ID verschleiert (`OAuthHelper::OAuthGetEncryptionKey`, XOR — Verschleierung, keine Verschlüsselung). Eine neue Instanz hätte jeden Nutzer zum erneuten Autorisieren gezwungen; die alte GUID zu behalten kostete keine Migration.
- **Die Hook-Pfade sind der Vertrag zu den Apps und werden nie umbenannt:** `lists/app` (REST, `apiVersion` 1), `lists/webapp` (Seite), `lists/ws` (WebSocket), `lists/pwa` (Service Worker und Manifest). Symcon-Hooknamen hängen weder an GUID noch an Präfix — deshalb überstand die Zusammenlegung ohne App-Release. Ein umbenannter Pfad hieße: jede gekoppelte App verliert ihre Verbindung.
- **Der App-Kern ist ein Trait (`libs/AppCore.php`), kein eigenes Modul.** Er setzt die Web-App aus Nachbarmodulen zusammen (SymDoWebApp, SymDoShoppingList) und rechnet die Pfade deshalb mit `dirname(__DIR__, 2)`. Wer die Datei verschiebt, muss diese Rechnung mitziehen.
- **Die Übernahme-Mechanik aus der Umbauzeit ist entfernt** (Commit `4b933f8`). Sie las die Attribute der alten Instanz aus der `settings.json`; für Neuinstallationen gibt es nichts zu übernehmen.

## Fallen/gemessen

- Die Gateway-Klasse besteht aus über dreißig Traits. Zwei gleichnamige Trait-Methoden sind ein Fatal beim LADEN der Klasse — danach meldet jede Instanz der Bibliothek „Class not found". `SymDoGateway/tests/KompositionTest.php` setzt die Klasse deshalb gegen die Attrappen zusammen.
- PHP-Methodennamen unterscheiden nicht zwischen Groß- und Kleinschreibung: ein Trait-`dispatch()` neben einem Wrapper `Dispatch()` ist dieselbe Methode (Endlosrekursion).

Stand: geprüft gegen den Code am 08.10.2026
