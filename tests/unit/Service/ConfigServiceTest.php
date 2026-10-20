<?php

declare(strict_types=1);

namespace OCA\Flowline\Tests\Unit\Service;

use OCA\Flowline\AppInfo\Application;
use OCA\Flowline\Service\ConfigService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * T13 (0.6.3) : validation de l'URL d'API contre le SSRF.
 */
final class ConfigServiceTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private ConfigService $config;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->config = new ConfigService(
			$this->appConfig,
			$this->createMock(IGroupManager::class),
		);
	}

	/** @return iterable<string, array{string}> */
	public static function forbiddenUrls(): iterable {
		yield 'aws metadata' => ['http://169.254.169.254/latest/meta-data/'];
		yield 'loopback v4' => ['http://127.0.0.1:21114'];
		yield 'loopback v6' => ['http://[::1]:21114'];
		yield 'localhost' => ['http://localhost:21114'];
		yield 'sous-domaine localhost' => ['http://api.localhost:21114'];
		yield 'unspecified' => ['http://0.0.0.0:21114'];
		yield 'alibaba metadata' => ['http://100.100.100.200/latest/meta-data/'];
		yield 'identifiants dans l URL' => ['http://user:pass@api.example.test'];
	}

	/**
	 * @dataProvider forbiddenUrls
	 */
	public function testRejectsSsrfTargets(string $url): void {
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->expectException(\InvalidArgumentException::class);
		$this->config->setApiUrl($url);
	}

	/** @return iterable<string, array{string}> */
	public static function allowedUrls(): iterable {
		yield 'API publique' => ['https://api-falcon.my-vth.ch'];
		yield 'IP publique' => ['https://8.8.8.8'];
		yield 'LAN 192.168' => ['https://192.168.15.253:21114'];
		yield 'LAN 10/8' => ['http://10.0.0.5:21114'];
	}

	/**
	 * @dataProvider allowedUrls
	 */
	public function testAcceptsPublicAndLanTargets(string $url): void {
		$this->appConfig->expects($this->once())->method('setValueString')
			->with(Application::APP_ID, 'api_url', $url);
		$this->config->setApiUrl($url);
	}

	public function testEmptyFallsBackToDefault(): void {
		$this->appConfig->expects($this->once())->method('setValueString')
			->with(Application::APP_ID, 'api_url', ConfigService::DEFAULT_API_URL);
		$this->config->setApiUrl('');
	}

	public function testConfiguredUrlsAreEmptyWhenUnset(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->assertSame('', $this->config->getConfiguredApiUrl());
		$this->assertSame('', $this->config->getConfiguredWebclientRdvUrl());
		$this->assertSame('', $this->config->getConfiguredWebclientRelayUrl());
	}

	public function testConfiguredApiUrlKeepsCustomValue(): void {
		$this->appConfig->method('getValueString')->willReturn('https://api.example.test/');
		$this->assertSame('https://api.example.test', $this->config->getConfiguredApiUrl());
	}
}
