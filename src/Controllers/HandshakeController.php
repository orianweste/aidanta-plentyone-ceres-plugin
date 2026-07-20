<?php

namespace AidantaChatbotConnector\Controllers;

use Plenty\Modules\Account\Contact\Contracts\ContactRepositoryContract;
use Plenty\Modules\Account\Contact\Models\Contact;
use Plenty\Modules\Frontend\Services\AccountService;
use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;

/**
 * Serverseitiger Handshake fuer die Aidanta-Chatbot-Einbindung.
 *
 * Ablauf (same-origin, mit plenty-Session-Cookie):
 *  1. Eingeloggten Kontakt aus der Session bestimmen (Status).
 *  2. Kundennummer + E-Mail laden und zum Aidanta-Kundenkontext aufbereiten.
 *  3. Serverseitig (lib/issueSession.php, Guzzle) ein Aidanta-Hybrid-Session-Token
 *     ausstellen lassen (API-Key) und dem Browser NUR dieses Token zurueckgeben.
 *
 * Der Browser self-asserted nie eine Identitaet -> kein Faelschungsrisiko.
 *
 * Zwei Aufruf-Modi (seit 1.1.0, Immer-Hybrid):
 *  - OHNE Parameter (Status-/Beweis-Handshake, widget.js): Token NUR fuer
 *    eingeloggte Kontakte; Gaeste erhalten {session_token: null, logged_in: false}.
 *    Das Token dient dem Widget als Einmal-Login-Beweis (POST /sessions/{token}/auth).
 *  - ?issue=guest (Bootstrap): stellt AUCH Gaesten eine anonyme Hybrid-Session
 *    aus (Issue ohne Kundenkontext). Nur so laeuft das Widget in beiden
 *    Login-Zustaenden im Hybrid-Modus und der Chat-Verlauf bleibt ueber einen
 *    Login-/Logout-Reload hinweg DIESELBE Session (die Gast-Session wird beim
 *    Login serverseitig hochgestuft, statt dass eine zweite, leere entsteht).
 *
 * Das explizite logged_in-Flag in der Antwort verhindert, dass das Widget ein
 * Gast-Token je mit einem Login-Beweis verwechselt.
 */
class HandshakeController extends Controller
{
    use Loggable;

    /**
     * Aidanta-Portal-URL (fix). Bewusst hartkodiert statt konfigurierbar:
     * fuer Haendler immer identisch, ein editierbares Feld waere nur ein Stolperstein.
     */
    public const API_BASE_URL = 'https://portal.aidanta.de';

