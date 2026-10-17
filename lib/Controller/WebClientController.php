<?php

declare(strict_types=1);

namespace OCA\Flowline\Controller;

use OCA\Flowline\AppInfo\Application;
use OCA\Flowline\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\FeaturePolicy;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Page « assistance navigateur » : sert le client web FlowLINE (bundle
 * construit dans `webclient/`) avec la configuration WS injectée.
 *
 * Sécurité : le jeton `flapi_` n'est jamais transmis au navigateur — la page
 * ne parle qu'aux endpoints WebSocket publics, et l'autorisation (fenêtre
 * support) est ouverte côté serveur par la console (API v1). La CSP est
 * restreinte à `self` + les origines WS configurées. L'id cible (`?target=…`)
 * est lu par le client web lui-même (jamais injecté dans le HTML).
 *
 * Le bundle est servi par ce contrôleur (page + assets) : les fichiers
 * statiques d'une app ne sont pas accessibles via `/apps/<id>/…` quand l'app
 * est installée dans `custom_apps/` (instance de dev) — les routes, elles,
 * fonctionnent partout.
 *
 * @psalm-suppress UnusedClass
 */
class WebClientController extends Controller {
	public function __construct(
		IRequest $request,
		private ConfigService $config,
		private IUserSession $userSession,
		private IURLGenerator $url,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/webclient')]
	public function index(): Response {
		if (!$this->userAllowed()) {
			return new Response(Http::STATUS_FORBIDDEN);
		}

		$html = @file_get_contents($this->webclientDir() . '/index.html');
		if ($html === false || $html === '') {
			return new Response(Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$rdvUrl = $this->config->getWebclientRdvUrl();
		$relayUrl = $this->config->getWebclientRelayUrl();
		$html = $this->rewriteAssetUrls($html);
		$html = $this->injectConfig($html, $rdvUrl, $relayUrl);
		$html = $this->addCspNonce($html, $this->cspNonce());

		$response = new DataDisplayResponse($html, Http::STATUS_OK, [
			'Content-Type' => 'text/html; charset=utf-8',
		]);
		$response->setContentSecurityPolicy($this->contentSecurityPolicy($rdvUrl, $relayUrl));
		// Une `Response` brute part d'un `EmptyFeaturePolicy` (→ fullscreen
		// 'none') : la fenêtre de support a besoin du plein écran (bouton de la
		// barre d'état), comme la page de la console (TemplateResponse).
		$response->setFeaturePolicy(new FeaturePolicy());
		$response->cacheFor(0);

		return $response;
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/webclient/assets/{file}')]
	public function asset(string $file): Response {
		if (!$this->userAllowed()) {
			return new Response(Http::STATUS_FORBIDDEN);
		}
		if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*$#', $file) !== 1 || str_contains($file, '..')) {
			return new Response(Http::STATUS_NOT_FOUND);
		}

		$assetsDir = realpath($this->webclientDir() . '/assets');
		if ($assetsDir === false) {
			return new Response(Http::STATUS_NOT_FOUND);
		}
		$path = realpath($assetsDir . '/' . $file);
		if ($path === false || !str_starts_with($path, $assetsDir . '/')) {
			return new Response(Http::STATUS_NOT_FOUND);
		}
		$content = @file_get_contents($path);
		if ($content === false) {
			return new Response(Http::STATUS_NOT_FOUND);
		}

		// Nom de fichier haché (build) : cache immuable.
		return new DataDisplayResponse($content, Http::STATUS_OK, [
			'Content-Type' => $this->contentType($file),
			'Cache-Control' => 'max-age=31536000, immutable',
		]);
	}

	private function webclientDir(): string {
		return __DIR__ . '/../../webclient';
	}

	private function userAllowed(): bool {
		$user = $this->userSession->getUser();

		return $user !== null && $this->config->isUserAllowed($user);
	}

	/**
	 * Le bundle référence ses assets en relatif (`./assets/index-<hash>.js`) ;
	 * ils sont servis par la route `flowline.webclient.asset`.
	 */
	private function rewriteAssetUrls(string $html): string {
		$rewritten = preg_replace_callback(
			'#\./assets/([A-Za-z0-9][A-Za-z0-9._-]*)#',
			fn (array $matches): string => $this->url->linkToRoute(
				'flowline.webclient.asset',
				['file' => $matches[1] ?? ''],
			),
			$html,
		);

		return $rewritten ?? $html;
	}

	/**
	 * Injecte la config dans une balise `<meta>` (pas de script inline : la CSP
	 * `script-src 'self'` le bloquerait). Le client web lit la balise au
	 * démarrage.
	 */
	private function injectConfig(string $html, string $rdvUrl, string $relayUrl): string {
		$json = json_encode(
			['rdv' => $rdvUrl, 'relay' => $relayUrl],
			JSON_THROW_ON_ERROR
			| JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			| JSON_UNESCAPED_SLASHES,
		);
		$meta = '<meta name="flowline-web-config" content="'
			. htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';

		$count = 0;
		$injected = preg_replace('/<head[^>]*>/i', '$0' . "\n\t\t" . $meta, $html, 1, $count);
		if ($count === 1 && $injected !== null) {
			return $injected;
		}

		return $meta . "\n" . $html;
	}

	/**
	 * Le middleware de sécurité Nextcloud ajoute un nonce CSP à chaque
	 * réponse ; les balises `<script>` servies doivent le porter, sinon elles
	 * sont bloquées (`script-src-elem 'strict-dynamic' 'nonce-…'`).
	 */
	private function addCspNonce(string $html, string $nonce): string {
		if ($nonce === '') {
			return $html;
		}
		$escaped = htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		return (string)preg_replace(
			'/<script\b(?![^>]*\bnonce=)/i',
			'<script nonce="' . $escaped . '"',
			$html,
		);
	}

	/**
	 * Nonce CSP de la requête. `ContentSecurityPolicyNonceManager` est une
	 * classe interne du serveur Nextcloud (aucune API publique) : résolue par
	 * identifiant, avec repli journalisé si l'API change (page dégradée plutôt
	 * qu'erreur 500).
	 */
	private function cspNonce(): string {
		try {
			$manager = $this->container->get('OC\\Security\\CSP\\ContentSecurityPolicyNonceManager');
		} catch (\Throwable $e) {
			$this->logNonceUnavailable($e->getMessage());

			return '';
		}
		if (!is_object($manager) || !method_exists($manager, 'getNonce')) {
			$this->logNonceUnavailable('unexpected manager');

			return '';
		}
		/**
		 * @psalm-suppress MixedMethodCall Classe interne Nextcloud, non typée.
		 * @psalm-suppress MixedAssignment Retour non typé.
		 */
		$nonce = $manager->getNonce();

		return is_string($nonce) ? $nonce : '';
	}

	private function logNonceUnavailable(string $reason): void {
		$this->logger->warning(
			'FlowLINE web client: CSP nonce unavailable (' . $reason . ')',
			['app' => Application::APP_ID],
		);
	}

	private function contentSecurityPolicy(string $rdvUrl, string $relayUrl): ContentSecurityPolicy {
		$csp = new ContentSecurityPolicy();
		// libsodium compile son module WASM : permission minimale dédiée
		// (contrairement à 'unsafe-eval', elle n'autorise pas eval()/Function).
		$csp->addAllowedScriptDomain("'wasm-unsafe-eval'");
		foreach ([$rdvUrl, $relayUrl] as $url) {
			$origin = $this->webSocketOrigin($url);
			if ($origin !== '') {
				$csp->addAllowedConnectDomain($origin);
			}
		}

		return $csp;
	}

	/**
	 * Origine CSP d'une URL WS (`wss://host:port/chemin` → `wss://host:port`) :
	 * connect-src raisonne par origine, le chemin est inutile.
	 */
	private function webSocketOrigin(string $url): string {
		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			return '';
		}
		$scheme = strtolower($parts['scheme']);
		if ($scheme !== 'ws' && $scheme !== 'wss') {
			return '';
		}
		$origin = $scheme . '://' . $parts['host'];
		if (isset($parts['port'])) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}

	private function contentType(string $file): string {
		return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
			'js', 'mjs' => 'application/javascript; charset=utf-8',
			'css' => 'text/css; charset=utf-8',
			'map', 'json' => 'application/json; charset=utf-8',
			'svg' => 'image/svg+xml',
			'woff2' => 'font/woff2',
			default => 'application/octet-stream',
		};
	}
}
