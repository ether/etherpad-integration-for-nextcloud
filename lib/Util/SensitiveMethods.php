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
 * discipline cannot cover. Hence a list.
 *
 * What earns an entry is not how secret an argument reads but whether it
 * can still be read once the app has lost the exception: credentials,
 * and the documents on the routes that rethrow rather than handle.
 *
 * Two limits. It reaches the serializer only, so a throwable logged
 * under any key but 'exception' is normalized elsewhere and no entry
 * here applies. And it covers the frames of the class it names, never
 * the callee's - where a method of Nextcloud's own holds the value, no
 * list helps and the chain has to be cut instead.
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
		// here holds it - measured, not assumed.
		\OCA\EtherpadNextcloud\Service\EtherpadClient::class => [
			'deleteSession', 'setText', 'setHTML', 'apiCall', 'sendRequest', 'formBody',
		],
		// The document itself, as an argument.
		\OCA\EtherpadNextcloud\Service\ManagedPadLifecycle::class => ['seed'],
		// And on its way out of the file, which is the half a pad travels
		// when a trash or restore listener rethrows what it caught.
		\OCA\EtherpadNextcloud\Service\PadFileService::class => [
			'parsePadFile', 'readPad', 'serialize',
			'withExportSnapshot', 'withRestoredSnapshot', 'buildSnapshotBody',
		],
		// The same document one frame on: handed to the file rather than
		// parsed out of it, which is what a locked or failing write leaves
		// behind, and carried from frame to frame as the parsed file, whose
		// public fields the serializer writes out.
		\OCA\EtherpadNextcloud\Service\RestoreService::class => [
			'writeRestoredContent', 'restoreWithReplacement', 'restoreOntoNewPad', 'seedFromSnapshot',
		],
		\OCA\EtherpadNextcloud\Service\PadFileLockRetryService::class => ['putContentWithSyncLockRetry'],
		\OCA\EtherpadNextcloud\Service\PadCreationService::class => ['writeCreatedFile'],
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
