<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Template;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadTemplateStorage;
use OCA\EtherpadNextcloud\Service\PadTypePolicy;
use OCP\Files\File;
use OCP\Files\Template\FieldType;
use OCP\Files\Template\Fields\RichTextField;
use OCP\Files\Template\ICustomTemplateProvider;
use OCP\Files\Template\Template;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * The app's tiles in Nextcloud's template picker, and the templates an admin
 * shares with everyone — see docs/templates.md.
 *
 * There is no tile for protected pads: the blank entry already creates one, so
 * the public tile only appears while both types are enabled.
 *
 * @psalm-api
 */
class PadTemplateProvider implements ICustomTemplateProvider {
	/**
	 * Nextcloud collects the templates of every provider into one map keyed by
	 * this id alone, so a generic value would let another app's tile and ours
	 * displace each other depending on registration order. The app id makes
	 * them ours.
	 */
	public const PUBLIC_TEMPLATE_ID = Application::APP_ID . ':pad-public';
	public const EXTERNAL_TEMPLATE_ID = Application::APP_ID . ':pad-external';
	/** The field Nextcloud's picker asks for, read back by the create listener. */
	public const FIELD_PAD_URL = 'pad_url';
	private const GLOBAL_ID_PREFIX = Application::APP_ID . ':global:';

	public function __construct(
		private PadTemplateStorage $storage,
		private PadTypePolicy $padTypePolicy,
		private IConfig $config,
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	public function getCustomTemplates(string $mimetype): array {
		if ($mimetype !== 'application/x-etherpad-nextcloud') {
			return [];
		}

		return array_merge($this->typeTile(), $this->externalTile(), $this->globalTiles());
	}

	/** @return list<Template> */
	private function typeTile(): array {
		$bothEnabled = $this->padTypePolicy->isEnabled(BindingService::ACCESS_PROTECTED)
			&& $this->padTypePolicy->isEnabled(BindingService::ACCESS_PUBLIC);
		if (!$bothEnabled) {
			return [];
		}

		return $this->markerTile(self::PUBLIC_TEMPLATE_ID, fn(): File => $this->storage->publicMarker());
	}

	/**
	 * The tile for a pad on another Etherpad server. Not a pad type, so it
	 * follows `allow_external_pads` rather than the pad type settings.
	 *
	 * @return list<Template>
	 */
	private function externalTile(): array {
		if ((string)$this->config->getAppValue(Application::APP_ID, 'allow_external_pads', 'no') !== 'yes') {
			return [];
		}

		return $this->markerTile(
			self::EXTERNAL_TEMPLATE_ID,
			fn(): File => $this->storage->externalMarker(),
			self::padAddressFields($this->l10n),
		);
	}

	/**
	 * Shared with BeforeGetTemplatesListener, which has to set this again after
	 * other apps have overwritten it — see the listener for why.
	 *
	 * @return list<\OCP\Files\Template\Field>
	 */
	public static function padAddressFields(IL10N $l10n): array {
		$field = new RichTextField(self::FIELD_PAD_URL, FieldType::RichText);
		// The example belongs in the label, not in the value: the picker
		// submits the value as typed, so a prefilled address would be sent for
		// real by anyone who just confirms the dialog.
		$field->alias = $l10n->t('Address of the pad on the other server, for example https://pad.example.org/p/notes');
		$field->setValue('');
		return [$field];
	}

	/**
	 * A missing tile is better than a broken picker, so a marker that cannot be
	 * resolved is logged and skipped: the blank entry still works.
	 *
	 * @param callable(): File $marker
	 * @param list<\OCP\Files\Template\Field> $fields
	 * @return list<Template>
	 */
	private function markerTile(string $templateId, callable $marker, array $fields = []): array {
		try {
			$template = new Template(self::class, $templateId, $marker());
			$template->setCustomPreviewUrl($this->iconUrl());
			if ($fields !== []) {
				$template->setFields($fields);
			}
			return [$template];
		} catch (\Throwable $e) {
			$this->logger->warning('Could not offer a pad template tile.', [
				'app' => 'etherpad_nextcloud',
				'templateId' => $templateId,
				'exception' => $e,
			]);
			return [];
		}
	}

	/**
	 * Templates an admin uploaded. They carry their own content and access
	 * mode, so nothing here decides either.
	 *
	 * @return list<Template>
	 */
	private function globalTiles(): array {
		// Offering them would be a promise the instance cannot keep: creating
		// from one provisions a local pad, which needs a pad type. An instance
		// that allows only external pads still shows the "New pad" entry — the
		// external tile needs it.
		if (!$this->padTypePolicy->hasAnyEnabledType()) {
			return [];
		}

		try {
			$files = $this->storage->globalTemplates();
		} catch (\Throwable $e) {
			// The picker must still open. The admin page reads the same list
			// without this guard, so the failure stays visible where it can be
			// acted on.
			$this->logger->warning('Could not list the shared pad templates.', [
				'app' => 'etherpad_nextcloud',
				'exception' => $e,
			]);
			return [];
		}

		$tiles = [];
		foreach ($files as $file) {
			$template = new Template(self::class, self::GLOBAL_ID_PREFIX . $file->getName(), $file);
			$template->setCustomPreviewUrl($this->iconUrl());
			$tiles[] = $template;
		}
		return $tiles;
	}

	/**
	 * Nextcloud points a tile at /core/preview, which has nothing to render for
	 * a .pad — the picker would fall back to its generic document icon.
	 */
	private function iconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'etherpad-icon-color.svg')
		);
	}

	public function getCustomTemplate(string $template): File {
		if ($template === self::PUBLIC_TEMPLATE_ID) {
			return $this->storage->publicMarker();
		}

		if ($template === self::EXTERNAL_TEMPLATE_ID) {
			return $this->storage->externalMarker();
		}

		if (str_starts_with($template, self::GLOBAL_ID_PREFIX)) {
			$file = $this->storage->globalTemplate(substr($template, strlen(self::GLOBAL_ID_PREFIX)));
			if ($file !== null) {
				return $file;
			}
		}

		throw new \RuntimeException('Unknown pad template: ' . $template);
	}
}
