<?php

declare(strict_types=1);

namespace OCP\Files\Template;

if (!class_exists(BeforeGetTemplatesEvent::class)) {
	class BeforeGetTemplatesEvent extends \OCP\EventDispatcher\Event {
		/** @param list<Template> $templates */
		public function __construct(
			private array $templates,
			private bool $withFields = false,
		) {
		}

		/** @return list<Template> */
		public function getTemplates(): array {
			return $this->templates;
		}

		public function shouldGetFields(): bool {
			return $this->withFields;
		}
	}
}
