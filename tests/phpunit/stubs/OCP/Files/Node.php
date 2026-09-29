<?php

declare(strict_types=1);

namespace OCP\Files;

if (!interface_exists(Node::class)) {
	/** What File and Folder share; the app asks it only whether a node is one or the other. */
	interface Node {
	}
}
