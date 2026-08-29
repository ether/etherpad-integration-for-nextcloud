<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Util;

use InvalidArgumentException;

class PathNormalizer {
	/**
	 * A request parameter arrives already decoded — PHP does that before the
	 * controller sees it. Decoding again is not a safety net, it changes
	 * names: urldecode() turns `+` into a space, so `C++.pad` becomes
	 * `C  .pad` and then, once the trailing spaces are trimmed, `C.pad` —
	 * a different file, opened or created without a word. A literal `%2B`
	 * in a file name would be decoded a second time for the same reason.
	 *
	 * Only a full DAV URL carries percent-encoding of its own, and only its
	 * path component is decoded, with rawurldecode() so `+` stays `+`.
	 */
	public function normalizeViewerFilePath(mixed $fileParam): string {
		if (!is_string($fileParam)) {
			throw new InvalidArgumentException('Invalid file parameter.');
		}

		// Trimmed only to decide whether a file was named at all, never to
		// change which one. That is the line Nextcloud's own
		// FilenameValidator draws: it trims to answer "empty?" and "." /
		// "..", and judges every other rule on the name as given. Trimming
		// the value itself would turn " Notes.pad" into "Notes.pad" and
		// "/Notes " — which becomes "/Notes .pad" below — into "/Notes.pad",
		// both names Nextcloud accepts, both a different file.
		if (trim($fileParam) === '') {
			return '';
		}

		$path = $fileParam;
		// A URL is not a name. Padding around one is transport noise, so the
		// scheme test looks past it and the URL is trimmed before it is
		// decoded — otherwise dropping the outer trim would leave a padded
		// DAV URL to be read as a relative path.
		if (preg_match('#^\s*https?://#i', $path) === 1) {
			$path = $this->normalizeDavUrlToPath(trim($path));
		}

		$path = str_replace('\\', '/', $path);
		// No rewriting beyond separators. Collapsing " .pad" to ".pad" turned
		// "Notes .pad" — a name Nextcloud accepts — into a different file,
		// the same silent substitution as the plus sign. What a name may be
		// is the storage's decision, asked at creation.
		if ($path === '' || $path[0] !== '/') {
			$path = '/' . $path;
		}

		$normalized = $this->normalizeSegments(ltrim($path, '/'));
		if ($normalized === '') {
			throw new InvalidArgumentException('Invalid file path.');
		}

		return '/' . $normalized;
	}

	/**
	 * Normalize a viewer-style absolute path and ensure it ends in `.pad`.
	 * Used by the `create`-style endpoints where the caller-supplied path
	 * may omit the file extension.
	 */
	public function normalizeCreatePath(string $file): string {
		$path = $this->normalizeViewerFilePath($file);
		if ($path === '') {
			throw new InvalidArgumentException('Invalid file path.');
		}
		if (!str_ends_with(strtolower($path), '.pad')) {
			$path .= '.pad';
		}
		return $path;
	}

	/**
	 * Normalize a single filename (no slashes) and ensure it ends in `.pad`.
	 * Used by `createByParent` where the caller passes a bare filename and
	 * the folder context comes from a separate parent-id.
	 */
	public function normalizeCreateFileName(string $name): string {
		// Same rule as above: the trimmed value answers whether a name was
		// given, the untrimmed one is the name.
		$trimmed = trim($name);
		if ($trimmed === '' || $trimmed === '.' || $trimmed === '..') {
			throw new InvalidArgumentException('Invalid file name.');
		}
		$fileName = $name;
		if (str_contains($fileName, '/') || str_contains($fileName, '\\')) {
			throw new InvalidArgumentException('Invalid file name.');
		}
		if (!str_ends_with(strtolower($fileName), '.pad')) {
			$fileName .= '.pad';
		}
		return $fileName;
	}

	public function normalizePublicShareFilePath(mixed $fileParam, string $shareToken): string {
		if (!is_string($fileParam)) {
			throw new InvalidArgumentException('Invalid file parameter.');
		}

		if (trim($fileParam) === '') {
			return '';
		}

		$path = $fileParam;
		// Same as above: the scheme test sees through padding, the name does not.
		if (preg_match('#^\s*https?://#i', $path) === 1) {
			$rawPath = (string)(parse_url(trim($path), PHP_URL_PATH) ?? '');
			if (preg_match('#/public\.php/dav/files/([^/]+)/(.*)$#', rawurldecode($rawPath), $matches) === 1) {
				if ($matches[1] !== $shareToken) {
					throw new InvalidArgumentException('Share token mismatch in public file path.');
				}
				$path = $matches[2];
			} elseif (preg_match('#/remote\.php/dav/files/[^/]+/(.*)$#', rawurldecode($rawPath), $matches) === 1) {
				$path = $matches[1];
			} else {
				$path = ltrim(rawurldecode($rawPath), '/');
			}
		}

		$path = $this->normalizeSegments($path);
		return $path;
	}

	private function normalizeDavUrlToPath(string $url): string {
		$rawPath = (string)(parse_url($url, PHP_URL_PATH) ?? '');
		$decodedPath = rawurldecode($rawPath);

		if (preg_match('#/remote\.php/dav/files/[^/]+/(.+)$#', $decodedPath, $matches) === 1) {
			return '/' . ltrim($matches[1], '/');
		}
		if (preg_match('#/public\.php/dav/files/[^/]+/(.+)$#', $decodedPath, $matches) === 1) {
			return '/' . ltrim($matches[1], '/');
		}

		return $decodedPath;
	}

	private function normalizeSegments(string $path): string {
		$path = str_replace('\\', '/', $path);
		$segments = explode('/', ltrim($path, '/'));
		$safe = [];
		foreach ($segments as $segment) {
			// A bare "." is path notation for "here" and collapses away. A
			// padded one is not notation, it is a name — and Nextcloud
			// rejects it, because FilenameValidator trims before comparing
			// against "." and "..". Refused rather than rewritten, so the
			// create and open halves of that rule agree.
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				throw new InvalidArgumentException('Path traversal is not allowed.');
			}
			$trimmedSegment = trim($segment);
			if ($trimmedSegment === '.' || $trimmedSegment === '..') {
				throw new InvalidArgumentException('Invalid path segment.');
			}
			$safe[] = $segment;
		}
		return implode('/', $safe);
	}
}
