import { BIN_NAME, KEYCHAIN_SERVICE } from "../branding.js";
import { CredentialStoreError } from "../credential/errors.js";
import type { CredentialStore } from "../credential/store.js";
import { ExitCode } from "../exit-codes.js";
import { deleteOAuthToken } from "../oauth/token-store.js";
import { canonicalOrigin } from "./canonical-origin.js";
import { ProfileResolutionError } from "./errors.js";
import type { DeleteProfileOptions, ProfileWriter } from "./writer.js";

export type DeleteProfileDeps = {
    writer: ProfileWriter;
    store: CredentialStore;
};

/**
 * credential の在り処。判別共用体にして「origin と reason が両方 null」
 * のような表現不能状態を型で排除する。
 */
export type CredentialLocation =
    | { kind: "located"; origin: string }
    | { kind: "unlocatable"; reason: string };

/**
 * 削除計画。**副作用ゼロ**で作られ、コマンド層は確認プロンプトの前にこれを
 * 組み立てて競合を検出する。
 */
export type ProfileDeletionPlan = {
    name: string;
    /**
     * 計画時に観測した `api_url` の**生値**。TOCTOU ガードの状態識別子。
     *
     * `credentials` はここからの派生であり、状態の同一性判定には使えない:
     * `unlocatable` の `reason` は複数の api_url が同じ文字列へ潰れる
     * (`ftp://a` と `ftp://b` はどちらも "Unsupported protocol: ftp:")。
     * `located` の `origin` も path 違いを吸収する。
     */
    apiUrl: string | undefined;
    credentials: CredentialLocation;
    wasDefault: boolean;
    /** 削除と同じ save で付け替える先 (無ければ null)。 */
    nextDefault: string | null;
    /** 削除後に残るプロファイル名。 */
    remaining: readonly string[];
    clearDefault: boolean;
};

export type DeleteProfileResult = {
    wasDefault: boolean;
    nextDefault: string | null;
    remaining: readonly string[];
    /** credential 破棄をスキップしたか (api_url が欠落/不正なとき true)。 */
    credentialsSkipped: boolean;
    /** credential index が壊れていたか (file backend でのみ到達しうる)。 */
    credentialIndexCorrupted: boolean;
};

/**
 * 削除計画を組み立てる。**config も credential も一切変更しない**。
 *
 * ここで投げる例外がそのまま CLI の事前検証エラーになる:
 *   - プロファイル不在      -> ProfileResolutionError.notFound  (exit 11)
 *   - default を守らず削除  -> ProfileResolutionError.conflict  (exit 10)
 *
 * **確認プロンプトより前に呼ぶこと**。後に呼ぶと、非 TTY 環境で
 * 「本当は exit 10 なのに確認が取れず exit 1」になる。
 * `executeProfileDeletion` は TOCTOU ガードとしてこれを**もう一度**呼ぶ。
 */
export function planProfileDeletion(
    writer: ProfileWriter,
    name: string,
    opts: { clearDefault: boolean },
): ProfileDeletionPlan {
    // 1 回の読み込みで entry / default / 全プロファイルを取る。
    // 3 回に分けて読むと、その間の書き替えで不整合な計画ができる。
    const state = writer.readState();
    const entry = state.profiles.find((row) => row.name === name)?.entry;
    if (entry === undefined) {
        throw ProfileResolutionError.notFound(
            `profile "${name}" not found in user config.`,
        );
    }

    const wasDefault = state.defaultProfile === name;
    const remaining = state.profiles
        .map((row) => row.name)
        .filter((candidate) => candidate !== name);

    if (wasDefault && !opts.clearDefault) {
        throw ProfileResolutionError.conflict(
            `profile "${name}" is the current default_profile. `
                + `Re-run with --clear-default, or point the default at `
                + `another profile first with \`${BIN_NAME} profile:use <name>\`.`,
        );
    }

    return {
        name,
        apiUrl: entry.api_url,
        credentials: locateCredentials(entry.api_url),
        wasDefault,
        nextDefault:
            wasDefault && remaining.length === 1 ? (remaining[0] ?? null) : null,
        remaining,
        clearDefault: opts.clearDefault,
    };
}

/**
 * 2 つの計画が同一状態から作られたかを判定する。
 * 確認プロンプト中に config が書き替わっていないかの検査に使う。
 */
function plansMatch(a: ProfileDeletionPlan, b: ProfileDeletionPlan): boolean {
    if (a.name !== b.name) return false;
    if (a.clearDefault !== b.clearDefault) return false;
    if (a.wasDefault !== b.wasDefault) return false;
    if (a.nextDefault !== b.nextDefault) return false;
    // 状態識別子は **api_url の生値**。派生値 (origin / reason) は多対一なので
    // ここを緩めると「確認待ちの間に config が変わっても消してしまう」経路が残る。
    if (a.apiUrl !== b.apiUrl) return false;
    // 以下は派生値の整合確認 (api_url が同じなら必ず一致するはずの不変条件)。
    if (a.credentials.kind !== b.credentials.kind) return false;
    if (
        a.credentials.kind === "located"
        && b.credentials.kind === "located"
        && a.credentials.origin !== b.credentials.origin
    ) {
        return false;
    }
    if (a.remaining.length !== b.remaining.length) return false;
    return a.remaining.every((v, i) => v === b.remaining[i]);
}

