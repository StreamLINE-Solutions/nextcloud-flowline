<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { formatRelativeTime, translate as t } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

interface AccountInfo {
	username: string
	display_name: string
	plan_name: string
	scopes: string[]
	support_module_url: string
	linked_at: string | null
}

interface AccountPayload {
	linked: boolean
	account: AccountInfo | null
	api_url: string
}

interface Machine {
	id: number
	hostname: string
	username: string
	os: string
	os_version: string
	online: boolean
	last_seen: string
	shared: boolean
	group_name: string
	source: string
}

interface Session {
	id: number
	device_id: string
	ip: string
	hostname: string
	os: string
	os_version: string
	started_at: string
	last_seen: string
	ended_at: string | null
	active: boolean
	duration_seconds: number | null
}

interface Invitation {
	device_id: string
	created_at: string
	expires_at: string
	last_conn_at: string | null
	quit_sent: number
	hostname: string | null
	os: string | null
	last_seen: string | null
}

interface SupportFeedback {
	type: 'success' | 'warning' | 'error'
	text: string
}

const loading = ref(true)
const error = ref('')
const linked = ref(false)
const account = ref<AccountInfo | null>(null)
const apiUrl = ref('')
const activeTab = ref<'support' | 'sessions' | 'account'>('support')

const machinesLoading = ref(false)
const machinesError = ref('')
const machines = ref<Machine[]>([])
const machinesTotal = ref(0)

const sessionsLoading = ref(false)
const sessionsError = ref('')
const activeSessions = ref<Session[]>([])
const recentSessions = ref<Session[]>([])
const needsRelink = ref(false)

const invitationsLoading = ref(false)
const invitationsError = ref('')
const invitations = ref<Invitation[]>([])
const supportDeviceId = ref('')
const supportBusy = ref(false)
const supportFeedback = ref<SupportFeedback | null>(null)
const linkCopied = ref(false)

const canInvite = computed(() => account.value?.scopes.includes('invitations:write') ?? false)
const busy = computed(() => machinesLoading.value || sessionsLoading.value || invitationsLoading.value)

const settingsUrl = generateUrl('/settings/user/flowline')

let pollTimer: ReturnType<typeof setInterval> | null = null

// Une fenêtre support active peut recevoir la connexion du module à tout
// moment : on rafraîchit régulièrement pour voir le poste et ses sessions
// apparaître sans action manuelle.
watch(invitations, () => {
	if (invitations.value.length > 0 && pollTimer === null) {
		pollTimer = setInterval(refresh, 30000)
	} else if (invitations.value.length === 0 && pollTimer !== null) {
		clearInterval(pollTimer)
		pollTimer = null
	}
})

onBeforeUnmount(() => {
	if (pollTimer !== null) {
		clearInterval(pollTimer)
		pollTimer = null
	}
})

onMounted(load)

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		const response = await axios.get(generateOcsUrl('apps/flowline/api/v1/account'))
		const payload = response.data.ocs.data as AccountPayload
		linked.value = payload.linked
		account.value = payload.account
		apiUrl.value = payload.api_url
	} catch {
		error.value = t('flowline', 'Could not load your FlowLINE account.')
	} finally {
		loading.value = false
	}

	if (linked.value) {
		await refresh()
	}
}

async function refresh(): Promise<void> {
	await Promise.all([loadMachines(), loadSessions(), loadInvitations()])
}

async function loadMachines(): Promise<void> {
	machinesLoading.value = true
	machinesError.value = ''
	try {
		const response = await axios.get(generateOcsUrl('apps/flowline/api/v1/machines'), {
			params: { pageSize: 500 },
		})
		const payload = response.data.ocs.data as { total: number, machines: Machine[] }
		machines.value = payload.machines
		machinesTotal.value = payload.total
	} catch (e) {
		machinesError.value = describeError(e)
	} finally {
		machinesLoading.value = false
	}
}

