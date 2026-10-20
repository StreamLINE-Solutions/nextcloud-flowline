<?php

declare(strict_types=1);

namespace OCA\Flowline\Tests\Unit\Service;

use OCA\Flowline\Service\ConfigService;
use OCA\Flowline\Service\FlowlineApiException;
use OCA\Flowline\Service\FlowlineClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FlowlineClientTest extends TestCase {
	private IClientService&MockObject $clientService;
	private IClient&MockObject $http;
	private FlowlineClient $flowline;

	protected function setUp(): void {
		$this->clientService = $this->createMock(IClientService::class);
		$this->http = $this->createMock(IClient::class);
		$this->clientService->method('newClient')->willReturn($this->http);

		$config = $this->createMock(ConfigService::class);
		$config->method('getApiUrl')->willReturn('https://api.example.test');

		$this->flowline = new FlowlineClient(
			$this->clientService,
			$config,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function response(int $status, string $body): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;
	}

	public function testMeReturnsParsedBody(): void {
		$this->http->expects($this->once())->method('get')
			->with('https://api.example.test/api/v1/me', $this->anything())
			->willReturn($this->response(200, '{"username":"lus","scopes":["machines:read"]}'));

		$this->assertSame(
			['username' => 'lus', 'scopes' => ['machines:read']],
			$this->flowline->me('flapi_x'),
		);
	}

	public function testUnauthorizedMapsToInvalidToken(): void {
		$this->http->method('get')->willReturn($this->response(401, '{"detail":{"error":"Invalid token"}}'));

		try {
			$this->flowline->me('flapi_x');
			$this->fail('Expected FlowlineApiException');
		} catch (FlowlineApiException $e) {
			$this->assertSame(FlowlineApiException::REASON_INVALID_TOKEN, $e->getReason());
			$this->assertSame(401, $e->getApiStatus());
			$this->assertSame('Invalid token', $e->getMessage());
		}
	}

	public function testBadRequestMapsToInvalidFormat(): void {
		$this->http->method('post')->willReturn($this->response(400, '{"detail":{"error":"Invalid device id"}}'));

		try {
			$this->flowline->openInvitation('flapi_x', 'x');
			$this->fail('Expected FlowlineApiException');
		} catch (FlowlineApiException $e) {
			$this->assertSame(FlowlineApiException::REASON_INVALID_FORMAT, $e->getReason());
			$this->assertSame(400, $e->getApiStatus());
		}
	}

	public function testForbiddenMapsToForbidden(): void {
		$this->http->method('get')->willReturn($this->response(403, '{"detail":{"error":"Missing scope"}}'));

		try {
			$this->flowline->me('flapi_x');
			$this->fail('Expected FlowlineApiException');
		} catch (FlowlineApiException $e) {
			$this->assertSame(FlowlineApiException::REASON_FORBIDDEN, $e->getReason());
		}
	}

	public function testNetworkErrorMapsToNetwork(): void {
		$this->http->method('get')->willThrowException(new \RuntimeException('connection refused'));

		try {
			$this->flowline->me('flapi_x');
			$this->fail('Expected FlowlineApiException');
		} catch (FlowlineApiException $e) {
			$this->assertSame(FlowlineApiException::REASON_NETWORK, $e->getReason());
		}
	}

	public function testInvalidJsonMapsToApiError(): void {
		$this->http->method('get')->willReturn($this->response(200, 'not-json'));

		try {
			$this->flowline->me('flapi_x');
			$this->fail('Expected FlowlineApiException');
		} catch (FlowlineApiException $e) {
			$this->assertSame(FlowlineApiException::REASON_API_ERROR, $e->getReason());
		}
	}

	public function testEndInvitationEncodesDeviceId(): void {
		$this->http->expects($this->once())->method('delete')
			->with('https://api.example.test/api/v1/invitations/123%20456', $this->anything())
			->willReturn($this->response(200, '{"id":"123 456","ended":true}'));

		$this->assertSame(
			['id' => '123 456', 'ended' => true],
			$this->flowline->endInvitation('flapi_x', '123 456'),
		);
	}

	public function testOpenInvitationPostsJsonBody(): void {
		$this->http->expects($this->once())->method('post')
			->with(
				'https://api.example.test/api/v1/invitations',
				$this->callback(static function (array $options): bool {
					return ($options['json']['device_id'] ?? null) === '123456789';
				}),
			)
			->willReturn($this->response(200, '{"id":"123456789","window":true,"expires_at":"2026-10-16 13:00:00"}'));

		$result = $this->flowline->openInvitation('flapi_x', '123456789');

		$this->assertTrue($result['window']);
	}

	public function testRequestsDisableRedirects(): void {
		$this->http->expects($this->once())->method('get')
			->with(
				'https://api.example.test/api/v1/me',
				$this->callback(static fn (array $options): bool => ($options['allow_redirects'] ?? null) === false),
			)
			->willReturn($this->response(200, '{"username":"lus"}'));

		$this->flowline->me('flapi_x');
	}

	public function testRefusedUrlMapsToNetworkError(): void {
		$config = $this->createMock(ConfigService::class);
		$config->method('getApiUrl')->willReturn('https://api.example.test');
		$config->method('assertApiUrlSafe')
			->willThrowException(new \InvalidArgumentException('API URL host not allowed'));

		$client = new FlowlineClient(
			$this->clientService,
			$config,
			$this->createMock(LoggerInterface::class),
		);

		try {
			$client->me('flapi_x');
			$this->fail('Expected FlowlineApiException');
		} catch (FlowlineApiException $e) {
			$this->assertSame(FlowlineApiException::REASON_NETWORK, $e->getReason());
			$this->assertSame('FlowLINE API URL not allowed', $e->getMessage());
		}
	}
}
