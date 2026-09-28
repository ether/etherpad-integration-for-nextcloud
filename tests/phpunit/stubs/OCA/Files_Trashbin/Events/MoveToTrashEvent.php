<?php

declare(strict_types=1);

namespace OCA\Files_Trashbin\Events;

use OCP\EventDispatcher\Event;
use OCP\Files\Node;

if (!class_exists(MoveToTrashEvent::class)) {
	class MoveToTrashEvent extends Event {
		public function __construct(private Node $node) {
		}

		public function getNode(): Node {
			return $this->node;
		}
	}
}
