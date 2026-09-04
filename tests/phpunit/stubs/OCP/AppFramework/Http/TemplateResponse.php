<?php

declare(strict_types=1);

namespace OCP\AppFramework\Http;

if (!class_exists(TemplateResponse::class)) {
	class TemplateResponse {
		public const RENDER_AS_GUEST = 'guest';
		public const RENDER_AS_BLANK = '';
		public const RENDER_AS_BASE = 'base';
		public const RENDER_AS_USER = 'user';
		public const RENDER_AS_ERROR = 'error';
		public const RENDER_AS_PUBLIC = 'public';

		/** @var array<string,mixed> */
		private array $params;
		private ?ContentSecurityPolicy $contentSecurityPolicy = null;
		private int $status = 200;

		/** @param array<string,mixed> $params */
		public function __construct(
			private string $appName,
			private string $templateName,
			array $params = [],
			private string $renderAs = self::RENDER_AS_USER,
		) {
			$this->params = $params;
		}

		/** @return array<string,mixed> */
		public function getParams(): array {
			return $this->params;
		}

		public function getTemplateName(): string {
			return $this->templateName;
		}

		public function getRenderAs(): string {
			return $this->renderAs;
		}

		public function setStatus(int $status): void {
			$this->status = $status;
		}

		public function getStatus(): int {
			return $this->status;
		}

		public function setContentSecurityPolicy(ContentSecurityPolicy $contentSecurityPolicy): void {
			$this->contentSecurityPolicy = $contentSecurityPolicy;
		}

		public function getContentSecurityPolicy(): ?ContentSecurityPolicy {
			return $this->contentSecurityPolicy;
		}
	}
}
