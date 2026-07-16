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
        │ 0) Aktive Chat-Session im localStorage (aidanta_hybrid_session, ≤ 24 h)?
        │    → widget.js direkt mit diesem Token laden (kein Handshake/Issue nötig)
        │ 1) sonst: fetch GET /aidanta-chatbot/handshake?issue=guest   (same-origin, plenty-Session-Cookie)
        ▼
  HandshakeController (serverseitig)
        │ AccountService::getAccountContactId() → eingeloggt: Kundenkontext bauen;
        │                                          Gast (?issue=guest): customer = null
        │ 2) resources/lib/issueSession.php (Guzzle) → POST {apiBaseUrl}/api/v1/chatbot/sessions/issue
        │    Authorization: Bearer <API-Key>
        │    eingeloggt: customer.identities = [{ provider:'plentyone', contact_id, customer_number, email }]
        │    Gast: OHNE customer → anonyme Session
        ▼
  Aidanta issue() → { session_token: 'cst_…' } (eingeloggt: mit verified_customer)
        │ 3) Handshake liefert { session_token, logged_in } an den Browser
        ▼
  Bootstrap lädt widget.js IMMER mit data-session-token + data-handshake-url
  (Immer-Hybrid seit 1.1.0 — data-token nur noch als Fallback bei Issue-Fehlern)
```

**Immer-Hybrid (1.1.0):** Auch Gäste erhalten eine (anonyme) Hybrid-Session. Nur so bleibt der
Chat-Verlauf über einen Login-/Logout-Reload hinweg **dieselbe Session**: Beim Login stuft Aidanta
die Gast-Session hoch („Im Shop angemeldet"-Pille + automatische Beantwortung der offenen,
anmeldepflichtigen Frage); beim Logout meldet widget.js den Zustand schon beim Verlauf-Laden und
Aidanta entzieht die Identität mit sichtbarer „Im Shop abgemeldet"-Pille. Der Status-Handshake
OHNE `?issue=guest` (Login-Beweis für widget.js) liefert Gästen weiterhin
`{ session_token: null, logged_in: false }` — das explizite `logged_in`-Flag stellt sicher, dass
ein Gast-Token nie als Login-Beweis gilt.

**Robustheit (Review-Härtungen):** Das Bootstrap verwendet gepinnte Sessions nur, wenn sie
tatsächlichen Verlauf haben (`aidanta_chat_<token>.lastId > 0` — nie benutzte Tokens leben nur in
Aidantas Kurzzeit-Cache und wären nach `ttlSeconds` tot), und stellt Gast-Sessions erst nach der
ersten Nutzer-Interaktion aus (Bots/Crawler lösen keinen Issue-Call aus — schont das
Session-Budget). Bei Issue-Fehlern (Rate-Limit/Timeout) meldet der Handshake `logged_in` ehrlich:
„eingeloggt ohne Token" wertet widget.js als *kein Signal* — so kann ein transienter
Infrastruktur-Fehler nie einen verifizierten Chat fälschlich beenden.

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
| `resources/lib/issueSession.php` | Guzzle-Aufruf an Aidanta `/sessions/issue` (**muss** unter `resources/lib/` liegen — plenty baut nur dort SDK-Library-Services) |
| `resources/views/content/WidgetBootstrap.twig` | Bootstrap-`<script>` (Handshake → widget.js) |

## Hinweise / im Ziel-System zu prüfen

- **Kundennummer:** `Contact->number`. Einzelne Importe befüllen stattdessen `Contact->externalId` –
  im Zweifel einmal per `dd($contact)` prüfen.
- **lib-Ordner & Guzzle-Version:** Die Library **muss** unter **`resources/lib/`** liegen (NICHT im
  Plugin-Root `lib/`) — plenty baut **nur** dort die SDK-Library-Services. Liegt sie falsch, schlägt
  der `LibraryCall` mit `404 "no services found"` fehl und der Handshake liefert nie ein Token. Guzzle
  wird über `dependencies` (`guzzlehttp/guzzle: 6.3.*`) gezogen; falls der konkrete LTS-Build eine
  andere Version pinnt, hier anpassen.
- **Sprach-Präfix:** Die Handshake-Route wird absolut unter `/aidanta-chatbot/handshake` aufgerufen.
  Bei mehrsprachigen Shops mit Pfad-Präfix ggf. den Bootstrap-Fetch-Pfad anpassen.
