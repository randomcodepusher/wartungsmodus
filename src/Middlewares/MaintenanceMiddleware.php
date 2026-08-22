<?php

namespace Wartungsmodus\Middlewares;

use Plenty\Modules\ContentCache\Contracts\ContentCacheRepositoryContract;
use Plenty\Modules\ShopBuilder\Helper\ShopBuilderRequest;
use Plenty\Plugin\Application;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Middleware;
use Plenty\Plugin\Templates\Twig;

/**
 * Wartungsmodus v1.0.4 - "darf das System nie gefaehrden"-Fassung.
 *
 * Lehren aus dem Ausfall vom 20.-22.08.2026 (Mandant komplett "no healthy upstream"):
 *  1. Die Wartungsseite gilt NUR fuer die konfigurierten Shop-Domains, NIE fuer den
 *     Plenty-System-Host (pXXXXX.my.plentysystems.com & Co.), IP-Adressen oder interne
 *     Hostnamen. Dort laufen Backend, REST-API und sehr wahrscheinlich die Health-Probes.
 *  2. Es wird NIE mehr mit einem 5xx-Status geantwortet. Die Wartungsseite kommt mit
 *     HTTP 200 + noindex. Ein 5xx auf jede Anfrage kann von der Plattform als
 *     "Container kaputt" gewertet werden.
 *  3. Nur echte Browser-Seitenaufrufe (GET, Accept: text/html, User-Agent, kein XHR)
 *     bekommen die Wartungsseite. Probes, HEAD-Requests, API-Aufrufe und Suchmaschinen-
 *     Crawler werden unveraendert durchgereicht (Crawler: sonst de-indexiert ein laengerer
 *     Wartungsmodus den Shop).
 *  4. Jeder Fehler in der Middleware fuehrt zu "fail-open": Original-Antwort bleibt.
 *  5. Waehrend der Wartungsmodus auf einer Shop-Domain aktiv ist, wird der plentyShop-
 *     Inhaltscache fuer diese Antworten abgeschaltet. Empfehlung: beim Ein- UND Ausschalten
 *     zusaetzlich den Inhaltscache im Backend leeren.
 *
 * Hinweis: Die Middleware arbeitet in after(), maskiert also nur die Ausgabe. Der Shop bleibt
 * fuer Nicht-HTML-Clients (REST, Warenkorb-API) technisch nutzbar. Fuer eine harte Sperre
 * zusaetzlich die Auftragsannahme des Mandanten im Backend deaktivieren.
 */
class MaintenanceMiddleware extends Middleware
{
    use Loggable;

    const VERSION = 'v1.0.4';

    /** Shop-Domains, falls in der Plugin-Konfiguration nichts (Gueltiges) eingetragen ist. */
    const DEFAULT_HOSTS = 'sogo24.de, www.sogo24.de';

    /** Plenty-System-Hosts: hier wird grundsaetzlich NIE gesperrt - auch wenn sie konfiguriert wuerden. */
    const PROTECTED_HOST_SUFFIXES = [
        '.my.plentysystems.com',
        '.my.plentymarkets.com',
        '.my.plentyone.com',
        '.plentymarkets-cloud01.com',
        '.plentymarkets-cloud02.com',
        '.plentymarkets-cloud03.com',
    ];

    /** Zusaetzlicher Schutz gegen neue/unbekannte Plenty-Systemdomains. */
    const PROTECTED_HOST_FRAGMENTS = ['plentysystems', 'plentymarkets', 'plentyone'];

    /** Suchmaschinen-Crawler sehen weiterhin den echten Shop (kein De-Indexieren). */
    const CRAWLER_UA_FRAGMENTS = [
        'googlebot', 'google-inspectiontool', 'bingbot', 'applebot', 'duckduckbot',
        'yandex', 'baiduspider', 'slurp', 'seznambot', 'ecosia',
    ];

    public function before(Request $request)
    {
        // before() kann die Response nicht ersetzen - nichts zu tun.
    }

    public function after(Request $request, Response $response): Response
    {
        try {
            return $this->handle($request, $response);
        } catch (\Throwable $e) {
            // Fehler duerfen weder Shop noch System lahmlegen: fail-open.
            // Auch Logging und Debug-Ausgabe sind abgesichert, damit hier garantiert nichts mehr wirft.
            try {
                $this->getLogger('Wartungsmodus::Middleware')->error('Fehler in after()', [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]);
            } catch (\Throwable $ignored) {
            }

            try {
                if ($request->get('wartungsdebug') !== null) {
                    return $response->make(
                        'WARTUNGSMODUS ' . self::VERSION . ' EXCEPTION: ' . $e->getMessage(),
                        200,
                        ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Wartungsmodus' => 'exception']
                    );
                }
            } catch (\Throwable $ignored) {
            }

            return $response;
        }
    }

