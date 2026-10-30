<?php

declare(strict_types=1);

namespace OCP\AppFramework;

use OCP\IRequest;
use OCP\ISession;

if (!class_exists(PublicShareController::class)) {
	abstract class PublicShareController extends Controller {
		public const DAV_AUTHENTICATED_FRONTEND = 'public_link_authenticated_frontend';

		private string $token = '';

		public function __construct(
			string $appName,
			IRequest $request,
			protected ISession $session,
		) {
			parent::__construct($appName, $request);
		}

		final public function setToken(string $token): void {
			$this->token = $token;
		}

		final public function getToken(): string {
			return $this->token;
		}

		abstract protected function getPasswordHash(): ?string;

		abstract public function isValidToken(): bool;

		abstract protected function isPasswordProtected(): bool;

		public function isAuthenticated(): bool {
			if (!$this->isPasswordProtected()) {
				return true;
			}

			return $this->validateTokenSession($this->getToken(), $this->getPasswordHash());
		}

		/** As upstream: the hash is a string, and a null one is a TypeError. */
		protected function validateTokenSession(string $token, string $passwordHash): bool {
			$allowedTokens = json_decode((string)($this->session->get(self::DAV_AUTHENTICATED_FRONTEND) ?? '[]'), true);
			if (!is_array($allowedTokens)) {
				$allowedTokens = [];
			}

			return ($allowedTokens[$token] ?? '') === $passwordHash;
		}
	}
}
