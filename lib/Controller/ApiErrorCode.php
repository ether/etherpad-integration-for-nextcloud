<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\LegacyPadCollisionException;
use OCA\EtherpadNextcloud\Exception\LegacyProtectedImportDisabledException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;

/**
 * The `code` of an error response, and the exception it stands for: the
 * one place both error mappers take it from. docs/api-reference.md lists
 * the same set, and a test holds the two together.
 *
 * Status and message stay each mapper's own; what the code means to a
 * client does not change with the endpoint.
 */
enum ApiErrorCode: string {
	case MissingBinding = 'missing_binding';
	case WaitingBinding = 'waiting_binding';
	case MissingFrontmatter = 'missing_frontmatter';
	case PadTooLarge = 'pad_too_large';
	case PadFileChanged = 'pad_file_changed';
	case PadTypeDisabled = 'pad_type_disabled';
	case LegacyCollisionNoAccess = 'legacy_collision_no_access';
	case LegacyProtectedImportDisabled = 'legacy_protected_import_disabled';

	/**
	 * $payload with the code for $e and, where the code says so,
	 * `retryable` - on a public share without the codes that need a
	 * signed-in user.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public static function addTo(array $payload, \Throwable $e, bool $onAPublicShare = false): array {
		$code = self::of($e);
		if ($code === null || ($onAPublicShare && $code->needsASignedInUser())) {
			return $payload;
		}
		$payload['code'] = $code->value;
		if ($code->retryable()) {
			$payload['retryable'] = true;
		}
		return $payload;
	}

	/**
	 * The code for $e, or null: most errors carry none. The first case whose
	 * exception $e is wins, so should one listed exception come to extend
	 * another, the more specific case goes first.
	 */
	public static function of(\Throwable $e): ?self {
		foreach (self::cases() as $code) {
			if (is_a($e, $code->exceptionClass())) {
				return $code;
			}
		}
		return null;
	}

	/** @return class-string<\Throwable> */
	public function exceptionClass(): string {
		return match ($this) {
			self::MissingBinding => MissingBindingException::class,
			self::WaitingBinding => WaitingBindingException::class,
			self::MissingFrontmatter => MissingFrontmatterException::class,
			self::PadTooLarge => EtherpadTooLargeException::class,
			self::PadFileChanged => PadFileChangedException::class,
			self::PadTypeDisabled => PadTypeDisabledException::class,
			self::LegacyCollisionNoAccess => LegacyPadCollisionException::class,
			self::LegacyProtectedImportDisabled => LegacyProtectedImportDisabledException::class,
		};
	}

	/** The same request may succeed later: the response says `retryable`. */
	public function retryable(): bool {
		return $this === self::WaitingBinding;
	}

	/**
	 * What a client does on this code needs a signed-in user - recover the
	 * pad, initialise the file - so a public share's answer leaves it out:
	 * the viewer, which opens public pads through the same flow, would
	 * start that action.
	 */
	public function needsASignedInUser(): bool {
		return $this === self::MissingBinding || $this === self::MissingFrontmatter;
	}
}
