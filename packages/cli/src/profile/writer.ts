import { loadConfigFromPath } from "../config/loader.js";
import { userConfigPath } from "../config/paths.js";
import { saveConfigToPath } from "../config/saver.js";
import {
    RootConfigInputSchema,
    type ProfileEntry,
    type RootConfigInput,
} from "../config/schema.js";
import { isValidProfileName } from "./name.js";
import type {
    MutableConnectionOptionsPatch,
    VerificationMetadata,
} from "./patch-types.js";

export type ProfileInit = {
    api_url: string;
    expected_environment_tag?: string | null;
    allow_insecure?: boolean;
};

export interface ProfileWriter {
    list(): ReadonlyArray<{ name: string; entry: ProfileEntry }>;
    get(name: string): ProfileEntry | undefined;
    snapshot(name: string): ProfileEntry;
    addProfile(name: string, init: ProfileInit): void;
    updateExpectedEnv(name: string, expected: string | null): void;
    deleteProfile(name: string, opts?: { clearDefault?: boolean }): void;
    useDefaultProfile(name: string): void;
    applyAtomic(
        name: string,
        patch: MutableConnectionOptionsPatch,
        verifyResult: VerificationMetadata,
    ): void;
    persistVerificationMeta(name: string, meta: VerificationMetadata): void;
}

type ExtraEntryFields = {
    [K in keyof ProfileEntry]?: ProfileEntry[K];
};

function applyPatchToEntry(
    base: ProfileEntry,
    patch: MutableConnectionOptionsPatch,
): ProfileEntry {
    const out: ProfileEntry = { ...base };
    // Iterate explicitly so we can delete keys set to `undefined` (which the
    // strict schema rejects as `undefined` values). Null is kept.
    const keys: Array<keyof MutableConnectionOptionsPatch> = [
        "ca_bundle",
        "http_proxy",
        "https_proxy",
        "allow_insecure",
        "timeout_ms",
        "retry_max",
        "retry_backoff_ms",
    ];
    for (const k of keys) {
        if (!Object.prototype.hasOwnProperty.call(patch, k)) continue;
        const v = patch[k];
        if (v === undefined) {
            // delete the key from out
            delete (out as ExtraEntryFields)[k];
            continue;
        }
        // Assign via index on a loose type because strictly-typed assignment
        // of a heterogeneous union requires per-key narrowing — we've already
        // validated types at the patch builder.
        (out as Record<string, unknown>)[k] = v;
    }
    return out;
}

function mergeVerificationMeta(
    base: ProfileEntry,
    meta: VerificationMetadata,
): ProfileEntry {
    return {
        ...base,
        environment_tag: meta.environment_tag,
        environment_tag_source: meta.environment_tag_source,
        capabilities: [...meta.capabilities],
        instance_id: meta.instance_id,
        server_version: meta.server_version,
        last_verified_at: meta.last_verified_at,
    };
}

export class FileProfileWriter implements ProfileWriter {
    constructor(private readonly userPath: string = userConfigPath()) {}

    private loadUser(): RootConfigInput {
        const loaded = loadConfigFromPath(this.userPath);
        return loaded ?? {};
    }

    private save(next: RootConfigInput): void {
        // Clone and strip any `undefined` leaves that strict() would reject.
        const sanitized = stripUndefined(next);
        const validated = RootConfigInputSchema.parse(sanitized);
        saveConfigToPath(this.userPath, validated);
    }

    list(): ReadonlyArray<{ name: string; entry: ProfileEntry }> {
        const user = this.loadUser();
        const profiles = user.profiles ?? {};
        return Object.entries(profiles).map(([name, entry]) => ({
            name,
            entry,
        }));
    }

    get(name: string): ProfileEntry | undefined {
        return this.loadUser().profiles?.[name];
    }

    snapshot(name: string): ProfileEntry {
        const entry = this.get(name);
        if (!entry) throw new Error(`profile "${name}" not found`);
        return structuredClone(entry);
    }

    addProfile(name: string, init: ProfileInit): void {
        if (!isValidProfileName(name)) {
            throw new Error(`invalid profile name: ${name}`);
        }
        const user = this.loadUser();
        const profiles = { ...(user.profiles ?? {}) };
        if (profiles[name]) {
            throw new Error(`profile "${name}" already exists`);
        }
        const entry: ProfileEntry = { api_url: init.api_url };
        if (init.expected_environment_tag !== undefined) {
            entry.expected_environment_tag = init.expected_environment_tag;
        }
        if (init.allow_insecure !== undefined) {
            entry.allow_insecure = init.allow_insecure;
        }
        profiles[name] = entry;
        this.save({ ...user, profiles });
    }

    updateExpectedEnv(name: string, expected: string | null): void {
        const user = this.loadUser();
        const entry = user.profiles?.[name];
        if (!entry) throw new Error(`profile "${name}" not found`);
        const next: ProfileEntry = {
            ...entry,
            expected_environment_tag: expected,
        };
        this.save({
            ...user,
            profiles: { ...(user.profiles ?? {}), [name]: next },
        });
    }

    deleteProfile(
        name: string,
        opts: { clearDefault?: boolean } = {},
    ): void {
        const user = this.loadUser();
        if (!user.profiles?.[name]) {
            throw new Error(`profile "${name}" not found`);
        }
        if (user.default_profile === name && !opts.clearDefault) {
            throw new Error(
                `profile "${name}" is the default. `
                    + "Use --clear-default or run `profile:use` first.",
            );
        }
        const { [name]: _removed, ...rest } = user.profiles;
        const next: RootConfigInput = { ...user, profiles: rest };
        if (opts.clearDefault && user.default_profile === name) {
            delete next.default_profile;
        }
        this.save(next);
    }

    useDefaultProfile(name: string): void {
        const user = this.loadUser();
        if (!user.profiles?.[name]) {
            throw new Error(`profile "${name}" not found`);
        }
        this.save({ ...user, default_profile: name });
    }

    applyAtomic(
        name: string,
        patch: MutableConnectionOptionsPatch,
        verifyResult: VerificationMetadata,
    ): void {
        const user = this.loadUser();
        const entry = user.profiles?.[name];
        if (!entry) throw new Error(`profile "${name}" not found`);
        const patched = applyPatchToEntry(entry, patch);
        const merged = mergeVerificationMeta(patched, verifyResult);
        this.save({
            ...user,
            profiles: { ...(user.profiles ?? {}), [name]: merged },
        });
    }

    persistVerificationMeta(
        name: string,
        meta: VerificationMetadata,
    ): void {
        const user = this.loadUser();
        const entry = user.profiles?.[name];
        if (!entry) throw new Error(`profile "${name}" not found`);
        const merged = mergeVerificationMeta(entry, meta);
        this.save({
            ...user,
            profiles: { ...(user.profiles ?? {}), [name]: merged },
        });
    }
}

function stripUndefined<T>(value: T): T {
    if (value === null || typeof value !== "object") return value;
    if (Array.isArray(value)) {
        return value.map((v) => stripUndefined(v)) as unknown as T;
    }
    const src = value as Record<string, unknown>;
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(src)) {
        if (v === undefined) continue;
        out[k] = stripUndefined(v);
    }
    return out as unknown as T;
}
