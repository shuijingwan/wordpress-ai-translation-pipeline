#!/usr/bin/env bash

set -euo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_dir"

php -l swq-glm52-translation-tuning.php
php -l sources/mu-plugins/swq-glm52-translation-tuning.php
php -l sources/mu-plugins/swq-glm52-translation-tuning-production-2026-07-17.php
php -l patch/featured-image-media-sync-tests.php
php patch/featured-image-media-sync-tests.php

root_sha="$(sha256sum swq-glm52-translation-tuning.php | cut -d ' ' -f 1)"
source_sha="$(sha256sum sources/mu-plugins/swq-glm52-translation-tuning.php | cut -d ' ' -f 1)"

test "$root_sha" = "$source_sha"

echo "Featured-image tests passed; current MU plugin SHA-256: $root_sha"
