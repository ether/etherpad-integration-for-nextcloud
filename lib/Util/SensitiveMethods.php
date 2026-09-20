<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * Methods whose arguments must never reach a log.
 *
 * Nextcloud prints every stack frame's argument when it serializes an
 * exception, and a frame far below the failure is printed just the same -
 * a pad opens successfully while a background probe fails, and the
 * session id from the frame above it is written out. Registering the
 * method makes the serializer replace its arguments instead. What a
 * reader needs from these frames - a pad id, a file id - belongs in the
 * log context, where it is chosen rather than swept up.
 *
 * A list of names goes stale in silence, so SensitiveMethodsTest checks
 * that every entry still exists.
 */
final class SensitiveMethods {
	/** @var array<class-string, list<string>> */
	public const ALL = [
	// The api key, and whole pads on their way to Etherpad.
	\OCA\EtherpadNextcloud\Service\EtherpadClient::class => [
		'apiCall', 'sendRequest', 'doRequest', 'assertApiKeyAccepted',
		'createSession', 'deleteSession', 'listSessionsOfAuthor',
		'setText', 'setHTML', 'getText', 'getHTML', 'getHTMLForPreview',
	],
	// The Etherpad session cookie, which is a live credential.
	\OCA\EtherpadNextcloud\Service\PadSessionService::class => [
		'buildEtherpadSessionCookie', 'buildSetCookieHeader',
	],
	// Public share tokens, which are the credential for a public pad.
	\OCA\EtherpadNextcloud\Service\PublicShareResolver::class => ['resolveShare', 'requestedPath'],
	\OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder::class => ['buildShareBaseUrl', 'buildShareRedirectUrl'],
	\OCA\EtherpadNextcloud\Service\PublicPadContextService::class => ['resolve', 'resolveContent', 'buildContentUrl'],
	// Pad text and html, which are the document itself and can be
	// megabytes - a leak and a log nobody can read afterwards.
	\OCA\EtherpadNextcloud\Service\ManagedPadLifecycle::class => ['seed'],
	\OCA\EtherpadNextcloud\Service\PadFileService::class => [
		'buildSnapshotBody', 'parsePadFile', 'readPad', 'withExportSnapshot',
	],
	\OCA\EtherpadNextcloud\Service\PadFileLockRetryService::class => ['putContentWithSyncLockRetry'],
	\OCA\EtherpadNextcloud\Service\PadCreationService::class => ['writeCreatedFile'],
	\OCA\EtherpadNextcloud\Service\SnapshotHtmlSanitizer::class => ['sanitize'],
	\OCA\EtherpadNextcloud\Service\LivePadHtmlFetcher::class => ['toPayload'],
	\OCA\EtherpadNextcloud\Service\PadTemplateStorage::class => ['addGlobalTemplate'],
	];
}
