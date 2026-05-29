#!/usr/bin/env sh

set -eu

memory_limit="${PHP_MEMORY_LIMIT:-512M}"

exec php -d "memory_limit=${memory_limit}" "$@"