    private function handle(Request $request, Response $response): Response
    {
        /** @var ConfigRepository $config */
        $config = pluginApp(ConfigRepository::class);
        $active = $config->get('Wartungsmodus.settings.active', 'MISSING');
        $hosts  = $this->allowedHosts((string) $config->get('Wartungsmodus.settings.hosts', ''));

        $decision = $this->decide($request, $active, $hosts);

        // Diagnose-Modus: nur bei explizitem Aufruf mit ?wartungsdebug=1, nur GET, nie auf /rest-Pfaden.
        // Bewusst host-unabhaengig, damit der Host-Schutz auf der Systemadresse geprueft werden kann.
        // Antwortet immer mit Klartext und HTTP 200.
        if ($request->get('wartungsdebug') !== null && $this->isGet($request) && !$this->isExemptPath($request)) {
            $debug = self::VERSION
                . '; active=' . json_encode($active)
                . '; host=' . $this->requestHost($request)
                . '; method=' . $request->getMethod()
                . '; accept=' . json_encode($this->headerString($request, 'Accept'))
                . '; ua=' . ($this->headerString($request, 'User-Agent') !== '' ? 'ja' : 'nein')
                . '; hosts=' . implode(',', $hosts)
                . '; entscheidung=' . $decision[1];
            $this->getLogger('Wartungsmodus::Middleware')->info('Debug-Aufruf', ['debug' => $debug]);

            return $response->make('WARTUNGSMODUS DEBUG: ' . $debug, 200, [
                'Content-Type'    => 'text/plain; charset=UTF-8',
                'Cache-Control'   => 'no-store, no-cache, must-revalidate, max-age=0',
                'X-Wartungsmodus' => $debug,
            ]);
        }

        // Auf einer aktiven Shop-Domain keine Antworten in den Inhaltscache legen (auch durchgereichte),
        // damit nach dem Umschalten keine veralteten Seiten aus dem Cache kommen.
        if (!in_array($decision[1], ['inaktiv', 'kein-host', 'system-host-geschuetzt', 'host-nicht-konfiguriert'], true)) {
            $this->disableContentCache();
        }

        if (!$decision[0]) {
            return $response;
        }

        // Wartungsseite - bewusst HTTP 200 (kein 5xx!), dafuer noindex + kein Caching.
        return $response->make($this->renderMaintenancePage(), 200, [
            'Content-Type'    => 'text/html; charset=UTF-8',
            'Cache-Control'   => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Robots-Tag'    => 'noindex, nofollow',
            'X-Wartungsmodus' => self::VERSION,
        ]);
    }

    /**
     * Entscheidet, ob diese Anfrage die Wartungsseite bekommt.
     * Reihenfolge = Sicherheitsreihenfolge: erst alle "nie sperren"-Regeln, zuletzt das Sperren.
     *
     * @param mixed    $active
     * @param string[] $hosts
     * @return array  [0 => bool sperren, 1 => string Grund]
     */
    private function decide(Request $request, $active, array $hosts): array
    {
        // Checkbox liefert String "true"/"false"; defensiv wie IO pruefen.
        if (!in_array($active, ['true', '1', 1, true], true)) {
            return [false, 'inaktiv'];
        }

        $host = $this->requestHost($request);
        if ($host === '') {
            return [false, 'kein-host'];
        }
        if ($this->isProtectedHost($host)) {
            return [false, 'system-host-geschuetzt'];
        }
        if (!in_array($host, $hosts, true)) {
            return [false, 'host-nicht-konfiguriert'];
        }

        // REST-/API-Aufrufe (ShopBuilder-Editor, /rest/io/...), robots.txt und Sitemaps nie anfassen.
        if ($this->isExemptPath($request)) {
            return [false, 'pfad-ausnahme'];
        }

        // Nur echte Browser-Seitenaufrufe: GET + Accept enthaelt text/html + User-Agent + kein XHR.
        if (!$this->isGet($request)) {
            return [false, 'kein-get'];
        }
        if (strpos(strtolower($this->headerString($request, 'Accept')), 'text/html') === false) {
            return [false, 'kein-html-accept'];
        }
        $userAgent = $this->headerString($request, 'User-Agent');
        if ($userAgent === '') {
            return [false, 'kein-user-agent'];
        }
        if ($this->isCrawler($userAgent)) {
            return [false, 'crawler-durchgelassen'];
        }
        if ($this->headerString($request, 'X-Requested-With') !== '') {
            return [false, 'xhr'];
        }

        // ShopBuilder-Vorschau und Backend-/Admin-Vorschau nicht blockieren.
        if ($this->isEditorOrPreview()) {
            return [false, 'editor-oder-backend'];
        }

        return [true, 'sperren'];
    }

    // ------------------------------------------------------------------ Regeln --

