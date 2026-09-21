<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * Methods whose arguments Nextcloud must replace when it serializes an
 * exception the app never got to handle.
 *
 * This is not how the app keeps secrets out of its logs - SafeError is,
 * and LogContextTest enforces it. An exception that escapes reaches
 * Nextcloud's own handler, which serializes it with every frame's
 * arguments no matter how this app logs, and that is the one case
 * discipline cannot cover. Hence a list, and hence a short one: only
 * what is a credential if it is read.
 *
 * Not the methods that carry a pad. A document in a log is a size and a
 * privacy problem, and not logging the exception already solves it.
 *
 * A list of names goes stale in silence, so SensitiveMethodsTest checks
 * that every entry still resolves.
 */
final class SensitiveMethods {
	/** @var array<class-string, list<string>> */
	public const ALL = [
		// A live session id, which deleteSession takes as a plain string,
		// and whole pads on their way to Etherpad. The api key needs no
		// entry: it travels as ApiKey and leaves as a stream, so no frame
		// holds it - measured, not assumed.
		\OCA\EtherpadNextcloud\Service\EtherpadClient::class => [
			'deleteSession', 'setText', 'setHTML', 'apiCall', 'sendRequest', 'formBody',
		],
		// The document itself, as arguments. Not logging the exception is
		// no answer here: these failures are rethrown, and Nextcloud
		// serializes what it catches with every frame it finds.
		\OCA\EtherpadNextcloud\Service\ManagedPadLifecycle::class => ['seed'],
		// And on its way out of the file, which is the half a pad travels
		// when a trash or restore listener rethrows what it caught.
		\OCA\EtherpadNextcloud\Service\PadFileService::class => [
			'parsePadFile', 'readPad', 'serialize',
			'withExportSnapshot', 'withRestoredSnapshot', 'buildSnapshotBody',
		],
		// The Etherpad session cookie, which is a live credential.
		\OCA\EtherpadNextcloud\Service\PadSessionService::class => [
			'buildEtherpadSessionCookie', 'buildSetCookieHeader',
		],
		// Public share tokens, which are the credential for a public pad.
		\OCA\EtherpadNextcloud\Service\PublicShareResolver::class => ['resolveShare', 'requestedPath'],
		\OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder::class => ['buildShareBaseUrl', 'buildShareRedirectUrl'],
		\OCA\EtherpadNextcloud\Service\PublicPadContextService::class => ['resolve', 'resolveContent', 'buildContentUrl'],
	];
}
