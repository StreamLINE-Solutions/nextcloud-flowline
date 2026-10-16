<?php

declare(strict_types=1);

/** @var array $_ */
/** @var \OCP\IL10N $l */

$statusMessages = [
	'linked' => [$l->t('Your FlowLINE account has been linked.'), 'success'],
	'unlinked' => [$l->t('Your FlowLINE account has been unlinked.'), 'success'],
	'invalid_format' => [$l->t('This does not look like a FlowLINE API token (expected prefix "flapi_").'), 'error'],
	'invalid_token' => [$l->t('FlowLINE rejected this token: unknown or revoked.'), 'error'],
	'missing_scope' => [$l->t('This token is missing required permissions (machines:read, sessions:read).'), 'error'],
	'network' => [$l->t('The FlowLINE API is unreachable right now. Please try again later.'), 'error'],
	'disabled' => [$l->t('FlowLINE is disabled by your administrator.'), 'error'],
	'restricted' => [$l->t('FlowLINE is not enabled for your account.'), 'error'],
	'error' => [$l->t('Something went wrong. Please try again.'), 'error'],
];
$status = (string)$_['status'];
?>
<div class="section" id="flowline-personal">
	<h2><?php p($l->t('FlowLINE')); ?></h2>

	<?php if ($status !== '' && isset($statusMessages[$status])): ?>
		<div class="msg <?php p($statusMessages[$status][1]); ?>"><?php p($statusMessages[$status][0]); ?></div>
	<?php endif; ?>

	<?php if (!$_['enabled']): ?>
		<p class="settings-hint"><?php p($l->t('FlowLINE is disabled by your administrator.')); ?></p>
	<?php elseif (!$_['allowed']): ?>
		<p class="settings-hint"><?php p($l->t('FlowLINE is not enabled for your account.')); ?></p>
	<?php elseif ($_['linked']): ?>
		<p>
			<?php p($l->t('Linked FlowLINE account:')); ?>
			<strong><?php p((string)$_['account']['display_name']); ?></strong>
			(<?php p((string)$_['account']['username']); ?> — <?php p((string)$_['account']['plan_name']); ?>)
		</p>
		<p class="settings-hint"><?php p($l->t('Connected to %s', [(string)$_['apiUrl']])); ?></p>
		<form method="POST" action="<?php p((string)$_['unlinkUrl']); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
			<button type="submit" class="button"><?php p($l->t('Unlink account')); ?></button>
		</form>
	<?php else: ?>
		<p class="settings-hint"><?php p($l->t('Create an API token in your FlowLINE portal (API tab), then paste it here. It is stored encrypted on this server and never displayed again.')); ?></p>
		<form method="POST" action="<?php p((string)$_['linkUrl']); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
			<input type="password" name="token" value="" placeholder="flapi_…" autocomplete="off">
			<button type="submit" class="button"><?php p($l->t('Link account')); ?></button>
		</form>
		<p class="settings-hint"><?php p($l->t('Connected to %s', [(string)$_['apiUrl']])); ?></p>
	<?php endif; ?>
</div>