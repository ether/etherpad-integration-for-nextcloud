<?php

declare(strict_types=1);

namespace OCP\Security\CSP;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\Event;

if (!class_exists(AddContentSecurityPolicyEvent::class)) {
	class AddContentSecurityPolicyEvent extends Event {
		/** @var list<ContentSecurityPolicy> */
		private array $policies = [];

		public function addPolicy(ContentSecurityPolicy $policy): void {
			$this->policies[] = $policy;
		}

		/**
		 * @stub-only Nextcloud has no getter; a test reads the policies a listener added.
		 * @return list<ContentSecurityPolicy>
		 */
		public function getPolicies(): array {
			return $this->policies;
		}
	}
}
