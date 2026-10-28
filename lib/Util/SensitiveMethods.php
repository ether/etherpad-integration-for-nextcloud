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
 * as a string, or a credential as a list. A pad's address counts as a
 * credential: one of a pad on another server opens it to whoever has
 * it. A pad's id does not - the app logs its own pads' ids, and an
 * external pad's `ext.` id names the remote pad but not its server. Not
 * the route it is on:
 * whether an exception can leave the app from there is a property of
 * today's callers - a listener that rethrows, a mapper that catches - and
 * the next caller need not share it. Nor whether the method can throw,
 * which its next edit decides. So the rule asks for no judgement.
 *
 * An entry has a price, paid only in a trace Nextcloud serializes: that
 * frame loses all its arguments, and every other frame loses whatever
 * equals one of them - the same file id, an empty string, `false`. That
 * is also what it is good for: a credential a listed frame holds is gone
 * from the frames that only passed it on. Nextcloud's own list does the
 * same to every method whose name contains `create`, `update` or `sync`,
 * and the app's own log lines carry no arguments at all, so little is
 * lost that was there to read.
 *
 * Either needs an entry only where it is that plain. The objects a
 * document travels in - ParsedPadFile, PadSnapshot, LivePadHtml,
 * CreatedFileClaim - and those that carry a credential - ApiKey and the
 * two settings objects, PadOpenTarget, PublicPadOpenTarget,
 * PublicPadContext, and for a pad's address ParsedPadFile, PadMeta and
 * PadResolution - keep it in private fields, which the serializer
 * does not read: a method that takes one of those needs none, and
 * DocumentStaysOutOfTracesTest and CredentialStaysOutOfTracesTest hold
 * them to it.
 *
 * Three limits. It reaches the serializer only, so a throwable logged
 * under any key but 'exception' is normalized elsewhere and no entry
 * here applies. It covers the frames of the class it names, and a
 * callee's only for a value it was handed unchanged - where a method of
 * Nextcloud's own holds a value made of it, no list helps and the chain
 * has to be cut instead. And a share token is in the address of the
 * public routes, which Nextcloud writes into every log line of such a
 * request: the entries keep a trace from being a second place for it,
 * not the log from having it.
 *
 * A list of names goes stale in silence, so SensitiveMethodsTest checks
 * that every entry still resolves, and reads the source for methods that
 * take a document or a credential so and are not here. It goes by the
 * parameter's name, which is all a signature says of a string.
 *
 * The list has an end, though not one for one. From PHP 8.2 on,
 * #[\SensitiveParameter] hides a parameter in its own frame, for every
 * reader of the trace; unlike an entry here, it leaves the same value in
 * the frames that passed it on, so those need it too. On PHP 8.1 the
 * attribute does nothing, and Nextcloud 32 still runs there. Once the
 * app's floor is a Nextcloud that needs PHP 8.2, as 33 does, the
 * attribute can take over, on every frame such a value passes through.
 */