    public function issue(
        Request $request,
        AccountService $accountService,
        ContactRepositoryContract $contactRepository,
        ConfigRepository $config,
        LibraryCallContract $libCall,
        Response $response
    ) {
        $contactId = (int) $accountService->getAccountContactId();
        $issueForGuest = (string) $request->get('issue') === 'guest';

        // Status-/Beweis-Handshake (widget.js, ohne ?issue=guest): Gast -> kein
        // Token. Nur der Bootstrap stellt Gast-Sessions aus.
        if ($contactId <= 0 && ! $issueForGuest) {
            return $response->json(['session_token' => null, 'logged_in' => false]);
        }

        $widgetToken = trim((string) $config->get('AidantaChatbotConnector.widgetToken'));
        $apiKey = trim((string) $config->get('AidantaChatbotConnector.apiKey'));
        $ttlSeconds = (int) $config->get('AidantaChatbotConnector.ttlSeconds', 3600);

        if ($widgetToken === '' || $apiKey === '') {
            $this->getLogger(__METHOD__)
                ->error('AidantaChatbotConnector: Plugin-Konfiguration unvollstaendig (Widget-Token oder API-Key fehlt).');

            // logged_in ehrlich melden: "eingeloggt, aber kein Token" wertet
            // widget.js als KEIN verlaessliches Signal (statt False-Logout).
            return $response->json(['session_token' => null, 'logged_in' => $contactId > 0]);
        }

        $customer = null;

        if ($contactId > 0) {
            $customer = $this->buildCustomerPayload($contactRepository, $contactId);

            // Kontakt nicht ladbar (transienter plenty-Fehler): Beweis-Handshake
            // -> kein Token, aber logged_in=true — widget.js behandelt das als
            // "kein verlaessliches Signal" und meldet KEINEN Logout.
            if ($customer === null && ! $issueForGuest) {
                return $response->json(['session_token' => null, 'logged_in' => true]);
            }
        }

        $result = $libCall->call('AidantaChatbotConnector::issueSession', [
            'apiBaseUrl' => self::API_BASE_URL,
            'apiKey' => $apiKey,
            'widgetToken' => $widgetToken,
            'ttlSeconds' => $ttlSeconds > 0 ? $ttlSeconds : 3600,
            // null/leer => anonyme Gast-Session ohne Kundenkontext.
            'customer' => $customer,
            // Echte Client-IP des Shop-Besuchers (der Handshake laeuft same-origin
            // im Browser-Request-Kontext). Aidanta braucht sie fuer Anzeige und
            // Spam-Sperren — der Issue-Call selbst traegt nur die Egress-IP des
            // plenty-Servers, die dafuer unbrauchbar ist.
            'visitorIp' => $this->resolveClientIp($request),
        ]);

        $sessionToken = is_array($result) ? ($result['session_token'] ?? null) : null;

        if ($sessionToken === null) {
            $this->getLogger(__METHOD__)
                ->warning('AidantaChatbotConnector: Kein Session-Token von Aidanta erhalten.', [
                    'result' => $result,
                ]);
        }

        // logged_in spiegelt den plenty-Login-Status — unabhaengig davon, ob der
        // Issue-Call ein Token lieferte. widget.js wertet "logged_in ohne Token"
        // als kein verlaessliches Signal (verhindert False-Logouts bei
        // transienten Issue-Fehlern) und akzeptiert ein Token nur zusammen mit
        // logged_in=true als Login-Beweis (Gast-Token zaehlen nie).
        return $response->json([
            'session_token' => $sessionToken,
            'logged_in' => $contactId > 0,
        ]);
    }

