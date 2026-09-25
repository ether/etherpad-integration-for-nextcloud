<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PadControllerErrorMapper;
use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\LegacyPadCollisionException;
use OCA\EtherpadNextcloud\Exception\LegacyProtectedImportDisabledException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\ControllerBadRequestException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Exception\PadAlreadyHasBindingException;
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadControllerErrorMapperTest extends TestCase {
	public function testRunMapsInvalidArgumentWithConfiguredMessage(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new \InvalidArgumentException('raw'),
			static fn(array $result): DataResponse => new DataResponse($result),
			['invalid_argument' => 'Invalid file path.'],
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid file path.', $response->getData()['message']);
	}

	public function testRunMapsUnauthorizedRequest(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new UnauthorizedRequestException('Authentication required.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Authentication required.', $response->getData()['message']);
	}

	public function testRunMapsControllerBadRequestWithoutOverridingMessage(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new ControllerBadRequestException('Invalid file ID.'),
			static fn(array $result): DataResponse => new DataResponse($result),
			['invalid_argument' => 'Invalid file path.'],
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid file ID.', $response->getData()['message']);
	}

	public function testRunMapsInvalidArgumentWithDefaultMessageWhenMessagesAreEmpty(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new \InvalidArgumentException(" \t\n"),
			static fn(array $result): DataResponse => new DataResponse($result),
			['invalid_argument' => ''],
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid input.', $response->getData()['message']);
	}

	public function testRunMapsNotFoundWithConfiguredMessage(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new NotFoundException('missing'),
			static fn(array $result): DataResponse => new DataResponse($result),
			['not_found' => 'Cannot resolve selected .pad file.'],
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Cannot resolve selected .pad file.', $response->getData()['message']);
	}

	public function testRunMapsLockedExceptionAsRetryable(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new LockedException('locked'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('Pad file is temporarily locked. Please retry.', $response->getData()['message']);
		$this->assertTrue($response->getData()['retryable']);
	}

	public function testRunMapsBindingExceptionWithConfiguredConflictMessage(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new BindingException('duplicate'),
			static fn(array $result): DataResponse => new DataResponse($result),
			[
				'binding_message' => 'A file with this name already exists.',
				'binding_status' => Http::STATUS_CONFLICT,
			],
		);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('A file with this name already exists.', $response->getData()['message']);
	}

	public function testRunMapsPadFileAlreadyExists(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new PadFileAlreadyExistsException('exists'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('A file with this name already exists.', $response->getData()['message']);
	}

	public function testRunMapsMissingBindingWithRecoveryCode(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new MissingBindingException('no binding'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing_binding', $response->getData()['code']);
	}

	/**
	 * A file whose row still waits is on its way back, not a dead end: the
	 * reader is told so in words, and a client sees a conflict worth trying
	 * again, by its code and its status.
	 */
	public function testRunMapsAWaitingBindingAsAConflictToTryAgain(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new WaitingBindingException('Pad binding is not active.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			['message' => 'This pad is still being restored. Try again later.', 'code' => 'waiting_binding', 'retryable' => true],
			$response->getData(),
		);
	}

	/**
	 * A caller's own wording for its own conflict carries none of our codes:
	 * a code tells a client what the message means, and this message is
	 * not the one the code stands for.
	 */
	public function testACallersOwnWordingCarriesNoCodeOfOurs(): void {
		foreach ([new MissingBindingException('no binding'), new WaitingBindingException('waiting')] as $e) {
			$response = $this->buildMapper()->run(
				static fn(): array => throw $e,
				static fn(array $result): DataResponse => new DataResponse($result),
				['binding_message' => 'A file with this name already exists.', 'binding_status' => Http::STATUS_CONFLICT],
			);

			$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus(), $e::class);
			$this->assertSame(['message' => 'A file with this name already exists.'], $response->getData(), $e::class);
		}
	}

	public function testRunMapsLegacyPadCollision(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new LegacyPadCollisionException('not your pad'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('legacy_collision_no_access', $response->getData()['code']);
		$this->assertSame('not your pad', $response->getData()['message']);
	}

	/**
	 * 403 and its code, with the mapper's own sentence: the exception's
	 * message is internal and must not reach the client.
	 */
	public function testRunMapsADisabledLegacyImportToForbidden(): void {
		$response = $this->buildMapper()->run(
			static function (): array {
				throw new LegacyProtectedImportDisabledException('internal wording');
			},
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('legacy_protected_import_disabled', $response->getData()['code']);
		$this->assertStringNotContainsString('internal wording', (string)$response->getData()['message']);
	}

	/** @return iterable<string, array{\Throwable, string}> an exception whose sentence is the mapper's own, and that sentence */
	public static function sentencesOfItsOwn(): iterable {
		yield 'not signed in' => [new UnauthorizedRequestException(''), 'Authentication required.'];
		yield 'bad input' => [new ControllerBadRequestException(''), 'Invalid input.'];
		yield 'not found' => [new NotFoundException('internal wording'), 'Resource not found.'];
		yield 'locked' => [new LockedException('internal wording'), 'Pad file is temporarily locked. Please retry.'];
		yield 'the target changed' => [new PadFileChangedException('internal wording'), 'The target file changed while the pad was being created. Try again with a new name.'];
		yield 'a file by that name' => [new PadFileAlreadyExistsException('internal wording'), 'A file with this name already exists.'];
		yield 'linked already' => [new PadAlreadyHasBindingException('internal wording'), 'This .pad file is already linked to a pad.'];
		yield 'a folder not writable' => [new PadParentFolderNotWritableException('internal wording'), 'Selected parent folder is not writable.'];
		yield 'a pad type switched off' => [new PadTypeDisabledException('internal wording'), 'This pad type is disabled on this instance.'];
		yield 'a legacy import switched off' => [new LegacyProtectedImportDisabledException('internal wording'), 'This file is a legacy Ownpad link to a protected pad, and importing those is disabled on this server. Please contact your administrator.'];
		yield 'too large to show' => [new EtherpadTooLargeException('internal wording'), 'This pad is too large to show here. Open it in Etherpad instead.'];
		yield 'no metadata' => [new MissingFrontmatterException('internal wording'), 'This .pad file has no pad metadata yet.'];
		yield 'anything else' => [new \RuntimeException('internal wording'), 'Request failed.'];
	}

	/**
	 * The sentence for an exception the mapper knows is its own, and
	 * translated: endpoints no longer pass the same sentence in five
	 * places to get it translated.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('sentencesOfItsOwn')]
	public function testTheMappersOwnSentencesAreTranslated(\Throwable $e, string $sentence): void {
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[de] ' . $text);

		$response = $this->buildMapper(l10n: $l10n)->run(static fn (): array => throw $e, static fn (array $result): DataResponse => new DataResponse($result));

		$this->assertSame('[de] ' . $sentence, $response->getData()['message']);
	}

	public function testRunMapsPadAlreadyHasBinding(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new PadAlreadyHasBindingException('already linked'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('This .pad file is already linked to a pad.', $response->getData()['message']);
	}

	public function testRunMapsDisabledPadTypeToForbiddenWithAStableCode(): void {
		// The exception's own message must not reach the client; the payload
		// is built here so the API contract stays independent of it.
		$response = $this->buildMapper()->run(
			static fn(): array => throw new \OCA\EtherpadNextcloud\Exception\PadTypeDisabledException('public'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('pad_type_disabled', $response->getData()['code']);
		$this->assertSame('public', $response->getData()['access_mode']);
		$this->assertSame('This pad type is disabled on this instance.', $response->getData()['message']);
	}

	public function testRunOmitsTheAccessModeWhenNoPadTypeIsEnabled(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new \OCA\EtherpadNextcloud\Exception\PadTypeDisabledException(),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('pad_type_disabled', $response->getData()['code']);
		$this->assertArrayNotHasKey('access_mode', $response->getData());
	}

	public function testRunMapsParentFolderNotWritable(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new PadParentFolderNotWritableException('not writable'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Selected parent folder is not writable.', $response->getData()['message']);
	}

	public function testRunMapsPadFileFormatException(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new PadFileFormatException('Invalid .pad file.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid .pad file.', $response->getData()['message']);
	}

	/**
	 * Etherpad failing answers 400 with its own sentence - for a pad on
	 * another server it says what was wrong with the link - and is logged
	 * once: it used to reach the client and nobody else.
	 */
	public function testRunMapsEtherpadClientExceptionAndLogsIt(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Etherpad failed while answering a pad request.',
			$this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['error'] === EtherpadClientException::class),
		);
		$logger->expects($this->never())->method('error');

		$response = $this->buildMapper($logger)->run(
			static fn(): array => throw new EtherpadClientException('Etherpad rejected request.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Etherpad rejected request.', $response->getData()['message']);
	}

	/** An endpoint that reports its own failures, naming its file, reports Etherpad's too - once. */
	public function testAnEndpointsOwnReportTakesEtherpadFailingToo(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());
		$reported = [];

		$this->buildMapper($logger)->run(
			static fn(): array => throw new EtherpadClientException('Etherpad API request failed: createPad'),
			static fn(array $result): DataResponse => new DataResponse($result),
			['on_throwable' => static function (\Throwable $e) use (&$reported): void {
				$reported[] = $e->getMessage();
			}],
		);

		$this->assertSame(['Etherpad API request failed: createPad'], $reported);
	}

	/** A pad too large to show is no failure of Etherpad's: nothing is logged. */
	public function testAPadTooLargeToShowIsNotLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());

		$response = $this->buildMapper($logger)->run(
			static fn(): array => throw new EtherpadTooLargeException('Pad export is larger than 5242880 bytes.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame('pad_too_large', $response->getData()['code']);
	}

	public function testRunAllowsThrowableOverride(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new \RuntimeException('custom'),
			static fn(array $result): DataResponse => new DataResponse($result),
			[
				'map_throwable' => static fn(\Throwable $e): ?DataResponse => $e->getMessage() === 'custom'
					? new DataResponse(['message' => 'Mapped custom failure.'], Http::STATUS_FORBIDDEN)
					: null,
			],
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Mapped custom failure.', $response->getData()['message']);
	}

	public function testRunCallsCustomThrowableLogger(): void {
		$logged = false;

		$response = $this->buildMapper()->run(
			static fn(): array => throw new \RuntimeException('Detailed failure.'),
			static fn(array $result): DataResponse => new DataResponse($result),
			[
				'generic' => 'Pad open failed.',
				'on_throwable' => static function (\Throwable $e) use (&$logged): void {
					$logged = $e->getMessage() === 'Detailed failure.';
				},
			],
		);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertTrue($logged);
	}

	public function testRunUsesDefaultLoggerForGenericFailures(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'Unhandled pad controller error',
				$this->callback(static fn(array $context): bool => ($context['app'] ?? '') === 'etherpad_nextcloud'
					&& ($context['error'] ?? '') === \RuntimeException::class
					&& !isset($context['exception']))
			);

		$response = $this->buildMapper($logger)->run(
			static fn(): array => throw new \RuntimeException('Detailed failure.'),
			static fn(array $result): DataResponse => new DataResponse($result),
			['generic' => 'Pad open failed.'],
		);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Pad open failed.', $response->getData()['message']);
	}

	public function testRunMapsRuntimeExceptionToGenericMessageByDefault(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new \RuntimeException('Detailed failure.'),
			static fn(array $result): DataResponse => new DataResponse($result),
			['generic' => 'Pad open failed.'],
		);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Pad open failed.', $response->getData()['message']);
	}

	/**
	 * The one `.pad` problem the clients act on rather than report: they
	 * initialise the file and retry. They used to recognise it by searching
	 * the English message for a phrase, so this code is what holds the two
	 * halves together — without it the frontends silently stop recovering.
	 */
	public function testMissingFrontmatterCarriesAStableCode(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new MissingFrontmatterException('Missing YAML frontmatter in .pad file.'),
			static fn(array $r): DataResponse => new DataResponse($r),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing_frontmatter', $response->getData()['code']);
	}

	/** Any other format problem stays a plain 400 with no code to branch on. */
	public function testAnotherFormatProblemCarriesNoCode(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new PadFileFormatException('The body is not a pad.'),
			static fn(array $r): DataResponse => new DataResponse($r),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayNotHasKey('code', $response->getData());
	}

	private function buildMapper(?LoggerInterface $logger = null, ?\OCP\IL10N $l10n = null): PadControllerErrorMapper {
		if ($l10n === null) {
			$l10n = $this->createMock(\OCP\IL10N::class);
			$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $text);
		}
		return new PadControllerErrorMapper(
			new PadResponseService(
				$this->createMock(IURLGenerator::class),
				$this->createMock(AppConfigService::class),
				$l10n,
			),
			$l10n,
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * A name the instance refuses is the user's to fix, so it answers 400
	 * — and with the sentence naming the rule, not the endpoint's generic
	 * one, which is the whole point of having its own type.
	 */
	public function testNameRefusalAnswers400WithItsOwnReason(): void {
		$response = $this->buildMapper()->run(
			static function (): array {
				throw new \OCA\EtherpadNextcloud\Exception\InvalidPadNameException('"COM1" is a reserved name');
			},
			static fn (array $result): DataResponse => new DataResponse($result),
			['invalid_argument' => 'Invalid pad name.'],
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('"COM1" is a reserved name', $response->getData()['message']);
	}
}
