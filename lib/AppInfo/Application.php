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
use OCP\Files\IMimeTypeDetector;
use OCP\Files\Template\RegisterTemplateCreatorEvent;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'etherpad_nextcloud';

	public function __construct() {
		parent::__construct(self::APP_ID);

		$detector = $this->getContainer()->query(IMimeTypeDetector::class);
		$detector->getAllMappings();
		$detector->registerType('pad', 'application/x-etherpad-nextcloud');
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, \OCA\EtherpadNextcloud\Listeners\CSPListener::class);
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
			$jobList->remove(\OCA\EtherpadNextcloud\BackgroundJob\RetryPendingDeleteJob::class);
			$jobList->add(\OCA\EtherpadNextcloud\BackgroundJob\HotPendingDeleteRetryJob::class);
			$jobList->add(\OCA\EtherpadNextcloud\BackgroundJob\WarmPendingDeleteRetryJob::class);
			$jobList->add(\OCA\EtherpadNextcloud\BackgroundJob\ColdPendingDeleteRetryJob::class);
		});
	}
}
