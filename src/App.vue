<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { formatRelativeTime, translate as t } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

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

const loading = ref(true)
const error = ref('')
const linked = ref(false)
const account = ref<AccountInfo | null>(null)
const apiUrl = ref('')

const machinesLoading = ref(false)
const machinesError = ref('')
const machines = ref<Machine[]>([])
const machinesTotal = ref(0)

const sessionsLoading = ref(false)
const sessionsError = ref('')
const activeSessions = ref<Session[]>([])
const recentSessions = ref<Session[]>([])
const needsRelink = ref(false)

const settingsUrl = generateUrl('/settings/user/flowline')

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
	await Promise.all([loadMachines(), loadSessions()])
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
		return t('flowline', 'Your token is missing the required permissions (machines and sessions).')
	}
	if (reason === 'rate_limited') {
		return t('flowline', 'Too many requests to FlowLINE. Try again in a moment.')
	}
	return t('flowline', 'FlowLINE is unreachable right now.')
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
						<div>
							<h2>FlowLINE</h2>
							<p>
								{{ t('flowline', 'Linked account:') }}
								<strong>{{ account.display_name }}</strong>
								({{ account.username }} — {{ account.plan_name }})
							</p>
							<p class="flowline-hint">
								{{ t('flowline', 'Connected to {url}', { url: apiUrl }) }}
							</p>
						</div>
						<NcButton :disabled="machinesLoading || sessionsLoading" @click="refresh">
							{{ t('flowline', 'Refresh') }}
						</NcButton>
					</div>

					<NcNoteCard v-if="needsRelink" type="warning">
						<p>{{ t('flowline', 'Link your account again to see your machines and sessions.') }}</p>
						<NcButton :href="settingsUrl" variant="primary">
							{{ t('flowline', 'Open settings') }}
						</NcButton>
					</NcNoteCard>

					<section class="flowline-section">
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

					<section class="flowline-section">
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
