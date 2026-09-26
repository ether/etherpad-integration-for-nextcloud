<?php

declare(strict_types=1);

namespace OCP\Files\Events\Node;

use OCP\EventDispatcher\Event;
use OCP\Files\Node;

if (!class_exists(BeforeNodeDeletedEvent::class)) {
	class BeforeNodeDeletedEvent extends Event {
		public function __construct(private Node $node) {
		}

		public function getNode(): Node {
			return $this->node;
		}
	}
}
