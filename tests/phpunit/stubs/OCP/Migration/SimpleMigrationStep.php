<?php

declare(strict_types=1);

namespace OCP\Migration;

if (!class_exists(SimpleMigrationStep::class)) {
	abstract class SimpleMigrationStep {
		public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options) {
		}

		public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
			return null;
		}

		public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options) {
		}
	}
}
