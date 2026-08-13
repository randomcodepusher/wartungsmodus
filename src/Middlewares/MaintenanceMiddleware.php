<?php

namespace Wartungsmodus\Middlewares;

use Plenty\Modules\ShopBuilder\Helper\ShopBuilderRequest;
use Plenty\Plugin\Application;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Middleware;
use Plenty\Plugin\Templates\Twig;

class MaintenanceMiddleware extends Middleware
{
    use Loggable;

    const VERSION = 'v1.0.3';

    public function before(Request $request)
    {
        // before() kann die Response nicht ersetzen - nichts zu tun.
    }

    public function after(Request $request, Response $response): Response
    {
        try {
            return $this->handle($request, $response);
        } catch (\Throwable $e) {
            // Fehler duerfen den Shop nie lahmlegen; aber sichtbar machen.
            $this->getLogger('Wartungsmodus::Middleware')->error('Fehler in after()', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            if ($request->get('wartungsdebug') !== null) {
                return $response->make(
                    'WARTUNGSMODUS ' . self::VERSION . ' EXCEPTION: ' . $e->getMessage(),
                    200,
                    ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Wartungsmodus' => 'exception']
                );
            }

            return $response;
        }
    }

    private function handle(Request $request, Response $response): Response
    {
        /** @var ConfigRepository $config */
        $config = pluginApp(ConfigRepository::class);
        $active = $config->get('Wartungsmodus.settings.active', 'MISSING');

        // Diagnose-Modus: nur bei explizitem Aufruf mit ?wartungsdebug=1.
        // Antwortet mit Klartext statt der echten Seite.
        if ($request->get('wartungsdebug') !== null) {
            $debug = self::VERSION . '; settings.active=' . json_encode($active);
            $this->getLogger('Wartungsmodus::Middleware')->info('Debug-Aufruf', ['active' => $active]);

            return $response->make('WARTUNGSMODUS DEBUG: ' . $debug, 200, [
                'Content-Type'    => 'text/plain; charset=UTF-8',
                'X-Wartungsmodus' => $debug,
            ]);
        }

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

        // ShopBuilder-Vorschau und Backend-/Admin-Vorschau nicht blockieren.
        if ($this->isEditorOrPreview()) {
            return $response;
        }

        $response = $response->make($this->renderMaintenancePage(), 503, ['Retry-After' => '3600']);
        $response->forceStatus(503);

        return $response;
    }

    private function isEditorOrPreview(): bool
    {
        try {
            /** @var ShopBuilderRequest $shopBuilderRequest */
            $shopBuilderRequest = pluginApp(ShopBuilderRequest::class);
            if ($shopBuilderRequest->isShopBuilder()) {
                return true;
            }
        } catch (\Throwable $e) {
            // Pruefung fehlgeschlagen -> nicht blockieren, weiter mit naechster Pruefung.
        }

        try {
            /** @var Application $app */
            $app = pluginApp(Application::class);
            if ($app->isAdminPreview() || $app->isBackendRequest()) {
                return true;
            }
        } catch (\Throwable $e) {
        }

        return false;
    }

    private function renderMaintenancePage(): string
    {
        try {
            /** @var Twig $twig */
            $twig = pluginApp(Twig::class);
            return $twig->render('Wartungsmodus::content.Maintenance');
        } catch (\Throwable $e) {
            // Fallback ohne Twig, damit die Wartungsseite immer erscheint.
            $this->getLogger('Wartungsmodus::Middleware')->error('Twig-Render fehlgeschlagen', [
                'message' => $e->getMessage(),
            ]);

            return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
                . '<meta name="robots" content="noindex, nofollow"><title>Wartungsarbeiten</title></head>'
                . '<body style="font-family:sans-serif;text-align:center;padding:4rem;">'
                . '<h1>Wir sind gleich zur&uuml;ck!</h1>'
                . '<p>Unser Shop wird gerade gewartet. Bitte versuchen Sie es in K&uuml;rze erneut.</p>'
                . '</body></html>';
        }
    }
}
