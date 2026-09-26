<?php
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
?>
<?php $embedCssUrl = link_to('etherpad_nextcloud', 'css/embed.css') . '?v=' . rawurlencode((string)filemtime(__DIR__ . '/../css/embed.css')); ?>
<?php $embedCreateJsUrl = link_to('etherpad_nextcloud', 'js/etherpad_nextcloud-embed-create-main.mjs') . '?v=' . rawurlencode((string)filemtime(__DIR__ . '/../js/etherpad_nextcloud-embed-create-main.mjs')); ?>
<link rel="stylesheet" href="<?php p($embedCssUrl); ?>">
<script nonce="<?php p((string)$_['cspNonce']); ?>" type="module" src="<?php p($embedCreateJsUrl); ?>"></script>
<div id="etherpad-nextcloud-embed-create"
	class="epnc-embed"
	data-parent-folder-id="<?php p((string)$_['parent_folder_id']); ?>"
	data-create-by-parent-url="<?php p((string)$_['create_by_parent_url']); ?>"
	data-request-token="<?php p((string)($_['requesttoken'] ?? '')); ?>"
	data-l10n-loading="<?php p((string)$_['l10n']['loading']); ?>"
	data-l10n-error-title="<?php p((string)$_['l10n']['error_title']); ?>"
	data-l10n-missing-name="<?php p((string)$_['l10n']['missing_name']); ?>"
	data-l10n-invalid-access-mode="<?php p((string)$_['l10n']['invalid_access_mode']); ?>"
	data-l10n-incomplete-config="<?php p((string)$_['l10n']['incomplete_config']); ?>"
	data-l10n-unanswered="<?php p((string)$_['l10n']['unanswered']); ?>">
	<div class="epnc-embed__loading" data-epnc-embed-create-loading>
		<?php p((string)$_['l10n']['loading']); ?>
	</div>
	<div class="epnc-embed__error" data-epnc-embed-create-error hidden>
		<h2 class="epnc-embed__error-title"><?php p((string)$_['l10n']['error_title']); ?></h2>
		<p class="epnc-embed__error-message" data-epnc-embed-create-error-message></p>
	</div>
</div>
