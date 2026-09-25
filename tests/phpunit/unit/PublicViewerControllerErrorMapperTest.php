<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PublicViewerControllerErrorMapper;
use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\ExternalPadExportNotFoundException;
use OCA\EtherpadNextcloud\Exception\InvalidShareFilePathException;
use OCA\EtherpadNextcloud\Exception\InvalidShareTokenException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Exception\NoShareFileSelectedException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\UnrecognisedPadContentException;
use OCA\EtherpadNextcloud\Exception\ShareFileNotInShareException;
use OCA\EtherpadNextcloud\Exception\ShareItemUnavailableException;
use OCA\EtherpadNextcloud\Exception\ShareReadForbiddenException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\EtherpadFailureLog;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PublicViewerControllerErrorMapperTest extends TestCase {
	public function testRunForDataReturnsSuccessResponse(): void {
		$response = $this->buildMapper()->runForData(
			static fn(): array => ['ok' => true],
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue((bool)$response->getData()['ok']);
	}

	/** @return iterable<string, array{\Throwable, int, string}> what a visitor gets: the status and the sentence, before it is translated */
	public static function publicAnswers(): iterable {
		yield 'a link that is invalid or expired' => [new InvalidShareTokenException('internal wording'), Http::STATUS_NOT_FOUND, 'This share link is invalid or has expired.'];
		yield 'a shared item gone' => [new ShareItemUnavailableException('internal wording'), Http::STATUS_NOT_FOUND, 'This shared item is no longer available.'];
		yield 'a file not in the share' => [new ShareFileNotInShareException('internal wording'), Http::STATUS_NOT_FOUND, 'The selected file is not part of this share.'];
		yield 'a link that may not read' => [new ShareReadForbiddenException('internal wording'), Http::STATUS_FORBIDDEN, 'This share link does not allow reading files.'];
		// By id, by path, or the two naming different files: not "the path".
		yield 'a link that names no valid file' => [new InvalidShareFilePathException('Invalid file id.'), Http::STATUS_BAD_REQUEST, 'This link does not point to a valid file.'];
		yield 'a folder without a file chosen' => [new NoShareFileSelectedException('internal wording'), Http::STATUS_BAD_REQUEST, 'No .pad file selected. Open a .pad file from this shared folder.'];
		// A folder in the share too.
		yield 'not a pad' => [new NotAPadFileException('internal wording'), Http::STATUS_BAD_REQUEST, 'The selected item is not a .pad document.'];
		yield 'no metadata' => [new MissingFrontmatterException('internal wording'), Http::STATUS_BAD_REQUEST, 'The selected .pad file is missing required metadata.'];
		yield 'another format problem' => [new PadFileFormatException('internal wording'), Http::STATUS_BAD_REQUEST, 'The selected .pad file has an invalid format.'];
		// Not missing metadata: a file that cannot be initialised.
		yield 'content neither metadata nor a shortcut' => [new UnrecognisedPadContentException('internal wording'), Http::STATUS_BAD_REQUEST, 'The selected .pad file has an invalid format.'];
		// A copy, or an original whose pad the sweep let go: the visitor cannot
		// tell, and the owner opening it is offered the pad back either way.
		yield 'no pad' => [new MissingBindingException('internal wording'), Http::STATUS_BAD_REQUEST, 'This .pad file has no pad in this Nextcloud. Its owner can open it to restore the pad.'];
		// The signed-in sentence, a conflict worth trying again.
		yield 'a pad still being restored' => [new WaitingBindingException('internal wording'), Http::STATUS_CONFLICT, 'This pad is still being restored. Try again later.'];
		yield 'another binding problem' => [new BindingException('internal wording'), Http::STATUS_BAD_REQUEST, 'Pad binding is inconsistent. Please contact the share owner.'];
		// Passes by itself, as on the signed-in side.
		yield 'a file locked' => [new LockedException('internal wording'), Http::STATUS_SERVICE_UNAVAILABLE, 'Pad file is temporarily locked. Please retry.'];
		yield 'a pad too large to show' => [new EtherpadTooLargeException('internal wording'), Http::STATUS_BAD_REQUEST, 'This pad is too large to show here. Open it in Etherpad instead.'];
		// Not this instance's Etherpad: gone from its server, or that server down.
		yield 'a pad on another server' => [new ExternalPadExportNotFoundException('internal wording'), Http::STATUS_BAD_REQUEST, 'The pad this file links to on another server could not be read.'];
		yield 'Etherpad failing' => [new EtherpadClientException('internal wording'), Http::STATUS_BAD_REQUEST, 'Etherpad is currently unavailable for this shared pad.'];
		yield 'anything else' => [new \RuntimeException('internal wording'), Http::STATUS_INTERNAL_SERVER_ERROR, 'Could not open pad'];
	}

	/**
	 * Every answer a visitor reads is this mapper's own sentence, translated,
	 * as data and on the error page alike; what the exception says is for
	 * the log.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('publicAnswers')]
	public function testAVisitorReadsATranslatedSentenceOfTheMappersOwn(\Throwable $e, int $status, string $sentence): void {
		$data = $this->buildMapper()->runForData(static fn (): array => throw $e, static fn (array $result): DataResponse => new DataResponse($result));
		$page = $this->buildMapper('/nc')->runForTemplate(static fn (): string => throw $e, static fn (string $target): RedirectResponse => new RedirectResponse($target), 'token');

		$this->assertSame([$status, '[de] ' . $sentence], [$data->getStatus(), $data->getData()['message']]);
		$this->assertInstanceOf(TemplateResponse::class, $page);
		$this->assertSame([$status, '[de] ' . $sentence], [$page->getStatus(), $page->getParams()['error']]);
	}

	/**
	 * A public answer carries the codes a visitor's client can act on, from
	 * the same place as the signed-in one - and not the two whose action
	 * needs a signed-in user: the viewer opens public pads through the flow
	 * that initialises a file on missing_frontmatter.
	 */
	public function testAPublicAnswerCarriesOnlyTheCodesAVisitorCanActOn(): void {
		$cases = [
			'a pad still being restored' => [new WaitingBindingException('Pad binding is not active.'), ['code' => 'waiting_binding', 'retryable' => true]],
			'a pad too large to show' => [new EtherpadTooLargeException('Pad export is larger than 5242880 bytes.'), ['code' => 'pad_too_large']],
			'no pad' => [new MissingBindingException('No binding exists for this file.'), []],
			'no metadata' => [new MissingFrontmatterException('Missing YAML frontmatter.'), []],
			// No code, but as on the signed-in side a retry is worth it.
			'a file locked' => [new LockedException('locked'), ['retryable' => true]],
		];
		foreach ($cases as $case => [$e, $expected]) {
			$data = $this->buildMapper()->runForData(static fn (): array => throw $e, static fn (array $result): DataResponse => new DataResponse($result))->getData();
			unset($data['message']);
			$this->assertSame($expected, $data, $case);
		}
	}

	/**
	 * This instance's Etherpad failing is the admin's to see, from a public
	 * share as from the signed-in side: one warning, and no share token in
	 * it. What a visitor's link or file got wrong, a file locked for a
	 * moment, a pad too large to show and a pad on another server are no
	 * failure here.
	 */
	public function testEtherpadFailingIsLoggedAndAVisitorsMistakeIsNot(): void {
		foreach ([
			'Etherpad failing' => [new EtherpadClientException('Etherpad API request failed: getHTML'), 1],
			'a pad too large to show' => [new EtherpadTooLargeException('Pad export is larger than 5242880 bytes.'), 0],
			'a pad on another server' => [new ExternalPadException('Public export HTTP error (500)'), 0],
			'a file locked' => [new LockedException('locked'), 0],
			'a link that is invalid' => [new InvalidShareTokenException('This share link is invalid or has expired.'), 0],
			'another binding problem' => [new BindingException('Binding pad ID mismatch.'), 0],
		] as $case => [$e, $warnings]) {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->exactly($warnings))->method('warning')->with(
				'Etherpad failed while answering a public share request.',
				$this->callback(static fn (array $context): bool => array_keys(array_diff_key($context, ['error' => 1, 'error_message' => 1, 'error_origin' => 1])) === ['app'] && $context['error'] === EtherpadClientException::class),
			);
			$logger->expects($this->never())->method('error');

			$this->buildMapper(logger: $logger)->runForData(static fn (): array => throw $e, static fn (array $result): DataResponse => new DataResponse($result));
			$this->addToAssertionCount(1);
		}
	}

	public function testRunForTemplateReturnsRedirectOnSuccess(): void {
		$response = $this->buildMapper('/nc')->runForTemplate(
			static fn(): string => '/nc/s/token?dir=%2F',
			static fn(string $target): RedirectResponse => new RedirectResponse($target),
			'token',
		);

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/nc/s/token?dir=%2F', $response->getRedirectURL());
	}

	public function testRunForTemplateReturnsNoViewerTemplateOnError(): void {
		$response = $this->buildMapper('/nc')->runForTemplate(
			static fn(): string => throw new NotAPadFileException('The selected file is not a .pad document.'),
			static fn(string $target): RedirectResponse => new RedirectResponse($target),
			'token',
		);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('[de] The selected item is not a .pad document.', $response->getParams()['error']);
		$this->assertSame('/nc/s/token', $response->getParams()['back_url']);
		$this->assertSame('[de] Back to shared files', $response->getParams()['back_label']);
	}

	public function testRunForDataLogsAndMasksUnexpectedFailures(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Unhandled public viewer error',
			$this->callback(static function ($context): bool {
				return is_array($context)
					&& ($context['app'] ?? '') === 'etherpad_nextcloud'
					&& ($context['error'] ?? '') === \RuntimeException::class
					&& !isset($context['exception']);
			}),
		);

		$response = $this->buildMapper(logger: $logger)->runForData(
			static fn(): array => throw new \RuntimeException('internal path /var/secret/file.pad'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('[de] Could not open pad', $response->getData()['message']);
	}

	public function testRunForTemplateLogsAndMasksUnexpectedFailures(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Unhandled public viewer error',
			$this->callback(static function ($context): bool {
				return is_array($context)
					&& ($context['app'] ?? '') === 'etherpad_nextcloud'
					&& ($context['error'] ?? '') === \RuntimeException::class
					&& !isset($context['exception']);
			}),
		);

		$response = $this->buildMapper('/nc', $logger)->runForTemplate(
			static fn(): string => throw new \RuntimeException('internal path /var/secret/file.pad'),
			static fn(string $target): RedirectResponse => new RedirectResponse($target),
			'token',
		);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('[de] Could not open pad', $response->getParams()['error']);
		$this->assertSame('/nc/s/token', $response->getParams()['back_url']);
	}

	private function buildMapper(string $webroot = '', ?LoggerInterface $logger = null): PublicViewerControllerErrorMapper {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getWebroot')->willReturn($webroot);
		// A translation that shows: what went through t() comes back marked.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[de] ' . $text);
		return new PublicViewerControllerErrorMapper(
			new PublicShareUrlBuilder($urlGenerator, new PathNormalizer()),
			new PadResponseService($urlGenerator, $this->createMock(AppConfigService::class), $l10n),
			$l10n,
			new EtherpadFailureLog($this->createMock(ICacheFactory::class), $logger ?? $this->createMock(LoggerInterface::class)),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