async function loadSessions(): Promise<void> {
	sessionsLoading.value = true
	sessionsError.value = ''
	try {
		const [active, recent] = await Promise.all([
			axios.get(generateOcsUrl('apps/flowline/api/v1/sessions'), { params: { active: '1', pageSize: 100 } }),
			axios.get(generateOcsUrl('apps/flowline/api/v1/sessions'), { params: { active: '0', pageSize: 20 } }),
		])
		activeSessions.value = (active.data.ocs.data as { sessions: Session[] }).sessions
		recentSessions.value = (recent.data.ocs.data as { sessions: Session[] }).sessions
	} catch (e) {
		sessionsError.value = describeError(e)
	} finally {
		sessionsLoading.value = false
	}
}

async function loadInvitations(): Promise<void> {
	invitationsLoading.value = true
	invitationsError.value = ''
	try {
		const response = await axios.get(generateOcsUrl('apps/flowline/api/v1/invitations'))
		// L'API conserve les fenêtres terminées (`quit_sent` = 1, fin envoyée au
		// module) jusqu'à leur expiration : elles ne sont plus actives ici.
		invitations.value = (response.data.ocs.data as { invitations: Invitation[] }).invitations
			.filter((invitation) => Number(invitation.quit_sent) !== 1)
	} catch (e) {
		invitationsError.value = describeError(e)
	} finally {
		invitationsLoading.value = false
	}
}

async function openSupportWindow(): Promise<void> {
	supportBusy.value = true
	supportFeedback.value = null
	try {
		const response = await axios.post(generateOcsUrl('apps/flowline/api/v1/invitations'), {
			deviceId: supportDeviceId.value,
		})
		const payload = response.data.ocs.data as { id: string, window: boolean, expires_at: string, reason: string }
		if (payload.window) {
			supportFeedback.value = {
				type: 'success',
				text: t('flowline', 'Support window opened for {id} (expires {when}). The machine appears in the Machines list once its module is connected.', {
					id: payload.id,
					when: relative(payload.expires_at),
				}),
			}
			supportDeviceId.value = ''
		} else {
			supportFeedback.value = {
				type: 'warning',
				text: t('flowline', 'This device already has a FlowLINE account — no support window is needed.'),
			}
		}
		await Promise.all([loadInvitations(), loadMachines(), loadSessions()])
	} catch (e) {
		supportFeedback.value = { type: 'error', text: describeSupportError(e) }
	} finally {
		supportBusy.value = false
	}
}

async function endSupportWindow(invitation: Invitation): Promise<void> {
	supportBusy.value = true
	supportFeedback.value = null
	try {
		await axios.delete(generateOcsUrl('apps/flowline/api/v1/invitations/{deviceId}', {
			deviceId: invitation.device_id,
		}))
		supportFeedback.value = { type: 'success', text: t('flowline', 'Support window ended.') }
		await Promise.all([loadInvitations(), loadMachines(), loadSessions()])
	} catch (e) {
		supportFeedback.value = { type: 'error', text: describeSupportError(e) }
	} finally {
		supportBusy.value = false
	}
}

async function copyModuleLink(): Promise<void> {
	try {
		await navigator.clipboard.writeText(account.value?.support_module_url ?? '')
		linkCopied.value = true
		setTimeout(() => {
			linkCopied.value = false
		}, 3000)
	} catch {
		linkCopied.value = false
	}
}

function reasonOf(e: unknown): string {
	const data = (e as { response?: { data?: { ocs?: { data?: { error?: unknown } } } } })?.response?.data?.ocs?.data
	return typeof data?.error === 'string' ? data.error : 'network'
}

function describeError(e: unknown): string {
	const reason = reasonOf(e)
	if (reason === 'invalid_token') {
		needsRelink.value = true
		return t('flowline', 'FlowLINE rejected your token.')
	}
	if (reason === 'forbidden') {
		return t('flowline', 'Your token is missing the required permissions.')
	}
	if (reason === 'rate_limited') {
		return t('flowline', 'Too many requests to FlowLINE. Try again in a moment.')
	}
	return t('flowline', 'FlowLINE is unreachable right now.')
}

function describeSupportError(e: unknown): string {
	const reason = reasonOf(e)
	if (reason === 'invalid_format') {
		return t('flowline', 'Invalid device id.')
	}
	if (reason === 'conflict') {
		return t('flowline', 'Another account already has an active support window on this device.')
	}
	if (reason === 'not_found') {
		return t('flowline', 'No active support window for this device.')
	}
	if (reason === 'rate_limited') {
		return t('flowline', 'Too many active support windows.')
	}
	return describeError(e)
}

