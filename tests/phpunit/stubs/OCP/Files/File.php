<?php

declare(strict_types=1);

namespace OCP\Files;

if (!interface_exists(File::class)) {
	interface File {
		public function getId(): int;

		public function getName(): string;

		public function getPath(): string;

		public function getMimeType(): string;

		public function getContent();

		public function putContent($data): void;

		/**
		 * Untyped, like Nextcloud's own: OCP\Files\Node declares it with a
		 * @return bool docblock and no return type. A stub that tightened
		 * that would accept mocks the real interface would not.
		 */
		public function isUpdateable();

		// Nextcloud's Template serialises a file, so these are part of the
		// contract even where the app itself never calls them.
		public function getEtag(): string;

		public function getMTime(): int;

		public function getSize();

		public function getType(): string;

		public function delete(): void;
	}
}
