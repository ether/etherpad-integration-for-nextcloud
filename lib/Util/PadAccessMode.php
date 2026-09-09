<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * The kinds of pad this app makes.
 *
 * A pad is either its own pad, reachable by anyone holding its id, or a pad
 * inside an Etherpad group that a session grants access to. What a value may
 * be is decided here, so the checks that let one in no longer each carry
 * their own copy of the answer.
 *
 * Reading a mode is a separate question. Several paths ask "is this the
 * protected kind?" and treat everything else as the other one, so a third
 * case added here would be served as a public pad until those are converted
 * too - see #241.
 */
enum PadAccessMode: string {
	case Public = 'public';
	case Protected = 'protected';
}
