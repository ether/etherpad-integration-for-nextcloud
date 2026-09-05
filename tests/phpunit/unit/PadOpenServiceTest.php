<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadOpenService;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadOpenServiceTest extends TestCase {
	public function testOpenByPathRejectsEmptyNormalizedPath(): void {
		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeViewerFilePath')
			->with(" \t")
			->willReturn('');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->never())->method('resolveUserFileNodeByPath');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid file path.');

		$this->buildService($padPaths, $userNodeResolver)
			->openByPath('alice', 'Alice', " \t");
	}

	/**
	 * A share that grants no write permission must not hand out a way to
	 * edit. Nextcloud's own read-only share stops at the file; the pad
	 * lives on another host, where the session and the URL are the whole of
	 * the access — so withholding them is the only thing that makes "view
	 * only" mean anything there.
	 */
	public function testGivesAProtectedPadAsAReadOnlyViewWhenTheShareIsReadOnly(): void {
		$session = $this->createMock(PadSessionService::class);
		$session->expects($this->never())->method('createProtectedOpenContext');

		// Nor any address. The content arrives over the separate content
		// endpoint, so nothing here needs the pad server — asking it
		// anything would put that dependency back into the open.
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('buildPadUrl');
		$client->expects($this->never())->method('getReadOnlyPadUrl');

		$target = $this->openWith(
			BindingService::ACCESS_PROTECTED,
			updateable: false,
			padSessionService: $session,
			etherpadClient: $client,
		);

		$this->assertTrue($target->isReadOnlyView);
		$this->assertSame('', $target->url, 'a viewer must not be given a pad to open');
		$this->assertSame('', $target->cookieHeader, 'and no session to open it with');
		// The response ships this as `pad_url`. Withholding one address and
		// handing back the same one under another key would undo the whole
		// thing, and no client reads it today — which is why it would go
		// unnoticed.
		$this->assertSame('', $target->padUrl, 'nor the address under a second name');
	}

	/** With write permission, the same pad opens as it always did. */
	public function testGivesAProtectedPadAsAnEditorWhenTheShareAllowsWriting(): void {
		$session = $this->createMock(PadSessionService::class);
		$session->expects($this->once())
			->method('createProtectedOpenContext')
			->willReturn([
				'url' => 'https://pad.example.test/p/g.ABCDEFGHIJKLMNOP$pad-1',
				'cookie' => [
					'name' => 'sessionID', 'value' => 's.x', 'expires' => 0, 'path' => '/',
					'domain' => '', 'secure' => true, 'http_only' => false, 'same_site' => 'lax',
				],
			]);

		$target = $this->openWith(
			BindingService::ACCESS_PROTECTED,
			updateable: true,
			padSessionService: $session,
		);

		$this->assertFalse($target->isReadOnlyView);
		$this->assertSame('https://pad.example.test/p/g.ABCDEFGHIJKLMNOP$pad-1', $target->url);
	}

	/**
	 * A public pad has no session to withhold, so the editable URL is the
	 * whole of the access. Etherpad's own read-only view is the one thing
	 * that cannot be typed into.
	 */
	public function testGivesAPublicPadItsReadOnlyUrlWhenTheShareIsReadOnly(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('buildPadUrl')->willReturn('https://pad.example.test/p/pad-1');
		$client->expects($this->once())
			->method('getReadOnlyPadUrl')
			->with('pad-1')
			->willReturn('https://pad.example.test/p/r.readonlyid');

		$target = $this->openWith(
			BindingService::ACCESS_PUBLIC,
			updateable: false,
			etherpadClient: $client,
			padId: 'pad-1',
		);

		$this->assertSame('https://pad.example.test/p/r.readonlyid', $target->url);
	}

	/**
	 * A pad server that cannot say what the read-only URL is must not turn
	 * a view-only open into a failure — the writable users on the same pad
	 * would keep working, so the outage would present as "only the people
	 * who may not edit cannot open it".
	 */
	public function testFallsBackToTheReadOnlyViewWhenTheReadOnlyUrlCannotBeResolved(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('buildPadUrl')->willReturn('https://pad.example.test/p/pad-1');
		$client->method('getReadOnlyPadUrl')
			->willThrowException(new EtherpadClientException('Connection timed out'));

		$target = $this->openWith(
			BindingService::ACCESS_PUBLIC,
			updateable: false,
			etherpadClient: $client,
			padId: 'pad-1',
		);

		$this->assertTrue($target->isReadOnlyView);
		$this->assertSame('', $target->url, 'no pad to open');
		$this->assertSame('', $target->padUrl, 'and not under the other name either');
		$this->assertSame('', $target->cookieHeader, 'and no session');
	}

	/**
	 * A copy carries the original's `pad_id` but no binding of its own, so
	 * the open fails before it starts. With the marker set, the open lands
	 * on the original instead of the recovery card.
	 */
	public function testFollowsTheAliasMarkerToTheOriginalPad(): void {
		$target = $this->openAliasScenario(aliasOfPadId: 'original-pad');

		$this->assertSame(42, $target->fileId);
		$this->assertSame('/Original.pad', $target->file);
		$this->assertSame('original-pad', $target->padId);
	}

	/**
	 * The opt-in is the whole feature: without the marker a copy still ends
	 * on the recovery card, so the user keeps deciding per file.
	 */
	public function testACopyWithoutTheMarkerStillFailsWithMissingBinding(): void {
		$this->expectException(MissingBindingException::class);
		$this->openAliasScenario(aliasOfPadId: '');
	}

	/**
	 * Authorization: a hand-written marker must not become a way to open a
	 * pad the requester cannot already read. The miss is indistinguishable
	 * from having no marker at all.
	 */
	public function testDoesNotFollowTheAliasWhenTheOriginalIsUnreadable(): void {
		$this->expectException(MissingBindingException::class);
		$this->openAliasScenario(aliasOfPadId: 'original-pad', originalReadable: false);
	}

	/** A binding that no longer exists leads nowhere, not to a second guess. */
	public function testDoesNotFollowTheAliasWhenTheTargetHasNoActiveBinding(): void {
		$this->expectException(MissingBindingException::class);
		$this->openAliasScenario(aliasOfPadId: 'original-pad', binding: null);
	}

	/**
	 * One hop only. A chain of three files — the copy points at an unbound
	 * middle file, which points at a bound one — must stop at the middle
	 * rather than walk to the end. Refusing the second hop is what keeps a
	 * cycle from spinning without tracking where the walk has been.
	 */
	public function testFollowsAtMostOneAliasHop(): void {
		$nodes = [];
		foreach ([710 => false, 42 => false, 43 => true] as $id => $bound) {
			$node = $this->createMock(File::class);
			$node->method('getId')->willReturn($id);
			$node->method('getContent')->willReturn('file-' . $id);
			$node->method('isUpdateable')->willReturn(false);
			$nodes[$id] = $node;
		}

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')
			->willReturnCallback(static fn (string $uid, int $fileId): File => $nodes[$fileId]);
		$userNodeResolver->method('toUserAbsolutePath')
			->willReturnCallback(static fn (string $uid, File $node): string => '/' . $node->getId() . '.pad');

		// The copy defers to the middle file, the middle file to the last one.
		$aliases = ['file-710' => 'pad-b', 'file-42' => 'pad-c', 'file-43' => ''];
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturnCallback(
			static fn (string $content): ParsedPadFile => new ParsedPadFile(
				frontmatter: [],
				body: '',
				padId: 'pad-b',
				accessMode: BindingService::ACCESS_PROTECTED,
				padUrl: '',
				isExternal: false,
				snapshotRev: -1,
				aliasOfPadId: $aliases[$content],
			)
		);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('assertConsistentMapping')->willReturnCallback(
			static function (int $fileId): void {
				if ($fileId !== 43) {
					throw new MissingBindingException('No binding exists for this file.');
				}
			}
		);
		$bindingService->method('findByPadId')->willReturnCallback(
			static fn (string $padId): array => ['file_id' => $padId === 'pad-b' ? 42 : 43]
		);

		$service = new PadOpenService(
			$padFileService,
			$this->createMock(PathNormalizer::class),
			$userNodeResolver,
			new PadFileLockRetryService(static function (int $delay): void {
			}),
			$bindingService,
			$this->createMock(EtherpadClient::class),
			$this->createMock(ExternalPadExportFetcher::class),
			$this->createMock(PadSessionService::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->expectException(MissingBindingException::class);
		$service->openById('alice', 'Alice', 710);
	}

	private function openAliasScenario(
		string $aliasOfPadId,
		bool $originalReadable = true,
		?array $binding = ['file_id' => 42],
	): \OCA\EtherpadNextcloud\Service\PadOpenTarget {
		$copy = $this->createMock(File::class);
		$copy->method('getId')->willReturn(710);
		$copy->method('getContent')->willReturn('copy');
		// Read-only, so the open stops at the read-only view and needs
		// neither the pad server nor a session to reach its answer.
		$copy->method('isUpdateable')->willReturn(false);

		$original = $this->createMock(File::class);
		$original->method('getId')->willReturn(42);
		$original->method('getContent')->willReturn('original');
		$original->method('isUpdateable')->willReturn(false);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturnCallback(
			static function (string $uid, int $fileId) use ($copy, $original, $originalReadable): File {
				if ($fileId === 710) {
					return $copy;
				}
				if (!$originalReadable) {
					throw new NotFoundException('not readable by this user');
				}
				return $original;
			}
		);
		$userNodeResolver->method('toUserAbsolutePath')->willReturnCallback(
			static fn (string $uid, File $node): string => $node->getId() === 42 ? '/Original.pad' : '/Copy.pad'
		);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturnCallback(
			static function (string $content) use ($aliasOfPadId): ParsedPadFile {
				$alias = $content === 'copy' ? $aliasOfPadId : '';
				return new ParsedPadFile(
					frontmatter: ['pad_id' => 'original-pad', 'access_mode' => BindingService::ACCESS_PROTECTED],
					body: '',
					padId: 'original-pad',
					accessMode: BindingService::ACCESS_PROTECTED,
					padUrl: '',
					isExternal: false,
					snapshotRev: -1,
					aliasOfPadId: $alias,
				);
			}
		);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('assertConsistentMapping')->willReturnCallback(
			static function (int $fileId): void {
				if ($fileId === 710) {
					throw new MissingBindingException('No binding exists for this file.');
				}
			}
		);
		$bindingService->method('findByPadId')->willReturn($binding);

		$service = new PadOpenService(
			$padFileService,
			$this->createMock(PathNormalizer::class),
			$userNodeResolver,
			new PadFileLockRetryService(static function (int $delay): void {
			}),
			$bindingService,
			$this->createMock(EtherpadClient::class),
			$this->createMock(ExternalPadExportFetcher::class),
			$this->createMock(PadSessionService::class),
			$this->createMock(LoggerInterface::class),
		);

		return $service->openById('alice', 'Alice', 710);
	}

	private function openWith(
		string $accessMode,
		bool $updateable,
		?PadSessionService $padSessionService = null,
		?EtherpadClient $etherpadClient = null,
		string $padId = 'g.ABCDEFGHIJKLMNOP$pad-1',
	): \OCA\EtherpadNextcloud\Service\PadOpenTarget {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(138);
		$file->method('getContent')->willReturn('frontmatter');
		$file->method('isUpdateable')->willReturn($updateable);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => $padId, 'access_mode' => $accessMode],
			body: 'snapshot text',
			padId: $padId,
			accessMode: $accessMode,
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		));

		$client = $etherpadClient ?? $this->createMock(EtherpadClient::class);
		$service = new PadOpenService(
			$padFileService,
			$this->createMock(PathNormalizer::class),
			$userNodeResolver,
			new PadFileLockRetryService(static function (int $delay): void {
			}),
			$this->createMock(BindingService::class),
			$client,
			$this->createMock(ExternalPadExportFetcher::class),
			$padSessionService ?? $this->createMock(PadSessionService::class),
			$this->createMock(LoggerInterface::class),
		);

		return $service->openById('alice', 'Alice', 138);
	}

	private function buildService(PathNormalizer $padPaths, UserNodeResolver $userNodeResolver): PadOpenService {
		$padFileService = $this->createMock(PadFileService::class);
		return new PadOpenService(
			$padFileService,
			$padPaths,
			$userNodeResolver,
			$this->createMock(PadFileLockRetryService::class),
			$this->createMock(BindingService::class),
			$this->createMock(EtherpadClient::class),
			$this->createMock(ExternalPadExportFetcher::class),
			$this->createMock(PadSessionService::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
