<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\Files\Template\RegisterTemplateCreatorEvent;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * @psalm-api
 */
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
		// Suppresses 4xx noise from NC's /core/preview endpoint when the
		// Files app or template picker lists .pad files.
		$context->registerPreviewProvider(
			\OCA\EtherpadNextcloud\Preview\PadPreviewProvider::class,
			'/^application\/x-etherpad-nextcloud$/',
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

		// Groupfolders still emits this legacy trashbin restore hook instead of NodeRestoredEvent.
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
