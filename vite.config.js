import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	'admin-settings': 'src/admin-settings.js',
	'embed-create-main': 'src/embed-create-main.js',
	'embed-main': 'src/embed-main.js',
	'public-share-main': 'src/public-share-main.js',
	'viewer-init': 'src/viewer-init.js',
})
