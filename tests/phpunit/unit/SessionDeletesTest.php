<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\RunBudget;
use OCA\EtherpadNextcloud\Service\SessionDeletes;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** The delete loop the background sweeps and the request's revokes share. */
class SessionDeletesTest extends TestCase {
	/** Live until the clock-skew allowance has passed too: Etherpad may still honour one ours calls expired. */
	public function testLiveIsWhatIsNotPastTheClockSkewAllowance(): void {
		$sessions = [
			's.live' => ['groupID' => 'g.A', 'validUntil' => FixedClock::NOW + 60],
			's.skewed' => ['groupID' => 'g.A', 'validUntil' => FixedClock::NOW - 60],
			's.onTheDot' => ['groupID' => 'g.A', 'validUntil' => FixedClock::NOW - EtherpadClient::CLOCK_SKEW_ALLOWANCE_SECONDS],
			's.expired' => ['groupID' => 'g.A', 'validUntil' => FixedClock::NOW - 301],
		];

		self::assertSame(['s.live', 's.skewed'], array_keys(SessionDeletes::live($sessions, FixedClock::NOW)));
	}

	public function testDeletesInOrderUpToItsMaximum(): void {
		$client = $this->createMock(EtherpadClient::class);
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(static function (string $id) use (&$removed): void {
			$removed[] = $id;
		});

		$run = $this->deletes($client)->within($this->budget(), $this->sessions(...['s.3', 's.1', 's.2']), 2, [], 'refused');

		self::assertSame(['s.3', 's.1'], $removed);
		self::assertSame(['deleted' => 2, 'handled' => 2, 'attempted' => 2, 'refused' => false, 'stopped' => false], $run);
	}

	/** An all-digit id comes out of PHP's keys as an int, and goes to Etherpad as the id it is. */
	public function testAnAllDigitIdIsDeletedAsAString(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::once())->method('deleteSession')->with('12345');

		$run = $this->deletes($client)->within($this->budget(), $this->sessions(...array_keys(['12345' => true])), 10, [], 'refused');

