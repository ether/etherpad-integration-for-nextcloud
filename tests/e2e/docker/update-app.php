<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 *
 * The app's update, run inside the stack by sync-app.sh and test-upgrade.sh:
 *
 *   php < update-app.php
 *
 * A repair step's warning or error fails it. Nextcloud hands those to
 * whoever listens - occ upgrade and the updater print them - and an update
 * run on its own has no listener: a warning would reach no one, an error
 * only the log.
 */

require '/var/www/html/lib/base.php';
// Nextcloud's exception handler would end a throwing update with status 0;
// PHP's own prints the trace and exits 255.
restore_exception_handler();

$reported = 0;
$dispatcher = \OCP\Server::get(\OCP\EventDispatcher\IEventDispatcher::class);
foreach ([\OC\Repair\Events\RepairWarningEvent::class, \OC\Repair\Events\RepairErrorEvent::class] as $event) {
	$dispatcher->addListener($event, static function (\OC\Repair\Events\RepairWarningEvent|\OC\Repair\Events\RepairErrorEvent $event) use (&$reported): void {
		fwrite(STDERR, 'Repair step: ' . $event->getMessage() . "\n");
		$reported++;
	});
}

exit(\OC_App::updateApp('etherpad_nextcloud') && $reported === 0 ? 0 : 1);
