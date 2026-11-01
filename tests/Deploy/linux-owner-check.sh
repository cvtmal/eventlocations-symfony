#!/bin/sh
# shellcheck disable=SC2016 # Single quotes on purpose: the commands are expanded where they run.
#
# Runs the job bodies of ci.yaml's `release` and `ops-lint` on a real Linux filesystem (#20, F-C1).
# Docker Desktop's file sharing hides who owns what, so a Mac never sees what a runner sees: the php
# container is root and the checkout is uid 1001. This is a throwaway docker:dind with a copy of the
# checkout owned by uid 1001 on the container's own disk. It is not a GitHub runner.
#
#   sh tests/Deploy/linux-owner-check.sh [overlay dir]
#
# An overlay is copied over the checkout first, to show that older scripts fail here. Differences from
# the jobs: `up` has no --build, because the image comes over from this lane and must not recompile;
# the steps are copied here, so a change to ci.yaml has to follow by hand; the build runs twice, because
# a re-run on the same checkout must work. Log lines start with "==". Exit 0 only if every step passed.
set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OVERLAY="${1:-}"
DIND=docker:29.9.2-dind
PROJECT="$(basename "$ROOT")"
IMAGES="$PROJECT-php postgres:18-alpine node:26-alpine httpd:2.4.68-alpine rhysd/actionlint:1.7.12"
name="hochzeitslocation-owner-check-$$"
CHECKOUT="/work/$PROJECT"

cleanup() { docker rm -f -v "$name" >/dev/null 2>&1 || true; }
trap cleanup EXIT

in_dind() { docker exec -i "$name" sh -c "$1"; }
as_runner() { docker exec -i -u 1001 -e CI=true -e HOME=/home/runner -w "$CHECKOUT" "$name" sh -ec "$1"; }

# containerd runs on its own, as on a runner. The one dockerd embeds hands dockerd's umask 0 to
# `docker exec`, and then everything root writes is world-writable, so the old scripts would pass here.
start() {
    docker run -d --privileged --name "$name" -v /var/lib/containerd --entrypoint sleep "$DIND" 86400 >/dev/null
    in_dind 'apk add --no-cache --quiet containerd make git curl bash dash shellcheck tar \
        && adduser -D -u 1001 runner && mkdir /work && chown 1001:1001 /work'
    docker exec -d "$name" sh -c 'umask 022; exec dind sh -c "containerd >/tmp/containerd.log 2>&1 &
        sleep 2; exec dockerd --containerd=/run/containerd/containerd.sock >/tmp/dockerd.log 2>&1"'
    until in_dind 'docker info' >/dev/null 2>&1; do sleep 1; done
    in_dind 'chmod 666 /var/run/docker.sock'
    # shellcheck disable=SC2086 # Several images.
    docker save $IMAGES | in_dind 'docker load' >/dev/null
}

# What actions/checkout leaves: the tracked and new files, nothing git ignores, one commit, uid 1001.
checkout() {
    in_dind "mkdir -p $CHECKOUT"
    (cd "$ROOT" && git ls-files -co --exclude-standard -z | COPYFILE_DISABLE=1 tar --null -T - -cf -) \
        | in_dind "tar -xf - -C $CHECKOUT"
    [ -z "$OVERLAY" ] || COPYFILE_DISABLE=1 tar -C "$OVERLAY" -cf - . | in_dind "tar -xf - -C $CHECKOUT"
    in_dind "chown -R 1001:1001 $CHECKOUT"
    as_runner 'git init -q -b main && git add -A && git -c user.name=ci -c user.email=ci@ci.invalid commit -qm checkout'
}

release_job() {
    as_runner 'docker compose up -d --wait php database'
    # A proof that cannot fail proves nothing: root must write what uid 1001 cannot delete.
    as_runner 'test "$(docker compose exec -T php sh -c "umask; id -u" | tr -d "\n")" = 00220'
    printf '%s\n' "== release: make release-check"
    as_runner 'make release-check'
    as_runner 'test -s var/release/release.tgz'
    printf '%s\n' "== release: the build again, on the same checkout"
    as_runner 'sh bin/build-release'
    as_runner 'test "$(stat -c %u var/release/release.tgz)" = 1001'
    as_runner 'tar -tzf var/release/release.tgz | grep -E "^\./var(/|$)" && exit 1 || true'
    printf '%s\n' "== release: passed"
}

ops_lint_job() {
    printf '%s\n' "== ops-lint: a fresh checkout"
    in_dind "docker compose -f $CHECKOUT/compose.yaml --project-directory $CHECKOUT down -v >/dev/null 2>&1 || true; rm -rf $CHECKOUT"
    checkout
    as_runner 'set -- bin/build-release bin/deploy bin/deploy-gate bin/warm-cache bin/cron-lock.sh \
        bin/cron-midnight bin/cron-cache-guard bin/cron-daily bin/prod-import bin/pull-media-backup \
        deploy/probe.sh tests/Deploy/*.sh
        shellcheck --shell=sh -x "$@"
        for script in "$@"; do dash -n "$script"; done'
    as_runner 'make workflow-lint'
    as_runner 'docker compose up -d --wait php'
    as_runner 'make deploy-test'
    printf '%s\n' "== ops-lint: passed"
}

start
checkout
release_job
ops_lint_job
printf '%s\n' "linux-owner-check: both jobs passed"
