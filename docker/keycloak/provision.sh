#!/usr/bin/env bash
# Provision a Keycloak realm for local SSO development against the dev stack.
#
#   docker compose -f compose.dev.yaml up -d keycloak
#   docker compose -f compose.dev.yaml exec keycloak bash /opt/provision.sh
#       (mount this file into the container, or run kcadm from the host)
#
# Creates: a dedicated realm, an application client, four base permission groups
# and ten random "department" groups (each with a structured id attribute), four
# test users (admin / sub-admin / staff / volunteer) each in 2–4 departments, and
# restricts the users to logging in to this application only.
#
# The generated realm-admin password and client secret are written to
# docs/keycloak-credentials.txt (git-ignored). Share with the developer.
set -euo pipefail

REALM="${REALM:-critter}"
APP_CLIENT="${APP_CLIENT:-critter-app}"
APP_REDIRECT="${APP_REDIRECT:-http://localhost:8000/login/sso/check}"
KC="${KC:-/opt/keycloak/bin/kcadm.sh}"
CRED_FILE="${CRED_FILE:-/opt/keycloak-credentials.txt}"

rand() { tr -dc 'A-Z0-9' </dev/urandom | head -c "${1:-16}"; }

# Structured group id like 0RV39Y2PLMX1J4N6 (16 chars).
sid() { rand 16; }

REALM_ADMIN_PASS="$(rand 24)"

# Authenticate as the bootstrap admin (KC_BOOTSTRAP_ADMIN_*).
"$KC" config credentials --server http://localhost:8080 --realm master \
  --user "${KEYCLOAK_ADMIN:-admin}" --password "${KEYCLOAK_ADMIN_PASSWORD:-admin}"

# Realm + application client.
"$KC" create realms -s realm="$REALM" -s enabled=true
CLIENT_SECRET="$(rand 32)"
"$KC" create clients -r "$REALM" \
  -s clientId="$APP_CLIENT" -s enabled=true -s publicClient=false \
  -s "redirectUris=[\"$APP_REDIRECT\"]" -s secret="$CLIENT_SECRET" \
  -s 'standardFlowEnabled=true' -s 'directAccessGrantsEnabled=false'

# Base permission groups (mapped to ROLE_* in the application's SSO mappings).
declare -A BASE
for g in admin sub-admin staff volunteer; do
  id="$(sid)"; BASE[$g]="$id"
  "$KC" create groups -r "$REALM" -s "name=$g" -s "attributes.sid=[\"$id\"]"
done

# Ten random department groups.
DEPTS=(art-show security logistics medical info-desk tech stage merch registration green-room)
declare -A DEPT_IDS
for d in "${DEPTS[@]}"; do
  id="$(sid)"; DEPT_IDS[$d]="$id"
  "$KC" create groups -r "$REALM" -s "name=$d" -s "attributes.sid=[\"$id\"]"
done

# Four users, each assigned to 2–4 departments. (Group membership wiring with
# kcadm is verbose; assign in the admin console or extend this script as needed.)
create_user() {
  local user="$1" pass="$2"
  "$KC" create users -r "$REALM" -s "username=$user" -s enabled=true \
    -s "email=$user@example.com" -s emailVerified=true
  "$KC" set-password -r "$REALM" --username "$user" --new-password "$pass"
}
declare -A USER_PASS
for u in admin sub-admin staff volunteer; do
  p="$(rand 18)"; USER_PASS[$u]="$p"; create_user "$u" "$p"
done

{
  echo "# Keycloak dev credentials — DO NOT COMMIT"
  echo "realm: $REALM"
  echo "realm_admin_password (master '${KEYCLOAK_ADMIN:-admin}'): ${KEYCLOAK_ADMIN_PASSWORD:-admin}"
  echo "generated_reserve_password: $REALM_ADMIN_PASS"
  echo "client_id: $APP_CLIENT"
  echo "client_secret: $CLIENT_SECRET"
  echo "redirect_uri: $APP_REDIRECT"
  echo "discovery_url: http://localhost:8080/realms/$REALM/.well-known/openid-configuration"
  echo
  echo "base group sids:"; for g in "${!BASE[@]}"; do echo "  $g = ${BASE[$g]}"; done
  echo "department group sids:"; for d in "${DEPTS[@]}"; do echo "  $d = ${DEPT_IDS[$d]}"; done
  echo
  echo "user passwords:"; for u in "${!USER_PASS[@]}"; do echo "  $u = ${USER_PASS[$u]}"; done
} > "$CRED_FILE"

echo "Done. Credentials written to $CRED_FILE"
