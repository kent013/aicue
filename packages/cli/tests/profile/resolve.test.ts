import { ENV } from "../../src/branding.js";
import { describe, expect, it, vi } from "vitest";
import type { RootConfigInput } from "../../src/config/schema.js";
import { ExitCode } from "../../src/exit-codes.js";
import { ProfileResolutionError } from "../../src/profile/errors.js";
import { resolveProfile } from "../../src/profile/resolve.js";

const userWithProd: RootConfigInput = {
    profiles: {
        prod: { api_url: "https://prod.example/api/v1" },
        stg: { api_url: "https://stg.example/api/v1" },
    },
    default_profile: "prod",
};

describe("resolveProfile", () => {
    it("--profile wins and resolves to named ctx", async () => {
        const errSpy = vi
            .spyOn(console, "error")
            .mockImplementation(() => {});
        try {
            const ctx = await resolveProfile(
                { profile: "stg" },
                {},
                userWithProd,
                undefined,
            );
            expect(ctx.kind).toBe("named");
            expect(ctx.name).toBe("stg");
            expect(ctx.resolvedBy).toBe("argv-profile");
        } finally {
            errSpy.mockRestore();
        }
    });

    it("--profile + --api-url throws ProfileResolutionError (exit 10)", async () => {
        // C2 / T144: core layer throws; command layer translates to
        // `exitWith(ExitCode.ProfileConflict)`. The test asserts on the
        // carried exit code so the public CI contract is preserved.
        await expect(
            resolveProfile(
                { profile: "prod", apiUrl: "https://x.example/api" },
                {},
                userWithProd,
                undefined,
            ),
        ).rejects.toMatchObject({ exitCode: ExitCode.ProfileConflict });
    });

    it(`${ENV.PROFILE} + ${ENV.API_URL} mismatch throws ProfileResolutionError (exit 10)`, async () => {
        await expect(
            resolveProfile(
                {},
                {
                    [ENV.PROFILE]: "prod",
                    [ENV.API_URL]: "https://other.example/api",
                },
                userWithProd,
                undefined,
            ),
        ).rejects.toMatchObject({ exitCode: ExitCode.ProfileConflict });
    });

    it("--api-url yields ephemeral ctx", async () => {
        const ctx = await resolveProfile(
            { apiUrl: "https://eph.example/api" },
            {},
            userWithProd,
            undefined,
        );
        expect(ctx.kind).toBe("ephemeral");
        expect(ctx.resolvedBy).toBe("argv-api-url");
        expect(ctx.name.startsWith("ephemeral-")).toBe(true);
    });

    it(`env ${ENV.API_URL} without profile yields ephemeral`, async () => {
        const ctx = await resolveProfile(
            {},
            { [ENV.API_URL]: "https://eph.example/api" },
            undefined,
            undefined,
        );
        expect(ctx.kind).toBe("ephemeral");
        expect(ctx.resolvedBy).toBe(`env-${ENV.API_URL}`);
    });

    it("falls back through project.profile → default_profile", async () => {
        const ctx = await resolveProfile(
            {},
            {},
            userWithProd,
            { profile: "stg" },
        );
        expect(ctx.name).toBe("stg");
        expect(ctx.resolvedBy).toBe("project-profile");
    });

    it("profile not found throws ProfileResolutionError (exit 11)", async () => {
        await expect(
            resolveProfile(
                { profile: "missing" },
                {},
                userWithProd,
                undefined,
            ),
        ).rejects.toMatchObject({ exitCode: ExitCode.ProfileNotFound });
    });

    it("uses builtin-production when nothing else set (throws when config lacks it)", async () => {
        // No config + no env → falls back to "production", which is
        // not defined → ProfileResolutionError.notFound.
        await expect(
            resolveProfile({}, {}, undefined, undefined),
        ).rejects.toBeInstanceOf(ProfileResolutionError);
    });

    it("uses apiKeyLoader (awaited) for named ctx when argv/env are absent", async () => {
        const loader = vi.fn(() => Promise.resolve("loaded-key"));
        const ctx = await resolveProfile(
            { profile: "prod" },
            {},
            userWithProd,
            undefined,
            loader,
        );
        expect(ctx.apiKey).toBe("loaded-key");
        expect(loader).toHaveBeenCalledTimes(1);
    });
});
