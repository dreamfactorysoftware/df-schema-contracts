<?php

namespace DreamFactory\Core\SchemaContracts\Drift;

/**
 * Drift severity classification. See docs/SYSTEM_API.md "severity values"
 * for semantics. The order here is deliberate: BREAKING > POTENTIALLY_BREAKING
 * > ADDITIVE > COSMETIC, mirroring the precedence the drift summary uses
 * when collapsing per-change severities to an overall verdict.
 */
final class Severity
{
    public const BREAKING             = 'breaking';
    public const POTENTIALLY_BREAKING = 'potentially_breaking';
    public const ADDITIVE             = 'additive';
    public const COSMETIC             = 'cosmetic';

    public const ALL = [
        self::BREAKING,
        self::POTENTIALLY_BREAKING,
        self::ADDITIVE,
        self::COSMETIC,
    ];
}
