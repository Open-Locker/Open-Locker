# ADR-0053: Terminate public MQTT TLS at Traefik

## Status

Proposed

## Date

2026-08-23

## Context

Production Compose currently publishes Mosquitto's plaintext listener on host
port 1883. Locker clients therefore send MQTT credentials, commands, and state
without transport encryption. An HTTP reverse-proxy route does not protect raw
MQTT traffic.

Open Locker supports two deployment models:

1. Coolify, using Coolify's managed Traefik proxy.
2. Plain Docker Compose, using a repository-maintained Traefik overlay.

Both models need the same public contract while preserving simple,
container-local communication between Laravel and Mosquitto:

- public HTTPS on port 443;
- public MQTTS on port 8883;
- internal Laravel HTTP on port 8080;
- internal plaintext MQTT on port 1883.

## Decision

1. Traefik terminates TLS for public HTTP and MQTT connections in both supported
   deployment models.
2. Mosquitto listens on plaintext port 1883 inside the Compose network only.
   Production Compose must not publish that port on the host.
3. The MQTTS router listens on public TCP port 8883, selects the broker by
   `HostSNI`, terminates TLS with an ACME-managed certificate, and forwards
   plaintext MQTT to `mqtt:1883` over the private Docker network.
4. Coolify deployments use Coolify's managed Traefik instance with an explicit
   TCP entrypoint and router. Plain-Docker deployments use the maintained
   Traefik Compose overlay.
5. Production locker clients use `mqtts://<mqtt-host>:8883` and verify the
   certificate chain and hostname against their operating-system trust store.
   No option to disable certificate verification is provided.
6. Backend containers continue to use `mqtt:1883` because that connection never
   leaves the private Compose network.
7. Client certificates are not required. Existing MQTT username/password
   authentication and HTTP-backed ACL checks remain authoritative.

## Rationale

Using the same TLS boundary in both deployment models keeps the external
contract and certificate lifecycle consistent. Traefik already manages HTTPS
certificates in Coolify and can also manage certificates in the standalone
reference stack. Mosquitto therefore does not need access to private keys or a
separate renewal process.

Keeping the backend-to-broker connection internal avoids unnecessary TLS
configuration without exposing plaintext traffic on the host.

## Alternatives Considered

### Alternative A: Terminate TLS directly in Mosquitto

- Pros: direct host port mapping; no TCP router is required.
- Cons: Mosquitto needs mounted private keys and a separate certificate renewal
  and reload process in both deployment models.
- Why not chosen: it duplicates certificate ownership already provided by
  Traefik and makes Coolify and standalone operations less consistent.

### Alternative B: Publish plaintext MQTT on port 1883

- Pros: no proxy or certificate configuration.
- Cons: exposes credentials and locker commands in plaintext.
- Why not chosen: it does not meet the production security boundary.

### Alternative C: Encrypt backend-to-broker traffic as well

- Pros: encryption also exists inside the Compose network.
- Cons: adds certificate trust and rotation to every backend worker without
  reducing the public exposure addressed by this decision.
- Why not chosen: the internal Docker network is the accepted trust boundary.

## Consequences

### Positive

- Public MQTT credentials and payloads are encrypted in transit.
- Port 1883 is not reachable from outside the production Docker network.
- Coolify and standalone deployments share one external MQTT contract.
- ACME issuance and renewal remain owned by the edge proxy.

### Negative

- Operators must configure a TCP entrypoint and router in addition to normal
  HTTP routes.
- Traefik-to-Mosquitto traffic is plaintext inside the Docker network.
- MQTTS depends on correct DNS, SNI, firewall, and ACME configuration.
- A staged migration temporarily retains the pre-existing plaintext endpoint
  for old clients. The standalone migration overlay is opt-in, requires an
  explicit bind address, and must be removed immediately after client migration.

### Risks

- Mapping host port 8883 directly to Mosquitto port 1883 would expose plaintext
  MQTT under a misleading port number. Mitigation: only Traefik publishes 8883.
- A missing TCP router may leave clients unable to connect while HTTPS remains
  healthy. Mitigation: require a separate MQTTS smoke test.
- A proxy attached to the wrong Docker network cannot reach Mosquitto.
  Mitigation: document and verify the shared network in both deployment paths.
- The standalone Docker provider reads the Docker socket, which is a privileged
  control-plane boundary. Mitigation: mount it read-only, disable automatic
  exposure, and run only trusted containers on the host.

## Rollout / Migration

1. If existing remote clients still require plaintext MQTT, retain the old
   endpoint only for the migration window. Standalone Compose uses the
   explicitly selected `docker-compose.prod.mqtt-migration.yml` overlay with
   `MQTT_MIGRATION_BIND_ADDRESS` set to the address used by those clients.
   Existing Coolify deployments leave an already-present legacy mapping
   unchanged; they do not add one when it is already absent. Normal production
   configuration publishes no port 1883.
2. Add the MQTTS TCP entrypoint, router, DNS record, and ACME certificate while
   existing clients still use the temporary old endpoint.
3. Verify certificate validation, authentication, ACL behavior, and command
   flow through port 8883.
4. Deploy locker clients configured with the MQTTS URL.
5. Remove the legacy endpoint: standalone redeploys without
   `docker-compose.prod.mqtt-migration.yml` and unsets
   `MQTT_MIGRATION_BIND_ADDRESS`; Coolify deploys the repository-owned Compose
   definition without its old mapping. Verify that port 1883 is no longer
   externally reachable.
6. Keep this ADR proposed until both deployment paths have passed the documented
   smoke test.

## Supersedes / Superseded By

- Supersedes: none.
- Related: [ADR-0014](0014-mqtt-session-and-reconnect-policy.md) defines MQTT
  session behavior; [ADR-0050](0050-per-provisioning-mqtt-credential-identities.md)
  defines MQTT credential identities.

## References

- Related issues: #163
- Related docs: [Installation](../Installation.md),
  [AsyncAPI MQTT contract](../asyncapi/mqtt.yaml)
- External references:
  [Traefik TCP TLS](https://doc.traefik.io/traefik/reference/routing-configuration/tcp/tls/),
  [Traefik Docker routing](https://doc.traefik.io/traefik/reference/routing-configuration/other-providers/docker/)
