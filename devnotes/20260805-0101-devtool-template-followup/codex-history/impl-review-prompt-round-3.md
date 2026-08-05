# Round 3: Round 2 指摘への対応

Round 2 の [Warning] 2 件 (実質 1 論点) を**全件対応**した。見送りはゼロ。

## 対応

指摘の核心「`reason` は `api_url` を一意に表さない = 状態識別子として使うのが誤り」を受け入れ、
**計画に `api_url` の生値を持たせて、それを第一の状態識別子にした**。

- `ProfileDeletionPlan` に `apiUrl: string | undefined` を追加 (計画時に観測した生値)
- `plansMatch` の第一条件を `if (a.apiUrl !== b.apiUrl) return false;` に
- 多対一に潰れる派生値は状態識別子から降格:
  - `reason` 比較は**削除**した (`apiUrl` 比較に包含される)
  - `kind` / `origin` 比較は「api_url が同じなら一致するはず」の**派生整合チェック**として残した
- テストを 2 本追加
  - 5c-g: `ftp://a.example.com` → `ftp://b.example.com` (`reason` が同一に潰れるケース)
  - 5c-h: `https://a.example.com/v1` → `/v2` (`origin` が同一に潰れるケース)
  - 既存の 5c-f (`api_url` 未設定 → `ftp://x`) も残した

## 逆確認 (実測後 revert 済み)

| # | 改悪 | 期待 | 実測 |
|---|------|------|------|
| M8 | `plansMatch` から `if (a.apiUrl !== b.apiUrl) return false;` を削除 | 5c-f / 5c-g / 5c-h が赤 | **3 failed**: f / g / h の 3 本すべて |

## 副作用の自己申告

`api_url` の生値で比較するため、**origin が同じで path だけ変わった書き替え**でも
競合終了する (5c-h)。credential の在り処 (origin ベース) は同じなので「消しても実害は無い」
ケースまで止めることになるが、設計書の収束契約は
「確認待ちの間に config が書き替わった → **何も触らず exit 10**」であり、
fail-closed 側に倒すのが設計意図に一致すると判断した。ユーザーは再実行で収束する。
この判断に異論があれば指摘してほしい。

## 検証結果

```
pnpm typecheck:packages     : OK
pnpm test:packages          : 10 files / 106 passed / 0 failed  (Round 2 開始時 104)
pnpm -F "./packages/*" lint : OK
pnpm lint / pnpm typecheck  : OK
```

## Round 2 からの追加差分 (git diff)

```diff
diff --git a/packages/cli/src/profile/delete.ts b/packages/cli/src/profile/delete.ts
index a082107..d8a3d81 100644
--- a/packages/cli/src/profile/delete.ts
+++ b/packages/cli/src/profile/delete.ts
@@ -26,6 +26,15 @@ export type CredentialLocation =
  */
 export type ProfileDeletionPlan = {
     name: string;
+    /**
+     * 計画時に観測した `api_url` の**生値**。TOCTOU ガードの状態識別子。
+     *
+     * `credentials` はここからの派生であり、状態の同一性判定には使えない:
+     * `unlocatable` の `reason` は複数の api_url が同じ文字列へ潰れる
+     * (`ftp://a` と `ftp://b` はどちらも "Unsupported protocol: ftp:")。
+     * `located` の `origin` も path 違いを吸収する。
+     */
+    apiUrl: string | undefined;
     credentials: CredentialLocation;
     wasDefault: boolean;
     /** 削除と同じ save で付け替える先 (無ければ null)。 */
@@ -86,6 +95,7 @@ export function planProfileDeletion(
 
     return {
         name,
+        apiUrl: entry.api_url,
         credentials: locateCredentials(entry.api_url),
         wasDefault,
         nextDefault:
@@ -104,6 +114,10 @@ function plansMatch(a: ProfileDeletionPlan, b: ProfileDeletionPlan): boolean {
     if (a.clearDefault !== b.clearDefault) return false;
     if (a.wasDefault !== b.wasDefault) return false;
     if (a.nextDefault !== b.nextDefault) return false;
+    // 状態識別子は **api_url の生値**。派生値 (origin / reason) は多対一なので
+    // ここを緩めると「確認待ちの間に config が変わっても消してしまう」経路が残る。
+    if (a.apiUrl !== b.apiUrl) return false;
+    // 以下は派生値の整合確認 (api_url が同じなら必ず一致するはずの不変条件)。
     if (a.credentials.kind !== b.credentials.kind) return false;
     if (
         a.credentials.kind === "located"
diff --git a/packages/cli/tests/profile/delete.test.ts b/packages/cli/tests/profile/delete.test.ts
index 358c60f..adac4e3 100644
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
@@ -666,19 +693,93 @@ describe("確認待ち中の config 変更 (TOCTOU ガード)", () => {
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
 
+    it("g. 同じ reason になる別 api_url へ変わっても競合終了する", () => {
+        // ftp://a と ftp://b はどちらも canonicalOrigin が
+        // "Unsupported protocol: ftp:" を投げる = reason が同一に潰れる。
+        // 状態識別子として reason を使うとこの書き替えを見逃す。
+        const h = setupHarness("file-plaintext", {
+            profiles: {
+                broken: { api_url: "ftp://a.example.com" },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "staging",
+        });
+        const plan = planProfileDeletion(h.writer, "broken", {
+            clearDefault: false,
+        });
+        seedConfig(h.configPath, {
+            profiles: {
+                broken: { api_url: "ftp://b.example.com" },
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
+    it("h. 同じ origin になる別 api_url (path 違い) でも競合終了する", () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: { prod: { api_url: "https://a.example.com/v1" } },
+            defaultProfile: "prod",
+        });
+        const plan = planProfileDeletion(h.writer, "prod", {
+            clearDefault: true,
+        });
+        seedConfig(h.configPath, {
+            profiles: { prod: { api_url: "https://a.example.com/v2" } },
+            defaultProfile: "prod",
+        });
+
+        expect(() =>
+            executeProfileDeletion({ writer: h.writer, store: h.store }, plan),
+        ).toThrow(ProfileResolutionError);
+        expect(h.writer.get("prod")).toBeDefined();
+    });
+
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
@@ -845,14 +946,10 @@ describe("planProfileDeletion の事前検証", () => {
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
@@ -895,3 +992,50 @@ describe("OAuth token の破棄経路", () => {
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
