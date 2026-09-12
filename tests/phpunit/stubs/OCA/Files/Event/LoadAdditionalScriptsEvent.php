<?php

declare(strict_types=1);

namespace OCA\Files\Event;

use OCP\EventDispatcher\Event;

if (!class_exists(LoadAdditionalScriptsEvent::class)) {
	class LoadAdditionalScriptsEvent extends Event {
	}
}
