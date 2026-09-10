<?php

declare(strict_types=1);

namespace OCA\Viewer\Event;

use OCP\EventDispatcher\Event;

if (!class_exists(LoadViewer::class)) {
	class LoadViewer extends Event {
	}
}
