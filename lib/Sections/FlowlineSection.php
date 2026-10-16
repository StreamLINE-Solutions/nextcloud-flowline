<?php

declare(strict_types=1);

namespace OCA\Flowline\Sections;

use OCA\Flowline\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Section « FlowLINE » des réglages (personnels et admin).
 *
 * Enregistrée via `appinfo/info.xml`.
 *
 * @psalm-suppress UnusedClass
 */
class FlowlineSection implements IIconSection {
	public function __construct(
		private IURLGenerator $url,
		private IL10N $l,
	) {
	}

	public function getID(): string {
		return 'flowline';
	}

	public function getName(): string {
		return $this->l->t('FlowLINE');
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->url->imagePath(Application::APP_ID, 'app.svg');
	}
}