    private function isProtectedHost(string $host): bool
    {
        foreach (self::PROTECTED_HOST_SUFFIXES as $suffix) {
            if ($this->endsWith($host, $suffix)) {
                return true;
            }
        }
        foreach (self::PROTECTED_HOST_FRAGMENTS as $fragment) {
            if (strpos($host, $fragment) !== false) {
                return true;
            }
        }
        // Interne Hostnamen ohne Punkt und IP-Literale (typisch fuer Probes) nie sperren.
        if (strpos($host, '.') === false || $this->looksLikeIp($host)) {
            return true;
        }
        return false;
    }

    private function isExemptPath(Request $request): bool
    {
        $uri = (string) $request->getRequestUri();
        return strpos($uri, '/rest') === 0
            || strpos($uri, '/robots.txt') === 0
            || strpos($uri, '/sitemap') === 0;
    }

    private function isGet(Request $request): bool
    {
        return strtoupper((string) $request->getMethod()) === 'GET';
    }

    private function isCrawler(string $userAgent): bool
    {
        $ua = strtolower($userAgent);
        foreach (self::CRAWLER_UA_FRAGMENTS as $fragment) {
            if (strpos($ua, $fragment) !== false) {
                return true;
            }
        }
        return false;
    }

    // ----------------------------------------------------------------- Helfer --

    /** Konfigurierte Shop-Domains (komma-/semikolon-/leerzeichengetrennt) -> normalisierte Liste. */
    private function allowedHosts(string $configured): array
    {
        $hosts = $this->parseHosts($configured);
        if (count($hosts) === 0) {
            $hosts = $this->parseHosts(self::DEFAULT_HOSTS);
        }
        return $hosts;
    }

    /** @return string[] */
    private function parseHosts(string $raw): array
    {
        $raw = str_replace([';', "\n", "\r", "\t", ' '], ',', $raw);
        $hosts = [];
        foreach (explode(',', $raw) as $entry) {
            $h = $this->normalizeHost($entry);
            if ($h !== '' && !in_array($h, $hosts, true)) {
                $hosts[] = $h;
            }
        }
        return $hosts;
    }

    /** Host der Anfrage - gleich normalisiert wie die konfigurierten Domains. */
    private function requestHost(Request $request): string
    {
        return $this->normalizeHost((string) $request->getHttpHost());
    }

    /** Kleinbuchstaben, ohne Schema, Pfad und Port; IPv6-Literale in Klammern bleiben erhalten. */
    private function normalizeHost(string $value): string
    {
        $h = strtolower(trim($value));
        foreach (['https://', 'http://'] as $scheme) {
            if (strpos($h, $scheme) === 0) {
                $h = substr($h, strlen($scheme));
            }
        }
        $slash = strpos($h, '/');
        if ($slash !== false) {
            $h = substr($h, 0, $slash);
        }
        if (strpos($h, '[') === 0) { // IPv6-Literal, z.B. [::1]:443
            $end = strpos($h, ']');
            return $end !== false ? substr($h, 0, $end + 1) : $h;
        }
        $colon = strpos($h, ':');
        if ($colon !== false) {
            $h = substr($h, 0, $colon);
        }
        return $h;
    }

    private function looksLikeIp(string $host): bool
    {
        if (strpos($host, '[') === 0 || strpos($host, ':') !== false) {
            return true; // IPv6
        }
        return $host !== '' && strspn($host, '0123456789.') === strlen($host); // IPv4
    }

    /** Header als String (Plenty liefert string|array|null). */
    private function headerString(Request $request, string $name): string
    {
        $value = $request->header($name, '');
        if (is_array($value)) {
            $value = isset($value[0]) ? $value[0] : '';
        }
        return trim((string) $value);
    }

    private function endsWith(string $haystack, string $needle): bool
    {
        $len = strlen($needle);
        return $len === 0 || substr($haystack, -$len) === $needle;
    }

    /** plentyShop-Inhaltscache (ShopBooster) fuer diese Antwort abschalten - optional, nie kritisch. */
    private function disableContentCache(): void
    {
        try {
            /** @var ContentCacheRepositoryContract $contentCache */
            $contentCache = pluginApp(ContentCacheRepositoryContract::class);
            $contentCache->disableCacheForResponse('Wartungsmodus aktiv');
        } catch (\Throwable $e) {
            // Cache-Steuerung ist Komfort, kein Muss - niemals den Shop gefaehrden.
        }
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
            try {
                $this->getLogger('Wartungsmodus::Middleware')->error('Twig-Render fehlgeschlagen', [
                    'message' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {
            }

            return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
                . '<meta name="robots" content="noindex, nofollow"><title>Wartungsarbeiten</title></head>'
                . '<body style="font-family:sans-serif;text-align:center;padding:4rem;">'
                . '<h1>Wir sind gleich zur&uuml;ck!</h1>'
                . '<p>Unser Shop wird gerade gewartet. Bitte versuchen Sie es in K&uuml;rze erneut.</p>'
                . '</body></html>';
        }
    }
}
