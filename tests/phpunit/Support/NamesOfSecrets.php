<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

/**
 * The names a document and a credential go by in this app, as a parameter
 * and as a field alike: one list for both, so that a name the one scan
 * knows is not a name the other lets pass.
 *
 * Names are all a signature or a declaration says of a string. What goes
 * by another name passes every test that reads these.
 */
final class NamesOfSecrets {
	/** What a pad says: its text or HTML, a file's content or body, its frontmatter, a template. */
	public const DOCUMENT = '/^(?:text|html|contents?|body|doc|document|frontmatter|expectedBefore)$|(?:Text|Html|Contents?|Body|Document|Doc)$/';

	/**
	 * What opens something: the API key, a secret to take out of a
	 * message, a share token or the address that carries one, a session
	 * id, a cookie. In whatever case - Etherpad spells it `sessionID` and
	 * `apikey`.
	 */
	public const CREDENTIAL = '/apikey|secret|password|token|session|cookie|^contentUrl$/i';
}
