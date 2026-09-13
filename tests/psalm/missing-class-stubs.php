<?php

declare(strict_types=1);

/**
 * Minimal class stubs for Psalm analysis only.
 *
 * Some `nextcloud/ocp` public type files reference Nextcloud-internal `OC\…`
 * classes the package does not ship — e.g. `OCP\Files\IRootFolder implements
 * OC\Hooks\Emitter`. When Psalm's autoloader requires such an OCP file, the
 * missing internal makes it fatal, so the OCP interface (and everything
 * depending on it) shows up as `UndefinedClass`. Defining empty placeholders
 * for those two internals lets the real OCP type files load, so Psalm gets the
 * actual OCP API instead of treating it as undefined.
 *
 * Genuinely-external classes that can't be loaded this way (the Doctrine DBAL
 * schema type, `OCP\Image`'s concrete internal base, and a few other apps'
 * events) are handled by targeted suppressions in psalm.xml instead.
 *
 * `\OC` is stubbed rather than suppressed: the app reads two untyped static
 * properties off it and nothing else, and suppressing the class left the only
 * method that reads them unanalysed - Psalm took the guard around them as
 * always throwing and everything after it as dead.
 *
 * Runtime/PHPUnit is unaffected — this file is loaded only by the Psalm
 * autoloader (tests/psalm/ocp-autoload.php), never by the app.
 */

namespace OC\Hooks {
	if (!interface_exists(Emitter::class)) {
		interface Emitter {
		}
	}
}

namespace OC\User {
	if (!class_exists(NoUserException::class)) {
		class NoUserException extends \Exception {
		}
	}
}

namespace {
	if (!class_exists(OC::class)) {
		class OC {
			/** Untyped, as in Nextcloud: a caller has to check before using it. */
			public static $configDir;
			public static $SERVERROOT;
		}
	}
}
