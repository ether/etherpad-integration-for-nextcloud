<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\InvalidShareFilePathException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;

/**
 * The app's old address for a pad in a public share, handed on to
 * Nextcloud's own share page for the same token.
 *
 * Not a PublicShareController: Nextcloud checks the share of one before
 * the method runs, and answers 404 for a share with a password nobody has
 * entered in this session yet, where the share page would ask for it. The
 * redirect needs nothing from the share - its address is made of the token
 * and the file named - and the share page checks the token and the
 * password itself.
 *
 * @psalm-api
 */
class PublicShareRedirectController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private PublicShareUrlBuilder $shareUrlBuilder,
	) {
		parent::__construct($appName, $request);
	}

	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function showPad(string $token): RedirectResponse {
		return new RedirectResponse($this->targetFor($token));
	}

	/**
	 * The share page, at the file the link names. A file it cannot name -
	 * no .pad, or no path - leads to the share's root: telling the visitor
	 * the file is wrong would be wrong when the link itself is dead, and
	 * the share page says which it is.
	 */
	private function targetFor(string $token): string {
		try {
			return $this->shareUrlBuilder->buildShareRedirectUrl($token, $this->request->getParam('file', ''));
		} catch (InvalidShareFilePathException|NotAPadFileException) {
			return $this->shareUrlBuilder->buildShareBaseUrl($token);
		}
	}
}
