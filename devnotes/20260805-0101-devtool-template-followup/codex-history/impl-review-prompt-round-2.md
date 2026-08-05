# Round 2: Round 1 指摘への対応

Round 1 の [Warning] 4 件と [Suggestion] 1 件を**全件対応**した。見送りはゼロ。
以下が対応内容と、対応後の差分 (Round 1 のコードからの追加差分) である。

## 対応マトリクス

| # | 指摘 | 対応 |
|---|------|------|
| 1 | `assertProfileName` が `resolveContext` の後ろ | `const name = args.name; assertProfileName(name);` を `resolveContext` の**前**へ移動。理由をコメントで明記 |
| 2 | `plansMatch` が `unlocatable` の詳細を比較しない | `unlocatable` 同士は `reason` まで比較する分岐を追加。`reason` は `api_url` から決定的に導かれるので「api_url 未設定 → `ftp://x`」を検出できる。回帰テスト 5c-f を追加 |
| 3 | `purgeProfile` の `complete` が active backend ではなくフィールドの有無で判定 | `complete: this.primary() === this.fileStore` へ変更 (`primary()` と**同じ式**なので将来ずれない)。加えて「keychain 候補はあるが `isAvailable()` が false」のケースを固定するテストを追加 |
| 4 | テストの ad-hoc `as` cast | `expectProfileResolutionError` / `expectCredentialStoreError` / `captureThrow` を導入し `instanceof` で実 narrowing。`process.exit` mock も型付き `fakeExit` を先に定義して cast を撤去。**両テストファイルから `as` cast は 0 件になった** |
| 5 | CLI 契約 #4 が credential 無傷を見ていない | 一時 HOME 配下に plaintext credential を実際に書き、確認拒否後もファイルが残ることを検証 |

## テストで discriminate できない点の明示（自己申告）

- **#1**: 本コマンドは `resolveMode: "if-needed"` で、`new FileProfileWriter()` /
  `new CredentialStore()` はいずれも構築時に config を読まない。したがって
  `resolveContext` は現状の経路では実質的に失敗せず、順序を戻しても既存 CLI 契約
  テスト #3 は緑のままである。これは**テストで固定できない堅牢化**であり、
  その旨をコード内コメントに残した。追加テストで無理に mock を積むより
  「設計書の順序どおりに直す + 理由をコメントに残す」ほうが妥当と判断した。
  この判断に異論があれば指摘してほしい。
- **#3**: `CredentialStore` の constructor は
  `this.keychain = candidate !== null && candidate.isAvailable() ? candidate : null` なので、
  現時点では `this.keychain === null` と `primary() === this.fileStore` は同値。
  追加したテストは **constructor 不変条件そのもの**を固定するもので、
  式の書き換え単体を discriminate するわけではない。
  指摘の「どちらか」ではなく**両方**行った。

## 逆確認 (追加分。実測後 revert 済み)

| # | 改悪 | 期待 | 実測 |
|---|------|------|------|
| M7 | `plansMatch` から `unlocatable` の `reason` 比較を削除 | 5c-f が赤 | 1 failed: `f. unlocatable 同士でも理由が変われば競合終了する` |

Round 1 で報告した M1〜M6 は引き続き有効 (実装の当該箇所は変わっていない)。

## 検証結果 (対応後)

```
pnpm typecheck:packages     : OK
pnpm test:packages          : 10 files / 104 passed / 0 failed  (Round 1 時点 102)
pnpm -F "./packages/*" lint : OK
pnpm lint                   : OK
pnpm typecheck              : OK
```

## Round 1 からの追加差分 (git diff — packages/cli のみ)

