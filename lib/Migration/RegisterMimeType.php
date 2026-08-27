<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Migration;

use OCP\Files\IMimeTypeDetector;
use OCP\Files\IMimeTypeLoader;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * @psalm-api
 */
class RegisterMimeType implements IRepairStep {
	private const MIME = 'application/x-etherpad-nextcloud';
	// Alias .pad onto the core "document" mimetype so pads appear in the Files
	// "Documents" type filter (which matches x-office/document, resolved through
	// the mimetype alias map). The file list keeps the pad glyph because rows
	// render the PadPreviewProvider thumbnail, not the mimetype icon; the "New"
	// menu uses its own etherpad icon. The generic document icon only surfaces
	// where no preview is rendered (e.g. file-picker dialogs / search results).
	private const MIME_ALIAS = 'x-office/document';

	public function __construct(
		private IConfig $config,
		private IMimeTypeLoader $mimeTypeLoader,
		private IMimeTypeDetector $mimeTypeDetector,
	) {
	}

	public function getName(): string {
		return 'Register MIME type for .pad files';
	}

	public function run(IOutput $output): void {
		$mimeTypeId = $this->mimeTypeLoader->getId(self::MIME);
		$this->mimeTypeLoader->updateFilecache('pad', $mimeTypeId);

		$configDir = '';
		if (isset(\OC::$configDir) && is_string(\OC::$configDir) && \OC::$configDir !== '') {
			$configDir = rtrim(\OC::$configDir, '/') . '/';
		} else {
			$dataDir = rtrim($this->config->getSystemValueString('datadirectory', ''), '/');
			if ($dataDir !== '') {
				$configDir = dirname($dataDir) . '/config/';
			}
		}
		if ($configDir === '') {
			$output->info('MIME mapping files were not updated: config directory could not be resolved.');
			return;
		}

		$mimetypeToExt = [
			self::MIME => self::MIME_ALIAS,
		];
		$extToMimetype = [
			'pad' => [self::MIME],
		];

		$this->appendToJsonFile($configDir . 'mimetypealiases.json', $mimetypeToExt);
		$this->appendToJsonFile($configDir . 'mimetypemapping.json', $extToMimetype);

		$output->info('Updated MIME mappings for .pad files and backfilled filecache extension mapping.');

		$this->regenerateMimetypeListJs($output);
	}

	/**
	 * Regenerate core/js/mimetypelist.js so the browser alias map
	 * (OC.MimeTypeList.aliases) picks up the .pad -> x-office/document alias.
	 *
	 * The Files type filter reads that generated map, and Nextcloud does NOT
	 * regenerate it on app install/enable — without this the Documents filter
	 * would only include pads after a manual `occ maintenance:mimetype:update-js`.
	 *
	 * Best-effort: this mirrors the `maintenance:mimetype:update-js` command
	 * (getAllAliases/getAllNamings are public OCP; only the file builder is a
	 * core-internal class, guarded by class_exists). On any failure we just log
	 * the manual command instead of breaking the repair step.
	 */
	private function regenerateMimetypeListJs(IOutput $output): void {
		$manualHint = 'Run `occ maintenance:mimetype:update-js` so .pad files appear under the Documents filter.';

		$builderClass = 'OC\\Core\\Command\\Maintenance\\Mimetype\\GenerateMimetypeFileBuilder';
		$serverRoot = (isset(\OC::$SERVERROOT) && is_string(\OC::$SERVERROOT)) ? rtrim(\OC::$SERVERROOT, '/') : '';
		if ($serverRoot === '' || !class_exists($builderClass)) {
			$output->info($manualHint);
			return;
		}

		$target = $serverRoot . '/core/js/mimetypelist.js';
		$writable = is_file($target) ? is_writable($target) : is_writable(dirname($target));
		if (!$writable) {
			$output->info('mimetypelist.js is not writable. ' . $manualHint);
			return;
		}

		try {
			// getAllAliases() may be cached without the alias we just wrote, so
			// merge it in explicitly to guarantee it lands in the generated map.
			$aliases = $this->mimeTypeDetector->getAllAliases();
			$aliases[self::MIME] = self::MIME_ALIAS;

			// getAllNamings() only exists on newer Nextcloud (not in the NC 31
			// OCP); the file builder accepts the extra arg and ignores it where
			// unsupported, so default to an empty map on older servers.
			$namingsGetter = 'getAllNamings';
			$namings = method_exists($this->mimeTypeDetector, $namingsGetter)
				? $this->mimeTypeDetector->$namingsGetter()
				: [];

			$builder = new $builderClass();
			$contents = $builder->generateFile($aliases, $namings);
			if (!is_string($contents) || @file_put_contents($target, $contents) === false) {
				$output->info('Could not regenerate mimetypelist.js. ' . $manualHint);
				return;
			}
		} catch (\Throwable $e) {
			$output->info('Could not regenerate mimetypelist.js (' . $e->getMessage() . '). ' . $manualHint);
			return;
		}

		$output->info('Regenerated core/js/mimetypelist.js for the .pad -> Documents alias.');
	}

	private function appendToJsonFile(string $file, array $mappings): void {
		$current = [];
		if (is_file($file)) {
			$decoded = json_decode((string)file_get_contents($file), true);
			if (is_array($decoded)) {
				$current = $decoded;
			}
		}

		$updated = self::mergeMimeMappings($current, $mappings);
		file_put_contents($file, json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	}

	/**
	 * Merge our `.pad` mapping into the existing mimetype config without
	 * clobbering unrelated entries, and idempotently (re-running yields the
	 * same result). Extracted as a pure function so the contract is testable
	 * without the surrounding filesystem / \OC config-dir resolution.
	 *
	 * @param array<string,mixed> $current
	 * @param array<string,mixed> $mappings
	 * @return array<string,mixed>
	 */
	public static function mergeMimeMappings(array $current, array $mappings): array {
		return array_replace_recursive($current, $mappings);
	}
}
