/**
 * DTO: fields that `profile:set-option` is allowed to mutate.
 *
 * By design this type does NOT include `api_url`, `environment_tag`,
 * `capabilities`, `last_verified_at`, or other immutable / server-sourced
 * fields. That gives us a compile-time guarantee that set-option cannot
 * touch `api_url` even by accident.
 */
export type MutableConnectionOptionsPatch = {
    ca_bundle?: string | null;
    http_proxy?: string | null;
    https_proxy?: string | null;
    allow_insecure?: boolean;
    timeout_ms?: number;
    retry_max?: number;
    retry_backoff_ms?: number;
};

/**
 * DTO: the verification metadata that verifyConnection* emits.
 *
 * Separate from MutableConnectionOptionsPatch so verify cannot overwrite
 * connection options and set-option cannot overwrite verify metadata.
 */
export type VerificationMetadata = {
    environment_tag: string | null;
    environment_tag_source: "explicit" | "derived" | "unknown";
    capabilities: ReadonlyArray<string>;
    instance_id: string | null;
    server_version: string;
    last_verified_at: string;
};
