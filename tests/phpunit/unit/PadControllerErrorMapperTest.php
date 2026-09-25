<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PadControllerErrorMapper;
use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\ControllerBadRequestException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\InvalidPadNameException;
use OCA\EtherpadNextcloud\Exception\LegacyPadCollisionException;
use OCA\EtherpadNextcloud\Exception\LegacyProtectedImportDisabledException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadAlreadyHasBindingException;
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Exception\UnrecognisedPadContentException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\EtherpadFailureLog;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadControllerErrorMapperTest extends TestCase {
	/**
	 * @return iterable<string, array{\Throwable, int, string, array<string,mixed>}>
	 *   what a client gets: the status, the sentence before it is
	 *   translated, and the rest of the payload
	 */
	public static function answers(): iterable {
		yield 'not signed in' => [new UnauthorizedRequestException('internal wording'), Http::STATUS_UNAUTHORIZED, 'Authentication required.', []];
		yield 'not a pad' => [new NotAPadFileException('internal wording'), Http::STATUS_BAD_REQUEST, 'Selected file is not a .pad file.', []];
		yield 'bad input' => [new \InvalidArgumentException('internal wording'), Http::STATUS_BAD_REQUEST, 'Invalid input.', []];
		yield 'not found' => [new NotFoundException('internal wording'), Http::STATUS_NOT_FOUND, '.pad file not found.', []];
		yield 'locked' => [new LockedException('internal wording'), Http::STATUS_SERVICE_UNAVAILABLE, 'Pad file is temporarily locked. Please retry.', ['retryable' => true]];
		// Creating and initialising a file both throw it; trying again is
		// right for both, a new name only for the one.
		yield 'the file changed' => [new PadFileChangedException('internal wording'), Http::STATUS_CONFLICT, 'The file changed while its pad was being set up. Try again.', ['code' => 'pad_file_changed']];
		yield 'a file by that name' => [new PadFileAlreadyExistsException('internal wording'), Http::STATUS_CONFLICT, 'A file with this name already exists.', []];
		yield 'linked already' => [new PadAlreadyHasBindingException('internal wording'), Http::STATUS_CONFLICT, 'This .pad file is already linked to a pad.', []];
		yield 'a folder not writable' => [new PadParentFolderNotWritableException('internal wording'), Http::STATUS_FORBIDDEN, 'Selected parent folder is not writable.', []];
		yield 'a pad type switched off' => [new PadTypeDisabledException('protected'), Http::STATUS_FORBIDDEN, 'This pad type is disabled on this instance.', ['access_mode' => 'protected', 'code' => 'pad_type_disabled']];
		yield 'both pad types switched off' => [new PadTypeDisabledException(), Http::STATUS_FORBIDDEN, 'This pad type is disabled on this instance.', ['code' => 'pad_type_disabled']];
		// The recovery card hangs off the code.
		yield 'no pad' => [new MissingBindingException('internal wording'), Http::STATUS_BAD_REQUEST, 'This .pad file has no matching pad in this Nextcloud.', ['code' => 'missing_binding']];
		// Not a dead end: a conflict worth trying again.
		yield 'a pad still being restored' => [new WaitingBindingException('internal wording'), Http::STATUS_CONFLICT, 'This pad is still being restored. Try again later.', ['code' => 'waiting_binding', 'retryable' => true]];
		yield 'a row naming another pad' => [new BindingException('Binding pad ID mismatch.'), Http::STATUS_BAD_REQUEST, 'This .pad file does not match its pad. Please contact your administrator.', []];
		yield 'a legacy import switched off' => [new LegacyProtectedImportDisabledException('internal wording'), Http::STATUS_FORBIDDEN, 'This file is a legacy Ownpad link to a protected pad, and importing those is disabled on this server. Please contact your administrator.', ['code' => 'legacy_protected_import_disabled']];
		yield 'a legacy pad bound elsewhere' => [new LegacyPadCollisionException('internal wording'), Http::STATUS_CONFLICT, 'This pad is already linked to another file you do not have access to.', ['code' => 'legacy_collision_no_access']];
		yield 'too large to show' => [new EtherpadTooLargeException('internal wording'), Http::STATUS_BAD_REQUEST, 'This pad is too large to show here. Open it in Etherpad instead.', ['code' => 'pad_too_large']];
		// The clients initialise the file and retry, by the code.
		yield 'no metadata' => [new MissingFrontmatterException('internal wording'), Http::STATUS_BAD_REQUEST, 'This .pad file has no pad metadata yet.', ['code' => 'missing_frontmatter']];
		yield 'another format problem' => [new PadFileFormatException('internal wording'), Http::STATUS_BAD_REQUEST, 'The selected .pad file has an invalid format.', []];
		yield 'content neither metadata nor a shortcut' => [new UnrecognisedPadContentException('internal wording'), Http::STATUS_BAD_REQUEST, 'The selected .pad file has an invalid format.', []];
		yield 'Etherpad failing' => [new EtherpadClientException('Etherpad API request failed: createPad'), Http::STATUS_BAD_REQUEST, 'Etherpad could not complete the request. Try again later.', []];
		yield 'anything else' => [new \RuntimeException('internal wording'), Http::STATUS_INTERNAL_SERVER_ERROR, 'Request failed.', []];
	}

	/**
	 * Every exception the mapper knows answers with its status, a sentence
	 * of the mapper's own, translated - the exception's message is for the
	 * log - and the code ApiErrorCode has for it.
	 *
	 * @param array<string,mixed> $rest
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('answers')]
	public function testEachExceptionAnswersWithATranslatedSentenceOfItsOwn(\Throwable $e, int $status, string $sentence, array $rest): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[de] ' . $text);

		$response = $this->buildMapper(l10n: $l10n)->run(static fn (): array => throw $e, static fn (array $result): DataResponse => new DataResponse($result));

		$this->assertSame(['message' => '[de] ' . $sentence, ...$rest], $response->getData());
		$this->assertSame($status, $response->getStatus());
	}

	/**
	 * Three pass their message on: a controller's refusal of a parameter
	 * and a refused name, each translated where it is thrown, and what was
	 * wrong with a link to a pad on another server - the user's only hint.
	 */
	public function testWhatItPassesOnIsTranslatedWhereItIsThrownOrTheLinksOwn(): void {
		foreach ([
			'a parameter refused' => new ControllerBadRequestException('Ungültige Datei-ID.'),
			'a name refused' => new InvalidPadNameException('„COM1“ ist ein reservierter Name'),
			'a link to another server' => new ExternalPadException('Only public pad URLs can be linked from external servers.'),
		] as $case => $e) {
			$response = $this->buildMapper()->run(
				static fn (): array => throw $e,
				static fn (array $result): DataResponse => new DataResponse($result),
				// Its own sentence wins over the endpoint's word for bad input.
				['invalid_argument' => 'Invalid pad name.'],
			);

			$this->assertSame([Http::STATUS_BAD_REQUEST, ['message' => $e->getMessage()]], [$response->getStatus(), $response->getData()], $case);
		}
	}

	/** An endpoint's own word for what the same exception means there. */
	public function testAnEndpointsOwnWording(): void {
		$wording = ['invalid_argument' => 'Invalid file path.', 'not_found' => 'Template file not found.', 'generic' => 'Could not create pad'];
		foreach ([
			'Invalid file path.' => new \InvalidArgumentException('raw'),
			'Template file not found.' => new NotFoundException('missing'),
			'Could not create pad' => new \RuntimeException('Detailed failure.'),
		] as $sentence => $e) {
			$response = $this->buildMapper()->run(static fn (): array => throw $e, static fn (array $result): DataResponse => new DataResponse($result), $wording);

			$this->assertSame($sentence, $response->getData()['message']);
		}
	}

	/**
	 * A caller's own wording for its own conflict carries none of our codes:
	 * a code tells a client what the message means, and this message is
	 * not the one the code stands for.
	 */
	public function testACallersOwnWordingCarriesNoCodeOfOurs(): void {
		foreach ([new BindingException('duplicate'), new MissingBindingException('no binding'), new WaitingBindingException('waiting')] as $e) {
			$response = $this->buildMapper()->run(
				static fn(): array => throw $e,
				static fn(array $result): DataResponse => new DataResponse($result),
				['binding_message' => 'A file with this name already exists.', 'binding_status' => Http::STATUS_CONFLICT],
			);

			$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus(), $e::class);
			$this->assertSame(['message' => 'A file with this name already exists.'], $response->getData(), $e::class);
		}
	}

	/**
	 * This instance's Etherpad failing is one warning, naming the request's
	 * file - whatever the endpoint's own line for its failures, which is
	 * for the unforeseen.
	 */
	public function testEtherpadFailingIsAWarningNamingTheFile(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Etherpad failed while answering a pad request.',
			$this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud'
				&& $context['file'] === '/Notes.pad'
				&& $context['error'] === EtherpadClientException::class),
		);
		$logger->expects($this->never())->method('error');

		$this->buildMapper($logger)->run(
			static fn (): array => throw new EtherpadClientException('Etherpad API request failed: getRevisionsCount'),
			static fn (array $result): DataResponse => new DataResponse($result),
			['failure' => 'Pad restore API failed', 'context' => ['file' => '/Notes.pad']],
		);
	}

	/**
	 * Anything unforeseen is an error, under the endpoint's line where it
	 * has one and naming the request's file, the cause's class but not its
	 * text as an exception object.
	 */
	public function testAnUnforeseenFailureIsAnErrorUnderTheEndpointsLine(): void {
		foreach ([
			'Pad restore API failed' => ['failure' => 'Pad restore API failed', 'context' => ['file' => '/Notes.pad']],
			'Unhandled pad controller error' => ['context' => ['fileId' => 42]],
		] as $line => $options) {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('error')->with(
				$line,
				$this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud'
					&& array_intersect_key($context, $options['context']) === $options['context']
					&& $context['error'] === \RuntimeException::class
					&& !isset($context['exception'])),
			);

			$response = $this->buildMapper($logger)->run(static fn (): array => throw new \RuntimeException('Detailed failure.'), static fn (array $result): DataResponse => new DataResponse($result), $options);

			$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		}
	}

	/**
	 * A pad on another server, a pad too large to show, and anything the
	 * request itself got wrong are no failure here: nothing is logged.
	 */
	public function testWhatIsNotAFailureHereIsNotLogged(): void {
		foreach ([
			new ExternalPadException('Public export HTTP error (500)'),
			new EtherpadTooLargeException('Pad export is larger than 5242880 bytes.'),
			new NotFoundException('missing'),
			new MissingBindingException('No binding exists for this file.'),
		] as $e) {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->never())->method($this->anything());

			$this->buildMapper($logger)->run(static fn (): array => throw $e, static fn (array $result): DataResponse => new DataResponse($result));
		}
	}

	private function buildMapper(?LoggerInterface $logger = null, ?IL10N $l10n = null): PadControllerErrorMapper {
		if ($l10n === null) {
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $text);
		}
		$logger ??= $this->createMock(LoggerInterface::class);
		return new PadControllerErrorMapper(
			new PadResponseService(
				$this->createMock(IURLGenerator::class),
				$this->createMock(AppConfigService::class),
				$l10n,
			),
			$l10n,
			// No cache: every failure is a warning, one call, one line.
			new EtherpadFailureLog($this->createMock(ICacheFactory::class), $logger),
			$logger,
		);
	}
}