/**
 * 計画を実行する。credential -> config の順で落とす。
 *
 * **順序は反転させないこと**: credential の物理位置は
 * `deriveProfileHash12(canonicalOrigin(api_url), name)` から導出されるため、
 * config を先に消すと api_url を失い、credential ディレクトリを二度と
 * 特定できなくなる (永久に孤児化する)。
 *
 * **収束契約（限定つき）**:
 *   - 通常経路 / config 保存失敗 -> **同じコマンドの再実行で収束する**
 *     (credential 不在は 3 backend すべてで no-op 成功)
 *   - keychain の credential index 破損 -> **fail-closed**。config を残して
 *     exit 18 で停止し、**OS keychain の手動清掃という外部操作**を要求する。
 *     再実行だけでは収束しない (取りこぼした秘密を到達不能にしないための仕様)
 *   - 確認待ちの間に config が書き替わった -> **何も触らず exit 10** で停止
 */
export function executeProfileDeletion(
    deps: DeleteProfileDeps,
    plan: ProfileDeletionPlan,
): DeleteProfileResult {
    const { writer, store } = deps;
    const { name } = plan;

    // --- 0. TOCTOU ガード: 計画作成から今までに config が変わっていないか ---
    // 確認プロンプトの間に別プロセスが同名 profile の api_url を A -> B に
    // 書き替えると、古い計画のまま **A の credential を消して B の config を
    // 消す** = B の credential が孤児化する。credential に触れる前に再計画して
    // 突き合わせ、食い違ったら何もせず停止する。
    const current = planProfileDeletion(writer, name, {
        clearDefault: plan.clearDefault,
    });
    if (!plansMatch(plan, current)) {
        throw ProfileResolutionError.conflict(
            `profile "${name}" changed while this command was waiting for `
                + "confirmation (another process modified the config). "
                + "Nothing was deleted. Re-run the command.",
        );
    }

    // --- 1. credential を破棄する (config より先) ---
    let credentialIndexCorrupted = false;
    if (plan.credentials.kind === "unlocatable") {
        console.error(
            `Warning: profile "${name}" cannot be mapped to a credential `
                + `location because ${plan.credentials.reason}. `
                + `Removing the config entry only; inspect `
                + `~/.${BIN_NAME}/credentials manually if needed.`,
        );
    } else {
        credentialIndexCorrupted = clearCredentials(
            store,
            plan.credentials.origin,
            name,
        );
    }

    // --- 2. config を 1 回の save で落とす ---
    const writerOpts: DeleteProfileOptions = {
        clearDefault: plan.clearDefault,
    };
    // exactOptionalPropertyTypes: 未指定はプロパティ自体を省略する。
    if (plan.nextDefault !== null) writerOpts.nextDefault = plan.nextDefault;

    try {
        writer.deleteProfile(name, writerOpts);
    } catch (e) {
        if (plan.credentials.kind === "located") {
            const flag = plan.clearDefault ? " --clear-default" : "";
            console.error(
                `Error: credentials for profile "${name}" were destroyed but `
                    + "the config update failed. The profile entry is still "
                    + "present. Re-run to finish cleaning up:\n"
                    + `  ${BIN_NAME} profile:delete ${name}${flag} --yes`,
            );
        }
        throw e;
    }

    return {
        wasDefault: plan.wasDefault,
        nextDefault: plan.nextDefault,
        remaining: plan.remaining,
        credentialsSkipped: plan.credentials.kind === "unlocatable",
        credentialIndexCorrupted,
    };
}

/**
 * api_url から canonical origin を導く。欠落・不正なら **理由つきで
 * `unlocatable` を返す** (throw しない)。壊れた profile を削除できないほうが
 * 害が大きいため。計画フェーズから呼ばれるので **stderr へ書かない**
 * (副作用ゼロを守る)。
 */
function locateCredentials(apiUrl: string | undefined): CredentialLocation {
    if (apiUrl === undefined || apiUrl === "") {
        return { kind: "unlocatable", reason: "it has no api_url" };
    }
    try {
        return { kind: "located", origin: canonicalOrigin(apiUrl) };
    } catch (e) {
        // 「ad-hoc な as cast を入れない」規約に従い instanceof で絞る。
        const message = e instanceof Error ? e.message : String(e);
        return {
            kind: "unlocatable",
            reason: `its api_url is invalid (${message})`,
        };
    }
}

/**
 * credential (indexed items + credential index + OAuth token) を落とす。
 * 戻り値は「index が壊れていたか」。
 *
 * `CredentialStore.clearProfile()` は index に載った item と meta:index しか
 * 消さない。OAuth token bundle は **meta 名前空間の非 index エントリ**
 * (`oauth:token`) なので、keychain backend では clearProfile だけでは残る
 * (file backend はディレクトリごと消えるので結果的に消える)。
 * 3 backend で挙動を揃えるため deleteOAuthToken を明示的に呼ぶ。
 */
function clearCredentials(
    store: CredentialStore,
    origin: string,
    name: string,
): boolean {
    deleteOAuthToken(store, origin, name);
    const { complete, indexCorrupted } = store.purgeProfile(origin, name);
    if (!complete) {
        // ここで **config を消してはならない**。api_url を失うと、
        // 残った資格情報の在り処 (profile_hash12) を二度と導出できず、
        // OS keychain に到達不能な秘密が固定される。
        throw new CredentialStoreError(
            `the credential index for profile "${name}" is corrupted and the `
                + "OS keychain cannot be enumerated, so some credentials may "
                + "still be stored. The profile was NOT deleted (removing it "
                + "would make those credentials unreachable). Remove the "
                + `entries for keychain service "${KEYCHAIN_SERVICE}" `
                + "manually, then re-run this command.",
            ExitCode.CredentialStoreFailure,
        );
    }
    if (indexCorrupted) {
        console.error(
            `Warning: the credential index for profile "${name}" was `
                + "corrupted. The whole credential directory was removed "
                + "instead, so nothing is left behind.",
        );
    }
    return indexCorrupted;
}
