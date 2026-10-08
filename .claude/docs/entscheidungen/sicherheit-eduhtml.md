# Sicherheit: `EduHtml()` ist die einzige Weißliste

Das Feld `html` einer Klassenseiten- oder LOGINEO-Karte wird in Web-App und Edumaps-Kachel per `innerHTML` angezeigt. Gesäubert wird es an genau einer Stelle: `EduLesen::EduHtml()`. Die Regel steht im Kopf von `SymDoGateway/libs/EduLesen.php`; hier steht, warum sie so streng ist und wie sie einmal fast unterlaufen wurde.

## Entscheidungen

- **Eine Prüfstelle, nicht mehrere.** `EduHtml()` baut die Ausgabe aus einer festen Menge von Elementen neu auf (`p, br, ol, ul, li, b, i, u, em, strong` und `a`), Linkziele nur `http`, `https`, `mailto`, und verwirft alles über `EDU_HTML_MAX` (dann bleibt es beim Klartext). Die Oberfläche prüft bewusst nicht mehr nach — dort steht ausdrücklich, dass dort nichts mehr geprüft werden kann.
- **Warum das so viel wiegt:** Es gibt keinen Content-Security-Policy-Kopf, und der Bearer-Token der Sitzung liegt im `localStorage` der Web-App. Fremdes Skript im `innerHTML` hätte also vollen API-Zugriff.
- **Regel:** Jeder Weg, der `html` in den Klassenseiten-Bestand schreibt, geht durch `EduHtml()`. Heute: der Zerleger der Klassenseiten (`EduLesen`), die drei LOGINEO-Quellen in `MoodleLesen` (Abschnittszusammenfassung, Modulbeschreibung, Forenbeitrag) und der Umschlagweg aus dem Scanner (`EduEinpflegen::EduUmschlagKarte`). Stellen, die bereits Gefiltertes nur weiterreichen (`EduStoreCalc::SatzBauen`, `NachzugRechnen`), sind unkritisch.

## Fallen/gemessen

- **Neue Herkunft = neue Prüfpflicht.** Beim Umzug des Klassenseiten-Laufs in den Scanner kam `html` erstmals aus einer Datei einer fremden Instanz. Die erste Fassung ergänzte nur fehlende Schlüssel mit dem PHP-Plus-Operator — der lässt vorhandene Schlüssel unberührt —, und `<img src=x onerror=…>` ging ungeprüft bis ins `innerHTML`. Gefunden von Prüfagenten und im Browser nachgestellt. Dieselbe Funktion prüfte `url` gegen `javascript:` und begründete das sogar; `html` ist der stärkere Vektor und war ungeprüft.
- Merksatz: Daten, die eine Prozess- oder Instanzgrenze überqueren (Umschlagdateien, Auftragsdateien), sind fremde Eingabe — auch wenn der Absender das eigene Modul ist.

Stand: geprüft gegen den Code am 08.10.2026
