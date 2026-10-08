# Web-App: auf dem iOS-Home-Bildschirm

Die Web-App lässt sich auf iOS zum Home-Bildschirm hinzufügen und läuft dann als eigene App (Voraussetzung für Web Push). Diese Datei hält fest, was iOS 26 dabei tatsächlich zulässt und wie sich die Home-Screen-App anmeldet. Die Werte stammen aus Messungen am Gerät (iPhone 375×812 pt, iOS 26.6, August 2026).

## Entscheidungen

- **`display: standalone`, ohne `apple-mobile-web-app-status-bar-style`.** Das ist auf iOS 26 das erreichbare Maximum. Gemessen in drei Konfigurationen:
  1. *standalone ohne Statusleisten-Meta:* Die Seite liegt unter der Statusleiste und endet bündig an der Unterkante (Seite 761 von 812 pt). Den reservierten Streifen oben malt iOS in `theme_color`/`background_color` aus dem MANIFEST, nicht im `theme-color`-Meta zur Laufzeit.
  2. *zusätzlich `black-translucent`:* Die Seite wird NICHT größer. Sie bleibt 761 pt hoch und rutscht nur an Pixel 0: Der Inhalt gerät unter die Uhr, und unten fehlen die 51 pt als Systemstreifen.
  3. *`display: fullscreen`:* iOS behandelt die Seite nicht mehr als Web-App. Das Symbol öffnet ein Safari-Lesezeichen mit Safari-Leisten und gemeinsamem Speicher, entgegen der Spezifikation ohne Rückfall auf standalone.

  Deshalb tragen die Manifestfarben die App-Fläche (`#2b2c30`), damit der obere Streifen mit ihr verschmilzt. „Inhalt hinter der Dynamic Island“ ist für Web-Apps auf iOS 26 nicht zu haben.
- **`id` und `start_url` sind auf Dauer festgelegt.** Ändert sich eines davon, gilt die App auf iOS als eine ANDERE: zweites Symbol, zweiter Speicher, verwaistes Push-Abo. `display`, `theme_color`, `lang` sind keine Identitätsfelder.
- **`start_url` trägt kein Token, und die Home-Screen-App koppelt sich selbst per Code.** Sobald die Seite ein Manifest anbietet, startet iOS das Symbol mit `start_url` und nicht mit der gerade offenen Adresse. Das Token im Adressfragment (`#t=…`) fällt dabei weg. Die App hat eigenen Speicher, sieht das Safari-Token also nicht und kann nicht scannen. Ein Token in `start_url` scheidet ebenfalls aus: Safari holt ein zur Laufzeit geändertes Manifest nicht verlässlich neu, das Token stünde in jedem Zugriffsprotokoll, und eine geänderte `start_url` macht eine andere App daraus. Der Kopplungsbildschirm in `webapp-adapter.js` nimmt deshalb einen Kopplungscode an (Feld `symdo-pair-code`), und die App bekommt einen eigenen Eintrag in der Geräteliste.

## Fallen

- **Manifestwerte und Metas fängt iOS beim HINZUFÜGEN ein.** Jede Änderung wird erst wirksam, nachdem das Symbol entfernt und neu angelegt wurde.
- **`env(safe-area-inset-*)` meldet in der Home-Screen-Web-App immer 0,** auch mit `viewport-fit=cover`. Weder `cover` noch das `theme-color`-Meta ändern die Systemgeometrie.
- **Ein 401 ohne Token heißt „nicht gekoppelt“, nicht „Sitzung abgelaufen“.** Die frühere Meldung „Sitzung abgelaufen“ erschien auch ohne jedes Token und führte die Fehlersuche auf den Server, obwohl dort alles stimmte. Der Adapter unterscheidet beide Fälle heute.
- Eine Messsonde kann nur die GRÖSSE des Fensters melden, nicht seine Lage. Ob oben oder unten etwas fehlt, zeigt erst ein Bildschirmfoto. Die Sonde von damals ist wieder ausgebaut (`a0eb429`).
- Eine angehängte Zahl am Symbolnamen („SymDo 2“) setzt iOS selbst, wenn schon ein gleichnamiges Symbol existiert.

Stand: geprüft gegen den Code am 08.10.2026 (Geräteverhalten am Gerät gemessen, nicht am Code prüfbar)
