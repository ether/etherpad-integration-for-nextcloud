<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;

class PadFileService {
	public const FORMAT_V1 = 'etherpad-nextcloud/1';
	private const TEXT_SECTION = '[TEXT]';
	private const HTML_BEGIN_SECTION = '[HTML-BEGIN]';
	private const HTML_END_SECTION = '[HTML-END]';

	/** @return array{frontmatter: array<string,mixed>, body: string} */
	public function parsePadFile(string $content): array {
		$normalized = str_replace("\r\n", "\n", $content);
		if (!preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $normalized, $matches)) {
			throw new MissingFrontmatterException('Missing YAML frontmatter in .pad file.');
		}

		$frontmatter = $this->parseFrontmatterBlock($matches[1]);
		$this->validateFrontmatter($frontmatter);

		return [
			'frontmatter' => $frontmatter,
			'body' => $matches[2],
		];
	}

	/** @param array<string,mixed> $frontmatter */
	public function serialize(array $frontmatter, string $body): string {
		$this->validateFrontmatter($frontmatter);

		$lines = [
			'format: ' . $this->stringScalar((string)$frontmatter['format']),
			'file_id: ' . (int)$frontmatter['file_id'],
			'pad_id: ' . $this->stringScalar((string)$frontmatter['pad_id']),
			'access_mode: ' . $this->stringScalar((string)$frontmatter['access_mode']),
			'state: ' . $this->stringScalar((string)$frontmatter['state']),
			'deleted_at: ' . $this->nullableScalar($frontmatter['deleted_at'] ?? null),
			'created_at: ' . $this->stringScalar((string)$frontmatter['created_at']),
			'updated_at: ' . $this->stringScalar((string)$frontmatter['updated_at']),
			'snapshot_rev: ' . (int)$frontmatter['snapshot_rev'],
		];
		if (isset($frontmatter['pad_url']) && is_string($frontmatter['pad_url']) && $frontmatter['pad_url'] !== '') {
			$lines[] = 'pad_url: ' . $this->stringScalar($frontmatter['pad_url']);
		}
		if (isset($frontmatter['pad_origin']) && is_string($frontmatter['pad_origin']) && $frontmatter['pad_origin'] !== '') {
			$lines[] = 'pad_origin: ' . $this->stringScalar($frontmatter['pad_origin']);
		}
		if (isset($frontmatter['remote_pad_id']) && is_string($frontmatter['remote_pad_id']) && $frontmatter['remote_pad_id'] !== '') {
			$lines[] = 'remote_pad_id: ' . $this->stringScalar($frontmatter['remote_pad_id']);
		}

		return "---\n" . implode("\n", $lines) . "\n---\n" . $body;
	}

	public function buildInitialDocument(
		int $fileId,
		string $padId,
		string $accessMode,
		string $snapshot = '',
		?string $padUrl = null,
		array $extraFrontmatter = []
	): string {
		$now = gmdate('c');
		$frontmatter = [
			'format' => self::FORMAT_V1,
			'file_id' => $fileId,
			'pad_id' => $padId,
			'access_mode' => $accessMode,
			'state' => BindingService::STATE_ACTIVE,
			'deleted_at' => null,
			'created_at' => $now,
			'updated_at' => $now,
			'snapshot_rev' => -1,
		];
		if ($padUrl !== null && $padUrl !== '') {
			$frontmatter['pad_url'] = $padUrl;
		}
		foreach ($extraFrontmatter as $key => $value) {
			if (is_string($key) && is_scalar($value)) {
				$frontmatter[$key] = (string)$value;
			}
		}
		return $this->serialize($frontmatter, $snapshot);
	}

	/** @return array{url: string, pad_id: string}|null */
	public function parseLegacyOwnpadShortcut(string $content): ?array {
		$normalized = str_replace("\r\n", "\n", $content);
		if (!preg_match('/^\s*\[InternetShortcut\]\s*$/mi', $normalized)) {
			return null;
		}
		if (!preg_match('/^\s*URL\s*=\s*(.+)\s*$/mi', $normalized, $matches)) {
			return null;
		}

		$url = trim((string)$matches[1]);
		if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
			return null;
		}

		$path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
		// rawurldecode (not urldecode): `+` is a literal in URL path
		// segments, only `application/x-www-form-urlencoded` (query strings,
		// form bodies) treats `+` as a space. A pad URL like
		// `/p/team+meeting` must yield pad-id `team+meeting`, not
		// `team meeting` — otherwise we re-emit `/p/team%20meeting` and the
		// binding points at a different / non-existent pad.
		$decodedPath = rawurldecode($path);
		if (preg_match('~/p/([^/?#]+)$~', $decodedPath, $padMatches) !== 1) {
			return null;
		}
		$padId = trim((string)$padMatches[1]);
		if ($padId === '') {
			return null;
		}

		return [
			'url' => $url,
			'pad_id' => $padId,
		];
	}

	public function inferAccessModeFromPadId(string $padId): string {
		if (preg_match('/^g\.[^$]+\$.+$/', $padId) === 1) {
			return BindingService::ACCESS_PROTECTED;
		}
		return BindingService::ACCESS_PUBLIC;
	}

	/**
	 * @param array<string,mixed> $frontmatter
	 * @return array{pad_id:string,access_mode:string,pad_url:string}
	 */
	public function extractPadMetadata(array $frontmatter): array {
		return [
			'pad_id' => isset($frontmatter['pad_id']) ? (string)$frontmatter['pad_id'] : '',
			'access_mode' => isset($frontmatter['access_mode']) ? (string)$frontmatter['access_mode'] : '',
			'pad_url' => isset($frontmatter['pad_url']) ? trim((string)$frontmatter['pad_url']) : '',
		];
	}

	/** @param array<string,mixed> $frontmatter */
	public function isExternalFrontmatter(array $frontmatter, string $padId): bool {
		$remotePadId = isset($frontmatter['remote_pad_id']) ? trim((string)$frontmatter['remote_pad_id']) : '';
		$padOrigin = isset($frontmatter['pad_origin']) ? trim((string)$frontmatter['pad_origin']) : '';
		return str_starts_with($padId, 'ext.') && $remotePadId !== '' && $padOrigin !== '';
	}

	public function withStateAndSnapshot(
		string $content,
		string $state,
		string $snapshot,
		?string $padId = null,
		?int $deletedAtTs = null,
		?string $padUrl = null
	): string {
		$parsed = $this->parsePadFile($content);
		$frontmatter = $parsed['frontmatter'];
		$bodyParts = $this->splitSnapshotBody((string)$parsed['body']);
		$frontmatter['state'] = $state;
		$frontmatter['updated_at'] = gmdate('c');
		$frontmatter['deleted_at'] = $deletedAtTs === null ? null : gmdate('c', $deletedAtTs);
		if ($padId !== null) {
			$frontmatter['pad_id'] = $padId;
		}
		if ($padUrl !== null) {
			$frontmatter['pad_url'] = $padUrl;
		}

		return $this->serialize($frontmatter, $this->buildSnapshotBody($snapshot, $bodyParts['html']));
	}

	public function withExportSnapshot(string $content, string $text, string $html, int $exportedRev, bool $includeHtmlSection = true): string {
		$parsed = $this->parsePadFile($content);
		$frontmatter = $parsed['frontmatter'];
		$frontmatter['updated_at'] = gmdate('c');
		$frontmatter['snapshot_rev'] = max(0, $exportedRev);

		return $this->serialize($frontmatter, $this->buildSnapshotBody($text, $html, $includeHtmlSection));
	}

	public function getSnapshotRevision(string $content): int {
		$parsed = $this->parsePadFile($content);
		return $this->getSnapshotRevisionFromFrontmatter($parsed['frontmatter']);
	}

	/** @param array<string,mixed> $frontmatter */
	public function getSnapshotRevisionFromFrontmatter(array $frontmatter): int {
		$rev = $frontmatter['snapshot_rev'] ?? null;
		if (!is_numeric($rev)) {
			return -1;
		}
		return (int)$rev;
	}

	public function getTextSnapshotForRestore(string $content): string {
		$parsed = $this->parsePadFile($content);
		return $this->getSnapshotPartsFromBody((string)$parsed['body'])['text'];
	}

	public function getHtmlSnapshotForRestore(string $content): string {
		$parsed = $this->parsePadFile($content);
		return $this->getSnapshotPartsFromBody((string)$parsed['body'])['html'];
	}

	/** @return array{text:string,html:string} */
	public function getSnapshotPartsFromBody(string $body): array {
		return $this->splitSnapshotBody($body);
	}

	/** @return array<string,mixed> */
	private function parseFrontmatterBlock(string $yaml): array {
		$lines = explode("\n", $yaml);
		$data = [];
		$currentMap = null;

		foreach ($lines as $lineNumber => $line) {
			if (trim($line) === '' || str_starts_with(trim($line), '#')) {
				continue;
			}

			if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*):\s*(.*)$/', $line, $matches) === 1) {
				$key = $matches[1];
				$raw = $matches[2];
				if ($raw === '') {
					$data[$key] = [];
					$currentMap = $key;
					continue;
				}
				$data[$key] = $this->parseScalar($raw);
				$currentMap = null;
				continue;
			}

			if (preg_match('/^\s{2}([a-zA-Z_][a-zA-Z0-9_]*):\s*(.*)$/', $line, $matches) === 1) {
				if ($currentMap === null || !is_array($data[$currentMap])) {
					throw new PadFileFormatException('Invalid nested YAML structure at line ' . ($lineNumber + 1) . '.');
				}
				$data[$currentMap][$matches[1]] = $this->parseScalar($matches[2]);
				continue;
			}

			throw new PadFileFormatException('Invalid YAML frontmatter line ' . ($lineNumber + 1) . '.');
		}

		return $data;
	}

	/** @param array<string,mixed> $frontmatter */
	private function validateFrontmatter(array $frontmatter): void {
		$required = ['format', 'file_id', 'pad_id', 'access_mode', 'state', 'created_at', 'updated_at', 'snapshot_rev'];
		foreach ($required as $key) {
			if (!array_key_exists($key, $frontmatter)) {
				throw new PadFileFormatException('Missing required frontmatter key: ' . $key);
			}
		}

		$format = $this->requireStringScalar($frontmatter, 'format');
		$fileId = $frontmatter['file_id'];
		$padId = $this->requireStringScalar($frontmatter, 'pad_id');
		$accessMode = $this->requireStringScalar($frontmatter, 'access_mode');
		$state = $this->requireStringScalar($frontmatter, 'state');
		$createdAt = $this->requireStringScalar($frontmatter, 'created_at');
		$updatedAt = $this->requireStringScalar($frontmatter, 'updated_at');

		if ($format !== self::FORMAT_V1) {
			throw new PadFileFormatException('Unsupported .pad format: ' . $format);
		}
		if (!is_numeric($fileId) || (int)$fileId <= 0) {
			throw new PadFileFormatException('Invalid file_id in frontmatter.');
		}
		if ($padId === '') {
			throw new PadFileFormatException('Invalid pad_id in frontmatter.');
		}
		if ($createdAt === '' || $updatedAt === '') {
			throw new PadFileFormatException('Invalid created_at/updated_at in frontmatter.');
		}

		if (!in_array($accessMode, [BindingService::ACCESS_PUBLIC, BindingService::ACCESS_PROTECTED], true)) {
			throw new PadFileFormatException('Invalid access_mode in frontmatter.');
		}

		// Keep parsing legacy files that were moved to trash before the binding
		// lifecycle was simplified. New writes use active only.
		if (!in_array($state, [BindingService::STATE_ACTIVE, 'trashed', 'purged'], true)) {
			throw new PadFileFormatException('Invalid state in frontmatter.');
		}

		if (!is_numeric($frontmatter['snapshot_rev'])) {
			throw new PadFileFormatException('Invalid snapshot_rev in frontmatter.');
		}
		if ((int)$frontmatter['snapshot_rev'] < -1) {
			throw new PadFileFormatException('Invalid snapshot_rev in frontmatter.');
		}
		if (isset($frontmatter['pad_url'])) {
			$padUrl = $this->requireOptionalStringScalar($frontmatter, 'pad_url');
			if ($padUrl !== '' && preg_match('#^https?://#i', $padUrl) !== 1) {
				throw new PadFileFormatException('Invalid pad_url in frontmatter.');
			}
		}
		if (isset($frontmatter['pad_origin'])) {
			$padOrigin = $this->requireOptionalStringScalar($frontmatter, 'pad_origin');
			if ($padOrigin !== '' && preg_match('#^https?://#i', $padOrigin) !== 1) {
				throw new PadFileFormatException('Invalid pad_origin in frontmatter.');
			}
		}
		if (isset($frontmatter['remote_pad_id']) && $this->requireOptionalStringScalar($frontmatter, 'remote_pad_id') === '') {
			throw new PadFileFormatException('Invalid remote_pad_id in frontmatter.');
		}
	}

	private function parseScalar(string $raw): mixed {
		$trimmed = trim($raw);
		if ($trimmed === 'null') {
			return null;
		}
		if ($trimmed === '') {
			return '';
		}
		if (is_numeric($trimmed) && preg_match('/^-?\d+$/', $trimmed) === 1) {
			return (int)$trimmed;
		}
		if (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"')) {
			$inner = substr($trimmed, 1, -1);
			return preg_replace('/\\\\(["\\\\])/', '$1', $inner);
		}
		if (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'")) {
			$inner = substr($trimmed, 1, -1);
			return str_replace("''", "'", $inner);
		}
		return $trimmed;
	}

	private function stringScalar(string $value): string {
		$escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
		return '"' . $escaped . '"';
	}

	private function nullableScalar(mixed $value): string {
		if ($value === null || $value === '') {
			return 'null';
		}
		return $this->stringScalar((string)$value);
	}

	/** @param array<string,mixed> $frontmatter */
	private function requireStringScalar(array $frontmatter, string $key): string {
		$value = $frontmatter[$key] ?? null;
		if (!is_scalar($value) && $value !== null) {
			throw new PadFileFormatException('Invalid ' . $key . ' in frontmatter.');
		}
		$stringValue = (string)$value;
		if ($stringValue === '') {
			throw new PadFileFormatException('Invalid ' . $key . ' in frontmatter.');
		}
		return $stringValue;
	}

	/** @param array<string,mixed> $frontmatter */
	private function requireOptionalStringScalar(array $frontmatter, string $key): string {
		$value = $frontmatter[$key] ?? '';
		if ($value === null) {
			return '';
		}
		if (!is_scalar($value)) {
			throw new PadFileFormatException('Invalid ' . $key . ' in frontmatter.');
		}
		return (string)$value;
	}

	private function buildSnapshotBody(string $text, string $html, bool $includeHtmlSection = true): string {
		if (!$includeHtmlSection) {
			return self::TEXT_SECTION . "\n" . $text;
		}

		return self::TEXT_SECTION . "\n"
			. $text . "\n"
			. self::HTML_BEGIN_SECTION . "\n"
			. $html . "\n"
			. self::HTML_END_SECTION;
	}

	/** @return array{text: string, html: string} */
	private function splitSnapshotBody(string $body): array {
		$textHeader = self::TEXT_SECTION . "\n";
		if (str_starts_with($body, $textHeader)) {
			$withoutHeader = substr($body, strlen($textHeader));
			$htmlStart = "\n" . self::HTML_BEGIN_SECTION . "\n";
			if (str_contains($withoutHeader, $htmlStart)) {
				$parts = explode($htmlStart, $withoutHeader, 2);
				$text = (string)$parts[0];
				$htmlPart = (string)$parts[1];
				$htmlEnd = "\n" . self::HTML_END_SECTION;
				if (str_ends_with($htmlPart, $htmlEnd)) {
					$html = substr($htmlPart, 0, -strlen($htmlEnd));
					return [
						'text' => $text,
						'html' => $html,
					];
				}
			}

			// Support text-only snapshots without HTML section markers.
			return [
				'text' => $withoutHeader,
				'html' => '',
			];
		}

		return [
			'text' => $body,
			'html' => '',
		];
	}
}
