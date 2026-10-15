<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\Flowline\AppInfo\Application::APP_ID, OCA\Flowline\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Flowline\AppInfo\Application::APP_ID, OCA\Flowline\AppInfo\Application::APP_ID . '-main');

?>

<div id="flowline"></div>
