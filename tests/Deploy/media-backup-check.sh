#!/bin/sh
# Runs bin/cron-media-backup with the real restic against a local S3 server (F15b), through
# `make deploy-test`: backup, a second run that copies nothing, retention, the freshness check and the
# restore drill of docs/operations.md. Runs on the host, like nginx-check.sh, because it starts its own
# containers; needs Docker only. Stops at the first failed case.
#
# MinIO no longer publishes images, so `rclone serve s3` stands in for Infomaniak Object Storage: restic
# reaches it through the same s3: repository URL and AWS_* keys. The cases run inside the restic image
# (this file again, with the argument `inside`), on a throwaway releases/ + shared/ tree, as a user
# `deploy` that cannot write /var or /srv, like the cron user on the VPS. No network of its own (the
# Docker address pool is exhausted): the restic container joins the S3 container's network namespace.
set -eu

# Debian 13 installs restic 0.18.0 (docs/operations.md, setup step 2).
RESTIC_IMAGE="restic/restic:0.18.0"
S3_IMAGE="rclone/rclone:1.71.2"
S3_KEY=media-backup-check
S3_SECRET=media-backup-check-secret

ok() { printf '%s\n' "ok - $1"; }
fail() {
    printf '%s\n' "not ok - $1" >&2
    [ -z "${out:-}" ] || printf '%s\n' "$out" >&2
    exit 1
}

# —— On the host ——————————————————————————————————————————————————————————————————————————————

host() {
    root="$(cd "$(dirname "$0")/../.." && pwd)"
    name="eventlocation-media-backup-$$"
    cleanup() {
        docker rm -f "$name-s3" >/dev/null 2>&1 || true
    }
    trap cleanup EXIT
    # dash runs no EXIT trap on a signal; exiting from the signal trap runs it.
    trap 'exit 130' INT TERM
    docker run -d --name "$name-s3" "$S3_IMAGE" \
        serve s3 /data --addr 127.0.0.1:9000 --auth-key "$S3_KEY,$S3_SECRET" >/dev/null
    # The owner creates the bucket in the Manager (docs/operations.md). Without it restic keeps retrying
    # for minutes instead of failing; for rclone a bucket is a directory.
    docker exec "$name-s3" mkdir -p /data/media-backup
    docker run --rm --network "container:$name-s3" --entrypoint sh \
        -v "$root/bin/cron-media-backup:/check/cron-media-backup:ro" \
        -v "$root/tests/Deploy/media-backup-check.sh:/check/media-backup-check.sh:ro" \
        "$RESTIC_IMAGE" /check/media-backup-check.sh inside
}

# —— Inside the restic container ————————————————————————————————————————————————————————————————

SHA=0123456789abcdef0123456789abcdef01234567
DEPLOY_ROOT=/srv/deine-eventlocation.ch
APP="$DEPLOY_ROOT/current"
SHARED="$DEPLOY_ROOT/shared"
MEDIA="$SHARED/var/media"
STAMP="$SHARED/var/log/media-backup.ok"

# run <command...>: keeps going on a failure, leaves the exit code in $rc and the output in $out.
run() {
    rc=0
    out="$("$@" 2>&1)" || rc=$?
}
# must <case> <command...>: like run, but the case fails on a non-zero exit.
must() {
    label="$1"
    shift
    run "$@"
    [ "$rc" -eq 0 ] || fail "$label (exit $rc)"
}
backup() { run sh "$APP/bin/cron-media-backup" "$@"; }
expect_exit() { # expect_exit <case> <code>
    [ "$rc" -eq "$2" ] || fail "$1 (exit $rc, expected $2)"
    ok "$1"
}
expect_silent() { # expect_silent <case>
    [ -z "$out" ] || fail "$1 (printed output)"
    ok "$1"
}
expect_output() { # expect_output <case> <text>
    case "$out" in
        *"$2"*) ok "$1" ;;
        *) fail "$1 (no \"$2\" in the output)" ;;
    esac
}
# The repository's own view, through the script's passthrough.
repo() { sh "$APP/bin/cron-media-backup" restic "$@"; }

