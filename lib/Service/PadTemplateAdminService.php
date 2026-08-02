<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\TemplateExistsException;
use OCP\Files\IFilenameValidator;
use OCP\Files\InvalidPathException;
use OCP\IL10N;

/**
 * Managing the templates an admin offers to everyone.
 *
 * A template is an ordinary `.pad` file, so the checks here are the ones that
 * would otherwise only fail later, when someone picks the tile: a file that
 * is not a pad, or one whose frontmatter carries no usable pad id, would give
 * the user an error instead of a document. Rejecting it at upload time puts
 * the message where it can be acted on.
 *
 * @psalm-api
 */
class PadTemplateAdminService {
	/** Generous, but enough to stop an accidental upload of something huge. */
	private const MAX_TEMPLATE_BYTES = 2 * 1024 * 1024;
	/** Characters, not bytes: the name is what an admin reads in the picker. */
	private const MAX_NAME_CHARS = 200;

	public function __construct(
		private PadTemplateStorage $storage,
		private PadFileService $padFileService,
		private IFilenameValidator $filenameValidator,
		private IL10N $l10n,
	) {
	}

	/** @return list<array{name:string,size:int,modified:int}> */
	public function list(): array {
		$templates = [];
		foreach ($this->storage->globalTemplates() as $file) {
			$templates[] = [
				'name' => $file->getName(),
				'size' => (int)$file->getSize(),
				'modified' => $file->getMTime(),
			];
		}
		return $templates;
	}

	/**
	 * @throws AdminValidationException
	 * @return array{name:string,size:int,modified:int}
	 */
	public function add(string $name, string $content, bool $replace = false): array {
		$name = $this->validateName($name);

		if (strlen($content) > self::MAX_TEMPLATE_BYTES) {
			throw new AdminValidationException('template', $this->l10n->t('Template file is too large.'));
		}
		if (trim($content) === '') {
			throw new AdminValidationException('template', $this->l10n->t('Template file is empty.'));
		}

		try {
			$pad = $this->padFileService->readPad($content);
		} catch (PadFileFormatException) {
			// A damaged or missing frontmatter is the admin picking the wrong
			// file, not a server fault — say so instead of answering 500.
			throw new AdminValidationException('template', $this->l10n->t('Not a pad file: the frontmatter could not be read.'));
		}
		if ($pad->isExternal || str_starts_with($pad->padId, 'ext.')) {
			throw new AdminValidationException('template', $this->l10n->t('A pad on another Etherpad server cannot be used as a template.'));
		}

		// Replacing is a normal admin action, but not a silent one: there is no
		// versioning or trash behind this folder, so a mistaken file would
		// destroy the previous template without a trace. The caller has to say
		// it meant to — and the storage decides that while it writes, so two
		// uploads racing each other cannot both find the name free.
		try {
			$file = $this->storage->addGlobalTemplate($name, $content, $replace);
		} catch (TemplateExistsException) {
			throw new AdminValidationException('template_exists', $this->l10n->t('A template of that name already exists.'));
		}
		return [
			'name' => $file->getName(),
			'size' => (int)$file->getSize(),
			'modified' => $file->getMTime(),
		];
	}

	/** @throws AdminValidationException */
	public function delete(string $name): void {
		if (!$this->storage->deleteGlobalTemplate($this->validateName($name))) {
			throw new AdminValidationException('template', $this->l10n->t('No such template.'));
		}
	}

	/**
	 * The name becomes a file name in a folder we own, so it may not carry a
	 * path of its own.
	 *
	 * @throws AdminValidationException
	 */
	private function validateName(string $name): string {
		$trimmed = trim($name);
		if ($trimmed === '') {
			throw new AdminValidationException('template', $this->l10n->t('Template name is required.'));
		}
		if (mb_strlen($trimmed) > self::MAX_NAME_CHARS) {
			throw new AdminValidationException('template', $this->l10n->t('Template name is too long.'));
		}
		if (!str_ends_with(strtolower($trimmed), '.pad')) {
			throw new AdminValidationException('template', $this->l10n->t('A template must be a .pad file.'));
		}
		if (str_contains($trimmed, '/') || str_contains($trimmed, '\\') || str_starts_with($trimmed, '.')) {
			throw new AdminValidationException('template', $this->l10n->t('Template name must not contain a path.'));
		}
		// Everything an instance forbids beyond that — control characters, the
		// configured forbidden characters and names — is Nextcloud's own rule
		// set. Without asking it here, the write fails deep in the storage and
		// the admin gets a 500 instead of a sentence they can act on.
		try {
			$this->filenameValidator->validateFilename($trimmed);
		} catch (InvalidPathException $e) {
			$message = trim($e->getMessage());
			throw new AdminValidationException('template', $message !== ''
				? $message
				: $this->l10n->t('That template name is not allowed on this server.'));
		}
		return $trimmed;
	}
}