final class SensitiveMethods {
	/** @var array<class-string, list<string>> */
	public const ALL = [
		// A live session id, which deleteSession takes as a plain string,
		// and the listing of them as Etherpad answers it; whole pads on
		// their way to Etherpad, and on their way back inside the answer
		// requireStringField() reads. The author mapper of a public share
		// is made of its token (PadSessionService below). A pad's address,
		// as a URL to take the origin of, fetch, or send a request to. The
		// api key needs no entry: it travels as ApiKey and leaves as a
		// stream, so no frame here holds it - measured, not assumed.
		\OCA\EtherpadNextcloud\Service\EtherpadClient::class => [
			'deleteSession', 'getSessionInfo', 'sessionsIn', 'setText', 'setHTML', 'requireStringField',
			'createAuthorIfNotExistsFor', 'apiCall', 'sendRequest', 'formBody',
			'normalizeOrigin', 'sendPublicGetRequest', 'doRequest',
		],
		// The document as a string, wherever a method takes it so: handed
		// to Etherpad or compared with what it holds, read from the file
		// and split, put together and written, a template filled in or
		// stored, a pad's HTML made safe to show.
		\OCA\EtherpadNextcloud\Service\ManagedPadLifecycle::class => ['seed', 'provisionSeeded', 'isMadeAnew'],
		\OCA\EtherpadNextcloud\Service\PadFileService::class => [
			'parsePadFile', 'readPad', 'serialize', 'parseLegacyOwnpadShortcut',
			'withRestoredSnapshot', 'buildSnapshotBody', 'getSnapshotPartsFromBody', 'splitSnapshotBody',
			'buildInitialDocument', 'parseFrontmatterBlock',
			// The frontmatter, which holds an external pad's address: as the
			// array, and by hand as a line, a raw scalar or a value, under
			// names no rule knows.
			'extractPadMetadata', 'isExternalFrontmatter', 'getSnapshotRevisionFromFrontmatter',
			'validateFrontmatter', 'requireStringScalar', 'requireOptionalStringScalar',
			'assertLineIsRoundTrippable', 'parseScalar', 'stringScalar', 'nullableScalar', 'assertScalarIsRoundTrippable',
		],
		\OCA\EtherpadNextcloud\Service\PadFileLockRetryService::class => ['putContentWithSyncLockRetry'],
		\OCA\EtherpadNextcloud\Service\ClaimedFileWriter::class => ['write', 'holdsAlready'],
		\OCA\EtherpadNextcloud\Service\PadBootstrapService::class => ['initializeMissingFrontmatter'],
		\OCA\EtherpadNextcloud\Service\PadPlaceholderResolver::class => ['applyForContent', 'applyInternal'],
		\OCA\EtherpadNextcloud\Service\PadTemplateAdminService::class => ['add'],
		\OCA\EtherpadNextcloud\Service\PadTemplateStorage::class => ['addGlobalTemplate'],
		\OCA\EtherpadNextcloud\Service\SnapshotHtmlSanitizer::class => ['sanitize'],
		\OCA\EtherpadNextcloud\Service\LivePadHtmlFetcher::class => ['toPayload'],
		// And where it is a string for the last time: the constructors of
		// the objects that carry it from there, and what a claim compares
		// with the file's content.
		\OCA\EtherpadNextcloud\Service\ParsedPadFile::class => ['__construct'],
		\OCA\EtherpadNextcloud\Service\PadSnapshot::class => ['__construct'],
		\OCA\EtherpadNextcloud\Service\LivePadHtml::class => ['__construct'],
		\OCA\EtherpadNextcloud\Service\CreatedFileClaim::class => ['__construct', 'holds'],
		// The API key as a string: in the settings form as it was sent,
		// which the validator takes whole and no parameter's name gives
		// away; on its way into the objects that keep it; and as the secret
		// to take out of a message.
		\OCA\EtherpadNextcloud\Service\AdminSettingsValidator::class => [
			'validateForSave', 'validateForHealthCheck', 'validate', 'resolveApiKey',
		],
		\OCA\EtherpadNextcloud\Service\ValidatedAdminSettings::class => ['__construct'],
		\OCA\EtherpadNextcloud\Service\StoredAdminSettings::class => ['__construct'],
		\OCA\EtherpadNextcloud\Util\ApiKey::class => ['__construct'],
		\OCA\EtherpadNextcloud\Util\DiagnosticText::class => ['withoutSecret', 'hostOf'],
		\OCA\EtherpadNextcloud\Util\SafeError::class => ['context', 'readable', 'originOf'],
		// Etherpad sessions, each a live credential: the ids a browser
		// carries and the ones Etherpad lists, the cookie made of them, the
		// objects that take the finished Set-Cookie line, and what sends it.
		//
		// The first two by hand, for what no name gives away: a public
		// share opens a protected pad as an author of its own, under a uid
		// made of the share's token. The helpers they call take the same
		// string, and Nextcloud takes a listed frame's values out of every
		// other frame too.
		\OCA\EtherpadNextcloud\Service\PadSessionService::class => [
			'createProtectedOpenContext', 'openContextFor',
			'sessionsToAttributeWith', 'cookieValueFor', 'buildEtherpadSessionCookie', 'buildSetCookieHeader',
		],
		// A public link's uid carries its token, and what it keeps is a
		// session id.
		\OCA\EtherpadNextcloud\Service\PublicLinkSessions::class => ['sessionFor', 'read', 'write', 'key'],
		\OCA\EtherpadNextcloud\Service\ExpiredSessionCollector::class => ['collectFrom'],
		\OCA\EtherpadNextcloud\Service\PublicLinkVisitors::class => ['openerFor', 'rememberAuthor', 'admits', 'stored', 'store', 'sessionKey'],
		\OCA\EtherpadNextcloud\Service\PublicLinkCache::class => ['key'],
		\OCA\EtherpadNextcloud\Service\PublicLinkOpener::class => ['__construct'],
		\OCA\EtherpadNextcloud\Service\PadSessionRevoker::class => ['live', 'deleteLive', 'carriedFirst'],
		\OCA\EtherpadNextcloud\Service\SessionDeletes::class => ['live', 'latestFirst', 'within'],
		\OCA\EtherpadNextcloud\Service\PadOpenTarget::class => ['__construct'],
		\OCA\EtherpadNextcloud\Service\PublicPadOpenTarget::class => ['__construct'],
		\OCA\EtherpadNextcloud\Service\PublicPadContext::class => ['__construct'],
		\OCA\EtherpadNextcloud\Http\CookieHeaders::class => ['add', 'send'],
		// Public share tokens, which are the credential for a public pad,
		// from the controller that takes one off the address on.
		\OCA\EtherpadNextcloud\Controller\PublicViewerController::class => ['padContent', 'openPadData'],
		\OCA\EtherpadNextcloud\Controller\PublicShareRedirectController::class => ['showPad', 'targetFor'],
		\OCA\EtherpadNextcloud\Service\PublicPadOpenService::class => ['open'],
		\OCA\EtherpadNextcloud\Service\PublicShareResolver::class => ['resolveShare', 'requestedPath', 'resolvePadFile'],
		\OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder::class => ['buildShareBaseUrl', 'buildShareRedirectUrl'],
		\OCA\EtherpadNextcloud\Service\PublicPadContextService::class => ['resolve', 'resolveContent', 'buildContentUrl'],
		// And inside the `file` a request names, which may be a public DAV
		// address with the token in its path: again by hand, under names
		// like $path and $param.
		\OCA\EtherpadNextcloud\Util\PathNormalizer::class => [
			'normalizePublicShareFilePath', 'normalizeViewerFilePath', 'normalizeDavUrlToPath', 'normalizeSegments',
		],
		\OCA\EtherpadNextcloud\Service\ApiErrorLog::class => ['scalar'],
		// A pad's address, which opens a pad on another server to whoever
		// has it. fetchPublicExport() by hand: its $resolved carries the
		// address under a name the source check does not know.
		\OCA\EtherpadNextcloud\Listeners\FileCreatedFromTemplateListener::class => ['seedExternalPad', 'padUrlField'],
		\OCA\EtherpadNextcloud\Controller\PadCreateController::class => ['createFromUrl'],
		\OCA\EtherpadNextcloud\Service\PadCreationService::class => ['createFromUrl'],
		\OCA\EtherpadNextcloud\Service\ExternalPadSeeder::class => ['prepare'],
		\OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher::class => [
			'normalizeAndFetchExternalPublicPadText', 'normalizeAndFetchExternalPublicPadTextOrEmpty',
			'fetchExternalPublicPadHtml', 'normalizeAndValidateExternalPublicPadUrl', 'buildPublicExportUrl',
			'fetchPublicExport', 'resolveAndValidateExternalPublicPadUrl', 'sendPinnedPublicGetRequest',
			'perform', 'parsePublicPadUrl',
		],
		\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class => [
			'migrate', 'unlinkable', 'sameOriginContext', 'refuseProtectedImportIfSwitchedOff',
			// The managed document it writes, as a string.
			'writeOrTakeBackTheRow',
		],
		\OCA\EtherpadNextcloud\Service\PadMetadataService::class => ['resolvePublicOpenUrl', 'resolveReadOnlyOpenUrl'],
		\OCA\EtherpadNextcloud\Service\PadMeta::class => ['__construct'],
		\OCA\EtherpadNextcloud\Service\PadResolution::class => ['__construct'],
	];
}
