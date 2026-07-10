import { z } from "zod";

// ============================================================================
// RootConfigInputSchema
//
// Used for:
//   - YAML parse immediately after load
//   - config:set value validation (per-key sub-schema picking)
//   - config:validate --scope user|project (single-file validation)
//
// Rules:
//   - every field is .optional() (we NEVER use .partial())
//   - every nested object is .strict() (unknown keys are rejected)
//   - no defaults — loaders must leave `undefined` for unspecified keys
// ============================================================================

const ScanProbeInputSchema = z
    .object({
        enabled: z.boolean().optional(),
        method: z.enum(["GET", "HEAD"]).optional(),
        timeout_ms: z.number().int().min(100).max(60000).optional(),
    })
    .strict();

const HttpMethodEnum = z.enum([
    "GET",
    "POST",
    "PUT",
    "PATCH",
    "DELETE",
    "HEAD",
]);

const ScanAdapterEnum = z.enum([
    "auto",
    "laravel",
    "nextjs",
    "sveltekit",
    "crawl",
    "sitemap",
]);

const ScanInputSchema = z
    .object({
        root: z.string().optional(),
        adapter: ScanAdapterEnum.optional(),
        include: z.array(z.string()).optional(),
        exclude: z.array(z.string()).optional(),
        methods: z.array(HttpMethodEnum).optional(),
        register_templates: z.boolean().optional(),
        probe: ScanProbeInputSchema.optional(),
    })
    .strict();

const CaptureInputSchema = z
    .object({
        dismiss_selectors: z.record(z.string(), z.string()).optional(),
        environment: z.string().min(1).optional(),
    })
    .strict();

const CiModeEnum = z.enum(["strict", "permissive"]);

const CiInputSchema = z
    .object({
        mode: CiModeEnum.optional(),
        auto_install_browser: z.boolean().optional(),
    })
    .strict();

const DoctorInputSchema = z
    .object({
        expected_cli_sha256: z.string().nullable().optional(),
    })
    .strict();

const ProfileEntrySchema = z
    .object({
        // api_url stays optional at the schema layer so legacy (U-14) tests
        // remain valid; runtime callers go through assertProfileHasApiUrl().
        api_url: z.string().url().optional(),
        ca_bundle: z.string().nullable().optional(),
        http_proxy: z.string().nullable().optional(),
        https_proxy: z.string().nullable().optional(),
        allow_insecure: z.boolean().optional(),
        timeout_ms: z.number().int().min(1000).max(120000).optional(),
        retry_max: z.number().int().min(0).max(10).optional(),
        retry_backoff_ms: z.number().int().min(100).max(60000).optional(),
        expected_environment_tag: z.string().nullable().optional(),
        environment_tag: z.string().nullable().optional(),
        environment_tag_source: z
            .enum(["explicit", "derived", "unknown"])
            .optional(),
        capabilities: z.array(z.string()).optional(),
        instance_id: z.string().nullable().optional(),
        server_version: z.string().optional(),
        last_verified_at: z.string().datetime().optional(),
    })
    .strict();

export type ProfileEntry = z.infer<typeof ProfileEntrySchema>;

export const RootConfigInputSchema = z
    .object({
        scan: ScanInputSchema.optional(),
        capture: CaptureInputSchema.optional(),
        ci: CiInputSchema.optional(),
        doctor: DoctorInputSchema.optional(),
        // Protected keys (rejected by config:set / config:unset at the policy
        // layer, but still validated for structure here):
        default_profile: z.string().optional(),
        profile: z.string().optional(),
        profiles: z.record(z.string(), ProfileEntrySchema).optional(),
    })
    .strict();

export type RootConfigInput = z.infer<typeof RootConfigInputSchema>;

// ============================================================================
// EffectiveConfigSchema
//
// Used for:
//   - the result of `deepMergeWithDefaults(user, project, DEFAULTS)`
//   - runtime config access
//
// Rules:
//   - all fields required (defaults have been applied)
//   - independent definition — must not reuse Input schema
// ============================================================================

const ScanProbeEffectiveSchema = z
    .object({
        enabled: z.boolean(),
        method: z.enum(["GET", "HEAD"]),
        timeout_ms: z.number().int().min(100).max(60000),
    })
    .strict();

