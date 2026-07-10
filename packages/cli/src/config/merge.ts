import {
    DEFAULT_EFFECTIVE,
    EffectiveConfigSchema,
    type EffectiveConfig,
    type RootConfigInput,
} from "./schema.js";

/**
 * Type guard: value is a plain object (Record<string, unknown>), NOT an array,
 * NOT null.
 */
export function isPlainObject(v: unknown): v is Record<string, unknown> {
    return (
        typeof v === "object"
        && v !== null
        && !Array.isArray(v)
    );
}

/**
 * Merge contract (for two scopes: base = user, override = project):
 *
 *   - object → deep merge (recursive, per-key)
 *   - array  → override fully replaces base's array (no concatenation)
 *   - scalar (string / number / boolean) → override replaces
 *   - null (explicit) → override replaces, result stays null
 *     (accepted only for nullable leaves — the schema check in
 *     deepMergeWithDefaults catches the other cases)
 *   - undefined (key unspecified) → fall through to base
 *
 * The function never mutates either input.
 */
export function mergeTwoScopes(
    base: RootConfigInput | undefined,
    override: RootConfigInput | undefined,
): Record<string, unknown> {
    const left: Record<string, unknown> = isPlainObject(base) ? { ...base } : {};
    const right: Record<string, unknown> = isPlainObject(override)
        ? { ...override }
        : {};
    return deepMerge(left, right);
}

function deepMerge(
    base: Record<string, unknown>,
    override: Record<string, unknown>,
): Record<string, unknown> {
    const out: Record<string, unknown> = { ...base };
    for (const key of Object.keys(override)) {
        const nextVal = override[key];
        if (nextVal === undefined) continue; // undefined: fall through to base

        const prevVal = out[key];
        if (isPlainObject(prevVal) && isPlainObject(nextVal)) {
            out[key] = deepMerge(prevVal, nextVal);
            continue;
        }
        // Arrays / scalars / null / mixed types → override wins.
        out[key] = nextVal;
    }
    return out;
}

/**
 * Merge user + project Root Input, then layer Effective defaults, then
 * validate against EffectiveConfigSchema.
 *
 * The effective defaults are applied AFTER user/project merge so that:
 *   (a) users never need to restate full defaults in their config
 *   (b) explicit `null` (for nullable leaves) survives the merge
 */
export function deepMergeWithDefaults(
    user: RootConfigInput | undefined,
    project: RootConfigInput | undefined,
): EffectiveConfig {
    const merged = mergeTwoScopes(user, project);

    // Layer defaults into each top-level section. We deep-copy the defaults
    // so the module-level `DEFAULT_EFFECTIVE` object is never mutated.
    const withDefaults: Record<string, unknown> = { ...merged };
    withDefaults["scan"] = layerDefaults(DEFAULT_EFFECTIVE.scan, merged["scan"]);
    withDefaults["capture"] = layerDefaults(
        DEFAULT_EFFECTIVE.capture,
        merged["capture"],
    );
    withDefaults["ci"] = layerDefaults(DEFAULT_EFFECTIVE.ci, merged["ci"]);
    withDefaults["doctor"] = layerDefaults(
        DEFAULT_EFFECTIVE.doctor,
        merged["doctor"],
    );

    return EffectiveConfigSchema.parse(withDefaults);
}

/**
 * Deep-merge a defaults object with a user-supplied partial.
 * `null` at a leaf in the partial wins over the default (explicit clear).
 */
function layerDefaults(defaults: unknown, user: unknown): unknown {
    if (user === undefined) return deepClone(defaults);
    if (!isPlainObject(defaults) || !isPlainObject(user)) {
        // Any non-object default (array / scalar / null) is fully replaced.
        return deepClone(user);
    }
    const out: Record<string, unknown> = {};
    const keys = new Set([...Object.keys(defaults), ...Object.keys(user)]);
    for (const k of keys) {
        const dv = defaults[k];
        const uv = user[k];
        if (uv === undefined) {
            out[k] = deepClone(dv);
            continue;
        }
        if (isPlainObject(dv) && isPlainObject(uv)) {
            out[k] = layerDefaults(dv, uv);
            continue;
        }
        out[k] = deepClone(uv);
    }
    return out;
}

function deepClone<T>(v: T): T {
    if (v === null || v === undefined) return v;
    if (Array.isArray(v)) return v.map((x) => deepClone(x)) as unknown as T;
    if (isPlainObject(v)) {
        const out: Record<string, unknown> = {};
        for (const [k, vv] of Object.entries(v)) out[k] = deepClone(vv);
        return out as unknown as T;
    }
    return v;
}
