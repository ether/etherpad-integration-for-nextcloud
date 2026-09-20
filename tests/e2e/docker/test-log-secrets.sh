#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# Does a secret in a stack frame reach nextcloud.log?
#
# Nextcloud serializes an exception by printing every frame's arguments,
# including frames far below the failure. The app declares the methods
# that carry a secret (lib/Util/SensitiveMethods.php) so the serializer
# replaces theirs - a declaration nothing else verifies, because a unit
# test cannot boot Nextcloud's logger.
#
# The case reproduced here is the one that needs no failed api call: a
# pad session is built, the release probe behind it fails, and the
# warning it writes carries the frame holding the session id.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$here/stack-env.sh"
require_stack_env E2E_USER

marker="log-secret-probe-$$-$(date +%s)"

probe=$(cat <<PHP
<?php
require '/var/www/html/lib/base.php';
\OC_App::loadApp('etherpad_nextcloud');
\$config = \OCP\Server::get(\OCP\IConfig::class);
\$restore = \$config->getAppValue('etherpad_nextcloud', 'etherpad_api_host', '');
try {
    // An unroutable api host is what makes the /health probe fail.
    \$config->setAppValue('etherpad_nextcloud', 'etherpad_api_host', 'http://127.0.0.1:9');
    \$config->deleteAppValue('etherpad_nextcloud', 'etherpad_release_state');
    \$config->deleteAppValue('etherpad_nextcloud', 'etherpad_release_failed');
    \$service = \OCP\Server::get(\OCA\EtherpadNextcloud\Service\PadSessionService::class);
    \$method = new ReflectionMethod(\$service, 'buildEtherpadSessionCookie');
    \$method->setAccessible(true);
    \$method->invoke(\$service, ['value' => '${marker}', 'expires' => time() + 3600]);
} finally {
    \$config->setAppValue('etherpad_nextcloud', 'etherpad_api_host', \$restore);
    \$config->deleteAppValue('etherpad_nextcloud', 'etherpad_release_state');
    \$config->deleteAppValue('etherpad_nextcloud', 'etherpad_release_failed');
}
PHP
)

log=/var/www/html/data/nextcloud.log
before="$(compose exec -T nextcloud sh -c "wc -l < $log" | tr -d ' \r')"

echo "==> building a session cookie while the release probe fails"
printf '%s' "$probe" | compose exec -T -u www-data nextcloud php -d error_reporting=E_ERROR

written="$(compose exec -T nextcloud sh -c "tail -n +$((before + 1)) $log")"

# Without the failure there is nothing to leak from, so the absence of the
# marker would prove nothing at all.
if ! grep -q 'Could not read the Etherpad release' <<<"$written"; then
	echo "The release probe did not fail, so this check proved nothing." >&2
	printf '%s\n' "$written" >&2
	exit 1
fi

if grep -q "$marker" <<<"$written"; then
	echo "The session id reached nextcloud.log:" >&2
	grep -o ".\{0,40\}$marker" <<<"$written" >&2
	exit 1
fi

echo "The probe failed and was logged, and the session id was not."
