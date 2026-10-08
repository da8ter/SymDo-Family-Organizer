# KI: lokaler Anbieter (OpenAI-kompatibler Server)

Der Anbieter `local` spricht jeden OpenAI-kompatiblen Server an (getestet mit LM Studio und einem Gemma-Vision-Modell). Hier stehen die Fallen, die beim ersten echten Einsatz auffielen.

## Entscheidungen

- **`/v1` wird ergänzt, wenn die Basisadresse keine Version trägt** (`AiProvider`, Chat und Transkription). Das Formular fragt nach der Basis, die Server bedienen `/v1`. Ohne diese Ergänzung antwortete LM Studio mit `{"error":"Unexpected endpoint or method"}` — bei HTTP 200 —, was im Modul als `ai_empty` ankam: der lokale Anbieter hatte bis Commit `c6aa6d6` nie funktioniert.
- **Großes Token-Budget (heute für alle Anbieter, `MAX_TOKENS`) und lange Frist für `local`** (`LOCAL_TIMEOUT` 300 s). Reasoning-Modelle verbrauchen ihr Budget zuerst im Denktext (`reasoning_content`); bei knappem `max_tokens` kommt `content: ""` mit `finish_reason: length`.
- **PDF-Bilder rendert `pdftoppm` direkt in Zielbreite**, nicht GD: Symcons PHP hat GD, aber kein Imagick, und GD liest kein PDF; eine A4-Seite in GD zu laden kostet 16 von 32 MB `memory_limit`.
- **Externe Werkzeuge über `exec` und Temp-Dateien, nie über `proc_open`** — `proc_open` bleibt in Symcon hängen.

## Fallen/gemessen

- **LM Studio zählt Bild-Tokens nicht in `usage.prompt_tokens`** (30 Tokens bei einem 50-kB-Bild). Die Token-Zahl taugt deshalb nicht als Nachweis, dass ein Bild ankam — nur ein inhaltlicher Test (ein Kennwort im Bild vorlesen lassen) beweist Vision.
- Gemessen über `/v1/ai/extract` mit einem kleinen lokalen Vision-Modell: digitales PDF 19,4 s, Scan-PDF ohne Textebene 23,6 s, beide korrekt.
- Lokale Modelle müssen vision-fähig sein, sonst geht nur der Text-Weg (PDF mit Textebene, Rezeptadresse).
- Poppler (`pdftotext`, `pdftoppm`) muss auf dem Symcon-Rechner installiert sein; fehlt es, protokolliert `AiProvider` das und der jeweilige Weg entfällt.

Stand: geprüft gegen den Code am 08.10.2026
