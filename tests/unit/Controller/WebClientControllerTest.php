<?php

declare(strict_types=1);

namespace OCA\Flowline\Tests\Unit\Controller;

use OCA\Flowline\Controller\WebClientController;
use OCA\Flowline\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Page « assistance navigateur » : bundle servi (page + assets via routes),
 * config WS injectée dans une balise meta, CSP restreinte aux origines WS
 * configurées, accès réservé aux utilisateurs autorisés.
 */
final class WebClientControllerTest extends TestCase {
	private ConfigService&MockObject $config;
	private IUserSession&MockObject $userSession;
	private IUser&MockObject $user;
	private IURLGenerator&MockObject $url;

	protected function setUp(): void {
		$this->config = $this->createMock(ConfigService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->user = $this->createMock(IUser::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->config->method('getWebclientRdvUrl')->willReturn('wss://rdv.example/ws/id');
		$this->config->method('getWebclientRelayUrl')->willReturn('wss://relay.example:8443/ws/relay');
		$this->url->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params = []): string =>
				'/index.php/apps/flowline/webclient/assets/' . ($params['file'] ?? ''),
		);
	}

	private function controller(): WebClientController {
		$nonceManager = new class {
			public function getNonce(): string {
				return 'test-nonce-123';
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($nonceManager);

		return new WebClientController(
			$this->createMock(IRequest::class),
			$this->config,
			$this->userSession,
			$this->url,
			$container,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function allowed(): void {
		$this->config->method('isUserAllowed')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($this->user);
	}

	public function testServesPageWithInjectedConfigAndRewrittenAssets(): void {
		$this->allowed();

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$body = $response->render();
		// Config injectée en meta (pas de script inline : CSP script-src 'self').
		$this->assertStringContainsString('name="flowline-web-config"', $body);
		$this->assertStringContainsString('wss://rdv.example/ws/id', $body);
		$this->assertStringContainsString('wss://relay.example:8443/ws/relay', $body);
		$this->assertStringNotContainsString('window.FLOWLINE_WEB_CONFIG', $body);
		// Les assets relatifs du bundle sont réécrits vers la route de l'app.
		$this->assertStringContainsString('/index.php/apps/flowline/webclient/assets/', $body);
		$this->assertStringNotContainsString('./assets/', $body);
		// Les balises <script> portent le nonce CSP (middleware Nextcloud).
		$this->assertMatchesRegularExpression('/<script nonce="test-nonce-123"/', $body);
	}

	public function testContentSecurityPolicyAllowsConfiguredOrigins(): void {
		$this->allowed();

		$policy = $this->controller()->index()->getContentSecurityPolicy()?->buildPolicy() ?? '';

		$this->assertStringContainsString('connect-src', $policy);
		$this->assertStringContainsString('wss://rdv.example', $policy);
		$this->assertStringContainsString('wss://relay.example:8443', $policy);
		// Origine seule (connect-src ignore le chemin) : pas de chemin dans la CSP.
		$this->assertStringNotContainsString('wss://rdv.example/ws/id', $policy);
		// libsodium (WASM) : permission minimale, jamais 'unsafe-eval'.
		$this->assertStringContainsString("'wasm-unsafe-eval'", $policy);
		$this->assertStringNotContainsString("'unsafe-eval'", $policy);
	}

	public function testServesBuiltAsset(): void {
		$this->allowed();
		$files = glob(__DIR__ . '/../../../webclient/assets/*.js') ?: [];
		$this->assertNotEmpty($files, 'bundle webclient/assets/*.js absent (scripts/build-webclient.sh)');
		$name = basename($files[0]);

		$response = $this->controller()->asset($name);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertStringContainsString('application/javascript', (string)($response->getHeaders()['Content-Type'] ?? ''));
		$this->assertStringContainsString('immutable', (string)($response->getHeaders()['Cache-Control'] ?? ''));
	}

	public function testAssetRejectsTraversalAndUnknownFiles(): void {
		$this->allowed();
		$controller = $this->controller();

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->asset('..%2Findex.html')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->asset('index-does-not-exist.js')->getStatus());
	}

	public function testForbiddenWhenUserNotAllowed(): void {
		$this->config->method('isUserAllowed')->willReturn(false);
		$this->userSession->method('getUser')->willReturn($this->user);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->index()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->asset('index-abc.js')->getStatus());
	}

	public function testForbiddenWhenAnonymous(): void {
		$this->config->method('isUserAllowed')->willReturn(true);
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->index()->getStatus());
	}
}