<?php

declare(strict_types=1);

namespace OCP\AppFramework\Http;

if (!class_exists(ContentSecurityPolicy::class)) {
	/*
	 * Only what a supported Nextcloud still offers: a method here that no
	 * server has makes these tests green on code that cannot run.
	 */
	class ContentSecurityPolicy {
		/** @var list<string> */
		private array $frameAncestorDomains = [];
		/** @var list<string> */
		private array $frameDomains = [];

		public function addAllowedFrameAncestorDomain(string $domain): void {
			$this->frameAncestorDomains[] = $domain;
		}

		public function addAllowedFrameDomain(string $domain): void {
			$this->frameDomains[] = $domain;
		}

		/** @return list<string> */
		public function getAllowedFrameAncestorDomains(): array {
			return $this->frameAncestorDomains;
		}

		/** @return list<string> */
		public function getAllowedFrameDomains(): array {
			return $this->frameDomains;
		}
	}
}
