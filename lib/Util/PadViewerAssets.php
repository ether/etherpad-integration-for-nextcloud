<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCP\Util;

/**
 * What a page needs to open a pad in Nextcloud's Viewer: the script that
 * registers the handler, and the styles that size the viewer's frame. One
 * list for every listener that loads it, so no page gets a viewer without
 * its styles.
 */
final class PadViewerAssets {
	public static function load(): void {
		Util::addStyle(Application::APP_ID, 'pad-document');
		Util::addStyle(Application::APP_ID, 'files-main');
		Util::addInitScript(Application::APP_ID, 'etherpad_nextcloud-viewer-init');
	}
}
