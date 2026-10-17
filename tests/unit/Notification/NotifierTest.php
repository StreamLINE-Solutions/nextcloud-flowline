<?php

declare(strict_types=1);

namespace OCA\Flowline\Tests\Unit\Notification;

use OCA\Flowline\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase {
	private IFactory&MockObject $l10nFactory;
	private IURLGenerator&MockObject $url;
	private Notifier $notifier;

	protected function setUp(): void {
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->notifier = new Notifier($this->l10nFactory, $this->url);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static function (string $text, array|string $parameters = []): string {
				$parameters = is_array($parameters) ? $parameters : [$parameters];
				return $parameters === [] ? $text : vsprintf($text, $parameters);
			},
		);
		$this->l10nFactory->method('get')->willReturn($l);

		$this->url->method('linkToRouteAbsolute')->willReturn('https://cloud.example.com/apps/flowline/');
		$this->url->method('imagePath')->willReturn('/apps/flowline/img/app.svg');
		$this->url->method('getAbsoluteURL')->willReturn('https://cloud.example.com/apps/flowline/img/app.svg');
	}

	/**
	 * @param array<string, string> $parameters
	 */
	private function notification(string $subject, array $parameters, string $app = 'flowline'): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		foreach (['setParsedSubject', 'setRichSubject', 'setLink', 'setIcon'] as $method) {
			$notification->method($method)->willReturnSelf();
		}

		return $notification;
	}

	public function testPrepareSessionStartedUsesHostname(): void {
		$notification = $this->notification('session_started', [
			'device' => 'pop-os',
			'device_id' => '159627370',
		]);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('Support session started on pop-os')->willReturnSelf();
		$notification->expects($this->once())->method('setRichSubject')
			->with('Support session started on pop-os')->willReturnSelf();
		$notification->expects($this->once())->method('setLink')
			->with('https://cloud.example.com/apps/flowline/')->willReturnSelf();

		$result = $this->notifier->prepare($notification, 'en');

		$this->assertSame($notification, $result);
	}

	public function testPrepareSessionEndedUsesHostname(): void {
		$notification = $this->notification('session_ended', [
			'device' => 'mac-mini',
			'device_id' => '42',
		]);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('Support session ended on mac-mini')->willReturnSelf();

		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepareFallsBackToDeviceIdWhenHostnameIsEmpty(): void {
		$notification = $this->notification('session_started', [
			'device' => '  ',
			'device_id' => '159627370',
		]);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('Support session started on 159627370')->willReturnSelf();

		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepareRejectsForeignApp(): void {
		$notification = $this->notification('session_started', [], 'other-app');

		$this->expectException(UnknownNotificationException::class);

		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepareRejectsUnknownSubject(): void {
		$notification = $this->notification('something_else', []);

		$this->expectException(UnknownNotificationException::class);

		$this->notifier->prepare($notification, 'en');
	}
}