function machineName(machine: Machine): string {
	if (machine.hostname !== '') {
		return machine.hostname
	}
	return machine.username !== '' ? machine.username : `#${machine.id}`
}

function sessionName(session: Session): string {
	return session.hostname !== '' ? session.hostname : session.device_id
}

function invitationName(invitation: Invitation): string {
	return invitation.hostname !== null && invitation.hostname !== '' ? invitation.hostname : invitation.device_id
}

function osLabel(os: string, version: string): string {
	return [os, version].filter((part) => part !== '').join(' ')
}

function sourceLabel(machine: Machine): string {
	if (machine.source === 'support') {
		return t('flowline', 'Support')
	}
	if (machine.source === 'fleet') {
		return machine.group_name !== '' ? machine.group_name : t('flowline', 'Shared fleet')
	}
	return ''
}

function parseUtc(value: string): Date | null {
	if (value === '') {
		return null
	}
	const date = new Date(value.replace(' ', 'T') + 'Z')
	return Number.isNaN(date.getTime()) ? null : date
}

function relative(value: string): string {
	const date = parseUtc(value)
	return date === null ? '' : formatRelativeTime(date)
}

function duration(seconds: number | null): string {
	if (seconds === null) {
		return ''
	}
	const minutes = Math.max(1, Math.round(seconds / 60))
	if (minutes < 60) {
		return t('flowline', '{minutes} min', { minutes })
	}
	return t('flowline', '{hours} h {minutes} min', { hours: Math.floor(minutes / 60), minutes: minutes % 60 })
}

function sessionEndedLabel(session: Session): string {
	const started = relative(session.started_at)
	const length = duration(session.duration_seconds)
	return length === '' ? started : `${started} · ${length}`
}

function isModuleOnline(invitation: Invitation): boolean {
	if (invitation.last_seen === null || invitation.last_seen === '') {
		return false
	}
	const seen = parseUtc(invitation.last_seen)
	// Tolerant : le serveur considere le module « present » sous 45 s de heartbeat.
	return seen !== null && Date.now() - seen.getTime() < 90000
}

function invitationStatus(invitation: Invitation): string {
	const parts: string[] = []
	if (isModuleOnline(invitation)) {
		parts.push(t('flowline', 'Module online'))
	} else if (invitation.last_seen !== null && invitation.last_seen !== '') {
		parts.push(t('flowline', 'Module offline ({when})', { when: relative(invitation.last_seen) }))
	} else {
		parts.push(t('flowline', 'Module never seen'))
	}
	parts.push(invitation.last_conn_at !== null && invitation.last_conn_at !== ''
		? t('flowline', 'Session active')
		: t('flowline', 'No support session yet'))
	parts.push(t('flowline', 'expires {when}', { when: relative(invitation.expires_at) }))
	return parts.join(' · ')
}
</script>

