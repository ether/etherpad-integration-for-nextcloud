<?php

declare(strict_types=1);

namespace OCP\Migration;

if (!interface_exists('OCP\\Migration\\IRepairStep')) {
	interface IRepairStep {
		public function getName(): string;

		public function run(IOutput $output): void;
	}
}
