<?php

namespace AidantaChatbotConnector\Providers;

use Plenty\Plugin\ServiceProvider;

/**
 * Haupt-ServiceProvider des Plugins.
 *
 * Registriert ausschliesslich den RouteServiceProvider; die Widget-Einbindung
 * laeuft ueber den DataProvider/Container (siehe plugin.json -> dataProviders).
 */
class AidantaChatbotConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->getApplication()->register(AidantaChatbotConnectorRouteServiceProvider::class);
    }
}