const ScanEffectiveSchema = z
    .object({
        root: z.string(),
        adapter: ScanAdapterEnum,
        include: z.array(z.string()),
        exclude: z.array(z.string()),
        methods: z.array(HttpMethodEnum),
        register_templates: z.boolean(),
        probe: ScanProbeEffectiveSchema,
    })
    .strict();

const CaptureEffectiveSchema = z
    .object({
        dismiss_selectors: z.record(z.string(), z.string()),
        environment: z.string().min(1),
    })
    .strict();

const CiEffectiveSchema = z
    .object({
        mode: CiModeEnum,
        auto_install_browser: z.boolean(),
    })
    .strict();

const DoctorEffectiveSchema = z
    .object({
        expected_cli_sha256: z.string().nullable(),
    })
    .strict();

export const EffectiveConfigSchema = z
    .object({
        scan: ScanEffectiveSchema,
        capture: CaptureEffectiveSchema,
        ci: CiEffectiveSchema,
        doctor: DoctorEffectiveSchema,
        default_profile: z.string().optional(),
        profile: z.string().optional(),
        profiles: z.record(z.string(), ProfileEntrySchema).optional(),
    })
    .strict();

export type EffectiveConfig = z.infer<typeof EffectiveConfigSchema>;

// ============================================================================
// Default tree for the Effective schema.
// Re-exported so `deepMergeWithDefaults` can reuse it.
// ============================================================================

export const DEFAULT_EFFECTIVE: {
    scan: z.infer<typeof ScanEffectiveSchema>;
    capture: z.infer<typeof CaptureEffectiveSchema>;
    ci: z.infer<typeof CiEffectiveSchema>;
    doctor: z.infer<typeof DoctorEffectiveSchema>;
} = {
    scan: {
        root: ".",
        adapter: "auto",
        include: [],
        exclude: [],
        methods: ["GET"],
        register_templates: false,
        probe: { enabled: false, method: "GET", timeout_ms: 5000 },
    },
    capture: {
        dismiss_selectors: {},
        environment: "development",
    },
    ci: {
        mode: "strict",
        auto_install_browser: false,
    },
    doctor: {
        expected_cli_sha256: null,
    },
};

// ============================================================================
// Schema descriptor for dotted-path traversal.
// Used by value-parser and config:set to find the leaf schema for a key.
// ============================================================================

export type SchemaKind =
    | { kind: "string"; nullable: boolean }
    | { kind: "number"; nullable: boolean }
    | { kind: "boolean"; nullable: boolean }
    | { kind: "enum"; values: ReadonlyArray<string>; nullable: boolean }
    | { kind: "array" }
    | { kind: "object" }
    | { kind: "record" };

/**
 * Return the descriptor of the leaf schema at a dotted path against the
 * Root Input tree. Returns null if the path is not known.
 *
 * For `record` nodes we treat any further sub-path as `unknown` — the caller
 * can choose to accept it (e.g. `capture.dismiss_selectors.foo` => string)
 * or reject. We keep the implementation intentionally scoped to Root Input.
 */
