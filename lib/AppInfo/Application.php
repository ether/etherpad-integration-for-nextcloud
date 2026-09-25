<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\AppInfo;

use OCA\EtherpadNextcloud\Util\SensitiveMethods;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\Files\Template\RegisterTemplateCreatorEvent;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'etherpad_nextcloud';

	public function __construct() {
		parent::__construct(self::APP_ID);
		// NB: no runtime MIME registration here. The `.pad` MIME type is
		// persisted by the RegisterMimeType repair step (config mimetype
		// mapping/aliases + filecache backfill), which is the supported
		// Nextcloud mechanism. The old constructor used
		// IMimeTypeDetector::registerType() — not part of the public OCP
		// interface, and it ran getAllMappings() on every app instantiation.
	}

	public function register(IRegistrationContext $context): void {
		foreach (SensitiveMethods::ALL as $class => $methods) {
			$context->registerSensitiveMethods($class, $methods);
		}

		// Files FullTextSearch exposes content extraction extensions through
		// this legacy generic event. GenericEvent is server-wide, so the
		// listener rejects every other subject before inspecting its payload.
		$context->registerEventListener(
			GenericEvent::class,
			\OCA\EtherpadNextcloud\Listeners\FullTextSearchIndexingListener::class,
		);
		$context->registerEventListener(
			GenericEvent::class,
			\OCA\EtherpadNextcloud\Listeners\FullTextSearchResultListener::class,
		);

		// Suppresses 4xx noise from NC's /core/preview endpoint when the
		// Files app or template picker lists .pad files.
		$context->registerPreviewProvider(
			\OCA\EtherpadNextcloud\Preview\PadPreviewProvider::class,
			\OCA\EtherpadNextcloud\Util\PadFileType::mimePattern(),
		);

		$context->registerEventListener(AddContentSecurityPolicyEvent::class, \OCA\EtherpadNextcloud\Listeners\CSPListener::class);
		// LoadAdditionalScriptsEvent is provided by the Files app, not by
		// nextcloud/ocp, so Psalm can't prove it is-a OCP\EventDispatcher\Event
		// and rejects the generic IEventListener<Event> listener here.
		/** @psalm-suppress InvalidArgument */
		$context->registerEventListener(
			LoadAdditionalScriptsEvent::class,
			\OCA\EtherpadNextcloud\Listeners\LoadFilesScriptsListener::class,
		);
		$context->registerEventListener(
			'OCA\\Files_Sharing\\Event\\BeforeTemplateRenderedEvent',
			\OCA\EtherpadNextcloud\Listeners\LoadPublicShareScriptsListener::class,
		);
		if (class_exists(RegisterTemplateCreatorEvent::class)) {
			$context->registerEventListener(
				RegisterTemplateCreatorEvent::class,
				\OCA\EtherpadNextcloud\Listeners\RegisterTemplateCreatorListener::class,
			);
		}
		if (interface_exists('OCP\\Files\\Template\\ICustomTemplateProvider')) {
			$context->registerTemplateProvider(\OCA\EtherpadNextcloud\Template\PadTemplateProvider::class);
		}
		if (class_exists('OCP\\Files\\Template\\BeforeGetTemplatesEvent')) {
			// Below the default priority, deliberately: other apps answer this
			// event for every template in it, ours included, and would
			// otherwise drop the field the external tile asks its address
			// with. This runs after every listener that kept the default.
			$context->registerEventListener(
				'OCP\\Files\\Template\\BeforeGetTemplatesEvent',
				\OCA\EtherpadNextcloud\Listeners\BeforeGetTemplatesListener::class,
				-100,
			);
		}
		if (class_exists(FileCreatedFromTemplateEvent::class)) {
			$context->registerEventListener(
				FileCreatedFromTemplateEvent::class,
				\OCA\EtherpadNextcloud\Listeners\FileCreatedFromTemplateListener::class,
			);
		}
		if (class_exists('OCA\\Viewer\\Event\\LoadViewer')) {
			$context->registerEventListener(
				'OCA\\Viewer\\Event\\LoadViewer',
				\OCA\EtherpadNextcloud\Listeners\LoadViewerListener::class,
			);
		}

		// The Etherpad session cookie outlives a Nextcloud logout, so the
		// sessions behind it are taken away explicitly.
		$context->registerEventListener(
			\OCP\User\Events\UserLoggedOutEvent::class,
			\OCA\EtherpadNextcloud\Listeners\UserLoggedOutListener::class,
		);

		$context->registerEventListener(
			'OCA\\Files_Trashbin\\Events\\MoveToTrashEvent',
			\OCA\EtherpadNextcloud\Listeners\MoveToTrashListener::class,
		);
		// NC fallback: legacy string event is dispatched alongside typed move-to-trash.
		$context->registerEventListener(
			'OCA\\Files_Trashbin::moveToTrash',
			\OCA\EtherpadNextcloud\Listeners\MoveToTrashListener::class,
		);
		$context->registerEventListener(
			'OCA\\Files_Trashbin\\Events\\NodeRestoredEvent',
			\OCA\EtherpadNextcloud\Listeners\RestoreFromTrashListener::class,
		);

		// Groupfolders restores through this legacy hook alone and fires no
		// NodeRestoredEvent. Core fires both, the hook first, so a core restore
		// reaches the listener twice (RestoreFromTrashListener::restoreNode()
		// says what the second pass does).
		// Its filePath is relative to the user's files root in both.
		\OCP\Util::connectHook(
			'\OCA\Files_Trashbin\Trashbin',
			'post_restore',
			\OCA\EtherpadNextcloud\Hooks\TrashbinHookHandler::class,
			'postRestore',
		);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (IJobList $jobList): void {
			$jobList->add(\OCA\EtherpadNextcloud\BackgroundJob\HotPendingDeleteRetryJob::class);
			$jobList->add(\OCA\EtherpadNextcloud\BackgroundJob\WarmPendingDeleteRetryJob::class);
			$jobList->add(\OCA\EtherpadNextcloud\BackgroundJob\ColdPendingDeleteRetryJob::class);
		});
	}
}