# The server as bin/deploy leaves it: absolute links from the release into shared/.
server_tree() {
    release="$DEPLOY_ROOT/releases/$SHA"
    mkdir -p "$release/bin" "$release/var" "$MEDIA" "$SHARED/var/log"
    cp /check/cron-media-backup "$release/bin/cron-media-backup"
    ln -s "$SHARED/var/media" "$release/var/media"
    ln -s "$SHARED/var/log" "$release/var/log"
    ln -s "$SHARED/.env.local" "$release/.env.local"
    ln -s "$release" "$APP"
    # Originals as the CMS writes them, plus a name with a space and an empty directory.
    mkdir -p "$MEDIA/venues/17" "$MEDIA/venues/23/documents" "$MEDIA/empty"
    head -c 300000 /dev/urandom > "$MEDIA/venues/17/0193a1b2-photo.jpg"
    head -c 120000 /dev/urandom > "$MEDIA/venues/23/0193a1b3-photo.png"
    head -c 40000 /dev/urandom > "$MEDIA/venues/23/documents/Preise 2027.pdf"
}

# write_env <password>: the MEDIA_BACKUP_* block of deploy/env.local.example, filled for the local S3.
write_env() {
    cat > "$SHARED/.env.local" <<ENV
APP_ENV=prod
MEDIA_ROOT=$MEDIA
MEDIA_BACKUP_REPOSITORY='s3:http://127.0.0.1:9000/media-backup/deine-eventlocation'
MEDIA_BACKUP_PASSWORD='$1'
export MEDIA_BACKUP_ACCESS_KEY_ID=$S3_KEY
MEDIA_BACKUP_SECRET_ACCESS_KEY="$S3_SECRET"
MEDIA_BACKUP_REGION=
ENV
}

wait_for_s3() {
    tries=50
    until nc -z 127.0.0.1 9000 2>/dev/null; do
        tries=$((tries - 1))
        [ "$tries" -gt 0 ] || fail "the S3 server never listened"
        sleep 0.2
    done
}

refusals() {
    write_env __MEDIA_BACKUP_PASSWORD__
    backup
    expect_exit "a placeholder is refused" 1
    expect_output "the refusal names the variable" "MEDIA_BACKUP_PASSWORD in .env.local still holds"
    write_env ""
    backup
    expect_exit "an empty password is refused" 1
    backup unknown
    expect_exit "an unknown mode is refused" 1
    backup status
    expect_exit "status fails before any backup" 1
    expect_output "status says why" "no backup has finished yet"
}

first_runs() {
    write_env correct-horse-battery-staple
    backup
    expect_exit "the nightly run never creates the repository" 1
    expect_output "it says that the backup failed" "cron-media-backup: the backup failed"
    [ ! -f "$STAMP" ] || fail "a failed run wrote the stamp"

    backup init
    expect_exit "init creates the repository" 0
    backup init
    [ "$rc" -ne 0 ] || fail "a second init succeeded"
    ok "a second init is refused"

    backup
    expect_exit "the first run backs up" 0
    expect_silent "a good night prints nothing"
    [ -s "$STAMP" ] || fail "the first run wrote no stamp"
    ok "the run writes its stamp into shared/var/log"
    must "the snapshots can be listed" repo list snapshots
    [ "$(printf '%s\n' "$out" | grep -c .)" -eq 1 ] || fail "one snapshot after the first run"
    must "the newest snapshot can be listed" repo ls latest
    expect_output "the snapshot holds the resolved shared path" "$MEDIA/venues/23/documents/Preise 2027.pdf"

    must "the packs can be listed" repo list packs
    packs="$(printf '%s\n' "$out" | sort)"
    must "the snapshots can be listed" repo list snapshots
    snapshots="$(printf '%s\n' "$out" | sort)"
    backup
    expect_exit "the second run succeeds" 0
    expect_silent "the second run prints nothing"
    must "the packs can be listed again" repo list packs
    [ "$(printf '%s\n' "$out" | sort)" = "$packs" ] || fail "the second run uploaded data"
    ok "the second run copies nothing"
    must "the snapshots can be listed again" repo list snapshots
    [ "$(printf '%s\n' "$out" | sort)" = "$snapshots" ] || fail "the second run added a snapshot"
    ok "the second run adds no snapshot"

    backup status
    expect_exit "status passes after a run" 0
    expect_output "status names the time" "the last backup finished at"
}

