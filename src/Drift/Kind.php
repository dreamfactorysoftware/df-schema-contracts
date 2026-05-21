<?php

namespace DreamFactory\Core\SchemaContracts\Drift;

/**
 * Stable change-kind identifiers for drift reports. Treat these strings as a
 * public contract: external tools (CI gates, dashboards) match on them.
 * Never rename; only deprecate-and-add.
 *
 * Severities for each kind are decided by DriftEngine, not encoded here,
 * because severity sometimes depends on the direction of a change
 * (e.g. length increased vs reduced share no single severity).
 */
final class Kind
{
    public const TABLE_REMOVED             = 'table.removed';
    public const TABLE_TYPE_CHANGED        = 'table.type_changed';
    public const TABLE_LABEL_CHANGED       = 'table.label_changed';
    public const TABLE_DESCRIPTION_CHANGED = 'table.description_changed';

    public const FIELD_ADDED                  = 'field.added';
    public const FIELD_REMOVED                = 'field.removed';
    public const FIELD_TYPE_CHANGED           = 'field.type_changed';
    public const FIELD_ELEMENT_TYPE_CHANGED   = 'field.element_type_changed';
    public const FIELD_NULLABLE_RELAXED       = 'field.nullable_relaxed';
    public const FIELD_NULLABLE_TIGHTENED     = 'field.nullable_tightened';
    public const FIELD_LENGTH_INCREASED       = 'field.length_increased';
    public const FIELD_LENGTH_REDUCED         = 'field.length_reduced';
    public const FIELD_PRECISION_INCREASED    = 'field.precision_increased';
    public const FIELD_PRECISION_REDUCED      = 'field.precision_reduced';
    public const FIELD_SCALE_INCREASED        = 'field.scale_increased';
    public const FIELD_SCALE_REDUCED          = 'field.scale_reduced';
    public const FIELD_DEFAULT_CHANGED        = 'field.default_changed';
    public const FIELD_DEFAULT_REMOVED        = 'field.default_removed';
    public const FIELD_REQUIRED_ADDED         = 'field.required_added';
    public const FIELD_REQUIRED_REMOVED       = 'field.required_removed';
    public const FIELD_AUTO_INCREMENT_ADDED   = 'field.auto_increment_added';
    public const FIELD_AUTO_INCREMENT_REMOVED = 'field.auto_increment_removed';
    public const FIELD_READ_ONLY_ADDED        = 'field.read_only_added';
    public const FIELD_READ_ONLY_REMOVED      = 'field.read_only_removed';
    public const FIELD_GENERATED_CHANGED      = 'field.generated_changed';
    public const FIELD_UNIQUE_ADDED           = 'field.unique_added';
    public const FIELD_UNIQUE_REMOVED         = 'field.unique_removed';
    public const FIELD_FOREIGN_KEY_CHANGED    = 'field.foreign_key_changed';
    public const FIELD_REF_CHANGED            = 'field.ref_changed';
    public const FIELD_ENUM_VALUE_ADDED       = 'field.enum_value_added';
    public const FIELD_ENUM_VALUE_REMOVED     = 'field.enum_value_removed';
    public const FIELD_VALIDATION_CHANGED     = 'field.validation_changed';
    public const FIELD_LABEL_CHANGED          = 'field.label_changed';
    public const FIELD_DESCRIPTION_CHANGED    = 'field.description_changed';

    public const PRIMARY_KEY_CHANGED = 'primary_key.changed';

    public const RELATIONSHIP_ADDED          = 'relationship.added';
    public const RELATIONSHIP_REMOVED        = 'relationship.removed';
    public const RELATIONSHIP_TYPE_CHANGED   = 'relationship.type_changed';
    public const RELATIONSHIP_TARGET_CHANGED = 'relationship.target_changed';
    public const RELATIONSHIP_LABEL_CHANGED  = 'relationship.label_changed';
}
