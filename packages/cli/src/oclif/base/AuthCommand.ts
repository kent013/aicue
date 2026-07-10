import type { CredentialStore } from "../../credential/store.js";
import { ExitCode, exitWith } from "../../exit-codes.js";
import type { ResolvedProfileContext } from "../../profile/context.js";
import { BaseCommand } from "./BaseCommand.js";
import { profileFlags } from "./flags.js";
import { resolveProfileBundle } from "./profile-context.js";

/**
 * `auth:*` (OAuth ログイン系) コマンドの基底クラス。
 *
 * `login` / `logout` / `status` はいずれも「named profile を解決し、その
 * canonical origin に紐づく OAuth トークンを読み書きする」という同一の
 * 前段処理を持つ。ここに集約して各コマンドは本体ロジックだけを持つ。
 *
 * OAuth トークンは named profile 単位で永続化するため、既定では ephemeral
 * (`--api-url`) を禁止する (`persistentRequired = true`)。`status` のように
 * API キー profile でも動くコマンドはこれを false に override する。
 */
export abstract class AuthCommand extends BaseCommand {
    static override baseFlags = {
        ...BaseCommand.baseFlags,
        profile: profileFlags.profile,
        "api-url": profileFlags["api-url"],
        "allow-plaintext-credentials":
            profileFlags["allow-plaintext-credentials"],
    };

    /** OAuth トークンは named profile 前提。既定で ephemeral を拒否する。 */
    protected persistentRequired: boolean = true;

    protected async resolveOAuthContext(
        flags: {
            profile?: string | undefined;
            "api-url"?: string | undefined;
            "api-key"?: string | undefined;
            "allow-plaintext-credentials"?: boolean | undefined;
        },
        options: { printBanner?: boolean } = {},
    ): Promise<{ ctx: ResolvedProfileContext; store: CredentialStore }> {
        const { ctx, store } = await resolveProfileBundle(
            {
                profile: flags.profile,
                apiUrl: flags["api-url"],
                apiKey: flags["api-key"],
                allowPlaintextCredentials: flags["allow-plaintext-credentials"],
            },
            {
                resolveMode: "always",
                persistentRequired: this.persistentRequired,
                ...(options.printBanner !== undefined
                    ? { printBanner: options.printBanner }
                    : {}),
            },
        );
        if (ctx === null) {
            // resolveMode="always" は非 null を保証するので防御的。
            exitWith(ExitCode.GeneralError);
        }
        return { ctx, store };
    }
}
