# SSO / Keycloak (OpenID Connect)

The application authenticates against any OpenID Connect provider. For local
development a Keycloak 26.6 container is part of `compose.dev.yaml`; production
uses a legacy SSO that reports memberships as structured group ids ("roles").

## Configuration (environment / Infisical)

SSO is configured entirely via environment variables (see `.env` `app/sso`):

| Variable                                                       | Purpose                                                                                              |
| -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `SSO_ENABLED`                                                  | `1` to enable the SSO login button and routes.                                                       |
| `SSO_DISCOVERY_URL`                                            | Provider `.well-known/openid-configuration`. **Preferred** — endpoints are discovered automatically. |
| `SSO_CLIENT_ID` / `SSO_CLIENT_SECRET`                          | Application client credentials.                                                                      |
| `SSO_AUTHORIZATION_URL` / `SSO_TOKEN_URL` / `SSO_USERINFO_URL` | Manual fallback when discovery is not used.                                                          |
| `SSO_SCOPES`                                                   | Default `openid profile email`.                                                                      |
| `SSO_PROVIDER_LABEL`                                           | Shown on the login button.                                                                           |

The admin status page at `/admin/sso` (permission `config:sso`, step-up protected
once 2FA is in place) shows whether discovery loaded, the resolved endpoints, the
**redirect URI** to register with the provider, and a truncated client id. The
client secret is never displayed.

The redirect URI to register with the provider is `…/login/sso/check`.

## Identity & provisioning

On SSO login the user is created or updated from the claims:

- **SSO owns** the username, full name and email — the user cannot change them.
- The username is collision-suffixed (`user_23`) on first creation, then kept
  stable.
- Banned identities are refused (the user only sees "The Ledger-Keeper").
- The provider's groups (structured ids, reported as `groups`/`roles`) are matched
  against **SSO group mappings** to assign permission groups (department-scoped
  where the mapping has a department), volunteer types and badges.

## SSO group mappings

Manage at `/manage/sso-mappings` (permission `rbac:ssomap:manage`). Mappings can
be bulk-imported as JSON:

```json
[
    {
        "id": "0RV39Y2PM21J4N6L",
        "name": "Art Show",
        "slug": "art-show",
        "staffonly": 1,
        "department": "art-show",
        "badges": ["security"],
        "volunteertype": ["Volunteer"],
        "permissiongroup": ["info-desk"]
    }
]
```

Unknown slugs are reported as warnings; the mapping is still saved.

## Local Keycloak provisioning

```bash
docker compose -f compose.dev.yaml up -d keycloak
# Provision the realm, client, groups and test users:
docker compose -f compose.dev.yaml cp docker/keycloak/provision.sh keycloak:/opt/provision.sh
docker compose -f compose.dev.yaml exec keycloak bash /opt/provision.sh
docker compose -f compose.dev.yaml cp keycloak:/tmp/keycloak-credentials.txt docs/keycloak-credentials.txt
```

`docs/keycloak-credentials.txt` (git-ignored) holds the generated client secret,
group structured ids and the four test-user passwords (admin / sub-admin / staff /
volunteer). Point the app at the realm with:

```
SSO_ENABLED=1
SSO_DISCOVERY_URL=http://localhost:8080/realms/critter/.well-known/openid-configuration
SSO_CLIENT_ID=critter-app
SSO_CLIENT_SECRET=<from credentials file>
```

> Live SSO login requires a running Keycloak and a browser round-trip, so it is verified
> manually rather than by the automated test suite. The provisioning, mapping import and
> claim→user logic are covered by tests.
