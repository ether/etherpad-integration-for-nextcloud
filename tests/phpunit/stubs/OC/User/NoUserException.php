<?php

declare(strict_types=1);

namespace OC\User;

/**
 * Mirrors the class Nextcloud actually throws from
 * IRootFolder::getUserFolder(). It lives under `OC\`, not `OCP\`, which
 * is why UserNodeResolver cannot name it in a catch clause — this stub
 * exists so the test can throw the real type rather than an invented one.
 */
if (!class_exists(NoUserException::class)) {
	class NoUserException extends \Exception {
	}
}
