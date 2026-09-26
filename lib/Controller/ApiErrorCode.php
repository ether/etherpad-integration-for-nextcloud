<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\BindingNotCreatedException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\LegacyPadCollisionException;
use OCA\EtherpadNextcloud\Exception\LegacyProtectedImportDisabledException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCP\Lock\LockedException;

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
	 * $payload with the code for $e - on a public share without the codes
	 * that need a signed-in user - and `retryable` where the same request
	 * may succeed later.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public static function addTo(array $payload, \Throwable $e, bool $onAPublicShare = false): array {
		$code = self::of($e);
		if ($code !== null && !($onAPublicShare && $code->needsASignedInUser())) {
			$payload['code'] = $code->value;
		}
		if (self::retryable($e)) {
			$payload['retryable'] = true;
		}
		return $payload;
	}

	/**
	 * The same request may succeed later: a row that waits, a file locked
	 * for a moment, this instance's Etherpad not reachable, a file's row
	 * another request made first - the next open finds the winner's pad.
	 * The one place that says so, for both mappers.
	 */
	public static function retryable(\Throwable $e): bool {
		return self::of($e) === self::WaitingBinding
			|| $e instanceof LockedException
			|| $e instanceof BindingNotCreatedException
			|| EtherpadClientException::isEtherpadUnreachable($e);
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
