<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\FileCreatedFromTemplateListener;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The listener handles two template-flow cases:
 *  - source-template → delegate to PadCreationService::materializeTemplateInto
 *  - blank-template (no source) → write fresh frontmatter via
 *    PadBootstrapService so /open doesn't 4xx on the first call.
 */
class FileCreatedFromTemplateListenerTest extends TestCase {
	public function testIgnoresUnrelatedEvent(): void {
		$creation = $this->createMock(PadCreationService::class);
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$creation->expects($this->never())->method('materializeTemplateInto');
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->buildListener($creation, $bootstrap)->handle(new class extends Event {});
	}

	public function testIgnoresNonPadTarget(): void {
		$creation = $this->createMock(PadCreationService::class);
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$creation->expects($this->never())->method('materializeTemplateInto');
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->buildListener($creation, $bootstrap)->handle(new FileCreatedFromTemplateEvent(
			$this->file('Template.pad'),
			$this->file('Notes.txt'),
		));
	}

	public function testBlankTemplateInitialisesFrontmatterImmediately(): void {
		// + → New pad with the "Blank" option: NC creates an empty .pad
		// and dispatches the event without a source template. Without this
		// branch the viewer's first /open hits a 400 (no YAML frontmatter)
		// before the retry path runs /initialize. Initialising in the
		// listener removes that first 400.
		$target = $this->file('New.pad');

		$creation = $this->createMock(PadCreationService::class);
		$creation->expects($this->never())->method('materializeTemplateInto');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())
			->method('initializeMissingFrontmatter')
			->with('alice', $target, '');

		$this->buildListener($creation, $bootstrap)->handle(new FileCreatedFromTemplateEvent(
			null,
			$target,
		));
	}

	public function testSourceTemplateDelegatesToCreationService(): void {
		$template = $this->file('Tpl.pad');
		$target = $this->file('New.pad');
		$target->expects($this->never())->method('putContent');

		$creation = $this->createMock(PadCreationService::class);
		$creation->expects($this->once())
			->method('materializeTemplateInto')
			->with($target, $template, $this->isInstanceOf(IUser::class));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$this->buildListener($creation, $bootstrap)->handle(new FileCreatedFromTemplateEvent($template, $target));
	}

	public function testFailedSourceTemplateFallsBackToBlankInit(): void {
		// If materializeTemplateInto throws, the byte-copy is wiped *and*
		// then re-initialised so the user still ends up with an openable
		// blank pad (not the 4xx-on-first-open state that existed before
		// the fall-through init).
		$template = $this->file('Tpl.pad');
		$target = $this->file('New.pad');
		$target->expects($this->once())->method('putContent')->with('');

		$creation = $this->createMock(PadCreationService::class);
		$creation->method('materializeTemplateInto')
			->willThrowException(new \RuntimeException('boom'));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())
			->method('initializeMissingFrontmatter')
			->with('alice', $target, '');

		$this->buildListener($creation, $bootstrap)
			->handle(new FileCreatedFromTemplateEvent($template, $target));
	}

	public function testResetsTargetWhenNoUserInSession(): void {
		$template = $this->file('Tpl.pad');
		$target = $this->file('New.pad');
		$target->expects($this->once())->method('putContent')->with('');

		$creation = $this->createMock(PadCreationService::class);
		$creation->expects($this->never())->method('materializeTemplateInto');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$listener = $this->buildListener($creation, $bootstrap, withUser: false);
		$listener->handle(new FileCreatedFromTemplateEvent($template, $target));
	}

	private function buildListener(
		PadCreationService $creation,
		PadBootstrapService $bootstrap,
		bool $withUser = true,
	): FileCreatedFromTemplateListener {
		$userSession = $this->createMock(IUserSession::class);
		if ($withUser) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$user->method('getDisplayName')->willReturn('Alice');
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}
		return new FileCreatedFromTemplateListener(
			$creation,
			$bootstrap,
			$userSession,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function file(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(42);
		return $file;
	}
}
