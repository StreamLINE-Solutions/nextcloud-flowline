<?php

declare(strict_types=1);

namespace OCA\Flowline\Tests\Unit\Listener;

use OCA\Flowline\Listener\UserDeletedListener;
use OCA\Flowline\Service\AccountService;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UserDeletedListenerTest extends TestCase {
	private AccountService&MockObject $accounts;
	private UserDeletedListener $listener;

	protected function setUp(): void {
		$this->accounts = $this->createMock(AccountService::class);
		$this->listener = new UserDeletedListener($this->accounts);
	}

	public function testDeletesTheLinkedAccount(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');

		$this->accounts->expects($this->once())->method('unlink')->with('user1');

		$this->listener->handle(new UserDeletedEvent($user));
	}

	public function testIgnoresOtherEvents(): void {
		$this->accounts->expects($this->never())->method('unlink');

		$this->listener->handle(new Event());
	}
}
