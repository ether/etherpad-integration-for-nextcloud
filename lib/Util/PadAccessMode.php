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
 * Reading a mode is a separate question. Several paths ask whether a value
 * is one particular kind and treat everything else as the other, so a third
 * case added here would be served as one of these two until they are
 * converted - and which one depends on the site: those asking for
 * `protected` widen it to public, the one asking for `public` narrows it to
 * protected. See #241.
 */
enum PadAccessMode: string {
	case Public = 'public';
	case Protected = 'protected';
}
