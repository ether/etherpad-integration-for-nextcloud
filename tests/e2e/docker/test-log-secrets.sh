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
