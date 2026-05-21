# Changelog

All notable changes to `dreamfactory/df-schema-contracts` are documented here.

## 0.1.0 (unreleased)

Initial package. SQL schema contracts for DreamFactory.

### Added

- Canonical connector-neutral schema model (`ServiceSchema`, `TableSchema`,
  `FieldSchema`, `RelationshipSchema`, `IndexSchema`).
- `CanonicalSchemaAdapter` interface + priority-based `AdapterRegistry`.
- `DefaultSqlAdapter` — consume-only adapter over `BaseDbService`.
- `Normalizer` — maps DreamFactory connector schema to canonical JSON, with
  best-effort MySQL/MariaDB `enum(...)`/`set(...)` and JSON/JSONB/geometry
  detection from `db_type`.
- Snapshot storage: `schema_contract_service` and `schema_contract_snapshot`
  tables + Eloquent models. Immutable, versioned, hashed contracts.
- `DriftEngine` with stable `Kind` identifiers and four-level `Severity`
  (breaking / potentially_breaking / additive / cosmetic).
- `OpenApiSchemaGenerator` — canonical JSON to OpenAPI 3.0 Schema Objects,
  with mode-aware source selection (locked snapshot vs live).
- System resource at `/api/v2/system/schema_contract` covering service and
  table level: list, summary, mode config, lock/unlock, test preview, diff,
  snapshot history, promote, and OpenAPI generation.
- `schema-contracts:describe` and `schema-contracts:prune` artisan commands.
- Audit columns (`created_by_id` / `last_modified_by_id`) on contract writes.
- Polyfill for PHP 8.4 `request_parse_body()` so DELETE/PATCH/PUT work on
  PHP 8.3 hosts.

### Validated against

- SQLite, MySQL, Oracle (incl. non-default schema), PostgreSQL (incl.
  multi-schema, cross-schema foreign keys, JSON/JSONB, NUMERIC decimals).

### Known gaps (future work)

- Per-connector enrichment (Postgres arrays/native enums, Snowflake VARIANT,
  SQL Server computed columns, etc.) — vendor metadata not yet pushed into
  `native`.
- `indexes` not yet populated by the normalizer.
- Runtime contract enforcement (response shaping) not implemented.
