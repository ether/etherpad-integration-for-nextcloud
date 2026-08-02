<?php

declare(strict_types=1);

namespace OCP\Files\Template;

// Prefer Nextcloud's own class: it is final and serialises more than a
// hand-written copy would, so tests exercise the real contract. The fallback
// below only matters where nextcloud/ocp is not installed.
$ocpTemplate = __DIR__ . '/../../../../../../vendor/nextcloud/ocp/OCP/Files/Template/Template.php';
if (!class_exists(Template::class, false) && is_file($ocpTemplate)) {
	$ocpDir = __DIR__ . '/../../../../../../vendor/nextcloud/ocp/OCP/Files/Template';
	require_once $ocpDir . '/FieldType.php';
	require_once $ocpDir . '/Field.php';
	require_once $ocpDir . '/Fields/RichTextField.php';
	require_once $ocpTemplate;
}

if (!class_exists(Template::class, false)) {
	class Template implements \JsonSerializable {
		private bool $hasPreview = false;
		private ?string $previewUrl = null;

		public function __construct(
			private string $templateType,
			private string $templateId,
			private \OCP\Files\File $file,
		) {
		}

		public function setCustomPreviewUrl(string $previewUrl): void {
			$this->previewUrl = $previewUrl;
		}

		public function setHasPreview(bool $hasPreview): void {
			$this->hasPreview = $hasPreview;
		}

		/** @return array<string,mixed> */
		public function jsonSerialize(): array {
			return [
				'templateType' => $this->templateType,
				'templateId' => $this->templateId,
				'basename' => $this->file->getName(),
				'etag' => $this->file->getEtag(),
				'fileid' => $this->file->getId(),
				'filename' => $this->templateId,
				'lastmod' => $this->file->getMTime(),
				'mime' => $this->file->getMimetype(),
				'size' => $this->file->getSize(),
				'type' => $this->file->getType(),
				'hasPreview' => $this->hasPreview,
				'previewUrl' => $this->previewUrl,
				'fields' => [],
			];
		}
	}
}
