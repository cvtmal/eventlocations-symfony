#!/bin/sh
# review-demo: the Cloudflare quick tunnels of `make review-up` and `make review-down` (docs/operations.md).
#
#   sh docker/review/tunnel.sh start <app port> <mail port>   one tunnel each, then the links and the logins
#   sh docker/review/tunnel.sh stop                           ends both
#   sh docker/review/tunnel.sh check <app port> <mail port>   before any build: cloudflared there, both ports free
#
# A quick tunnel needs no Cloudflare account; its https://<random>.trycloudflare.com link dies with the process.
# PIDs and logs live in var/review/ of this checkout, so stop from the checkout that started them. Never installs
# anything: without cloudflared it says how and stops. The Mailpit link is a convenience: without it the demo still runs.
set -eu
DIR=var/review

require_cloudflared() {
    command -v cloudflared > /dev/null 2>&1 || {
        printf '%s\n' "review: cloudflared is not installed. Install it with: brew install cloudflared" >&2
        exit 1
    }
}

# A listener on the port, read by lsof from the socket table, never connecting to or signalling its process. lsof
# sees only this user's sockets: one held by root (or no lsof at all) gets through, and Docker then names the port.
# The review stack's own edge and Mailpit hold theirs while it runs, and `make review-up` again is fine then.
taken() { # taken <port>
    lsof -nP -iTCP:"$1" -sTCP:LISTEN > /dev/null 2>&1 || return 1
    ! docker ps --filter label=com.docker.compose.project=deine-eventlocation-review --format '{{.Ports}}' 2> /dev/null |
        grep -q "127\.0\.0\.1:$1->"
}

next_free() { # next_free <port>: the first of port + 100, + 200, … that nothing listens on
    port=$(($1 + 100))
    while taken "$port"; do port=$((port + 100)); done
    printf '%s\n' "$port"
}

check() { # check <app port> <mail port>: one line naming the taken port(s) and the override, before anything is built
    app=$1 mail=$2 busy=""
    if taken "$1"; then busy="port $1 is" app=$(next_free "$1"); fi
    if taken "$2"; then
        if [ -n "$busy" ]; then busy="ports $1 and $2 are"; else busy="port $2 is"; fi
        mail=$(next_free "$2")
    fi
    if [ -n "$busy" ]; then
        printf '%s\n' "review: $busy taken by another process. Start with: make review-up REVIEW_PORT=$app REVIEW_MAIL_PORT=$mail" >&2
        exit 1
    fi
    require_cloudflared
}

stop() {
    for name in app mail; do
        [ -f "$DIR/$name.pid" ] || continue
        pid="$(cat "$DIR/$name.pid")"
        # After a reboot the number may belong to another process.
        if ps -p "$pid" -o comm= 2> /dev/null | grep -q cloudflared; then
            kill "$pid" 2> /dev/null || true
        fi
        rm -f "$DIR/$name.pid"
    done
}

start_one() { # start_one <name> <port>
    nohup cloudflared tunnel --no-autoupdate --url "http://localhost:$2" > "$DIR/$1.log" 2>&1 &
    printf '%s\n' "$!" > "$DIR/$1.pid"
}

link() { # link <name>: the tunnel's https link, once cloudflared has printed it (60 s at most)
    i=0
    while [ "$i" -lt 60 ]; do
        url="$(grep -Eo 'https://[a-z0-9-]+\.trycloudflare\.com' "$DIR/$1.log" | head -n 1 || true)"
        if [ -n "$url" ]; then
            printf '%s\n' "$url"
            return 0
        fi
        kill -0 "$(cat "$DIR/$1.pid")" 2> /dev/null || break
        sleep 1
        i=$((i + 1))
    done
    printf '%s\n' "review: the $1 tunnel gave no link, see $DIR/$1.log" >&2
    return 1
}

start() { # start <app port> <mail port>
    require_cloudflared
    mkdir -p "$DIR"
    stop
    start_one app "$1"
    start_one mail "$2"
    app="$(link app)"
    mail="$(link mail || printf '%s' "no link (http://localhost:$2 on this Mac)")"
    cat <<TEXT

Review demo is up: prod mode, demo data, no 2FA, every page noindex.
  Site          $app
  Back office   $app/admin    login admin / admin
  Konto         $app/konto    login admin / admin
  Mails         $mail    (Mailpit: every mail the demo sends, nothing leaves the Mac)
  This Mac      http://localhost:$1

Anyone with the link can log in as admin on the demo data. Keep the Mac awake: caffeinate -dimsu
A new link takes a few seconds to resolve. Stop with: make review-down
TEXT
}

case "${1:-}" in
    start) start "${2:?app port}" "${3:?mail port}" ;;
    stop) stop ;;
    check) check "${2:?app port}" "${3:?mail port}" ;;
    *)
        printf '%s\n' "usage: sh docker/review/tunnel.sh start <app port> <mail port> | stop | check <app port> <mail port>" >&2
        exit 2
        ;;
esac
