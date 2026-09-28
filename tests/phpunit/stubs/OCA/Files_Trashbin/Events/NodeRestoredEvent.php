<?php

declare(strict_types=1);

namespace OCA\Files_Trashbin\Events;

use OCP\EventDispatcher\Event;
use OCP\Files\Node;

if (!class_exists(NodeRestoredEvent::class)) {
	class NodeRestoredEvent extends Event {
		public function __construct(private Node $source, private Node $target) {
		}

		public function getSource(): Node {
			return $this->source;
		}

		public function getTarget(): Node {
			return $this->target;
		}
	}
}
