<?php

declare(strict_types=1);

namespace OCP\Migration;

if (!interface_exists(IOutput::class)) {
	interface IOutput {
		public function info(string $message): void;

		public function warning(string $message): void;

		public function startProgress(int $max = 0): void;

		public function advance(int $step = 1, string $description = ''): void;

		public function finishProgress(): void;
	}
}
