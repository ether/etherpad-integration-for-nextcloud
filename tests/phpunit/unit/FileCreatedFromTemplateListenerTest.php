<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\FileCreatedFromTemplateListener;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadPlaceholderResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileCreatedFromTemplateListenerTest extends TestCase {
	private const FIXED_NOW = 1778976000; // 2026-05-17 (Sunday)

	public function testIgnoresUnrelatedEvent(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('createBinding');

		$this->buildListener($bindingService)->handle(new class extends Event {});
	}

	public function testIgnoresNonPadTarget(): void {
		$target = $this->file(101, 'Notes.txt', '');
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('createBinding');

		$this->buildListener($bindingService)->handle(new FileCreatedFromTemplateEvent(
			$this->file(7, 'Template.pad', ''),
			$target,
		));
	}

	public function testIgnoresBlankTemplate(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('createBinding');

		$this->buildListener($bindingService)->handle(new FileCreatedFromTemplateEvent(
			null,
			$this->file(102, 'New.pad', ''),
		));
	}

	public function testMaterializesFromTemplate(): void {
		$template = $this->file(7, 'Protokoll-Template.pad', 'tpl-content');
		$targetParent = $this->createMock(Folder::class);
		$targetParent->method('getPath')->willReturn('/alice/files/Meetings');
		$target = $this->file(99, 'Protokoll.pad', '', $targetParent);
		$target->expects($this->once())
			->method('putContent')
			->with($this->stringContains('snapshot+placeholders'));

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->with('tpl-content')->willReturn([
			'frontmatter' => ['pad_id' => 'g.tpl$pad', 'access_mode' => 'protected'],
			'body' => 'body',
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => 'g.tpl$pad',
			'access_mode' => 'protected',
			'pad_url' => '',
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(false);
		$padFileService->method('getSnapshotPartsFromBody')->willReturn([
			'text' => 'Sitzung am {{date:next monday|d.m.Y}}',
			'html' => '<p>{{date:next monday|d.m.Y}}</p>',
		]);
		$padFileService->method('buildInitialDocument')->willReturn('initial-doc');
		$padFileService->method('withExportSnapshot')->willReturn('snapshot+placeholders-applied');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())
			->method('provisionPadId')
			->with('protected')
			->willReturn('p-newpad123');
		$bootstrap->expects($this->once())
			->method('pushInitialSnapshot')
			->with(
				'p-newpad123',
				'Sitzung am 18.05.2026',
				'<p>18.05.2026</p>',
			);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with(99, 'p-newpad123', 'protected');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/p-newpad123');

		$this->buildListener($bindingService, $padFileService, $bootstrap, $etherpadClient)
			->handle(new FileCreatedFromTemplateEvent($template, $target));
	}

	public function testNeverRenamesTargetEvenWhenTemplateNameHasPlaceholder(): void {
		// NC's TemplateManager re-fetches the new file by its original path
		// *after* this event fires (TemplateManager.php:171). A rename here
		// would break that lookup with NotFoundException → 403 to the
		// client. Filename templating must come from elsewhere; this
		// listener stays read-only on the file path.
		$template = $this->file(7, 'Protokoll {{date:next monday|d.m.Y}}.pad', 'tpl');
		$target = $this->file(99, 'Untitled.pad', '');
		$target->expects($this->never())->method('move');

		$padFileService = $this->minimalPadFileService();
		$bootstrap = $this->minimalBootstrap();
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('createBinding');

		$this->buildListener($bindingService, $padFileService, $bootstrap)
			->handle(new FileCreatedFromTemplateEvent($template, $target));
	}

	public function testSkipsExternalTemplateGracefully(): void {
		$template = $this->file(7, 'External.pad', 'tpl');
		$target = $this->file(99, 'New.pad', '');
		$target->expects($this->never())->method('putContent');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->willReturn([
			'frontmatter' => ['pad_id' => 'ext.remote', 'access_mode' => 'public'],
			'body' => '',
		]);
		$padFileService->method('extractPadMetadata')->willReturn(['pad_id' => 'ext.remote']);

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('provisionPadId');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('createBinding');

		$this->buildListener($bindingService, $padFileService, $bootstrap)
			->handle(new FileCreatedFromTemplateEvent($template, $target));
	}

	private function buildListener(
		?BindingService $bindingService = null,
		?PadFileService $padFileService = null,
		?PadBootstrapService $bootstrap = null,
		?EtherpadClient $etherpadClient = null,
	): FileCreatedFromTemplateListener {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Jacob');
		$user->method('getUID')->willReturn('jaggob');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::FIXED_NOW);

		return new FileCreatedFromTemplateListener(
			$padFileService ?? $this->minimalPadFileService(),
			new PadPlaceholderResolver($time),
			$bootstrap ?? $this->minimalBootstrap(),
			$bindingService ?? $this->createMock(BindingService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$userSession,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function minimalPadFileService(): PadFileService {
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->willReturn([
			'frontmatter' => ['pad_id' => 'g.tpl$pad', 'access_mode' => 'protected'],
			'body' => '',
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => 'g.tpl$pad',
			'access_mode' => 'protected',
			'pad_url' => '',
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(false);
		$padFileService->method('getSnapshotPartsFromBody')->willReturn(['text' => '', 'html' => '']);
		$padFileService->method('buildInitialDocument')->willReturn('initial-doc');
		$padFileService->method('withExportSnapshot')->willReturn('updated-doc');
		return $padFileService;
	}

	private function minimalBootstrap(): PadBootstrapService {
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('provisionPadId')->willReturn('p-new');
		return $bootstrap;
	}

	private function file(int $id, string $name, string $content, ?Folder $parent = null): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($content);
		if ($parent !== null) {
			$file->method('getParent')->willReturn($parent);
		}
		return $file;
	}
}