<template>
	<NcContent app-name="flowline">
		<NcAppContent>
			<div class="flowline-console">
				<NcLoadingIcon v-if="loading" :size="44" />
				<NcEmptyContent v-else-if="error !== ''"
					:name="t('flowline', 'Error')"
					:description="error" />
				<NcEmptyContent v-else-if="!linked"
					:name="t('flowline', 'No FlowLINE account linked')"
					:description="t('flowline', 'Link your FlowLINE account in your personal settings to see your machines and sessions here.')">
					<template #action>
						<NcButton :href="settingsUrl" variant="primary">
							{{ t('flowline', 'Open settings') }}
						</NcButton>
					</template>
				</NcEmptyContent>
				<template v-else-if="account">
					<div class="flowline-header">
						<h2>FlowLINE</h2>
						<NcButton :disabled="busy" @click="refresh">
							{{ t('flowline', 'Refresh') }}
						</NcButton>
					</div>

					<NcNoteCard v-if="needsRelink" type="warning">
						<p>{{ t('flowline', 'Link your account again to see your machines and sessions.') }}</p>
						<NcButton :href="settingsUrl" variant="primary">
							{{ t('flowline', 'Open settings') }}
						</NcButton>
					</NcNoteCard>

					<div class="flowline-tabs">
						<NcButton :variant="activeTab === 'support' ? 'primary' : 'tertiary'"
							@click="activeTab = 'support'">
							{{ t('flowline', 'Support') }}
						</NcButton>
						<NcButton :variant="activeTab === 'sessions' ? 'primary' : 'tertiary'"
							@click="activeTab = 'sessions'">
							{{ t('flowline', 'Recent connections') }}
						</NcButton>
						<NcButton :variant="activeTab === 'account' ? 'primary' : 'tertiary'"
							@click="activeTab = 'account'">
							{{ t('flowline', 'Linked account') }}
						</NcButton>
					</div>

					<section v-show="activeTab === 'support'" class="flowline-section">
						<h3>
							{{ t('flowline', 'Machines') }}
							<span v-if="machinesTotal > 0" class="flowline-count">{{ machinesTotal }}</span>
						</h3>
						<NcLoadingIcon v-if="machinesLoading" :size="32" />
						<NcNoteCard v-else-if="machinesError !== ''" type="error">
							{{ machinesError }}
						</NcNoteCard>
						<p v-else-if="machines.length === 0" class="flowline-hint">
							{{ t('flowline', 'No machine is visible for this FlowLINE account.') }}
						</p>
						<ul v-else class="flowline-list">
							<li v-for="machine in machines" :key="machine.id" class="flowline-row">
								<span class="flowline-dot" :class="{ 'flowline-dot--online': machine.online }" />
								<span class="flowline-primary">{{ machineName(machine) }}</span>
								<span class="flowline-meta">{{ osLabel(machine.os, machine.os_version) }}</span>
								<span v-if="machine.source !== 'account'" class="flowline-chip">{{ sourceLabel(machine) }}</span>
								<span class="flowline-meta flowline-right">
									{{ machine.online ? t('flowline', 'Online') : relative(machine.last_seen) }}
								</span>
							</li>
						</ul>
					</section>

					<section v-show="activeTab === 'sessions'" class="flowline-section">
						<h3>{{ t('flowline', 'Sessions') }}</h3>
						<NcLoadingIcon v-if="sessionsLoading" :size="32" />
						<NcNoteCard v-else-if="sessionsError !== ''" type="error">
							{{ sessionsError }}
						</NcNoteCard>
						<template v-else>
							<h4>
								{{ t('flowline', 'Active') }}
								<span v-if="activeSessions.length > 0" class="flowline-count">{{ activeSessions.length }}</span>
							</h4>
							<p v-if="activeSessions.length === 0" class="flowline-hint">
								{{ t('flowline', 'No active session.') }}
							</p>
							<ul v-else class="flowline-list">
								<li v-for="session in activeSessions" :key="session.id" class="flowline-row">
									<span class="flowline-dot flowline-dot--online" />
									<span class="flowline-primary">{{ sessionName(session) }}</span>
									<span class="flowline-meta">{{ osLabel(session.os, session.os_version) }}</span>
									<span class="flowline-meta">{{ session.ip }}</span>
									<span class="flowline-meta flowline-right">
										{{ t('flowline', 'Started {when}', { when: relative(session.started_at) }) }}
									</span>
								</li>
							</ul>

							<template v-if="recentSessions.length > 0">
								<h4>{{ t('flowline', 'Recent') }}</h4>
								<ul class="flowline-list">
									<li v-for="session in recentSessions" :key="session.id" class="flowline-row">
										<span class="flowline-dot" />
										<span class="flowline-primary">{{ sessionName(session) }}</span>
										<span class="flowline-meta">{{ osLabel(session.os, session.os_version) }}</span>
										<span class="flowline-meta flowline-right">
											{{ sessionEndedLabel(session) }}
										</span>
									</li>
								</ul>
							</template>
						</template>
					</section>

					<section v-show="activeTab === 'support'" class="flowline-section">
						<h3>{{ t('flowline', 'Support') }}</h3>
						<NcLoadingIcon v-if="invitationsLoading" :size="32" />
						<template v-else>
							<p class="flowline-hint">
								{{ t('flowline', 'Give the support module link to the user, then open a window with the id shown by the module. Start the session from your FlowLINE client.') }}
							</p>
							<div class="flowline-actions">
								<NcButton @click="copyModuleLink">
									{{ linkCopied ? t('flowline', 'Link copied') : t('flowline', 'Copy module link') }}
								</NcButton>
								<NcTextField v-model="supportDeviceId"
									class="flowline-device-field"
									:label="t('flowline', 'Device id')"
									:placeholder="t('flowline', 'e.g. 159 627 370')"
									:disabled="!canInvite || supportBusy"
									@keyup.enter="openSupportWindow" />
								<NcButton variant="primary"
									:disabled="!canInvite || supportBusy || supportDeviceId.trim() === ''"
									@click="openSupportWindow">
									{{ t('flowline', 'Open window') }}
								</NcButton>
							</div>

							<NcNoteCard v-if="!canInvite" type="warning">
								{{ t('flowline', 'Your token is missing the invitations permission (invitations:write).') }}
							</NcNoteCard>
							<NcNoteCard v-if="supportFeedback" :type="supportFeedback.type">
								{{ supportFeedback.text }}
							</NcNoteCard>
							<NcNoteCard v-if="invitationsError !== ''" type="error">
								{{ invitationsError }}
							</NcNoteCard>

							<h4>
								{{ t('flowline', 'Active windows') }}
								<span v-if="invitations.length > 0" class="flowline-count">{{ invitations.length }}</span>
							</h4>
							<p v-if="invitations.length === 0 && invitationsError === ''" class="flowline-hint">
								{{ t('flowline', 'No active support window.') }}
							</p>
							<ul v-else class="flowline-list">
								<li v-for="invitation in invitations" :key="invitation.device_id" class="flowline-row">
									<span class="flowline-dot" :class="{ 'flowline-dot--online': isModuleOnline(invitation) }" />
									<span class="flowline-primary">{{ invitationName(invitation) }}</span>
									<span class="flowline-meta">{{ invitation.device_id }}</span>
									<span class="flowline-meta flowline-right">{{ invitationStatus(invitation) }}</span>
									<NcButton :disabled="!canInvite || supportBusy" @click="endSupportWindow(invitation)">
										{{ t('flowline', 'End') }}
									</NcButton>
								</li>
							</ul>
						</template>
					</section>

					<section v-show="activeTab === 'account'" class="flowline-section">
						<h3>{{ t('flowline', 'Linked account') }}</h3>
						<ul class="flowline-list">
							<li class="flowline-row">
								<span class="flowline-primary">{{ account.display_name }}</span>
								<span class="flowline-meta">{{ account.username }}</span>
								<span class="flowline-meta flowline-right">{{ account.plan_name }}</span>
							</li>
						</ul>
						<p class="flowline-hint">
							{{ t('flowline', 'Connected to {url}', { url: apiUrl }) }}
						</p>
						<div class="flowline-actions">
							<NcButton :href="settingsUrl" variant="primary">
								{{ t('flowline', 'Open settings') }}
							</NcButton>
						</div>
					</section>
				</template>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.flowline-console {
	margin: 16px auto;
	max-width: 900px;
	padding: 0 16px;
}

.flowline-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
}

.flowline-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 16px;
}

.flowline-hint {
	color: var(--color-text-maxcontrast);
}

.flowline-section {
	margin-top: 24px;
}

.flowline-section h4 {
	margin-bottom: 4px;
}

.flowline-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	font-weight: normal;
}

.flowline-actions {
	display: flex;
	align-items: flex-end;
	flex-wrap: wrap;
	gap: 12px;
	margin: 8px 0;
}

.flowline-device-field {
	max-width: 260px;
}

.flowline-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.flowline-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 4px;
	border-bottom: 1px solid var(--color-border);
}

.flowline-dot {
	flex: 0 0 auto;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-text-maxcontrast);
}

.flowline-dot--online {
	background: var(--color-success);
}

.flowline-primary {
	font-weight: 600;
}

.flowline-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.flowline-right {
	margin-inline-start: auto;
	text-align: end;
}

.flowline-chip {
	padding: 1px 6px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
}
</style>
