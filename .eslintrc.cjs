module.exports = {
	extends: [
		// Config TypeScript : indispensable pour parser les blocs
		// `<script lang="ts">` des fichiers .vue (cf. README @nextcloud/eslint-config).
		'@nextcloud/eslint-config/typescript',
	],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
	},
}
