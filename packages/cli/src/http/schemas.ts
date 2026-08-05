import { z } from "zod";

import { EnvironmentTagSourceSchema } from "../schemas/environment-tag-source.js";

export const VersionResponseSchema = z
    .object({
        data: z
            .object({
                api_version: z.string(),
                server_version: z.string(),
                min_cli_version: z.string(),
                deprecated_at: z.string().nullable(),
                capabilities: z.array(z.string()),
                environment_tag: z.string().nullable(),
                environment_tag_source: EnvironmentTagSourceSchema,
                instance_id: z.string().nullable(),
                // T715 Phase 3: public PKCE client id for the CLI `login` command.
                // Optional so older servers (and the CLI mock harness) that
                // don't advertise it stay schema-valid; the login command
                // falls back to --client-id / APP_OAUTH_CLIENT_ID.
                cli_oauth_client_id: z.string().nullable().optional(),
            })
            .strict(),
    })
    .strict();

export type VersionData = z.infer<typeof VersionResponseSchema>["data"];