    /**
     * Client-IP des Shop-Besuchers — strikt nice-to-have: Der Handshake darf an
     * der IP-Ermittlung NIE scheitern (dann lieber ohne IP ausstellen, Aidanta
     * faellt auf die Egress-IP zurueck). Nur valide IPs zurueckgeben, sonst
     * wuerde Aidantas Validierung (422) den kompletten Issue abbrechen.
     */
    private function resolveClientIp(Request $request): string
    {
        try {
            $ip = trim((string) $request->ip());
        } catch (\Throwable $e) {
            return '';
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /**
     * Baut den Aidanta-Kundenkontext aus dem eingeloggten Plenty-Kontakt.
     *
     * identities[].provider='plentyone' bindet die personenbezogene Auskunft im
     * Bot strikt an die PlentyOne-Tools genau dieser Kennung. identities[].contact_id
     * ist die EINDEUTIGE Kontakt-ID — Aidanta scopt Bestell-Auskuenfte primaer darueber
     * (eine E-Mail kann an mehreren Kontakten haengen, die Kontakt-ID nie).
     *
     * @return array<string, mixed>|null  null, wenn der Kontakt nicht ladbar ist
     */
    private function buildCustomerPayload(ContactRepositoryContract $contactRepository, int $contactId): ?array
    {
        // Robustheit: findContactById darf den Handshake NIE mit einer uncaught Exception
        // abbrechen (sonst HTTP 500 statt sauberem session_token:null → Login schlaegt still
        // fehl). Erst mit ['options'] (E-Mail-Fallback) versuchen, bei Fehler ohne; im
        // Zweifel Gast.
        $contact = null;

        try {
            /** @var Contact|null $contact */
            $contact = $contactRepository->findContactById($contactId, ['options']);
        } catch (\Throwable $e) {
            try {
                /** @var Contact|null $contact */
                $contact = $contactRepository->findContactById($contactId);
            } catch (\Throwable $e2) {
                $this->getLogger(__METHOD__)
                    ->warning('AidantaChatbotConnector: Kontakt konnte nicht geladen werden.', [
                        'contactId' => $contactId,
                        'error' => $e2->getMessage(),
                    ]);

                return null;
            }
        }

        if ($contact === null) {
            return null;
        }

        $email = $this->resolveContactEmail($contact);
        $customerNumber = $this->resolveCustomerNumber($contact);
        $name = trim(((string) ($contact->firstName ?? '')).' '.((string) ($contact->lastName ?? '')));

        // Die plenty-Kontakt-ID ist die eindeutige, IMMER vorhandene Kennung — der
        // Payload wird darum immer gebaut (auch ohne Kundennummer/E-Mail).
        return [
            'name' => $name !== '' ? $name : null,
            'email' => $email !== '' ? $email : null,
            'identities' => [
                [
                    'provider' => 'plentyone',
                    'contact_id' => $contactId,
                    'customer_number' => $customerNumber !== '' ? $customerNumber : null,
                    'email' => $email !== '' ? $email : null,
                ],
            ],
        ];
    }

    /**
     * E-Mail des Kontakts. plenty befuellt $contact->email nicht zuverlaessig (die
     * Adresse liegt oft nur in den Kontakt-Options, typeId 2) -> dort als Fallback lesen.
     */
    private function resolveContactEmail(Contact $contact): string
    {
        $email = isset($contact->email) ? trim((string) $contact->email) : '';
        if ($email !== '') {
            return $email;
        }

        // array/Traversable explizit prüfen (manche Typ-Helper sind in der plenty-Sandbox gesperrt).
        $options = $contact->options ?? null;
        if (is_array($options) || $options instanceof \Traversable) {
            foreach ($options as $option) {
                $typeId = is_object($option) ? ($option->typeId ?? null) : (is_array($option) ? ($option['typeId'] ?? null) : null);
                if ((int) $typeId !== 2) {
                    continue;
                }
                foreach ($this->optionValues($option) as $value) {
                    if (strpos($value, '@') !== false) {
                        return $value;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Kundennummer; der String "null" und Leerwerte werden verworfen, Fallback auf
     * externalId (manche Importe befuellen die Kundennummer dort).
     */
    private function resolveCustomerNumber(Contact $contact): string
    {
        $number = isset($contact->number) ? trim((string) $contact->number) : '';

        if ($number === '' || strtolower($number) === 'null') {
            $number = isset($contact->externalId) ? trim((string) $contact->externalId) : '';
        }

        return strtolower($number) === 'null' ? '' : $number;
    }

    /**
     * Skalare Werte einer Kontakt-Option (Struktur variiert je plenty-Version:
     * ->value bzw. ->values[].value).
     *
     * @param  mixed  $option
     * @return array<int, string>
     */
    private function optionValues($option): array
    {
        $out = [];

        $direct = is_object($option) ? ($option->value ?? null) : (is_array($option) ? ($option['value'] ?? null) : null);
        if (is_string($direct) && trim($direct) !== '') {
            $out[] = trim($direct);
        }

        $values = is_object($option) ? ($option->values ?? null) : (is_array($option) ? ($option['values'] ?? null) : null);
        if (is_array($values) || $values instanceof \Traversable) {
            foreach ($values as $v) {
                $vv = is_object($v) ? ($v->value ?? null) : (is_array($v) ? ($v['value'] ?? null) : null);
                if (is_string($vv) && trim($vv) !== '') {
                    $out[] = trim($vv);
                }
            }
        }

        return $out;
    }
}
