<?php

declare(strict_types=1);

namespace OCP\Files;

if (!interface_exists(FileInfo::class)) {
	interface FileInfo {
		public const MIMETYPE_FOLDER = 'httpd/unix-directory';
	}
}
