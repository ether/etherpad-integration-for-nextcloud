#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# Two properties that only hold inside a running Nextcloud.
#
# One: the methods this app declares in lib/Util/SensitiveMethods.php are
# actually registered. The declaration is a constant nothing else proves
# reaches Nextcloud - delete the loop in Application::register() and every
# unit test still passes.
#
# Two: a failure logged while a session is in scope does not write it out.
# That is SafeError's job rather than the registration's, and this is the
# case that needs no failed api call for it: a pad session is built, the
# release probe behind it fails, and the warning it writes is raised from
# the frame holding the session id.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$here/stack-env.sh"
require_stack_env E2E_USER

marker="log-secret-probe-$$-$(date +%s)"
# TEST-NET-1, and a different address every run: the release probe claims
# itself in a distributed cache under a key derived from the host, so a
# claim left by a run in the last minute would make this one skip.
unroutable="http://192.0.2.$((RANDOM % 250 + 1)):9"

# occ from the shell, not a finally in the probe: a fatal or an OOM kill
# ends php without unwinding, and a stack left pointing at an unroutable
# api host fails every protected-pad test after this one.
# `|| true`: occ exits non-zero for a key that is not set, and an unset
# override is the ordinary case.
saved_api_host="$(occ config:app:get etherpad_nextcloud etherpad_api_host 2>/dev/null | tr -d '\r\n' || true)"
saved_cookie_mode="$(occ config:app:get etherpad_nextcloud etherpad_http_only_session_cookie 2>/dev/null | tr -d '\r\n' || true)"
restore_stack() {
	set +e
	if [[ -n "$saved_api_host" ]]; then
		occ config:app:set etherpad_nextcloud etherpad_api_host --value="$saved_api_host" >/dev/null
	else
		# Unset before, and unset again: left behind, the unroutable host
		# would stand in for the fallback to etherpad_host for good.
		occ config:app:delete etherpad_nextcloud etherpad_api_host >/dev/null
	fi
	if [[ -n "$saved_cookie_mode" ]]; then
		occ config:app:set etherpad_nextcloud etherpad_http_only_session_cookie --value="$saved_cookie_mode" >/dev/null
	else
		occ config:app:delete etherpad_nextcloud etherpad_http_only_session_cookie >/dev/null
	fi
	occ config:app:delete etherpad_nextcloud etherpad_release_state >/dev/null
	occ config:app:delete etherpad_nextcloud etherpad_release_failed >/dev/null
}
trap restore_stack EXIT

# An override short-circuits supportsHttpOnlySessionCookie() before it
# ever probes, and the protected-cookie spec sets one.
occ config:app:delete etherpad_nextcloud etherpad_http_only_session_cookie >/dev/null
occ config:app:set etherpad_nextcloud etherpad_api_host --value="$unroutable" >/dev/null

probe=$(cat <<PHP
<?php
require '/var/www/html/lib/base.php';
\OC_App::loadApp('etherpad_nextcloud');
\$service = \OCP\Server::get(\OCA\EtherpadNextcloud\Service\PadSessionService::class);
\$method = new ReflectionMethod(\$service, 'buildEtherpadSessionCookie');
\$method->setAccessible(true);
\$method->invoke(\$service, ['value' => '${marker}', 'expires' => time() + 3600]);
PHP
)

echo "==> checking that the declared methods are registered"
registration=$(cat <<'PHP'
<?php
require '/var/www/html/lib/base.php';
$coordinator = \OCP\Server::get(\OC\AppFramework\Bootstrap\Coordinator::class);
$coordinator->runInitialRegistration();
$registered = [];
foreach ($coordinator->getRegistrationContext()->getSensitiveMethods() as $registration) {
    if ($registration->getAppId() !== 'etherpad_nextcloud') {
        continue;
    }
    $registered[$registration->getName()] = $registration->getValue();
}
$missing = [];
foreach (\OCA\EtherpadNextcloud\Util\SensitiveMethods::ALL as $class => $methods) {
    foreach ($methods as $method) {
        if (!in_array($method, $registered[$class] ?? [], true)) {
            $missing[] = $class . '::' . $method;
        }
    }
}
echo $missing === [] ? 'ok' : 'missing: ' . implode(', ', $missing);
PHP
)
verdict="$(printf '%s' "$registration" | compose exec -T nextcloud php -d error_reporting=E_ERROR | tail -n1)"
if [[ "$verdict" != ok ]]; then
	echo "Declared but not registered - $verdict" >&2
	exit 1
fi

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

echo "The declarations are registered, and the session id stayed out of the log."
