#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# Rebuild the Files FullTextSearch index for the throwaway admin account.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$here/stack-env.sh"
require_stack_env E2E_USER
require_elasticsearch

occ fulltextsearch:index "{\"user\":\"$E2E_USER\"}" --no-readline --quiet
echo "Full-text index for $E2E_USER is up to date."
