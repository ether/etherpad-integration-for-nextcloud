<?php

declare(strict_types=1);

namespace OCA\Files_Sharing\Event;

use OCP\EventDispatcher\Event;
use OCP\Share\IShare;

if (!class_exists(BeforeTemplateRenderedEvent::class)) {
	class BeforeTemplateRenderedEvent extends Event {
		public const SCOPE_PUBLIC_SHARE_AUTH = 'publicShareAuth';

		public function __construct(
			private string $scope,
			private IShare $share,
		) {
		}

		public function getScope(): string {
			return $this->scope;
		}

		public function getShare(): IShare {
			return $this->share;
		}
	}
}