retention() {
    # A snapshot older than a year, written the way the nightly run writes it.
    run repo backup --quiet --host deine-eventlocation.ch --time "2024-01-01 04:30:00" "$MEDIA"
    expect_exit "an old snapshot can be planted" 0
    must "the snapshots can be read" repo snapshots --json
    expect_output "the planted snapshot is there" '"time":"2024-01-01T04:30:00'

    head -c 200000 /dev/urandom > "$MEDIA/venues/17/0193a1c0-new.jpg"
    backup
    expect_exit "a run after an upload succeeds" 0
    expect_silent "it prints nothing"
    must "the newest snapshot can be listed" repo ls latest
    expect_output "the new upload is in the newest snapshot" "0193a1c0-new.jpg"
    must "the snapshots can be read" repo snapshots --json
    case "$out" in
        *2024-01-01T04:30:00*) fail "the snapshot older than a year was kept" ;;
    esac
    ok "the retention forgets a snapshot older than a year"
}

stale() {
    # 27 hours ago.
    past="$(date -d "@$(($(date +%s) - 97200))" +%Y%m%d%H%M.%S)"
    touch -t "$past" "$STAMP"
    backup status
    expect_exit "status fails when the last backup is older than 26 hours" 1
    expect_output "status says so" "more than 26 hours ago"

    write_env wrong-password
    backup
    expect_exit "a wrong password fails the run" 1
    expect_output "the mail names the failure" "cron-media-backup: the backup failed"
    case "$out" in
        *wrong-password*) fail "the output shows the password" ;;
    esac
    backup status
    expect_exit "a failed run leaves the stamp old" 1
    write_env correct-horse-battery-staple
}

restore_drill() {
    cp -a "$MEDIA" /var/tmp/reference
    # The drill of docs/operations.md: the media folder of the snapshot into a scratch directory on disk.
    run repo restore "latest:$MEDIA" --target /var/tmp/media-drill
    expect_exit "the drill restores into a scratch directory" 0
    diff -r /var/tmp/reference /var/tmp/media-drill >/dev/null || fail "the drill's copy differs from the originals"
    ok "the drill's copy matches the originals"

    # The restore after losing the server: into the empty shared/var/media a first deploy leaves. As the
    # deploy user, who may not touch /srv itself (on the VPS: /var and /var/www).
    rm -rf "${MEDIA:?}"/*
    run repo restore "latest:$MEDIA" --target "$MEDIA"
    expect_exit "a restore to the server path succeeds" 0
    diff -r /var/tmp/reference "$MEDIA" >/dev/null || fail "the restored originals differ"
    ok "the restore brings back every original, the empty directory and the name with a space"

    backup
    expect_exit "the run after a restore succeeds" 0
    expect_silent "and prints nothing"
}

cases() {
    wait_for_s3
    refusals
    first_runs
    retention
    stale
    restore_drill
    printf '%s\n' "media-backup-check.sh: all cases passed"
}

# As root: the tree, then the cases as an unprivileged owner of the deploy root, like cron on the VPS.
inside() {
    adduser -D deploy
    server_tree
    chown -R deploy:deploy "$DEPLOY_ROOT"
    exec su deploy -s /bin/sh -c "sh /check/media-backup-check.sh cases"
}

case "${1:-}" in
    inside) inside ;;
    cases) cases ;;
    *) host ;;
esac
