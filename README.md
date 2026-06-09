# Aidanta Chatbot Connector (plentyShop Ceres)

Bindet den **Aidanta-Chatbot** in einen **plentyShop Ceres** ein und übergibt den **eingeloggten
Kunden** (Kundennummer + E-Mail) **fälschungssicher** an Aidanta. Der Bot kann dann personenbezogene
Auskünfte geben (Bestellstatus, Sendungsverfolgung …) – die Daten holt Aidanta über die **bereits im
Workflow konfigurierte PlentyOne-Anbindung**, nicht über dieses Plugin.

## Warum ein Plugin (und kein simpler Script-Tag)?

Ceres liefert Seiten aus dem **Content-Cache**; personalisierte Kundendaten kommen erst per REST nach.
Ein signierter Kundenkontext lässt sich daher **nicht** serverseitig ins gecachte HTML backen. Dieses
Plugin löst das mit einem **serverseitigen Handshake**: eine same-origin Route kennt über die
plenty-Session den eingeloggten Kontakt und lässt bei Aidanta ein **kurzlebiges Hybrid-Session-Token**
ausstellen. Der Browser überträgt **nie selbst** eine Identität → keine Manipulationsmöglichkeit.

## Datenfluss

```
Ceres-Seite (gecached)
  └─ Container injiziert Bootstrap-<script> (nicht personalisiert, cache-safe)
        │ 1) fetch GET /aidanta-chatbot/handshake   (same-origin, plenty-Session-Cookie)
        ▼
  HandshakeController (serverseitig)
        │ AccountService::getAccountContactId()  → 0? → {session_token: null}
        │ ContactRepositoryContract::findContactById() → Kundennummer + E-Mail
        │ 2) lib/issueSession.php (Guzzle) → POST {apiBaseUrl}/api/v1/chatbot/sessions/issue
        │    Authorization: Bearer <API-Key>
        │    customer.identities = [{ provider:'plentyone', customer_number, email }]
        ▼
  Aidanta issue() → setzt verified_customer → { session_token: 'cst_…' }
        │ 3) Handshake liefert { session_token } an den Browser
        ▼
  Bootstrap lädt widget.js mit data-session-token (eingeloggt) bzw. data-token (Gast)
```

## Voraussetzungen in Aidanta

1. Im **selben Workflow** eine **PlentyOne-Anbindung** (API-Zugriff auf Bestellungen) **und** eine
   **Chatbot-Anbindung** (Widget) aktiv.
2. Einen **API-Key** mit Scope **`chatbot.session.issue`** anlegen
   (Portal → API-Keys).
3. Den **Widget-Token** der Chatbot-Anbindung notieren
   (Portal → Chatbot-Anbindung → „Website-Integration").

## Installation & Konfiguration

1. Plugin in ein Plugin-Set des Shops einspielen (Git-Anbindung oder ZIP-Upload), bereitstellen und
   in die Produktivumgebung deployen.
2. Plugin-Konfiguration ausfüllen (Plugin-Übersicht → dieses Plugin → Konfiguration):
   - **Widget-Token der Chatbot-Anbindung**
   - **API-Key** (Scope `chatbot.session.issue`)
   - **Session-Gültigkeit in Sekunden** – Standard `3600`
3. Das Plugin-Set **deployen/provisionieren**. Der DataProvider **„Aidanta Chatbot Widget"** wird per
   `defaultLayoutContainer` automatisch im globalen Container **`Ceres::Script.Loader`** eingebunden
   (lädt auf **allen** Seiten) — i. d. R. **kein** manuelles Verknüpfen nötig.
   Falls dein Build doch eine Verknüpfung verlangt: Plugin → **Container-Verknüpfungen** →
   „Aidanta Chatbot Widget" → `Script.Loader` (alternativ `Script.AfterScriptsLoaded`).

> Tipp zum Testen: Der **Vorschau-Modus** der Plugin-Übersicht umgeht das Content-Caching – ideal,
> um eingeloggt vs. Gast sofort zu prüfen, ohne 5 Minuten auf die Cache-Invalidierung zu warten.

## Sicherheit

- **Trust-Anker:** plenty-Session (serverseitig) + API-Key (Server-zu-Server). Keine Identität aus
  ungeprüftem Browser-Input.
- **Hybrid:** kurzlebige Tokens, **kein** Auth-Secret im Shop, **kein** Replay-Fenster.
- `identities[].provider='plentyone'` gated im Bot die Tools strikt auf diese Kennung; Aidanta gibt
  über eine Code-Schicht ausschließlich die **eigenen** Bestellungen der verifizierten Identität heraus.
- API-Key ausschließlich mit Scope `chatbot.session.issue` ausstatten.

## Dateien

| Datei | Zweck |
| --- | --- |
| `plugin.json` | Plugin-Definition, DataProvider (Container), Guzzle-Abhängigkeit |
| `config.json` | Konfigurationsfelder (Widget-Token, API-Key, TTL) |
| `src/Providers/…ServiceProvider.php` | Registriert den RouteServiceProvider |
| `src/Providers/…RouteServiceProvider.php` | `GET /aidanta-chatbot/handshake` |
| `src/Controllers/HandshakeController.php` | Kontakt → Kontext → Token ausstellen |
| `src/Containers/WidgetBootstrapContainer.php` | Rendert das Bootstrap-`<script>` |
| `lib/issueSession.php` | Guzzle-Aufruf an Aidanta `/sessions/issue` |
| `resources/views/content/WidgetBootstrap.twig` | Bootstrap-`<script>` (Handshake → widget.js) |

## Hinweise / im Ziel-System zu prüfen

- **Kundennummer:** `Contact->number`. Einzelne Importe befüllen stattdessen `Contact->externalId` –
  im Zweifel einmal per `dd($contact)` prüfen.
- **lib-Ordner & Guzzle-Version:** `lib/` liegt im Plugin-Root, Guzzle wird über `dependencies`
  (`guzzlehttp/guzzle: 6.3.*`) gezogen. Falls der konkrete LTS-Build eine andere Version pinnt,
  hier anpassen.
- **Sprach-Präfix:** Die Handshake-Route wird absolut unter `/aidanta-chatbot/handshake` aufgerufen.
  Bei mehrsprachigen Shops mit Pfad-Präfix ggf. den Bootstrap-Fetch-Pfad anpassen.
