<?php

declare(strict_types=1);

namespace OCA\Flowline\Tests\Unit\BackgroundJob;

use OCA\Flowline\BackgroundJob\PollEventsJob;
use OCA\Flowline\Db\Account;
use OCA\Flowline\Db\AccountMapper;
use OCA\Flowline\Service\ConfigService;
use OCA\Flowline\Service\FlowlineApiException;
use OCA\Flowline\Service\FlowlineClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PollEventsJobTest extends TestCase {
	private AccountMapper&MockObject $mapper;
	private FlowlineClient&MockObject $client;
	private ConfigService&MockObject $config;
	private ICrypto&MockObject $crypto;
	private INotificationManager&MockObject $notifications;
	private LoggerInterface&MockObject $logger;
	private PollEventsJob $job;

	protected function setUp(): void {
		$this->mapper = $this->createMock(AccountMapper::class);
		$this->client = $this->createMock(FlowlineClient::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->notifications = $this->createMock(INotificationManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->config->method('isEnabled')->willReturn(true);

		$this->job = new PollEventsJob(
			$this->createMock(ITimeFactory::class),
			$this->mapper,
			$this->client,
			$this->config,
			$this->crypto,
			$this->notifications,
			$this->logger,
		);
	}

	/**
	 * Exécute le job (méthode protégée, appelée par le cron).
	 */
	private function runJob(?PollEventsJob $job = null): void {
		$method = new \ReflectionMethod(PollEventsJob::class, 'run');
		$method->invoke($job ?? $this->job, null);
	}

	private function account(?string $cursor = null): Account {
		$account = new Account();
		$account->setUserId('user1');
		$account->setTokenEncrypted('encrypted-token');
		$account->setAccountJson('{}');
		$account->setLastEventAt($cursor);
		$account->setCreatedAt('2026-10-16 12:00:00');
		$account->setUpdatedAt('2026-10-16 12:00:00');

		return $account;
	}

	/**
	 * @param list<array<string, mixed>> $events
	 * @return array<string, mixed>
	 */
	private function payload(array $events, string $nextSince): array {
		return ['since' => '2026-10-17 09:00:00', 'events' => $events, 'next_since' => $nextSince];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function event(string $type, int $sessionId, string $hostname): array {
		return [
			'type' => $type,
			'at' => '2026-10-17 10:00:00',
			'session_id' => $sessionId,
			'device_id' => '159627370',
			'hostname' => $hostname,
			'os' => 'linux',
		];
	}

	/**
	 * Notification factice : appels chaînés renvoyant le mock ; le sujet est
	 * capturé pour les assertions.
	 *
	 * @param list<array{subject: string, parameters: array<string, string>}> $subjects
	 */
	private function notificationMock(array &$subjects): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters = []) use (&$subjects, $notification): INotification {
				$subjects[] = ['subject' => $subject, 'parameters' => $parameters];
				return $notification;
			},
		);

		return $notification;
	}

	public function testFirstRunSetsCursorWithoutNotifying(): void {
		$account = $this->account(null);
		$this->mapper->method('findAllAccounts')->willReturn([$account]);
		$this->crypto->method('decrypt')->willReturn('flapi_clear');
		$this->client->expects($this->once())->method('events')
			->with('flapi_clear', '', 100)
			->willReturn($this->payload([
				$this->event('session_started', 1, 'pop-os'),
				$this->event('session_ended', 1, 'pop-os'),
			], '2026-10-17 10:00:00'));

		$this->notifications->expects($this->never())->method('notify');
		$this->mapper->expects($this->once())->method('update')->with($this->callback(
			static fn (Account $updated): bool => $updated->getLastEventAt() === '2026-10-17 10:00:00',
		));

		$this->runJob();
	}

	public function testNotifiesStartedAndEndedAndAdvancesCursor(): void {
		$account = $this->account('2026-10-17 09:00:00');
		$this->mapper->method('findAllAccounts')->willReturn([$account]);
		$this->crypto->method('decrypt')->willReturn('flapi_clear');
		$this->client->expects($this->once())->method('events')
			->with('flapi_clear', '2026-10-17 09:00:00', 100)
			->willReturn($this->payload([
				$this->event('session_started', 42, 'pop-os'),
				$this->event('session_ended', 43, 'mac-mini'),
			], '2026-10-17 10:30:00'));

		$subjects = [];
		$notification = $this->notificationMock($subjects);
		$notification->expects($this->exactly(2))->method('setUser')->with('user1')->willReturnSelf();
		$this->notifications->method('createNotification')->willReturn($notification);
		$this->notifications->expects($this->exactly(2))->method('notify')->with($notification);
		$this->mapper->expects($this->once())->method('update')->with($this->callback(
			static fn (Account $updated): bool => $updated->getLastEventAt() === '2026-10-17 10:30:00',
		));

		$this->runJob();

		$this->assertSame([
			[
				'subject' => 'session_started',
				'parameters' => ['device' => 'pop-os', 'device_id' => '159627370'],
			],
			[
				'subject' => 'session_ended',
				'parameters' => ['device' => 'mac-mini', 'device_id' => '159627370'],
			],
		], $subjects);
	}

	public function testUnknownEventTypesAreIgnoredButCursorAdvances(): void {
		$account = $this->account('2026-10-17 09:00:00');
		$this->mapper->method('findAllAccounts')->willReturn([$account]);
		$this->crypto->method('decrypt')->willReturn('flapi_clear');
		$this->client->method('events')
			->willReturn($this->payload([$this->event('something_else', 7, 'pop-os')], '2026-10-17 11:00:00'));

		$this->notifications->expects($this->never())->method('notify');
		$this->mapper->expects($this->once())->method('update')->with($this->callback(
			static fn (Account $updated): bool => $updated->getLastEventAt() === '2026-10-17 11:00:00',
		));

		$this->runJob();
	}

	public function testMalformedEventsPayloadIsTolerated(): void {
		$account = $this->account('2026-10-17 09:00:00');
		$this->mapper->method('findAllAccounts')->willReturn([$account]);
		$this->crypto->method('decrypt')->willReturn('flapi_clear');
		$this->client->method('events')
			->willReturn(['events' => 'oops', 'next_since' => '2026-10-17 12:00:00']);

		$this->notifications->expects($this->never())->method('notify');
		$this->mapper->expects($this->once())->method('update')->with($this->callback(
			static fn (Account $updated): bool => $updated->getLastEventAt() === '2026-10-17 12:00:00',
		));

		$this->runJob();
	}

	public function testBacklogIsDrainedOnFirstRunWhenBatchIsFull(): void {
		$account = $this->account(null);
		$this->mapper->method('findAllAccounts')->willReturn([$account]);
		$this->crypto->method('decrypt')->willReturn('flapi_clear');

		$fullBatch = array_fill(0, 100, $this->event('session_started', 1, 'pop-os'));
		$this->client->expects($this->exactly(2))->method('events')
			->willReturnOnConsecutiveCalls(
				$this->payload($fullBatch, '2026-10-17 10:00:00'),
				$this->payload([$this->event('session_ended', 1, 'pop-os')], '2026-10-17 10:30:00'),
			);

		$this->notifications->expects($this->never())->method('notify');
		$this->mapper->expects($this->once())->method('update')->with($this->callback(
			static fn (Account $updated): bool => $updated->getLastEventAt() === '2026-10-17 10:30:00',
		));

		$this->runJob();
	}

	public function testApiErrorLeavesCursorUntouched(): void {
		$account = $this->account('2026-10-17 09:00:00');
		$this->mapper->method('findAllAccounts')->willReturn([$account]);
		$this->crypto->method('decrypt')->willReturn('flapi_clear');
		$this->client->method('events')->willThrowException(
			new FlowlineApiException(FlowlineApiException::REASON_INVALID_TOKEN, 'revoked', 401),
		);

		$this->notifications->expects($this->never())->method('notify');
		$this->mapper->expects($this->never())->method('update');

		$this->runJob();
	}

	public function testUnreadableTokenSkipsAccount(): void {
		$account = $this->account('2026-10-17 09:00:00');
		$this->mapper->method('findAllAccounts')->willReturn([$account]);
		$this->crypto->method('decrypt')->willThrowException(new \Exception('HMAC mismatch'));

		$this->client->expects($this->never())->method('events');
		$this->notifications->expects($this->never())->method('notify');
		$this->mapper->expects($this->never())->method('update');

		$this->runJob();
	}

	public function testDisabledAppSkipsPollingEntirely(): void {
		$config = $this->createMock(ConfigService::class);
		$config->method('isEnabled')->willReturn(false);
		$job = new PollEventsJob(
			$this->createMock(ITimeFactory::class),
			$this->mapper,
			$this->client,
			$config,
			$this->crypto,
			$this->notifications,
			$this->logger,
		);

		$this->mapper->expects($this->never())->method('findAllAccounts');

		$this->runJob($job);
	}
}
