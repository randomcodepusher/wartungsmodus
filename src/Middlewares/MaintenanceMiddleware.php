<?php

namespace Wartungsmodus\Middlewares;

use Plenty\Modules\ShopBuilder\Helper\ShopBuilderRequest;
use Plenty\Plugin\Application;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Middleware;
use Plenty\Plugin\Templates\Twig;

class MaintenanceMiddleware extends Middleware
{
    public function before(Request $request)
    {
        // before() kann die Response nicht ersetzen - nichts zu tun.
    }

    public function after(Request $request, Response $response): Response
    {
        /** @var ConfigRepository $config */
        $config = pluginApp(ConfigRepository::class);
        $active = $config->get('Wartungsmodus.settings.active', 'false');

        // Checkbox liefert String "true"/"false"; defensiv wie IO pruefen.
        if (!in_array($active, ['true', '1', 1, true], true)) {
            return $response;
        }

        $uri = $request->getRequestUri();

        // REST-/API-Aufrufe (ShopBuilder-Editor, /rest/io/...), robots.txt
        // und Sitemaps nicht anfassen.
        if (strpos($uri, '/rest') === 0
            || strpos($uri, '/robots.txt') === 0
            || strpos($uri, '/sitemap') === 0) {
            return $response;
        }

        // ShopBuilder-Vorschau und Widget-Rendering nicht blockieren.
        /** @var ShopBuilderRequest $shopBuilderRequest */
        $shopBuilderRequest = pluginApp(ShopBuilderRequest::class);
        if ($shopBuilderRequest->isShopBuilder()) {
            return $response;
        }

        // Backend-/Admin-Vorschau erlauben (interne Kontrolle bei Wartung).
        /** @var Application $app */
        $app = pluginApp(Application::class);
        if ($app->isAdminPreview() || $app->isBackendRequest()) {
            return $response;
        }

        /** @var Twig $twig */
        $twig = pluginApp(Twig::class);
        $content = $twig->render('Wartungsmodus::content.Maintenance');

        $response = $response->make($content, 503, ['Retry-After' => '3600']);
        $response->forceStatus(503);

        return $response;
    }
}
