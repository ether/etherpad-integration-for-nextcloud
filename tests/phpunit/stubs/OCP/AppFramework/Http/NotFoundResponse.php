<?php

declare(strict_types=1);

namespace OCP\AppFramework\Http;

use OCP\AppFramework\Http;

if (!class_exists(NotFoundResponse::class)) {
	class NotFoundResponse extends TemplateResponse {
		/** @param array<string,mixed> $headers */
		public function __construct(int $status = Http::STATUS_NOT_FOUND, array $headers = []) {
			parent::__construct('core', '404', [], 'guest');
			$this->setStatus($status);
		}
	}
}
