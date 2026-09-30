<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\AdminPermissionRequiredException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Service\AdminConsistencyCheckResponseBuilder;
use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\AdminSettingsValidator;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\ConsistencyCheckService;
use OCA\EtherpadNextcloud\Service\CookieDomainDecision;
use OCA\EtherpadNextcloud\Service\CookieDomainMessages;
use OCA\EtherpadNextcloud\Service\CookieDomainPolicy;
use OCA\EtherpadNextcloud\Service\EtherpadHealthCheckService;
use OCA\EtherpadNextcloud\Service\GoneFileSweep;
use OCA\EtherpadNextcloud\Service\HealthCheckItem;
use OCA\EtherpadNextcloud\Service\HealthCheckResult;
use OCA\EtherpadNextcloud\Service\PadTemplateAdminService;
use OCA\EtherpadNextcloud\Service\RunBudget;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCA\EtherpadNextcloud\Util\PositiveIntParam;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * @psalm-api
 */
class AdminController extends Controller {
	private const CONSISTENCY_SAMPLE_LIMIT = 25;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IL10N $l10n,
		private AdminSettingsValidator $settingsValidator,
		private AdminSettingsRepository $settingsRepository,
		private EtherpadHealthCheckService $healthCheckService,
		private ConsistencyCheckService $consistencyCheckService,
		private AdminConsistencyCheckResponseBuilder $consistencyResponseBuilder,
		private AdminControllerErrorMapper $errors,
		private CookieDomainPolicy $cookieDomainPolicy,
		private CookieDomainMessages $cookieDomainMessages,
		private IURLGenerator $urlGenerator,
		private PadTemplateAdminService $padTemplateAdmin,
		private GoneFileSweep $goneFileSweep,
		private ITimeFactory $timeFactory,
		private BindingService $bindingService,
		private AppConfigService $appConfigService,
	) {
		parent::__construct($appName, $request);
	}

	public function saveSettings(): DataResponse {
		return $this->errors->run(
			function (): ValidatedAdminSettings {
				$this->requireAdmin();
				$settings = $this->settingsValidator->validateForSave(
					$this->readJsonPayload(),
					$this->settingsRepository->getStoredSettings(),
				);
				$this->settingsRepository->persist($settings);
				return $settings;
			},
			fn(ValidatedAdminSettings $settings): DataResponse => new DataResponse([
				'ok' => true,
				'message' => $this->l10n->t('Settings saved.'),
				'api_version' => $settings->etherpadApiVersion,
				'has_api_key' => $this->settingsRepository->hasApiKey(),
				// Same shape the connection test answers in, so the page has one
				// way to show a verdict.
				'checks' => $this->describeChecks([$this->cookieDomainMessages->asCheckItem($this->savedCookieDecision($settings))]),
			]),
			[
				'generic' => $this->l10n->t('Failed to save settings.'),
				'log_message' => 'Saving Etherpad settings failed',
			],
		);
	}

	public function healthCheck(): DataResponse {
		return $this->errors->run(
			function (): HealthCheckResult {
				$this->requireAdmin();
				$settings = $this->settingsValidator->validateForHealthCheck(
					$this->readJsonPayload(),
					$this->settingsRepository->getStoredSettings(),
				);
				return $this->healthCheckService->check($settings);
			},
			function (HealthCheckResult $result): DataResponse {
				// One list feeds both the summary and the payload, so they
				// cannot report different outcomes.
				$checks = [...$result->checks, $this->cookieDomainMessages->asCheckItem($result->cookieDomain)];
				return new DataResponse([
					'ok' => true,
					// Not "successful": the request going through says nothing
					// about the configuration.
					'message' => $this->summariseChecks($checks),
					'host' => $result->host,
					'api_host' => $result->apiHost,
					'api_version' => $result->apiVersion,
					'latency_ms' => $result->latencyMs,
					'target' => $result->target,
					'pending_delete_count' => $result->pendingDeleteCount,
					// Machine-readable form of the protected-pads line above.
					'protected_pads' => $this->describeCookieDomain($result->cookieDomain),
					'session_cookie_release' => $result->sessionCookieRelease,
					'checks' => $this->describeChecks($checks),
				]);
			},
			[
				'generic' => $this->l10n->t('Etherpad connection test failed.'),
				'log_message' => 'Etherpad health check failed',
			],
		);
	}

	public function settlePending(): DataResponse {
		return $this->errors->run(
			function (): array {
				$this->requireAdmin();
				// What the background job does, now, and without the grace.
				$result = $this->goneFileSweep->run(new RunBudget($this->timeFactory, RunBudget::DEFAULT_SECONDS), atOnce: true);
				return $result + [
					'pending_delete_count' => $this->bindingService->countPendingDeletes(),
					'deleting' => $this->appConfigService->isDeletePadWithFileEnabled(),
				];
			},
			fn(array $result): DataResponse => new DataResponse([
				'ok' => true,
				// With deleting off the check deletes nothing, and says why
				// rather than leave the count to stand unexplained.
				'message' => $result['deleting']
					? $this->l10n->t('Pending pad check finished.')
					: $this->l10n->t('Deleting pads is switched off, so no pad was deleted. The files deleted for good wait for it to be switched on.'),
				'checked' => $result['checked'],
				'settled' => $result['deleted'],
				'pending_delete_count' => $result['pending_delete_count'],
			]),
			[
				'generic' => $this->l10n->t('Pending pad check failed.'),
				'log_message' => 'Pending pad check failed',
			],
		);
	}

	/**
	 * One vanished file's pad deleted on the admin's word
	 * (ConsistencyCheckService::markVanishedFile()). `fileId` is required:
	 * all of them is a route of its own, never what a request that sent
	 * no readable id falls to.
	 */
	public function deleteVanished(): DataResponse {
		return $this->errors->run(
			function (): array {
				$this->requireAdmin();
				$fileId = $this->requiredFileId();
				$deleting = $this->appConfigService->isDeletePadWithFileEnabled();
				// With deleting off a mark would only wait for it to be
				// switched on: nothing is marked, and the page says why.
				$marked = $deleting && $this->consistencyCheckService->markVanishedFile($fileId);
				return $this->withTheList([
					'message' => match (true) {
						!$deleting => $this->deletingOff(),
						$marked => $this->l10n->t('The pad of this vanished file is marked for deletion. It is deleted within minutes, or at once with "Check pending pads".'),
						default => $this->noLongerVanished(),
					},
					'marked' => $marked ? 1 : 0,
				]);
			},
			fn(array $result): DataResponse => new DataResponse(['ok' => true] + $result),
			[
				'generic' => $this->l10n->t('Could not delete the pads of the vanished files.'),
				'log_message' => 'Deleting the pad of a vanished file failed',
			],
		);
	}

	/**
	 * The pads of all vanished files deleted on the admin's word
	 * (ConsistencyCheckService::markVanished()), as many as `expected`
	 * says: the count the admin was shown and confirmed. A list that no
	 * longer has that many is not taken - the answer carries it as it is
	 * now, to be confirmed again.
	 */
	public function deleteAllVanished(): DataResponse {
		return $this->errors->run(
			function (): array {
				$this->requireAdmin();
				$expected = $this->requiredCount();
				$deleting = $this->appConfigService->isDeletePadWithFileEnabled();
				$marked = $deleting ? $this->consistencyCheckService->markVanished(new RunBudget($this->timeFactory, RunBudget::DEFAULT_SECONDS), $expected) : 0;
				$result = $this->withTheList(['marked' => $marked ?? 0]);
				return ['message' => match (true) {
					!$deleting => $this->deletingOff(),
					$marked === null => $this->l10n->t('The list of vanished files has changed since it was shown, so no pad was marked for deletion. Check it again.'),
					// Some of those confirmed are left - the budget ran out,
					// before the first mark even - and none left to mark is
					// said only of none.
					$result['vanished_file_count'] > 0 && $marked < $expected => $this->l10n->t('Not every vanished file could be marked in one go. Run it again for the rest.'),
					// All confirmed are marked, and the list is not empty:
					// files vanished since, which the admin was not shown.
					$result['vanished_file_count'] > 0 => $this->l10n->t('The pads of the vanished files shown are marked for deletion. More files have vanished since: check the list before deleting their pads.'),
					$marked === 0 => $this->l10n->t('No vanished file was left to mark for deletion.'),
					default => $this->l10n->t('The pads of the vanished files are marked for deletion. They are deleted within minutes, or at once with "Check pending pads".'),
				}] + $result;
			},
			fn(array $result): DataResponse => new DataResponse(['ok' => true] + $result),
			[
				// A chunk that fails leaves the marks before it standing.
				'generic' => $this->l10n->t('Could not delete the pads of all vanished files. Some may be marked for deletion already: check the list again.'),
				'log_message' => 'Deleting the pads of vanished files failed',
			],
		);
	}

	/**
	 * One vanished file's row removed, its public pad left in Etherpad, on
	 * the admin's word (ConsistencyCheckService::forgetVanished()).
	 */
	public function forgetVanished(): DataResponse {
		return $this->errors->run(
			function (): array {
				$this->requireAdmin();
				$outcome = $this->consistencyCheckService->forgetVanished($this->requiredFileId());
				return $this->withTheList([
					'message' => match ($outcome) {
						ConsistencyCheckService::FORGOTTEN => $this->l10n->t('The pad stays in Etherpad, and the app no longer looks after it.'),
						ConsistencyCheckService::PROTECTED_PAD => $this->l10n->t('Only a public pad can be forgotten. Delete a protected one instead.'),
						default => $this->noLongerVanished(),
					},
					'forgotten' => $outcome === ConsistencyCheckService::FORGOTTEN,
				]);
			},
			fn(array $result): DataResponse => new DataResponse(['ok' => true] + $result),
			[
				'generic' => $this->l10n->t('Could not forget the pad of the vanished file.'),
				'log_message' => 'Forgetting the pad of a vanished file failed',
			],
		);
	}

	/**
	 * $result with the list as it is after the action - count and samples,
	 * as the consistency check gives them - and the pads now waiting: what
	 * the page shows next, without asking again.
	 *
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>&array{vanished_file_count: int}
	 */
	private function withTheList(array $result): array {
		$list = $this->consistencyCheckService->run(self::CONSISTENCY_SAMPLE_LIMIT);
		return $result + [
			'vanished_file_count' => $list['vanished_file_count'],
			'samples' => $list['samples'],
			'pending_delete_count' => $this->bindingService->countPendingDeletes(),
		];
	}

	/** @throws \InvalidArgumentException without a positive `fileId` */
	private function requiredFileId(): int {
		return $this->requiredNumber('fileId', $this->l10n->t('Invalid file ID.'));
	}

	/** @throws \InvalidArgumentException without a positive `expected` */
	private function requiredCount(): int {
		return $this->requiredNumber('expected', $this->l10n->t('Invalid count.'));
	}

	/**
	 * The request's $name, a positive whole number (PositiveIntParam),
	 * never a cast of something else.
	 *
	 * @throws \InvalidArgumentException with $refusal when there is none
	 */
	private function requiredNumber(string $name, string $refusal): int {
		try {
			$number = PositiveIntParam::read($this->request->getParam($name));
		} catch (\InvalidArgumentException) {
			$number = null;
		}
		if ($number === null) {
			throw new \InvalidArgumentException($refusal);
		}
		return $number;
	}

	private function deletingOff(): string {
		return $this->l10n->t('Deleting pads is switched off, so no pad was marked for deletion.');
	}

	/** The answer for a file named that is no longer vanished. */
	private function noLongerVanished(): string {
		return $this->l10n->t('This file is no longer vanished; nothing was changed.');
	}

	public function consistencyCheck(): DataResponse {
		return $this->errors->run(
			function (): array {
				$this->requireAdmin();
				// With the pads that wait, as every action on the list answers:
				// the page asks this after an action that failed part-way, and
				// what it marked before shows only there.
				return $this->withTheList([]);
			},
			fn(array $result): DataResponse => new DataResponse($this->consistencyResponseBuilder->build($result) + ['pending_delete_count' => $result['pending_delete_count']]),
			[
				'generic' => $this->l10n->t('Consistency check failed.'),
				'log_message' => 'Consistency check failed',
			],
		);
	}

	/** @return array<string,mixed> */
	private function readJsonPayload(): array {
		return $this->request->getParams();
	}

	private function requireAdmin(): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new UnauthorizedRequestException('Authentication required.');
		}
		if (!$this->groupManager->isAdmin($user->getUID())) {
			throw new AdminPermissionRequiredException('Admin permissions required.');
		}
	}

	public function listPadTemplates(): DataResponse {
		return $this->errors->run(
			function (): array {
				$this->requireAdmin();
				return $this->padTemplateAdmin->list();
			},
			fn(array $templates): DataResponse => new DataResponse(['ok' => true, 'templates' => $templates]),
			['generic' => $this->l10n->t('Could not read the templates.'), 'log_message' => 'Listing pad templates failed'],
		);
	}

	public function uploadPadTemplate(): DataResponse {
		return $this->errors->run(
			function (): array {
				$this->requireAdmin();
				$payload = $this->readJsonPayload();
				return $this->padTemplateAdmin->add(
					(string)($payload['name'] ?? ''),
					(string)($payload['content'] ?? ''),
					filter_var($payload['replace'] ?? false, FILTER_VALIDATE_BOOLEAN),
				);
			},
			fn(array $template): DataResponse => new DataResponse([
				'ok' => true,
				'message' => $this->l10n->t('Template saved.'),
				'template' => $template,
			]),
			['generic' => $this->l10n->t('Could not save the template.'), 'log_message' => 'Saving a pad template failed'],
		);
	}

	public function deletePadTemplate(): DataResponse {
		return $this->errors->run(
			function (): string {
				$this->requireAdmin();
				$name = (string)($this->readJsonPayload()['name'] ?? '');
				$this->padTemplateAdmin->delete($name);
				return $name;
			},
			fn(string $name): DataResponse => new DataResponse([
				'ok' => true,
				'message' => $this->l10n->t('Template deleted.'),
				'name' => $name,
			]),
			['generic' => $this->l10n->t('Could not delete the template.'), 'log_message' => 'Deleting a pad template failed'],
		);
	}

	/** @param list<HealthCheckItem> $checks */
	private function summariseChecks(array $checks): string {
		$needAttention = count(array_filter(
			$checks,
			static fn(HealthCheckItem $item): bool => $item->status === HealthCheckItem::STATUS_WARNING,
		));
		return $needAttention === 0
			? $this->l10n->t('All checks passed.')
			: $this->l10n->t('Check finished. Some settings need attention.');
	}

	private function savedCookieDecision(ValidatedAdminSettings $settings): ?CookieDomainDecision {
		if (!$settings->enableProtectedPads) {
			return null;
		}
		return $this->cookieDomainPolicy->decide(
			$this->urlGenerator->getBaseUrl(),
			$settings->etherpadHost,
			$this->cookieDomainPolicy->storedValue($settings->etherpadCookieDomain, $settings->cookieDomainConfigured),
		);
	}

	/**
	 * @param list<HealthCheckItem> $checks
	 * @return list<array<string,string>>
	 */
	private function describeChecks(array $checks): array {
		return array_map(
			static fn(HealthCheckItem $item): array => [
				'id' => $item->id,
				'status' => $item->status,
				'label' => $item->label,
				'detail' => $item->detail,
				'field' => $item->field,
			],
			$checks,
		);
	}

	/** @return array<string,mixed>|null */
	private function describeCookieDomain(?CookieDomainDecision $decision): ?array {
		if ($decision === null) {
			return null;
		}
		return [
			'ok' => $decision->isOk(),
			'status' => $decision->status,
			'reason' => $decision->reason,
			'cookie_domain' => $decision->effectiveDomain,
			'cookie_domain_source' => $decision->source,
			'nextcloud_host' => $decision->nextcloudHost,
			'etherpad_host' => $decision->etherpadHost,
			'message' => $this->cookieDomainMessages->describe($decision),
		];
	}
}
