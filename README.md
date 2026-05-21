# DreamFactory Schema Contracts

Schema contract locking, canonical SQL schema JSON, drift detection, and
schema-driven OpenAPI generation for DreamFactory.

The package gives every SQL connector a single connector-neutral JSON schema
shape, snapshots it as a versioned "contract," detects drift between the live
database and the locked contract, and generates stable OpenAPI schemas from
it. Default DreamFactory behavior is unchanged unless a service opts in.

## Goals

- Preserve existing DreamFactory behavior by default.
- Give every SQL connector a single canonical JSON schema shape.
- Let database APIs and OpenAPI specs generate from that canonical schema.
- Add optional schema contracts so API shape can remain stable when a database
  schema drifts.
- Provide a review/test/promote workflow before schema changes become the
  public API contract.

## How it works

```
SQL connector (BaseDbService)
  -> DefaultSqlAdapter            consumes existing connector metadata
  -> Normalizer                   maps to connector-neutral canonical JSON
  -> SchemaContractSnapshot       versioned, hashed, immutable contract rows
  -> DriftEngine                  diffs live schema vs locked contract
  -> OpenApiSchemaGenerator       canonical -> OpenAPI 3.0 Schema Objects
  -> SchemaContractResource       /api/v2/system/schema_contract/* endpoints
```

The package is **consume-only** with respect to other DreamFactory packages:
it reads what each connector already exposes via
`BaseDbService::getTableSchema()` and never modifies connector code. Adding
vendor-specific fidelity (e.g. MySQL ENUM values, Postgres arrays) is done by
pushing metadata into the existing `native` bag on the connector side, not by
changing this package.

## System API

All endpoints live under `/api/v2/system/schema_contract`:

| Method | Path | Purpose |
|--------|------|---------|
| `GET`    | `/` | List SQL services with mode + snapshot counts |
| `GET`    | `/{service}` | Service summary + drift rollup |
| `PATCH`  | `/{service}` | Set mode (`none`/`auto`/`strict`) + retention |
| `DELETE` | `/{service}` | Unlock whole service |
| `POST`   | `/{service}/promote` | Mode-aware bulk promote |
| `GET`    | `/{service}/diff` | Service-wide drift report |
| `GET`    | `/{service}/openapi` | OpenAPI `components/schemas` for the service |
| `GET`    | `/{service}/tables` | Per-table lock + drift status |
| `POST`   | `/{service}/tables/{table}/lock` | Lock / re-lock / promote a table |
| `POST`   | `/{service}/tables/{table}/test` | Dry-run lock preview |
| `GET`    | `/{service}/tables/{table}/diff` | Table drift report |
| `GET`    | `/{service}/tables/{table}/snapshot` | Active snapshot |
| `GET`    | `/{service}/tables/{table}/snapshots[/{v}]` | Version history |
| `GET`    | `/{service}/tables/{table}/openapi` | OpenAPI schema for one table |
| `DELETE` | `/{service}/tables/{table}` | Unlock one table |

Tables in non-default schemas are addressed by their qualified name
(`schema.table`).

## Console

```bash
php artisan schema-contracts:describe <service> [--table=NAME] [--pretty]
php artisan schema-contracts:prune <service|--all> [--dry-run]
```

## Contract modes

- `none` — live schema is the API shape (default; no contract).
- `auto` — additive/cosmetic drift auto-promotes; breaking drift held for review.
- `strict` — all drift held for explicit review.

Mode drives `POST /promote` behavior and OpenAPI source selection (locked
snapshot vs live).

## Layout

```
src/
  Contracts/CanonicalSchemaAdapter.php   adapter interface
  Adapters/AdapterRegistry.php           priority-based resolver
  Adapters/DefaultSqlAdapter.php         consume-only SQL adapter
  Canonical/                             canonical DTOs (Service/Table/Field/Relationship/Index)
  Normalization/Normalizer.php           DF schema -> canonical JSON
  Drift/                                 DriftEngine + Severity + Kind
  OpenApi/OpenApiSchemaGenerator.php     canonical -> OpenAPI 3.0
  Models/                                SchemaContractService, SchemaContractSnapshot
  Resources/SchemaContractResource.php   system resource (HTTP)
  Console/                               describe + prune commands
  ServiceProvider.php                    Laravel auto-discovery
database/migrations/                     schema_contract_service, schema_contract_snapshot
```

## Requirements

- PHP 8.2+
- `dreamfactory/df-core` and `dreamfactory/df-database` (~1.0)

A polyfill for PHP 8.4's `request_parse_body()` is bundled so DELETE/PATCH/PUT
requests work on PHP 8.3 hosts; it no-ops on PHP 8.4+.