export function describeSchemaAt(path: ReadonlyArray<string>): SchemaKind | null {
    type Entry = {
        kind: "leaf";
        desc: SchemaKind;
    } | {
        kind: "object";
        children: Record<string, Entry>;
    } | {
        kind: "record-of-string";
    };

    const scanProbe: Entry = {
        kind: "object",
        children: {
            enabled: { kind: "leaf", desc: { kind: "boolean", nullable: false } },
            method: {
                kind: "leaf",
                desc: { kind: "enum", values: ["GET", "HEAD"], nullable: false },
            },
            timeout_ms: {
                kind: "leaf",
                desc: { kind: "number", nullable: false },
            },
        },
    };

    const scan: Entry = {
        kind: "object",
        children: {
            root: { kind: "leaf", desc: { kind: "string", nullable: false } },
            adapter: {
                kind: "leaf",
                desc: {
                    kind: "enum",
                    values: [
                        "auto",
                        "laravel",
                        "nextjs",
                        "sveltekit",
                        "crawl",
                        "sitemap",
                    ],
                    nullable: false,
                },
            },
            include: { kind: "leaf", desc: { kind: "array" } },
            exclude: { kind: "leaf", desc: { kind: "array" } },
            methods: { kind: "leaf", desc: { kind: "array" } },
            register_templates: {
                kind: "leaf",
                desc: { kind: "boolean", nullable: false },
            },
            probe: scanProbe,
        },
    };

    const capture: Entry = {
        kind: "object",
        children: {
            dismiss_selectors: { kind: "record-of-string" },
            environment: {
                kind: "leaf",
                desc: { kind: "string", nullable: false },
            },
        },
    };

    const ci: Entry = {
        kind: "object",
        children: {
            mode: {
                kind: "leaf",
                desc: {
                    kind: "enum",
                    values: ["strict", "permissive"],
                    nullable: false,
                },
            },
            auto_install_browser: {
                kind: "leaf",
                desc: { kind: "boolean", nullable: false },
            },
        },
    };

    const doctor: Entry = {
        kind: "object",
        children: {
            expected_cli_sha256: {
                kind: "leaf",
                desc: { kind: "string", nullable: true },
            },
        },
    };

    // Profile entry (for describing leaves under `profiles.<name>.*`).
    const profileEntry: Entry = {
        kind: "object",
        children: {
            api_url: { kind: "leaf", desc: { kind: "string", nullable: false } },
            ca_bundle: {
                kind: "leaf",
                desc: { kind: "string", nullable: true },
            },
            http_proxy: {
                kind: "leaf",
                desc: { kind: "string", nullable: true },
            },
            https_proxy: {
                kind: "leaf",
                desc: { kind: "string", nullable: true },
            },
            allow_insecure: {
                kind: "leaf",
                desc: { kind: "boolean", nullable: false },
            },
            timeout_ms: {
                kind: "leaf",
                desc: { kind: "number", nullable: false },
            },
            retry_max: {
                kind: "leaf",
                desc: { kind: "number", nullable: false },
            },
            retry_backoff_ms: {
                kind: "leaf",
                desc: { kind: "number", nullable: false },
            },
            expected_environment_tag: {
                kind: "leaf",
                desc: { kind: "string", nullable: true },
            },
            environment_tag: {
                kind: "leaf",
                desc: { kind: "string", nullable: true },
            },
            environment_tag_source: {
                kind: "leaf",
                desc: {
                    kind: "enum",
                    values: ["explicit", "derived", "unknown"],
                    nullable: false,
                },
            },
            capabilities: { kind: "leaf", desc: { kind: "array" } },
            instance_id: {
                kind: "leaf",
                desc: { kind: "string", nullable: true },
            },
            server_version: {
                kind: "leaf",
                desc: { kind: "string", nullable: false },
            },
            last_verified_at: {
                kind: "leaf",
                desc: { kind: "string", nullable: false },
            },
        },
    };

    const root: Entry = {
        kind: "object",
        children: {
            scan,
            capture,
            ci,
            doctor,
            default_profile: {
                kind: "leaf",
                desc: { kind: "string", nullable: false },
            },
            profile: {
                kind: "leaf",
                desc: { kind: "string", nullable: false },
            },
            // We treat `profiles` as `object` — callers drill further manually
            // because the middle segment is a dynamic record key.
            profiles: { kind: "leaf", desc: { kind: "object" } },
        },
    };

    // Explicit handling for `profiles.<name>.<field>` (dynamic middle key).
    if (path.length >= 2 && path[0] === "profiles") {
        if (path.length === 1) return { kind: "object" };
        if (path.length === 2) return { kind: "object" };
        // path.length >= 3 — descend into profile entry
        const rest = path.slice(2);
        let node: Entry = profileEntry;
        for (const seg of rest) {
            if (node.kind !== "object") return null;
            const child: Entry | undefined = node.children[seg];
            if (!child) return null;
            node = child;
        }
        if (node.kind === "leaf") return node.desc;
        if (node.kind === "object") return { kind: "object" };
        return { kind: "record" };
    }

    // `capture.dismiss_selectors.*` — record of strings.
    if (
        path.length === 3
        && path[0] === "capture"
        && path[1] === "dismiss_selectors"
    ) {
        return { kind: "string", nullable: false };
    }
    if (
        path.length === 2
        && path[0] === "capture"
        && path[1] === "dismiss_selectors"
    ) {
        return { kind: "record" };
    }

    let node: Entry = root;
    for (const seg of path) {
        if (node.kind !== "object") return null;
        const child: Entry | undefined = node.children[seg];
        if (!child) return null;
        node = child;
    }

    if (node.kind === "leaf") return node.desc;
    if (node.kind === "object") return { kind: "object" };
    return { kind: "record" };
}
