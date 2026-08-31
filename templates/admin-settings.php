<?php
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
?>
<div id="etherpad-nextcloud-admin-settings"
	class="section"
	data-save-url="<?php p((string)$_['save_settings_url']); ?>"
	data-health-url="<?php p((string)$_['health_check_url']); ?>"
	data-consistency-url="<?php p((string)$_['consistency_check_url']); ?>"
	data-retry-pending-url="<?php p((string)$_['retry_pending_deletes_url']); ?>"
	data-l10n-saving="<?php p((string)$_['l10n']['saving']); ?>"
	data-l10n-saved="<?php p((string)$_['l10n']['saved']); ?>"
	data-l10n-checking="<?php p((string)$_['l10n']['checking']); ?>"
	data-l10n-consistency-running="<?php p((string)$_['l10n']['consistency_running']); ?>"
	data-l10n-consistency-ok="<?php p((string)$_['l10n']['consistency_ok']); ?>"
	data-l10n-request-failed="<?php p((string)$_['l10n']['request_failed']); ?>"
	data-l10n-saving-failed="<?php p((string)$_['l10n']['saving_failed']); ?>"
	data-l10n-health-failed="<?php p((string)$_['l10n']['health_failed']); ?>"
	data-l10n-consistency-failed="<?php p((string)$_['l10n']['consistency_failed']); ?>"
	data-l10n-pending-delete-label="<?php p((string)$_['l10n']['pending_delete_label']); ?>"
	data-l10n-retry-failed="<?php p((string)$_['l10n']['retry_failed']); ?>"
	data-templates-url="<?php p((string)$_['templates_url']); ?>"
	data-templates-delete-url="<?php p((string)$_['templates_delete_url']); ?>"
	data-l10n-template-uploading="<?php p((string)$_['l10n']['templates_uploading']); ?>"
	data-l10n-template-delete="<?php p((string)$_['l10n']['templates_delete_button']); ?>"
	data-l10n-template-delete-label="<?php p((string)$_['l10n']['templates_delete_label']); ?>"
	data-l10n-template-too-large="<?php p((string)$_['l10n']['templates_too_large']); ?>"
	data-l10n-template-confirm-delete="<?php p((string)$_['l10n']['templates_confirm_delete']); ?>"
	data-l10n-template-confirm-replace="<?php p((string)$_['l10n']['templates_confirm_replace']); ?>"
	data-l10n-template-failed="<?php p((string)$_['l10n']['templates_failed']); ?>">
	<h2><?php p((string)$_['l10n']['section_title']); ?></h2>
	<p class="settings-hint">
		<?php p((string)$_['l10n']['intro']); ?>
	</p>

	<form id="etherpad-nextcloud-admin-form">
		<h3 class="ep-section-heading"><?php p((string)$_['l10n']['section_server']); ?></h3>

		<p>
			<label for="etherpad-host"><?php p((string)$_['l10n']['etherpad_base_url']); ?></label>
			<input
				type="url"
				id="etherpad-host"
				name="etherpad_host"
				required
				placeholder="https://pad.example.org"
				value="<?php p((string)$_['etherpad_host']); ?>">
			<span class="ep-field-error" data-field-error="etherpad_host" aria-live="polite"></span>
			<span class="ep-check-result" data-check-result="etherpad_host" role="status"></span>
		</p>

		<p class="ep-field-row">
			<label for="etherpad-api-key"><?php p((string)$_['l10n']['etherpad_api_key']); ?></label>
			<input
				type="password"
				id="etherpad-api-key"
				name="etherpad_api_key"
				autocomplete="off"
				placeholder="••••••••••••••••">
			<span class="ep-field-error" data-field-error="etherpad_api_key" aria-live="polite"></span>
			<span class="ep-check-result" data-check-result="etherpad_api_key" role="status"></span>
		</p>
		<p class="settings-hint ep-detected-value">
			<?php p((string)$_['l10n']['detected_api_version']); ?> <strong><?php p((string)$_['etherpad_api_version']); ?></strong>
		</p>

		<p class="ep-field-row">
			<label for="etherpad-api-host"><?php p((string)$_['l10n']['etherpad_api_url']); ?></label>
			<input
				type="url"
				id="etherpad-api-host"
				aria-describedby="epnc-hint-api-host"
				name="etherpad_api_host"
				placeholder="https://etherpad.internal"
				value="<?php p((string)$_['etherpad_api_host']); ?>">
			<span class="ep-field-error" data-field-error="etherpad_api_host" aria-live="polite"></span>
			<span class="ep-check-result" data-check-result="etherpad_api_host" role="status"></span>
		</p>
		<p class="settings-hint ep-field-hint" id="epnc-hint-api-host"><?php p((string)$_['l10n']['etherpad_api_url_hint']); ?></p>

		<p class="ep-field-row">
			<label for="etherpad-cookie-domain"><?php p((string)$_['l10n']['etherpad_cookie_domain']); ?></label>
			<input
				type="text"
				id="etherpad-cookie-domain"
				aria-describedby="epnc-hint-cookie-domain"
				name="etherpad_cookie_domain"
				placeholder=".example.org"
				value="<?php p((string)$_['etherpad_cookie_domain']); ?>">
			<span class="ep-field-error" data-field-error="etherpad_cookie_domain" aria-live="polite"></span>
			<span class="ep-check-result<?php if ((string)$_['cookie_domain_warning'] !== ''): ?> ep-check-warning<?php endif; ?>" data-check-result="etherpad_cookie_domain" role="status"><?php p((string)$_['cookie_domain_warning']); ?></span>
		</p>
		<p class="settings-hint ep-field-hint" id="epnc-hint-cookie-domain"><?php p((string)$_['l10n']['etherpad_cookie_domain_hint']); ?></p>

		<?php // The session cookie belongs to no input: the release decides it,
		// and the release is discovered rather than typed. Without a slot of
		// its own the connection test would count the line and render it
		// nowhere. ?>
		<p>
			<span class="ep-check-result" data-check-result="etherpad_session_cookie" role="status"></span>
		</p>

		<div class="etherpad-nextcloud-admin-actions">
			<button type="button" id="etherpad-nextcloud-health-check"><?php p((string)$_['l10n']['health_button']); ?></button>
		</div>
		<p class="settings-hint ep-field-hint"><?php p((string)$_['l10n']['section_connection_hint']); ?></p>
		<p id="etherpad-nextcloud-connection-status" class="ep-status" aria-live="polite"></p>

		<h3 class="ep-section-heading"><?php p((string)$_['l10n']['section_pad_types']); ?></h3>

		<p id="enable-protected-pads-row" class="ep-checkbox-row">
			<label class="checkbox">
				<input
					type="checkbox"
					name="enable_protected_pads"
					aria-describedby="epnc-hint-protected-pads"
					value="1"
					<?php if ((bool)$_['enable_protected_pads']): ?>checked<?php endif; ?>>
				<?php p((string)$_['l10n']['enable_protected_pads']); ?>
			</label>
		</p>
		<p class="settings-hint ep-field-hint ep-checkbox-hint" id="epnc-hint-protected-pads"><?php p((string)$_['l10n']['enable_protected_pads_hint']); ?></p>

		<p id="enable-public-pads-row" class="ep-checkbox-row">
			<label class="checkbox">
				<input
					type="checkbox"
					name="enable_public_pads"
					aria-describedby="epnc-hint-public-pads"
					value="1"
					<?php if ((bool)$_['enable_public_pads']): ?>checked<?php endif; ?>>
				<?php p((string)$_['l10n']['enable_public_pads']); ?>
			</label>
		</p>
		<p class="settings-hint ep-field-hint ep-checkbox-hint" id="epnc-hint-public-pads"><?php p((string)$_['l10n']['enable_public_pads_hint']); ?></p>
		<?php /* Always rendered, text written by the script — and no whitespace
			inside the tag, or `:empty` would not match. */ ?>
		<p class="settings-hint ep-field-hint ep-checkbox-hint" id="pad-types-none-hint" role="status" data-message="<?php p((string)$_['l10n']['pad_types_none_hint']); ?>"><?php if (!(bool)$_['enable_protected_pads'] && !(bool)$_['enable_public_pads']) {
			p((string)$_['l10n']['pad_types_none_hint']);
		} ?></p>

		<p class="ep-field-row">
			<label for="sync-interval-seconds"><?php p((string)$_['l10n']['copy_interval']); ?></label>
			<input
				type="number"
				id="sync-interval-seconds"
				aria-describedby="epnc-hint-sync-interval"
				name="sync_interval_seconds"
				min="5"
				max="3600"
				step="1"
				value="<?php p((string)$_['sync_interval_seconds']); ?>">
			<span class="ep-field-error" data-field-error="sync_interval_seconds" aria-live="polite"></span>
		</p>
		<p class="settings-hint ep-field-hint" id="epnc-hint-sync-interval"><?php p((string)$_['l10n']['copy_interval_hint']); ?></p>

		<p id="delete-on-trash-row" class="ep-checkbox-row">
			<label class="checkbox">
				<input
					type="checkbox"
					name="delete_on_trash"
					aria-describedby="epnc-hint-delete-on-trash"
					value="1"
					<?php if ((bool)$_['delete_on_trash']): ?>checked<?php endif; ?>>
				<?php p((string)$_['l10n']['delete_on_trash']); ?>
			</label>
		</p>
		<p class="settings-hint ep-field-hint ep-checkbox-hint" id="epnc-hint-delete-on-trash"><?php p((string)$_['l10n']['delete_on_trash_hint']); ?></p>

		<h3 class="ep-section-heading"><?php p((string)$_['l10n']['section_external']); ?></h3>

		<p id="allow-external-pads-row">
			<label class="checkbox">
				<input
					type="checkbox"
					name="allow_external_pads"
					value="1"
					<?php if ((bool)$_['allow_external_pads']): ?>checked<?php endif; ?>>
				<?php p((string)$_['l10n']['allow_external_pads']); ?>
			</label>
		</p>

		<p id="external-pad-allowlist-row" class="ep-field-row">
			<label for="external-pad-allowlist"><?php p((string)$_['l10n']['external_allowlist']); ?></label>
			<textarea
				id="external-pad-allowlist"
				aria-describedby="external-pad-allowlist-hint"
				name="external_pad_allowlist"
				rows="5"
				placeholder="pad.example.org&#10;etherpad.example.net"><?php p((string)$_['external_pad_allowlist']); ?></textarea>
			<span class="ep-field-error" data-field-error="external_pad_allowlist" aria-live="polite"></span>
		</p>
		<p id="external-pad-allowlist-hint" class="settings-hint ep-field-hint"><?php p((string)$_['l10n']['external_allowlist_hint']); ?></p>

		<p id="trusted-embed-origins-row" class="ep-field-row">
			<label for="trusted-embed-origins"><?php p((string)$_['l10n']['trusted_embed_origins']); ?></label>
			<textarea
				id="trusted-embed-origins"
				aria-describedby="trusted-embed-origins-hint"
				name="trusted_embed_origins"
				rows="4"
				placeholder="https://portal.example.org&#10;https://app.example.org"><?php p((string)$_['trusted_embed_origins']); ?></textarea>
			<span class="ep-field-error" data-field-error="trusted_embed_origins" aria-live="polite"></span>
		</p>
		<p id="trusted-embed-origins-hint" class="settings-hint ep-field-hint"><?php p((string)$_['l10n']['trusted_embed_origins_hint']); ?></p>

		<div class="etherpad-nextcloud-admin-actions">
			<button type="submit" class="primary"><?php p((string)$_['l10n']['save_button']); ?></button>
		</div>
		<p id="etherpad-nextcloud-admin-status" class="ep-status" aria-live="polite"></p>

		<h3 class="ep-section-heading"><?php p((string)$_['l10n']['section_templates']); ?></h3>
		<p class="settings-hint ep-field-hint"><?php p((string)$_['l10n']['section_templates_hint']); ?></p>

		<ul id="epnc-template-list" class="ep-template-list"></ul>
		<p id="epnc-template-empty" class="settings-hint ep-field-hint"><?php p((string)$_['l10n']['templates_none']); ?></p>

		<div class="etherpad-nextcloud-admin-actions">
			<?php /* The visible button operates it; on its own it would be a
			       focusable control with no name. */ ?>
			<input type="file" id="epnc-template-file" accept=".pad" class="ep-visually-hidden" tabindex="-1" aria-hidden="true">
			<button type="button" id="epnc-template-upload"><?php p((string)$_['l10n']['templates_upload_button']); ?></button>
		</div>
		<p id="epnc-template-status" class="ep-status" aria-live="polite"></p>

		<h3 class="ep-section-heading"><?php p((string)$_['l10n']['section_diagnostics']); ?></h3>
		<p class="settings-hint ep-field-hint"><?php p((string)$_['l10n']['section_consistency_hint']); ?></p>

		<div class="etherpad-nextcloud-admin-actions">
			<button type="button" id="etherpad-nextcloud-consistency-check"><?php p((string)$_['l10n']['consistency_button']); ?></button>
		</div>
		<div id="etherpad-nextcloud-pending-actions" class="etherpad-nextcloud-admin-actions" style="display:none;">
			<button type="button" id="etherpad-nextcloud-retry-pending"><?php p((string)$_['l10n']['retry_pending_button']); ?></button>
			<span id="etherpad-nextcloud-pending-count" class="settings-hint"></span>
		</div>
		<p id="etherpad-nextcloud-diagnostics-status" class="ep-status" aria-live="polite"></p>
	</form>
</div>