		self::assertSame(1, $run['deleted']);
	}

	/** One Etherpad has lost already is gone, as asked: handled, not deleted, not refused. */
	public function testASessionAlreadyGoneIsHandled(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('deleteSession')->willThrowException(new EtherpadRefusedException('Etherpad API error (deleteSession): sessionID does not exist'));

		$run = $this->deletes($client)->within($this->budget(), $this->sessions(...['s.gone']), 10, [], 'refused');

		self::assertSame(['deleted' => 0, 'handled' => 1, 'attempted' => 1, 'refused' => false, 'stopped' => false], $run);
	}

	/**
	 * Sessions Etherpad refuses to delete, more than a run puts up with
	 * when Etherpad does not answer, stand in front of no other while it
	 * answers: asked the question it always can, it says it is there.
	 */
	public function testRefusalsWhileEtherpadAnswersDoNotEndTheRun(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::exactly(6))->method('assertAnswering');
		$removed = [];
		$client->method('deleteSession')->willReturnCallback(static function (string $id) use (&$removed): void {
			if (str_starts_with($id, 's.poison')) {
				throw new EtherpadClientException('Etherpad API request failed: deleteSession');
			}
			$removed[] = $id;
		});
		$ids = [...array_map(static fn (int $i): string => 's.poison' . $i, range(1, 6)), 's.one', 's.two'];

		$run = $this->deletes($client)->within($this->budget(), $this->sessions(...$ids), 250, [], 'refused');

		self::assertSame(['s.one', 's.two'], $removed);
		self::assertSame(['deleted' => 2, 'handled' => 2, 'attempted' => 8, 'refused' => true, 'stopped' => false], $run);
	}

	/** A refusal in so many words is an answer: Etherpad is not asked whether it answers. */
	public function testARefusalInSoManyWordsIsAnAnswer(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::never())->method('assertAnswering');
		$client->method('deleteSession')->willThrowException(new EtherpadRefusedException('Etherpad API error (deleteSession): no'));

		$run = $this->deletes($client)->within($this->budget(), $this->sessions(...['s.1', 's.2', 's.3', 's.4', 's.5', 's.6']), 250, [], 'refused');

		self::assertSame(['deleted' => 0, 'handled' => 0, 'attempted' => 6, 'refused' => true, 'stopped' => false], $run);
	}

	/** Failures Etherpad gives no answer to are an outage: a few end the run. */
	public function testFailuresWithoutAnAnswerEndTheRun(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('assertAnswering')->willThrowException(new EtherpadClientException('Connection refused'));
		$client->expects(self::exactly(5))->method('deleteSession')->willThrowException(new EtherpadClientException('Connection refused'));

		$run = $this->deletes($client)->within($this->budget(), $this->sessions(...array_map(static fn (int $i): string => 's.' . $i, range(1, 20))), 250, [], 'refused');

		self::assertTrue($run['refused']);
		self::assertSame(0, $run['handled']);
	}

	/**
	 * In a request someone waits on, a failure without an answer counts at
	 * once: no call asks whether Etherpad answers, and a few end the run.
	 */
	public function testARequestCountsAnOutageWithoutAskingEtherpad(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::never())->method('assertAnswering');
		$client->expects(self::exactly(5))->method('deleteSession')->willThrowException(new EtherpadClientException('Connection refused'));

		$run = $this->deletes($client)->within(RunBudget::forRequest(new FixedClock(), 2.0), $this->sessions(...array_map(static fn (int $i): string => 's.' . $i, range(1, 20))), 25, [], 'refused');

		self::assertSame(5, $run['attempted']);
	}

	/**
	 * The ceiling counts attempts: deletes, refusals and sessions already
	 * gone alike, so mixed answers make no more calls than it allows.
	 */
	public function testTheCeilingCountsEveryAttempt(): void {
		$calls = 0;
		$client = $this->createMock(EtherpadClient::class);
		$client->method('assertAnswering');
		$client->method('deleteSession')->willReturnCallback(static function () use (&$calls): void {
			$calls++;
			match ($calls % 3) {
				0 => throw new EtherpadRefusedException('Etherpad API error (deleteSession): internal error'),
				1 => throw new EtherpadRefusedException('Etherpad API error (deleteSession): sessionID does not exist'),
				default => null,
			};
		});

		$run = $this->deletes($client)->within($this->budget(), $this->sessions(...array_map(static fn (int $i): string => 's.' . $i, range(1, 40))), 25, [], 'refused');

		self::assertSame(25, $calls);
		self::assertSame(['deleted' => 8, 'handled' => 17, 'attempted' => 25, 'refused' => true, 'stopped' => false], $run);
	}

	/** A session already gone counts as handled: refusals in a row start again after it. */
	public function testASessionAlreadyGoneEndsARowOfRefusals(): void {
		$calls = 0;
		$client = $this->createMock(EtherpadClient::class);
		$client->method('assertAnswering');
		$client->method('deleteSession')->willReturnCallback(static function () use (&$calls): void {
			$calls++;
			if ($calls === 20) {
				throw new EtherpadRefusedException('Etherpad API error (deleteSession): sessionID does not exist');
			}
			if ($calls !== 40) {
				throw new EtherpadRefusedException('Etherpad API error (deleteSession): internal error');
			}
		});

		$run = $this->deletes($client)->within($this->budget(), $this->sessions(...array_map(static fn (int $i): string => 's.' . $i, range(1, 40))), 250, [], 'refused');

		self::assertSame(40, $calls);
		self::assertSame(['deleted' => 1, 'handled' => 2, 'attempted' => 40, 'refused' => true, 'stopped' => false], $run);
	}

	/**
	 * A failure now and then between deletes is no outage, in a request
	 * either: the deletes show that Etherpad answers.
	 */
	public function testAFailureNowAndThenDoesNotEndARequest(): void {
		$calls = 0;
		$client = $this->createMock(EtherpadClient::class);
		$client->method('deleteSession')->willReturnCallback(static function () use (&$calls): void {
			$calls++;
			if ($calls % 4 === 0) {
				throw new EtherpadClientException('Etherpad API HTTP error (502)');
			}
		});

		$run = $this->deletes($client)->within(RunBudget::forRequest(new FixedClock(), 2.0), $this->sessions(...array_map(static fn (int $i): string => 's.' . $i, range(1, 25))), 25, [], 'refused');

		self::assertSame(['deleted' => 19, 'handled' => 19, 'attempted' => 25, 'refused' => true, 'stopped' => false], $run);
	}

	/** A budget spent before the first delete starts none. */
	public function testStartsNothingWithASpentBudget(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::never())->method('deleteSession');
		$budget = RunBudget::forRequest(new FixedClock(), 2.0);
		for ($i = 0; $i < 5; $i++) {
			$budget->noteUnanswered();
		}

		$run = $this->deletes($client)->within($budget, $this->sessions('s.1'), 25, [], 'refused');

		self::assertSame(0, $run['attempted']);
	}

	/** A refusal's line names the session's own group, wherever the sessions come from. */
	public function testSaysTheGroupOfTheSessionRefused(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('assertAnswering');
		$client->method('deleteSession')->willThrowException(new EtherpadRefusedException('Etherpad API error (deleteSession): internal error'));
		$lines = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$lines): void {
			$lines[] = $context;
		});

		(new SessionDeletes($client, new ManagedPadLifecycle($client, $logger), $logger))->within($this->budget(), [
			's.one' => ['groupID' => 'g.ONE', 'validUntil' => FixedClock::NOW + 60],
			's.two' => ['groupID' => 'g.TWO', 'validUntil' => FixedClock::NOW + 60],
		], 10, ['uid' => 'alice'], 'Could not revoke.');

		self::assertSame([['alice', 'g.ONE'], ['alice', 'g.TWO']], array_map(static fn (array $context): array => [$context['uid'], $context['groupId']], $lines));
	}

	/** With no time left to ask whether it answers, it is not asked: the budget ends the run. */
	public function testAsksNothingWithoutTimeLeft(): void {
		$clock = new FixedClock();
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::never())->method('assertAnswering');
		$client->expects(self::once())->method('deleteSession')->willReturnCallback(static function () use ($clock): void {
			$clock->advance(19);
			throw new EtherpadClientException('Etherpad API request failed: deleteSession');
		});

		$run = $this->deletes($client)->within(new RunBudget($clock, RunBudget::DEFAULT_SECONDS), $this->sessions(...['s.1', 's.2']), 250, [], 'refused');

		self::assertSame(['deleted' => 0, 'handled' => 0, 'attempted' => 1, 'refused' => true, 'stopped' => false], $run);
	}

	/** The question before a delete may take the time: then no delete starts. */
	public function testStartsNoDeleteAfterTheQuestionTookTheTime(): void {
		$clock = new FixedClock();
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::never())->method('deleteSession');

		$run = $this->deletes($client)->within(new RunBudget($clock, RunBudget::DEFAULT_SECONDS), $this->sessions(...['s.1']), 250, [], 'refused', static function () use ($clock): bool {
			$clock->advance(19);
			return true;
		});

		self::assertSame(['deleted' => 0, 'handled' => 0, 'attempted' => 0, 'refused' => false, 'stopped' => false], $run);
	}

	/**
	 * Twenty refusals in a row end the run, though Etherpad answers: one
	 * that refuses every delete is not asked a run's worth of times, nor
	 * logged as many.
	 */
	public function testTwentyRefusalsInARowEndTheRun(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::exactly(20))->method('deleteSession')->willThrowException(new EtherpadRefusedException('Etherpad API error (deleteSession): internal error'));

		$run = $this->deletes($client)->within($this->budget(), $this->sessions(...array_map(static fn (int $i): string => 's.' . $i, range(1, 30))), 250, [], 'refused');

		self::assertSame(['deleted' => 0, 'handled' => 0, 'attempted' => 20, 'refused' => true, 'stopped' => false], $run);
	}

	/**
	 * In a row: a session deleted between refusals starts the count
	 * again.
	 */
	public function testRefusalsCountOnlyInARow(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::exactly(40))->method('deleteSession')->willReturnCallback(static function (string $id): void {
			if (str_ends_with($id, '.gone')) {
				throw new EtherpadRefusedException('Etherpad API error (deleteSession): sessionID does not exist');
			}
			if (!str_ends_with($id, '.ok')) {
				throw new EtherpadRefusedException('Etherpad API error (deleteSession): internal error');
			}
		});
		$ids = [];
		foreach (['ok', 'gone'] as $round => $end) {
			foreach (range(1, 19) as $i) {
				$ids[] = 's.' . $round . '.' . $i;
			}
			$ids[] = 's.' . $round . '.' . $end;
		}

		$run = $this->deletes($client)->within($this->budget(), $this->sessions(...$ids), 250, [], 'refused');

		self::assertSame(['deleted' => 1, 'handled' => 2, 'attempted' => 40, 'refused' => true, 'stopped' => false], $run);
	}

	/**
	 * Fifty refusals in all end the run too: scattered among deletes, those
	 * in a row start again after each, and would otherwise multiply a run's
	 * calls and lines.
	 */
	public function testFiftyRefusalsInAllEndTheRun(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::exactly(52))->method('deleteSession')->willReturnCallback(static function (string $id): void {
			if (!str_ends_with($id, '.ok')) {
				throw new EtherpadRefusedException('Etherpad API error (deleteSession): internal error');
			}
		});
		$ids = [];
		foreach (range(1, 10) as $round) {
			foreach (range(1, 19) as $i) {
				$ids[] = 's.' . $round . '.' . $i;
			}
			$ids[] = 's.' . $round . '.ok';
		}
		$warnings = 0;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function () use (&$warnings): void {
			$warnings++;
		});

		$run = (new SessionDeletes($client, new ManagedPadLifecycle($client, $logger), $logger))->within($this->budget(), $this->sessions(...$ids), 250, [], 'refused');

		self::assertSame(['deleted' => 2, 'handled' => 2, 'attempted' => 52, 'refused' => true, 'stopped' => false], $run);
		self::assertSame(50, $warnings);
	}

	/** Asked before each delete; a no ends the run there. */
	public function testStopsWhenItIsNoLongerWanted(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects(self::once())->method('deleteSession')->with('s.1');
		$asked = 0;

		$run = $this->deletes($client)->within($this->budget(), $this->sessions(...['s.1', 's.2', 's.3']), 250, [], 'refused', static function () use (&$asked): bool {
			return ++$asked === 1;
		});

		self::assertSame(['deleted' => 1, 'handled' => 1, 'attempted' => 1, 'refused' => false, 'stopped' => true], $run);
	}

	public function testSaysWhoseSessionWasRefusedByItsDigest(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('deleteSession')->willThrowException(new EtherpadRefusedException('Etherpad API error (deleteSession): s.secret123 refused'));
		$lines = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$lines): void {
			$lines[] = [$message, $context];
		});

		(new SessionDeletes($client, new ManagedPadLifecycle($client, $logger), $logger))->within($this->budget(), $this->sessions(...['s.secret123']), 10, ['groupId' => 'g.A'], 'Could not revoke.');

		self::assertSame('Could not revoke.', $lines[0][0]);
		self::assertSame('g.A', $lines[0][1]['groupId']);
		self::assertSame(substr(hash('sha256', 's.secret123'), 0, 12), $lines[0][1]['sessionRef']);
		self::assertStringNotContainsString('s.secret123', (string)json_encode($lines[0][1]));
	}

	/**
	 * Live sessions of one group under $ids, in their order.
	 *
	 * @return array<array-key,array{groupID:string,validUntil:int}>
	 */
	private function sessions(string|int ...$ids): array {
		$sessions = [];
		foreach ($ids as $id) {
			$sessions[$id] = ['groupID' => 'g.A', 'validUntil' => FixedClock::NOW + 3600];
		}
		return $sessions;
	}

	private function budget(): RunBudget {
		return new RunBudget(new FixedClock(), RunBudget::DEFAULT_SECONDS);
	}

	private function deletes(EtherpadClient $client): SessionDeletes {
		$logger = $this->createMock(LoggerInterface::class);
		return new SessionDeletes($client, new ManagedPadLifecycle($client, $logger), $logger);
	}
}