```diff
diff --git a/packages/cli/src/credential/store.ts b/packages/cli/src/credential/store.ts
index dc8c9fa..43ef11e 100644
--- a/packages/cli/src/credential/store.ts
+++ b/packages/cli/src/credential/store.ts
@@ -334,9 +334,11 @@ export class CredentialStore {
             META_INDEX_ID,
         );
         this.fileStore.clearProfile(canonicalOrigin, profileName);
-        // keychain が primary のときだけ取りこぼしがありうる。
+        // keychain が primary のときだけ取りこぼしがありうる。判定は
+        // `primary()` と **同じ式**から導く (「keychain フィールドの有無」と
+        // 「実際に使われる backend」が将来ずれても嘘をつかないため)。
         return {
-            complete: this.keychain === null,
+            complete: this.primary() === this.fileStore,
             indexCorrupted: true,
         };
     }
diff --git a/packages/cli/src/oclif/commands/profile/delete.ts b/packages/cli/src/oclif/commands/profile/delete.ts
index 90771bd..c779c5b 100644
--- a/packages/cli/src/oclif/commands/profile/delete.ts
+++ b/packages/cli/src/oclif/commands/profile/delete.ts
@@ -37,9 +37,12 @@ export default class ProfileDelete extends ProfileCommand {
     public async run(): Promise<void> {
         const { args, flags } = await this.parse(ProfileDelete);
         this.latchCiFlag(flags.ci);
-        const { writer, store } = await this.resolveContext(flags);
+        // 名前検証は **resolveContext より前**。config / credential の初期化が
+        // 失敗しうる状態でも、不正な名前は必ず exit 13 で落ちる
+        // (設計書 §実装順序 の 1 番目)。
         const name = args.name;
         assertProfileName(name);
+        const { writer, store } = await this.resolveContext(flags);
 
         // 事前検証は **確認プロンプトより前**。ここで
         // profile 不在 (11) / default 競合 (10) が確定する。
diff --git a/packages/cli/src/profile/delete.ts b/packages/cli/src/profile/delete.ts
index a082107..1a3bb5c 100644
--- a/packages/cli/src/profile/delete.ts
+++ b/packages/cli/src/profile/delete.ts
@@ -112,6 +112,16 @@ function plansMatch(a: ProfileDeletionPlan, b: ProfileDeletionPlan): boolean {
     ) {
         return false;
     }
+    // unlocatable 同士も **理由まで**比較する。reason は api_url から決定的に
+    // 導かれるので、「api_url 未設定 -> ftp://x」のような書き替えを検出できる。
+    // ここを緩めると「確認待ちの間に config が変わっても消してしまう」経路が残る。
+    if (
+        a.credentials.kind === "unlocatable"
+        && b.credentials.kind === "unlocatable"
+        && a.credentials.reason !== b.credentials.reason
+    ) {
+        return false;
+    }
     if (a.remaining.length !== b.remaining.length) return false;
     return a.remaining.every((v, i) => v === b.remaining[i]);
 }
diff --git a/packages/cli/tests/commands/profile/delete.test.ts b/packages/cli/tests/commands/profile/delete.test.ts
index 681a57d..5974b0d 100644
--- a/packages/cli/tests/commands/profile/delete.test.ts
+++ b/packages/cli/tests/commands/profile/delete.test.ts
@@ -1,4 +1,4 @@
-import { mkdtempSync, rmSync } from "node:fs";
+import { existsSync, mkdtempSync, rmSync } from "node:fs";
 import { tmpdir } from "node:os";
 import { join } from "node:path";
 import { fileURLToPath } from "node:url";
@@ -6,8 +6,12 @@ import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
 import { BIN_NAME } from "../../../src/branding.js";
 import type { RootConfigInput } from "../../../src/config/schema.js";
 import { saveConfigToPath } from "../../../src/config/saver.js";
+import { FileStore } from "../../../src/credential/file-store.js";
+import { deriveProfileHash12 } from "../../../src/credential/key-derivation.js";
 import { ExitCode } from "../../../src/exit-codes.js";
 import ProfileDelete from "../../../src/oclif/commands/profile/delete.js";
+import { canonicalOrigin } from "../../../src/profile/canonical-origin.js";
+import { setGlobalAllowPlaintextFlag } from "../../../src/runtime-state.js";
 
 /**
  * `profile:delete` の **CLI 契約** テスト。
@@ -36,11 +40,21 @@ vi.mock("../../../src/credential/prompt.js", async (importOriginal) => {
 const CLI_ROOT = fileURLToPath(new URL("../../../", import.meta.url));
 
 const API_URL_A = "https://a.example.com";
+const ORIGIN_A = canonicalOrigin(API_URL_A);
 
 let home: string;
 let origHome: string | undefined;
 let exitCodes: number[];
 
+/**
+ * `process.exit` の差し替え実装。`as` cast を避けるため、`process.exit` と
+ * 同じシグネチャの関数として先に定義する。
+ */
+function fakeExit(code?: number | string | null | undefined): never {
+    exitCodes.push(typeof code === "number" ? code : Number(code ?? 0));
+    throw new Error(`EXIT:${String(code)}`);
+}
+
 function seedConfig(config: RootConfigInput): void {
     saveConfigToPath(join(home, `.${BIN_NAME}`, "config.yaml"), config);
 }
@@ -55,17 +69,13 @@ beforeEach(() => {
     // `process.exit` の最初の記録だけが本当の意図。BaseCommand.catch が
     // その throw を拾って **もう一度** exit(1) を呼ぶため、単純な
     // rejects.toThrow では常に 1 を見てしまう。
-    vi.spyOn(process, "exit").mockImplementation(((
-        code?: string | number | null,
-    ): never => {
-        exitCodes.push(typeof code === "number" ? code : Number(code ?? 0));
-        throw new Error(`EXIT:${String(code)}`);
-    }) as (code?: string | number | null) => never);
+    vi.spyOn(process, "exit").mockImplementation(fakeExit);
     vi.spyOn(console, "error").mockImplementation(() => {});
 });
 
 afterEach(() => {
     vi.restoreAllMocks();
+    setGlobalAllowPlaintextFlag(false);
     rmSync(home, { recursive: true, force: true });
     if (origHome !== undefined) process.env["HOME"] = origHome;
     else delete process.env["HOME"];
@@ -127,6 +137,18 @@ describe("profile:delete — CLI 契約", () => {
                 staging: { api_url: API_URL_A },
             },
         });
+        // 拒否時に credential へ触れていないことまで見る (契約の直接固定)。
+        setGlobalAllowPlaintextFlag(true);
+        const credDir = join(home, `.${BIN_NAME}`, "credentials");
+        const seedStore = new FileStore(credDir);
+        seedStore.write(ORIGIN_A, "prod", "apikey", "", "prod-secret");
+        const credPath = seedStore.datPath(
+            deriveProfileHash12(ORIGIN_A, "prod"),
+            "apikey",
+            "",
+        );
+        expect(existsSync(credPath)).toBe(true);
+
         await expect(runDelete(["prod"])).rejects.toThrow(/EXIT:/);
         expect(exitCodes[0]).toBe(ExitCode.GeneralError);
         expect(confirmSpy).toHaveBeenCalledTimes(1);
@@ -135,6 +157,7 @@ describe("profile:delete — CLI 契約", () => {
             "../../../src/profile/writer.js"
         );
         expect(new FileProfileWriter().get("prod")).toBeDefined();
+        expect(existsSync(credPath)).toBe(true);
     });
 
     it("5. --yes 付きの正常削除では exit せず config から消える", async () => {
diff --git a/packages/cli/tests/profile/delete.test.ts b/packages/cli/tests/profile/delete.test.ts
index 358c60f..9b9d1ad 100644
--- a/packages/cli/tests/profile/delete.test.ts
+++ b/packages/cli/tests/profile/delete.test.ts
@@ -15,7 +15,7 @@ import {
     resetGlobalMasterKeyRegistryForTests,
 } from "../../src/credential/master-key-registry.js";
 import { CredentialStore } from "../../src/credential/store.js";
-import { ExitCode } from "../../src/exit-codes.js";
+import { ExitCode, type ExitCodeValue } from "../../src/exit-codes.js";
 import {
     deleteOAuthToken,
     readOAuthToken,
@@ -224,6 +224,39 @@ function silenceStderr(): void {
     vi.spyOn(console, "error").mockImplementation(() => {});
 }
 
+/**
+ * `as` cast を使わずに例外の型と exit code を検証する。
+ * (`instanceof` で実際に narrowing する = TS 規約「ad-hoc な as cast を
+ * 新規導入しない」に合わせる)
+ */
+function expectProfileResolutionError(
+    thrown: unknown,
+    exitCode: ExitCodeValue,
+): void {
+    expect(thrown).toBeInstanceOf(ProfileResolutionError);
+    if (!(thrown instanceof ProfileResolutionError)) throw thrown;
+    expect(thrown.exitCode).toBe(exitCode);
+}
+
+function expectCredentialStoreError(
+    thrown: unknown,
+    exitCode: ExitCodeValue,
+): void {
+    expect(thrown).toBeInstanceOf(CredentialStoreError);
+    if (!(thrown instanceof CredentialStoreError)) throw thrown;
+    expect(thrown.exitCode).toBe(exitCode);
+}
+
+/** 例外を捕らえて返す (throw されなければ null)。 */
+function captureThrow(fn: () => void): unknown {
+    try {
+        fn();
+    } catch (e) {
+        return e;
+    }
+    return null;
+}
+
 // ---------------------------------------------------------------------------
 // 1 / 2 / 4 / 5: backend 横断
 // ---------------------------------------------------------------------------
@@ -364,14 +397,10 @@ describe("default_profile transitions", () => {
         await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
         const before = readFileSync(h.configPath, "utf-8");
 
-        let thrown: unknown = null;
-        try {
-            planProfileDeletion(h.writer, "prod", { clearDefault: false });
-        } catch (e) {
-            thrown = e;
-        }
-        expect(thrown).toBeInstanceOf(ProfileResolutionError);
-        expect((thrown as ProfileResolutionError).exitCode).toBe(
+        expectProfileResolutionError(
+            captureThrow(() => {
+                planProfileDeletion(h.writer, "prod", { clearDefault: false });
+            }),
             ExitCode.ProfileConflict,
         );
         // config も credential も変わらない (計画フェーズは副作用ゼロ)。
@@ -519,17 +548,15 @@ describe("壊れた profile / 破損 index", () => {
         h.primary.write(ORIGIN_A, "prod", "meta", "index", "{not json");
         silenceStderr();
 
-        let thrown: unknown = null;
-        try {
-            executeProfileDeletion(
-                { writer: h.writer, store: h.store },
-                planProfileDeletion(h.writer, "prod", { clearDefault: true }),
-            );
-        } catch (e) {
-            thrown = e;
-        }
-        expect(thrown).toBeInstanceOf(CredentialStoreError);
-        expect((thrown as CredentialStoreError).exitCode).toBe(
+        expectCredentialStoreError(
+            captureThrow(() => {
+                executeProfileDeletion(
+                    { writer: h.writer, store: h.store },
+                    planProfileDeletion(h.writer, "prod", {
+                        clearDefault: true,
+                    }),
+                );
+            }),
             ExitCode.CredentialStoreFailure,
         );
         // config を消すと api_url を失い、keychain の秘密が到達不能になる。
@@ -666,19 +693,45 @@ describe("確認待ち中の config 変更 (TOCTOU ガード)", () => {
         });
         h.writer.deleteProfile("prod", { clearDefault: true });
 
-        let thrown: unknown = null;
-        try {
-            executeProfileDeletion({ writer: h.writer, store: h.store }, plan);
-        } catch (e) {
-            thrown = e;
-        }
-        expect(thrown).toBeInstanceOf(ProfileResolutionError);
-        expect((thrown as ProfileResolutionError).exitCode).toBe(
+        expectProfileResolutionError(
+            captureThrow(() => {
+                executeProfileDeletion(
+                    { writer: h.writer, store: h.store },
+                    plan,
+                );
+            }),
             ExitCode.ProfileNotFound,
         );
         expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBe("secret-a");
     });
 
+    it("f. unlocatable 同士でも理由が変われば競合終了する", () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: { broken: {}, staging: { api_url: API_URL_A } },
+            defaultProfile: "staging",
+        });
+        const plan = planProfileDeletion(h.writer, "broken", {
+            clearDefault: false,
+        });
+        expect(plan.credentials.kind).toBe("unlocatable");
+
+        // api_url 未設定 -> 非 http(s) スキーム。どちらも unlocatable だが、
+        // credential の在り処に関する前提は変わっている。
+        seedConfig(h.configPath, {
+            profiles: {
+                broken: { api_url: "ftp://x.example.com" },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "staging",
+        });
+        silenceStderr();
+
+        expect(() =>
+            executeProfileDeletion({ writer: h.writer, store: h.store }, plan),
+        ).toThrow(ProfileResolutionError);
+        expect(h.writer.get("broken")).toBeDefined();
+    });
+
     it("e. 何も変わっていなければ正常に削除される (誤検知しない)", async () => {
         const h = setupHarness("file-plaintext", {
             profiles: {
@@ -845,14 +898,10 @@ describe("planProfileDeletion の事前検証", () => {
             profiles: { prod: { api_url: API_URL_A } },
             defaultProfile: "prod",
         });
-        let thrown: unknown = null;
-        try {
-            planProfileDeletion(h.writer, "ghost", { clearDefault: true });
-        } catch (e) {
-            thrown = e;
-        }
-        expect(thrown).toBeInstanceOf(ProfileResolutionError);
-        expect((thrown as ProfileResolutionError).exitCode).toBe(
+        expectProfileResolutionError(
+            captureThrow(() => {
+                planProfileDeletion(h.writer, "ghost", { clearDefault: true });
+            }),
             ExitCode.ProfileNotFound,
         );
     });
@@ -895,3 +944,50 @@ describe("OAuth token の破棄経路", () => {
         expect(readOAuthToken(h.store, ORIGIN_A, "prod")).toBeNull();
     });
 });
+
+// ---------------------------------------------------------------------------
+// CredentialStore の active backend 不変条件
+// ---------------------------------------------------------------------------
+
+describe("purgeProfile の complete 判定は active backend に従う", () => {
+    it("keychain 候補があっても利用不能なら file backend として完遂する", async () => {
+        // beforeEach で DISABLE_KEYCHAIN=1 のまま keychain 候補を注入する。
+        // isAvailable() が false なので primary は file backend になる。
+        const tmp = makeTmp();
+        const configPath = join(tmp, "config.yaml");
+        const credDir = join(tmp, "credentials");
+        seedConfig(configPath, {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        setGlobalAllowPlaintextFlag(true);
+        const fileStore = new FileStore(credDir, getGlobalMasterKeyRegistry());
+        const store = new CredentialStore({
+            keychain: fakeKeychain(new Map<string, string>()),
+            fileStore,
+        });
+        expect(store.backend()).not.toBe("keychain");
+
+        const writer = new FileProfileWriter(configPath);
+        await store.writeWithPreflight(
+            ORIGIN_A,
+            "prod",
+            "apikey",
+            "",
+            "prod-secret",
+            { printBanner: false },
+        );
+        fileStore.write(ORIGIN_A, "prod", "meta", "index", "{not json");
+        silenceStderr();
+
+        const result = executeProfileDeletion(
+            { writer, store },
+            planProfileDeletion(writer, "prod", { clearDefault: true }),
+        );
+        expect(result.credentialIndexCorrupted).toBe(true);
+        expect(writer.get("prod")).toBeUndefined();
+    });
+});
```

再レビューし、最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記すること。
