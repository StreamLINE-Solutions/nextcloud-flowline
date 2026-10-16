<?php

declare(strict_types=1);

/** @var array $_ */
/** @var \OCP\IL10N $l */

$statusMessages = [
	'saved' => [$l->t('Settings saved.'), 'success'],
	'invalid_url' => [$l->t('The API URL is not valid (expected http(s)://…).'), 'error'],
];
$status = (string)$_['status'];
?>
<div class="section" id="flowline-admin">
	<h2><?php p($l->t('FlowLINE')); ?></h2>

	<?php if ($status !== '' && isset($statusMessages[$status])): ?>
		<div class="msg <?php p($statusMessages[$status][1]); ?>"><?php p($statusMessages[$status][0]); ?></div>
	<?php endif; ?>

	<form method="POST" action="<?php p((string)$_['saveUrl']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">

		<p>
			<label for="flowline-api-url"><?php p($l->t('FlowLINE API URL')); ?></label><br>
			<input type="text" id="flowline-api-url" name="apiUrl" value="<?php p((string)$_['apiUrl']); ?>" placeholder="<?php p((string)$_['defaultApiUrl']); ?>">
			<br>
			<span class="settings-hint"><?php p($l->t('Default: %s — change only for a self-hosted FlowLINE API.', [(string)$_['defaultApiUrl']])); ?></span>
		</p>

		<p>
			<label for="flowline-groups"><?php p($l->t('Restrict to groups (none selected = all users)')); ?></label><br>
			<select id="flowline-groups" name="groups[]" multiple size="6">
				<?php foreach ($_['groups'] as $group): ?>
					<option value="<?php p((string)$group['id']); ?>" <?php if (in_array($group['id'], $_['restrictedGroups'], true)) {
						p('selected');
					} ?>>
						<?php p((string)$group['name']); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<input type="checkbox" id="flowline-enabled" name="enabled" value="1" <?php if ($_['enabled']) {
				p('checked');
			} ?>>
			<label for="flowline-enabled"><?php p($l->t('Enable FlowLINE for users')); ?></label>
		</p>

		<button type="submit" class="button"><?php p($l->t('Save')); ?></button>
	</form>
</div>