# Einkaufs-Kachel: Listen-Umschalter

Gibt es mehrere Einkaufslisten, zeigt die Einkaufs-Kachel oben statt des Titels eine Auswahl (`#shoppingSwitcher`, derselbe wie in der Web-App) und kann jede andere Liste anzeigen und bedienen.

## Entscheidungen (22.10.2026, Nutzerwunsch)

- **Die Kachel spricht nur mit ihrer eigenen Instanz.** Für eine fremde Liste schickt sie `GetState` bzw. `ListCall` mit `list`; die eigene Instanz reicht über die öffentlichen Funktionen der Zielliste weiter (`SL_GetTileState`, `SL_AppCall`), nie über `RequestAction` einer fremden Instanz (`SymDoShoppingList/libs/ListSwitcher.php`).
- **Zugriffsgrenze:** Fremde Listen nur, wenn beide Seiten den Schalter `ListSwitcher` an haben (Vorgabe an, im Formular unter „Layout“). Eine ID außerhalb des Umschalters wird abgelehnt, nicht still auf die eigene Liste umgelenkt. Aktionen nur aus der Freigabeliste von `AppCall`. Eine Freigabeliste je Kachel oder ein Standard „aus“ wurde nach einem Sicherheitshinweis erwogen und vom Nutzer verworfen (22.10.2026): SymDo läuft in einem Haushalt, alle Visus gehören derselben Familie.
- **Ein Push erreicht alle Clients der Kachel.** Stände tragen deshalb `listId` (dazu `homeId`, `lists`); jeder Client zeigt nur den Stand der Liste, die er gewählt hat (`kachelListenUebernehmen()` in `SymDoWebApp/module.html`). Die Wahl merkt sich der Browser je Kachel (`symdo.kachelListe.<homeId>`).
- **Änderungen anderswo:** Die Instanz beobachtet `ItemCount` der anderen Listen (`VM_UPDATE`) und schickt nur `{type: listChanged, listId}`; wer die Liste anzeigt, holt den Stand mit Prüfwert. Neue Listen kommen mit dem nächsten `ApplyChanges` der Kachel-Instanz in die Beobachtung, im Umschalter stehen sie sofort.
- **Rückfälle:** Ohne `SL_GetTileState` (vor dem Kernelstart nach dem Update) nimmt die Instanz `SL_GetAppState` (Bildkarte dann inline). Kommt nach einem Wechsel 8 s kein Stand, springt die Kachel zur eigenen Liste zurück. Der neue Schalter gilt bis zum Neuladen des Moduls als an (`ReadBooleanPropertyOrDefault`).
- Instanzgebundenes (Märkte, Favoriten, Abgleich externer Listen, Produktbilder) kommt aus der gewählten Liste.

## Prüfen

- `php SymDoShoppingList/tests/ListSwitcherTest.php` (Gegenprobe: ohne die Schalter-Prüfung fallen 5 Zusicherungen)
- `node SymDoWebApp/tests/ListenUmschalterTest.mjs`
- Live im Docker (22.10.2026): Umschalter mit vier Listen, Wechsel zeigt die fremde Liste, Anlegen landet nur dort, `listChanged` beim Ändern der fremden Liste (Debug „ListSwitcher“). Neuladen mit gemerkter Wahl und das Nachladen nach `listChanged` im Browser nicht live geprüft (die Visu verlangte im Headless-Lauf ein Passwort).

Stand: geprüft gegen den Code am 22.10.2026
