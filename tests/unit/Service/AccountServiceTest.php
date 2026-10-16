<?php

declare(strict_types=1);

namespace OCA\Flowline\Tests\Unit\Service;

use OCA\Flowline\Db\Account;
use OCA\Flowline\Db\AccountMapper;
use OCA\Flowline\Service\AccountService;
use OCA\Flowline\Service\FlowlineApiException;
use OCA\Flowline\Service\FlowlineClient;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AccountServiceTest extends TestCase {
	private AccountMapper&MockObject $mapper;
	private FlowlineClient&MockObject $client;
	private ICrypto&MockObject $crypto;
	private AccountService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(AccountMapper::class);
		$this->client = $this->createMock(FlowlineClient::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->service = new AccountService($this->mapper, $this->client, $this->crypto);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function validMe(): array {
		return [
			'username' => 'lus',
			'display_name' => 'Lucas',
			'email' => 'lus@example.com',
			'plan_key' => 'pro',
			'plan_name' => 'Pro',
			'scopes' => ['machines:read', 'sessions:read', 'invitations:write'],
			'support_module_url' => 'https://www.flowline.support/download.html',
			'is_admin' => false,
			'is_manager' => false,
		];
	}

	public function testLinkInsertsEncryptedToken(): void {
		$token = 'flapi_' . str_repeat('a', 32);
		$captured = null;

		$this->client->expects($this->once())->method('me')->with($token)->willReturn($this->validMe());
		$this->crypto->expects($this->once())->method('encrypt')->with($token)->willReturn('encrypted-token');
		$this->mapper->method('findByUser')->willReturn(null);
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Account $account) use (&$captured): Account {
				$captured = $account;
				return $account;
			},
		);

		$this->service->link('user1', '  ' . $token . '  ');

		$this->assertInstanceOf(Account::class, $captured);
		$this->assertSame('user1', $captured->getUserId());
		$this->assertSame('encrypted-token', $captured->getTokenEncrypted());

		$stored = json_decode($captured->getAccountJson(), true);
		$this->assertIsArray($stored);
		$this->assertSame('Lucas', $stored['display_name']);
		$this->assertSame('lus', $stored['username']);
		$this->assertArrayNotHasKey('token', $stored);
	}

	public function testPublicAccountNeverContainsTheToken(): void {
		$account = new Account();
		$account->setUserId('user1');
		$account->setTokenEncrypted('encrypted-token');
		$account->setAccountJson(json_encode($this->validMe(), JSON_THROW_ON_ERROR));
		$account->setCreatedAt('2026-10-16 12:00:00');
		$account->setUpdatedAt('2026-10-16 12:00:00');

		$this->mapper->method('findByUser')->willReturn($account);

		$public = $this->service->getPublicAccount('user1');

		$this->assertNotNull($public);
		$this->assertSame('Lucas', $public['display_name']);
		$this->assertSame('lus', $public['username']);
		$this->assertSame('Pro', $public['plan_name']);
		$this->assertSame(['machines:read', 'sessions:read', 'invitations:write'], $public['scopes']);
		$this->assertArrayNotHasKey('token', $public);
		$this->assertArrayNotHasKey('token_encrypted', $public);
	}

	public function testLinkUpdatesExistingAccount(): void {
		$existing = new Account();
		$existing->setUserId('user1');
		$existing->setTokenEncrypted('old-encrypted');
		$existing->setAccountJson('{}');
		$existing->setCreatedAt('2026-01-01 00:00:00');
		$existing->setUpdatedAt('2026-01-01 00:00:00');

		$this->mapper->method('findByUser')->willReturn($existing);
		$this->client->method('me')->willReturn($this->validMe());
		$this->crypto->method('encrypt')->willReturn('encrypted-new');
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->once())->method('update')->willReturnCallback(
			static fn (Account $account): Account => $account,
		);

		$this->service->link('user1', 'flapi_' . str_repeat('c', 32));

		$this->assertSame('encrypted-new', $existing->getTokenEncrypted());
		$stored = json_decode($existing->getAccountJson(), true);
		$this->assertIsArray($stored);
		$this->assertSame('Lucas', $stored['display_name']);
	}

	public function testLinkRejectsInvalidTokenFormat(): void {
		$this->client->expects($this->never())->method('me');
		$this->mapper->expects($this->never())->method('insert');

		try {
			$this->service->link('user1', 'not-a-token');
			$this->fail('Expected FlowlineApiException');
		} catch (FlowlineApiException $e) {
			$this->assertSame(FlowlineApiException::REASON_INVALID_FORMAT, $e->getReason());
		}
	}

	public function testLinkRejectsMissingScopes(): void {
		$this->client->method('me')->willReturn([
			'username' => 'lus',
			'scopes' => ['machines:read'],
		]);
		$this->mapper->expects($this->never())->method('insert');

		try {
			$this->service->link('user1', 'flapi_' . str_repeat('b', 32));
			$this->fail('Expected FlowlineApiException');
		} catch (FlowlineApiException $e) {
			$this->assertSame(FlowlineApiException::REASON_FORBIDDEN, $e->getReason());
		}
	}

	public function testUnlinkDeletesAccount(): void {
		$existing = new Account();
		$existing->setUserId('user1');

		$this->mapper->method('findByUser')->willReturn($existing);
		$this->mapper->expects($this->once())->method('delete')->with($existing);

		$this->service->unlink('user1');
	}

	public function testUnlinkIsNoopWhenNotLinked(): void {
		$this->mapper->method('findByUser')->willReturn(null);
		$this->mapper->expects($this->never())->method('delete');

		$this->service->unlink('user1');
	}

	public function testGetPublicAccountReturnsNullWhenAbsent(): void {
		$this->mapper->method('findByUser')->willReturn(null);

		$this->assertNull($this->service->getPublicAccount('user1'));
	}

	/**
	 * Compte lié minimal (jeton chiffré factice).
	 */
	private function linkedAccount(string $encryptedToken): Account {
		$account = new Account();
		$account->setUserId('user1');
		$account->setTokenEncrypted($encryptedToken);
		$account->setAccountJson('{}');
		$account->setCreatedAt('2026-01-01 00:00:00');
		$account->setUpdatedAt('2026-01-01 00:00:00');

		return $account;
	}

	public function testGetMachinesRequiresLinkedAccount(): void {
		$this->mapper->method('findByUser')->willReturn(null);
		$this->client->expects($this->never())->method('machines');

		try {
			$this->service->getMachines('user1');
			$this->fail('Expected FlowlineApiException');
		} catch (FlowlineApiException $e) {
			$this->assertSame(FlowlineApiException::REASON_NOT_LINKED, $e->getReason());
		}
	}

	public function testGetMachinesUsesDecryptedTokenAndShapesPayload(): void {
		$this->mapper->method('findByUser')->willReturn($this->linkedAccount('encrypted-token'));
		$this->crypto->expects($this->once())->method('decrypt')->with('encrypted-token')->willReturn('flapi_clear');
		$this->client->expects($this->once())->method('machines')
			->with('flapi_clear', 2, 100)
			->willReturn([
				'total' => 3,
				'machines' => [['id' => 1], ['id' => 2], ['id' => 3]],
				'ignored' => 'extra',
			]);

		$result = $this->service->getMachines('user1', 2, 100);

		$this->assertSame(3, $result['total']);
		$this->assertCount(3, $result['machines']);
		$this->assertSame(['id' => 1], $result['machines'][0]);
	}

	public function testGetSessionsPassesActiveFilterAndShapesPayload(): void {
		$this->mapper->method('findByUser')->willReturn($this->linkedAccount('encrypted-token'));
		$this->crypto->method('decrypt')->willReturn('flapi_clear');
		$this->client->expects($this->once())->method('sessions')
			->with('flapi_clear', true, 1, 20)
			->willReturn(['total' => 1, 'sessions' => [['id' => 9, 'active' => true]]]);

		$result = $this->service->getSessions('user1', true, 1, 20);

		$this->assertSame(1, $result['total']);
		$this->assertSame(9, $result['sessions'][0]['id']);
	}

	public function testGetSessionsToleratesMalformedPayload(): void {
		$this->mapper->method('findByUser')->willReturn($this->linkedAccount('encrypted-token'));
		$this->crypto->method('decrypt')->willReturn('flapi_clear');
		$this->client->method('sessions')->willReturn(['sessions' => 'oops']);

		$result = $this->service->getSessions('user1');

		$this->assertSame(0, $result['total']);
		$this->assertSame([], $result['sessions']);
	}

	public function testGetMachinesFailsWhenStoredTokenIsUnreadable(): void {
		$this->mapper->method('findByUser')->willReturn($this->linkedAccount('corrupted'));
		$this->crypto->method('decrypt')->willThrowException(new \Exception('HMAC mismatch'));
		$this->client->expects($this->never())->method('machines');

		try {
			$this->service->getMachines('user1');
			$this->fail('Expected FlowlineApiException');
		} catch (FlowlineApiException $e) {
			$this->assertSame(FlowlineApiException::REASON_INVALID_TOKEN, $e->getReason());
		}
	}

	public function testGetInvitationsShapesPayload(): void {
		$this->mapper->method('findByUser')->willReturn($this->linkedAccount('encrypted-token'));
		$this->crypto->method('decrypt')->willReturn('flapi_clear');
		$this->client->expects($this->once())->method('invitations')
			->with('flapi_clear')
			->willReturn(['invitations' => [['device_id' => '159627370']]]);

		$result = $this->service->getInvitations('user1');

		$this->assertCount(1, $result['invitations']);
		$this->assertSame('159627370', $result['invitations'][0]['device_id']);
	}

	public function testOpenInvitationNormalizesDeviceIdAndShapesPayload(): void {
		$this->mapper->method('findByUser')->willReturn($this->linkedAccount('encrypted-token'));
		$this->crypto->method('decrypt')->willReturn('flapi_clear');
		$this->client->expects($this->once())->method('openInvitation')
			->with('flapi_clear', '159627370')
			->willReturn(['id' => '159627370', 'window' => true, 'expires_at' => '2026-10-16 20:00:00']);

		$result = $this->service->openInvitation('user1', ' 159 627 370 ');

		$this->assertSame('159627370', $result['id']);
		$this->assertTrue($result['window']);
		$this->assertSame('2026-10-16 20:00:00', $result['expires_at']);
		$this->assertSame('', $result['reason']);
	}

	public function testOpenInvitationReportsDeviceWithAccount(): void {
		$this->mapper->method('findByUser')->willReturn($this->linkedAccount('encrypted-token'));
		$this->crypto->method('decrypt')->willReturn('flapi_clear');
		$this->client->method('openInvitation')
			->willReturn(['id' => '42', 'window' => false, 'reason' => 'device_has_account']);

		$result = $this->service->openInvitation('user1', '42');

		$this->assertFalse($result['window']);
		$this->assertSame('device_has_account', $result['reason']);
	}

	public function testOpenInvitationRejectsEmptyDeviceId(): void {
		$this->client->expects($this->never())->method('openInvitation');

		try {
			$this->service->openInvitation('user1', '   ');
			$this->fail('Expected FlowlineApiException');
		} catch (FlowlineApiException $e) {
			$this->assertSame(FlowlineApiException::REASON_INVALID_FORMAT, $e->getReason());
		}
	}

	public function testEndInvitationRejectsTooLongDeviceId(): void {
		$this->client->expects($this->never())->method('endInvitation');

		try {
			$this->service->endInvitation('user1', str_repeat('a', 65));
			$this->fail('Expected FlowlineApiException');
		} catch (FlowlineApiException $e) {
			$this->assertSame(FlowlineApiException::REASON_INVALID_FORMAT, $e->getReason());
		}
	}

	public function testEndInvitationShapesPayload(): void {
		$this->mapper->method('findByUser')->willReturn($this->linkedAccount('encrypted-token'));
		$this->crypto->method('decrypt')->willReturn('flapi_clear');
		$this->client->expects($this->once())->method('endInvitation')
			->with('flapi_clear', '159627370')
			->willReturn(['id' => '159627370', 'ended' => true]);

		$result = $this->service->endInvitation('user1', '159627370');

		$this->assertSame('159627370', $result['id']);
		$this->assertTrue($result['ended']);
	}
}
