import { z } from "zod";

/**
 * Single source of truth for the `environment_tag_source` vocabulary shared by
 * `/version` (snake_case) and `/me` (camelCase) schemas (F-0-01). Keeping one
 * enum avoids the two-file drift that previously let `/me` accept `z.string()`
 * while `/version` was strict.
 */
export const EnvironmentTagSourceSchema = z.enum([
    "explicit",
    "derived",
    "unknown",
]);

export type EnvironmentTagSource = z.infer<typeof EnvironmentTagSourceSchema>;
