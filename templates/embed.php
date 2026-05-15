<?php
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
?>
<?php $embedCssUrl = link_to('etherpad_nextcloud', 'css/embed.css') . '?v=' . rawurlencode((string)filemtime(__DIR__ . '/../css/embed.css')); ?>
<?php $embedJsUrl = link_to('etherpad_nextcloud', 'js/etherpad_nextcloud-embed-main.mjs') . '?v=' . rawurlencode((string)filemtime(__DIR__ . '/../js/etherpad_nextcloud-embed-main.mjs')); ?>
<link rel="stylesheet" href="<?php p($embedCssUrl); ?>">
<script nonce="<?php p((string)$_['cspNonce']); ?>" type="module" src="<?php p($embedJsUrl); ?>"></script>
<div id="etherpad-nextcloud-embed"
	class="epnc-embed"
	data-file-id="<?php p((string)$_['file_id']); ?>"
	data-open-by-id-url="<?php p((string)$_['open_by_id_url']); ?>"
	data-initialize-by-id-url-template="<?php p((string)$_['initialize_by_id_url_template']); ?>"
	data-recover-url-template="<?php p((string)($_['recover_url_template'] ?? '')); ?>"
	data-find-original-url-template="<?php p((string)($_['find_original_url_template'] ?? '')); ?>"
	data-request-token="<?php p((string)($_['requesttoken'] ?? '')); ?>"
	data-trusted-origins="<?php p(implode(' ', array_map('strval', $_['trusted_embed_origins'] ?? []))); ?>"
	data-l10n-loading="<?php p((string)$_['l10n']['loading']); ?>"
	data-l10n-error-title="<?php p((string)$_['l10n']['error_title']); ?>"
	data-l10n-external-title="<?php p((string)($_['l10n']['external_title'] ?? 'Pad from another server')); ?>"
	data-l10n-external-message="<?php p((string)($_['l10n']['external_message'] ?? 'Read-only snapshot from the .pad file.')); ?>"
	data-l10n-external-empty="<?php p((string)($_['l10n']['external_empty'] ?? 'No synced snapshot is stored in this .pad file yet.')); ?>"
	data-l10n-external-link="<?php p((string)($_['l10n']['external_link'] ?? 'Open original pad')); ?>"
	data-l10n-recovery-checking="<?php p((string)($_['l10n']['recovery_checking'] ?? 'Checking for the original pad...')); ?>"
	data-l10n-recovery-copy-body="<?php p((string)($_['l10n']['recovery_copy_body'] ?? '')); ?>"
	data-l10n-recovery-orphan-body="<?php p((string)($_['l10n']['recovery_orphan_body'] ?? '')); ?>"
	data-l10n-recovery-open-original="<?php p((string)($_['l10n']['recovery_open_original'] ?? 'Open the original .pad file')); ?>"
	data-l10n-recovery-create-new="<?php p((string)($_['l10n']['recovery_create_new'] ?? 'Create new pad from this file')); ?>"
	data-l10n-recovery-creating="<?php p((string)($_['l10n']['recovery_creating'] ?? 'Creating new pad...')); ?>">
	<div class="epnc-embed__loading" data-epnc-embed-loading>
		<?php p((string)$_['l10n']['loading']); ?>
	</div>
	<div class="epnc-embed__error" data-epnc-embed-error hidden>
		<h2 class="epnc-embed__error-title"><?php p((string)$_['l10n']['error_title']); ?></h2>
		<p class="epnc-embed__error-message" data-epnc-embed-error-message></p>
	</div>
	<div class="epnc-embed__recovery" data-epnc-embed-recovery hidden>
		<h2 class="epnc-embed__recovery-title"><?php p((string)$_['l10n']['error_title']); ?></h2>
		<p class="epnc-embed__recovery-message" data-epnc-embed-recovery-message></p>
		<p class="epnc-embed__recovery-body" data-epnc-embed-recovery-body></p>
		<div class="epnc-embed__recovery-actions" data-epnc-embed-recovery-actions></div>
	</div>
	<iframe class="epnc-embed__iframe" data-epnc-embed-iframe title="Etherpad" hidden></iframe>
</div>
