<?php

declare(strict_types=1);

namespace OCA\Files_Sharing\Event;

use OCP\EventDispatcher\Event;
use OCP\Share\IShare;

if (!class_exists(BeforeTemplateRenderedEvent::class)) {
	class BeforeTemplateRenderedEvent extends Event {
		public const SCOPE_PUBLIC_SHARE_AUTH = 'publicShareAuth';

		// Argument order and nullability follow
		// apps/files_sharing/lib/Event/BeforeTemplateRenderedEvent.php: a stub
		// that disagrees passes against a signature nobody ships.
		public function __construct(
			private IShare $share,
			private ?string $scope = null,
		) {
		}

		public function getScope(): ?string {
			return $this->scope;
		}

		public function getShare(): IShare {
			return $this->share;
		}
	}
}
