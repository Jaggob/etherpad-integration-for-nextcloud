import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	'admin-settings': 'src/admin-settings.js',
	'embed-create-main': 'src/embed-create-main.js',
	'embed-main': 'src/embed-main.js',
	'files-main': 'src/files-main.js',
	'viewer-main': 'src/viewer-main.js',
})
