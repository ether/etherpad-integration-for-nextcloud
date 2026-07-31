<?php

declare(strict_types=1);

namespace OCP\Files\Template;

if (!class_exists(RegisterTemplateCreatorEvent::class)) {
	class RegisterTemplateCreatorEvent extends \OCP\EventDispatcher\Event {
		public function __construct(
			private ITemplateManager $templateManager,
		) {
		}

		public function getTemplateManager(): ITemplateManager {
			return $this->templateManager;
		}
	}
}
