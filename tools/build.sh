#!/usr/bin/env bash
# Builds build/smstunnel-oc3.ocmod.zip and build/smstunnel-oc4/smstunnel.ocmod.zip (see tools/build.php)
# with the PHP of the composer:2 Docker image, which has the zip extension.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if command -v cygpath >/dev/null 2>&1; then
  ROOT="$(cygpath -m "$ROOT")" # Git Bash on Windows: D:/... for Docker
fi
MSYS_NO_PATHCONV=1 docker run --rm -v "$ROOT:/repo" -w /repo --entrypoint php composer:2 tools/build.php
