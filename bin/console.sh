#!/bin/sh
# Snowprint operator console. Run it inside the Snowprint container:
#
#   docker exec snowprint bin/console.sh <command>
#
# Commands:
#   site:add <domain> [timezone]          register a site to track (timezone default UTC)
#   site:list
#   goal:add <domain> <pageview|event> <path-or-event> [name]
#                                          conversion goal; paths may end in * (e.g. /thanks*)
#   key:create <name> <domain,...|all> [--write]
#                                          new API key for MCP / the server-side API;
#                                          printed once, store it safely
#   key:list
#   key:revoke <id>
#   help
#
# It calls the local server's /api/admin endpoints on 127.0.0.1 with the token
# the server writes to var/operator.token at start-up.
set -eu
cd "$(dirname "$0")/.."

BASE="${SNOWPRINT_CONSOLE_URL:-http://127.0.0.1:7669}"
TOKEN_FILE="var/operator.token"

usage() { sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; }
die() { echo "error: $*" >&2; exit 1; }

# Values go inside JSON strings: allow only characters that need no escaping.
safe() {
    case "$1" in *[!A-Za-z0-9._:/@+*\ -]*|'') die "unsupported characters in \"$1\"" ;; esac
    printf '%s' "$1"
}

call() {  # METHOD PATH [JSON]
    [ -r "$TOKEN_FILE" ] || die "$TOKEN_FILE not found: is the server running in this container?"
    if [ -n "${3:-}" ]; then
        curl -sS -X "$1" -H "X-Operator-Token: $(cat "$TOKEN_FILE")" \
            -H 'Content-Type: application/json' --data "$3" "$BASE$2"
    else
        curl -sS -X "$1" -H "X-Operator-Token: $(cat "$TOKEN_FILE")" "$BASE$2"
    fi
    echo
}

cmd="${1:-help}"
[ $# -gt 0 ] && shift
case "$cmd" in
    site:add)
        [ $# -ge 1 ] || die "usage: site:add <domain> [timezone]"
        call POST /api/admin/sites "{\"domain\":\"$(safe "$1")\",\"timezone\":\"$(safe "${2:-UTC}")\"}"
        ;;
    site:list)
        call GET /api/admin/sites
        ;;
    goal:add)
        [ $# -ge 3 ] || die "usage: goal:add <domain> <pageview|event> <path-or-event> [name]"
        name=""
        [ $# -ge 4 ] && name=",\"name\":\"$(safe "$4")\""
        call POST /api/admin/goals "{\"site\":\"$(safe "$1")\",\"kind\":\"$(safe "$2")\",\"match\":\"$(safe "$3")\"$name}"
        ;;
    key:create)
        [ $# -ge 2 ] || die "usage: key:create <name> <domain,...|all> [--write]"
        name=$(safe "$1")
        if [ "$2" = all ]; then
            sites='"all"'
        else
            sites="[$(safe "$2" | sed 's/[^,][^,]*/"&"/g')]"
        fi
        write=false
        [ "${3:-}" = --write ] && write=true
        call POST /api/admin/api-keys "{\"name\":\"$name\",\"sites\":$sites,\"write\":$write}"
        ;;
    key:list)
        call GET /api/admin/api-keys
        ;;
    key:revoke)
        [ $# -eq 1 ] || die "usage: key:revoke <id>"
        case "$1" in *[!0-9]*) die "id must be a number" ;; esac
        call DELETE "/api/admin/api-keys/$1"
        ;;
    help|-h|--help)
        usage
        ;;
    *)
        usage >&2
        exit 1
        ;;
esac
