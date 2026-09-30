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
 * What earns an entry is a method that takes a credential or a document
 * as a string. Not the route it is on: whether an exception can leave
 * the app from there is a property of today's callers - a listener that
 * rethrows, a mapper that catches - and the next caller need not share
 * it. Nor whether the method can throw, which its next edit decides. An
 * entry costs nothing, so the rule asks for no judgement.
 *
 * A document needs an entry only where it is a string. The objects it
 * travels in - ParsedPadFile, PadSnapshot, LivePadHtml, CreatedFileClaim -
 * keep it in private fields, which the serializer does not read: a
 * method that takes one of those needs none, and
 * DocumentStaysOutOfTracesTest holds them to it.
 *
 * Two limits. It reaches the serializer only, so a throwable logged
 * under any key but 'exception' is normalized elsewhere and no entry
 * here applies. And it covers the frames of the class it names, never
 * the callee's - where a method of Nextcloud's own holds the value, no
 * list helps and the chain has to be cut instead.
 *
 * A list of names goes stale in silence, so SensitiveMethodsTest checks
 * that every entry still resolves, and reads the source for methods that
 * take a document as a string and are not here. Credentials it does not
 * look for.
 *
 * The list has an end. From PHP 8.2 on, #[\SensitiveParameter] on the
 * parameter does this in the trace itself, for every reader of it, and
 * stands for an entry here one for one. On PHP 8.1 the attribute does
 * nothing, and Nextcloud 32 still runs there. Once the app's floor is a
 * Nextcloud that needs PHP 8.2, as 33 does, the attribute takes over and
 * the list goes; keeping both until then would be the same thing twice.
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
		// The document as a string, wherever a method takes it so: handed
		// to Etherpad or compared with what it holds, read from the file
		// and split, put together and written, a template filled in or
		// stored, a pad's HTML made safe to show.
		\OCA\EtherpadNextcloud\Service\ManagedPadLifecycle::class => ['seed', 'isMadeAnew'],
		\OCA\EtherpadNextcloud\Service\PadFileService::class => [
			'parsePadFile', 'readPad', 'serialize', 'parseLegacyOwnpadShortcut',
			'withRestoredSnapshot', 'buildSnapshotBody', 'getSnapshotPartsFromBody', 'splitSnapshotBody',
		],
		\OCA\EtherpadNextcloud\Service\PadFileLockRetryService::class => ['putContentWithSyncLockRetry'],
		\OCA\EtherpadNextcloud\Service\PadCreationService::class => ['writeCreatedFile'],
		\OCA\EtherpadNextcloud\Service\PadCreateAttempt::class => ['claimFile'],
		\OCA\EtherpadNextcloud\Service\PadBootstrapService::class => ['initializeMissingFrontmatter', 'writeInitialDocument'],
		\OCA\EtherpadNextcloud\Service\PadPlaceholderResolver::class => ['applyForContent', 'applyInternal'],
		\OCA\EtherpadNextcloud\Service\PadTemplateAdminService::class => ['add'],
		\OCA\EtherpadNextcloud\Service\PadTemplateStorage::class => ['addGlobalTemplate'],
		\OCA\EtherpadNextcloud\Service\SnapshotHtmlSanitizer::class => ['sanitize'],
		\OCA\EtherpadNextcloud\Service\LivePadHtmlFetcher::class => ['toPayload'],
		// And where it is a string for the last time: the constructors of
		// the objects that carry it from there.
		\OCA\EtherpadNextcloud\Service\ParsedPadFile::class => ['__construct'],
		\OCA\EtherpadNextcloud\Service\PadSnapshot::class => ['__construct'],
		\OCA\EtherpadNextcloud\Service\LivePadHtml::class => ['__construct'],
		\OCA\EtherpadNextcloud\Service\CreatedFileClaim::class => ['__construct'],
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
