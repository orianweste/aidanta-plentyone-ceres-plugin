<?php

namespace AidantaChatbotConnector\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\ApiRouter;
use Plenty\Plugin\Routing\Router;

/**
 * Registriert die same-origin Frontend-Route fuer den Handshake.
 *
 * Bewusst eine normale Router-Route (Session-Cookie-Auth des Shops), KEINE
 * ApiRouter-/OAuth-Route: nur so kennt der Handler ueber die plenty-Session den
 * aktuell eingeloggten Kontakt.
 */
class AidantaChatbotConnectorRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router, ApiRouter $api): void
    {
        $router->get(
            'aidanta-chatbot/handshake',
            'AidantaChatbotConnector\Controllers\HandshakeController@issue'
        );
    }
}
