<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\BindingMismatchException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Tests\Support\BuildsBoundPads;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BoundPadResolverTest extends TestCase {
	use BuildsBoundPads;

	/**
	 * The file's own row decides, and the file has to name its pad, in its
	 * access mode, or the pad it replaced; the row has to be active.
	 * Another file's row is no row for this one.
	 */
	public function testTheFilesRowDecidesWhichPadItReaches(): void {
		$cases = [
			'the row\'s pad' => [self::row(10, 'pad-a'), 'pad-a', BindingService::ACCESS_PUBLIC, 'pad-a', null],
			'the pad it replaced' => [self::row(10, 'pad-b', replaced: 'pad-a'), 'pad-a', BindingService::ACCESS_PUBLIC, 'pad-b', null],
			// The row's mode comes along: the file named the pad in the mode it had.
			'the pad it replaced, in another mode' => [self::row(10, 'pad-b', mode: BindingService::ACCESS_PROTECTED, replaced: 'pad-a'), 'pad-a', BindingService::ACCESS_PUBLIC, 'pad-b', null],
			'another pad' => [self::row(10, 'pad-a'), 'pad-b', BindingService::ACCESS_PUBLIC, null, [BindingMismatchException::class, 'Binding pad ID mismatch.']],
			// A pad the row replaced is the one exception, not a pass for any other.
			'neither pad' => [self::row(10, 'pad-b', replaced: 'pad-a'), 'pad-c', BindingService::ACCESS_PUBLIC, null, [BindingMismatchException::class, 'Binding pad ID mismatch.']],
			'another mode' => [self::row(10, 'pad-a', mode: BindingService::ACCESS_PROTECTED), 'pad-a', BindingService::ACCESS_PUBLIC, null, [BindingMismatchException::class, 'Binding access mode mismatch.']],
			// Waiting is its own kind, so the one who opens the file is told it is on its way back.
			'a deletion owed' => [self::row(10, 'pad-a', BindingService::STATE_PENDING_DELETE), 'pad-a', BindingService::ACCESS_PUBLIC, null, [WaitingBindingException::class, 'Pad binding is not active.']],
			'a restore undecided' => [self::row(10, 'pad-a', BindingService::STATE_RESTORE_PENDING), 'pad-a', BindingService::ACCESS_PUBLIC, null, [WaitingBindingException::class, 'Pad binding is not active.']],
			'the pad it replaced, while it waits' => [self::row(10, 'pad-b', BindingService::STATE_RESTORE_PENDING, replaced: 'pad-a'), 'pad-a', BindingService::ACCESS_PUBLIC, null, [WaitingBindingException::class, 'Pad binding is not active.']],
			// A file that names neither pad is not the row's to wait for.
			'another pad, while it waits' => [self::row(10, 'pad-a', BindingService::STATE_RESTORE_PENDING), 'pad-b', BindingService::ACCESS_PUBLIC, null, [BindingMismatchException::class, 'Binding pad ID mismatch.']],
			// No sweep takes up a state it does not know, so nothing waits for one either.
			'a state the app does not know' => [self::row(10, 'pad-a', 'trashed'), 'pad-a', BindingService::ACCESS_PUBLIC, null, [BindingException::class, 'Pad binding is not active.']],
			'another file\'s row' => [self::row(11, 'pad-a'), 'pad-a', BindingService::ACCESS_PUBLIC, null, [MissingBindingException::class, 'No binding exists for this file.']],
		];
		foreach ($cases as $case => [$row, $padId, $accessMode, $reaches, $refusal]) {
			// A waiting row of another file sits alongside: only file 10's decides.
			$resolver = $this->boundPads(new BindingService(new InMemoryBindingTable([self::row(9, 'pad-9', BindingService::STATE_PENDING_DELETE), $row]), new FixedClock()));
			try {
				$bound = $resolver->resolve(10, self::pad($padId, $accessMode));
				$this->assertNull($refusal, $case . ': reached ' . $bound->padId);
				$this->assertSame($reaches, $bound->padId, $case);
			} catch (BindingException $e) {
				$this->assertSame($refusal, [$e::class, $e->getMessage()], $case);
			}
		}
	}

	/**
	 * A file that names the pad its row replaced is read as naming the
	 * row's: its id, mode and link, and no revision of its own, since the
	 * one it has is the replaced pad's. The rest of the file stays, and
	 * nothing is written; that is the caller's to decide. A file that names
	 * the row's pad comes back as it is.
	 */
	public function testAFileNamingThePadItsRowReplacedIsReadAsNamingTheRows(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'A .pad file names the pad its row replaced; the row\'s pad is used.',
			['app' => 'etherpad_nextcloud', 'fileId' => 10],
		);
		$logger->expects($this->never())->method('warning');
		$bindings = new BindingService(new InMemoryBindingTable([self::row(10, 'g.new', mode: BindingService::ACCESS_PROTECTED, replaced: 'g.old')]), new FixedClock());
		$pad = self::pad('g.old', BindingService::ACCESS_PUBLIC, rev: 42, body: 'Text of the old pad');

		$bound = $this->boundPads($bindings, $logger, new PadFileService(new FixedClock(2000000000)))->resolve(10, $pad);

		$this->assertSame(
			['g.new', BindingService::ACCESS_PROTECTED, self::PAD_BASE . 'g.new', -1, false, 'Text of the old pad'],
			[$bound->padId, $bound->accessMode, $bound->padUrl, $bound->snapshotRev, $bound->isExternal, $bound->body],
		);
		$this->assertSame([
			'file_id' => 10,
			'pad_id' => 'g.new',
			'access_mode' => BindingService::ACCESS_PROTECTED,
			'snapshot_rev' => -1,
			'updated_at' => '2033-05-18T03:33:20+00:00',
			'pad_url' => self::PAD_BASE . 'g.new',
		], array_intersect_key($bound->frontmatter, array_flip(['file_id', 'pad_id', 'access_mode', 'pad_url', 'snapshot_rev', 'updated_at'])));
		$this->assertSame(PadFileService::FORMAT_V1, $bound->frontmatter['format']);

		$own = self::pad('g.new', BindingService::ACCESS_PROTECTED);
		$this->assertSame($own, $this->boundPads($bindings)->resolve(10, $own));
	}

	/**
	 * For a caller that holds the row, whatever its state: only a file that
	 * names the pad the row replaced is read as naming the row's. Neither
	 * the row's own pad nor any other is touched; whether those may pass is
	 * the caller's rule.
	 */
	public function testFollowingRowTakesOnlyThePadTheRowReplaced(): void {
		$resolver = $this->boundPads($this->createMock(BindingService::class));
		$cases = [
			'the pad it replaced' => [self::binding('pad-b', 'pad-a'), 'pad-a', 'pad-b'],
			'the pad it replaced, while it waits' => [self::binding('pad-b', 'pad-a', BindingService::STATE_PENDING_DELETE), 'pad-a', 'pad-b'],
			'the row\'s pad' => [self::binding('pad-b', 'pad-a'), 'pad-b', null],
			'another pad' => [self::binding('pad-b', 'pad-a'), 'pad-c', null],
			'a row that replaced none' => [self::binding('pad-b', null), 'pad-a', null],
			// A row that names the pad it replaced has nothing to follow.
			'a row that replaced its own pad' => [self::binding('pad-a', 'pad-a'), 'pad-a', null],
		];
		foreach ($cases as $case => [$binding, $padId, $follows]) {
			$pad = self::pad($padId, BindingService::ACCESS_PUBLIC, rev: 7);
			$bound = $resolver->followingRow($pad, $binding);
			if ($follows === null) {
				$this->assertSame($pad, $bound, $case);
			} else {
				$this->assertSame([$follows, -1], [$bound->padId, $bound->snapshotRev], $case);
			}
		}
	}

	/** A file written to name its row's pad is said once, as a warning. */
	public function testARepairIsWarnedOf(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'A .pad file named the pad its row replaced; it now names the row\'s.',
			['app' => 'etherpad_nextcloud', 'fileId' => 10],
		);

		$this->boundPads($this->createMock(BindingService::class), $logger)->repaired(10);
	}

	/** @return array<string,mixed> */
	private static function row(int $fileId, string $padId, string $state = BindingService::STATE_ACTIVE, string $mode = BindingService::ACCESS_PUBLIC, ?string $replaced = null): array {
		return [
			'file_id' => $fileId,
			'pad_id' => $padId,
			'access_mode' => $mode,
			'state' => $state,
			'deleted_at' => null,
			'updated_at' => 100,
			'replaced_pad_id' => $replaced,
		];
	}

	private static function binding(string $padId, ?string $replaced, string $state = BindingService::STATE_ACTIVE): Binding {
		return new Binding(10, $padId, BindingService::ACCESS_PUBLIC, $state, replacedPadId: $replaced);
	}

	private static function pad(string $padId, string $accessMode, int $rev = -1, string $body = ''): ParsedPadFile {
		return new ParsedPadFile(
			frontmatter: ['format' => PadFileService::FORMAT_V1, 'file_id' => 10, 'pad_id' => $padId, 'access_mode' => $accessMode, 'snapshot_rev' => $rev, 'updated_at' => '2026-01-01T00:00:00+00:00'],
			body: $body,
			padId: $padId,
			accessMode: $accessMode,
			padUrl: '',
			isExternal: false,
			snapshotRev: $rev,
		);
	}
}
