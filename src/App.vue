<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

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

const loading = ref(true)
const error = ref('')
const linked = ref(false)
const account = ref<AccountInfo | null>(null)
const apiUrl = ref('')

const settingsUrl = generateUrl('/settings/user/flowline')

onMounted(async () => {
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
})
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
					<h2>FlowLINE</h2>
					<p>
						{{ t('flowline', 'Linked account:') }}
						<strong>{{ account.display_name }}</strong>
						({{ account.username }} — {{ account.plan_name }})
					</p>
					<p class="settings-hint">
						{{ t('flowline', 'Connected to {url}', { url: apiUrl }) }}
					</p>
				</template>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.flowline-console {
	margin: 16px auto;
	max-width: 900px;
}
</style>