【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 (AGENTS.md)】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

Laravel + Svelte アプリの **supply-chain 依存更新 (npm lockfile)** の実装レビュアー。
本 TODO (T111) は詳細設計「施策 D1: 未受容 advisory 4 件の upgrade」の実装であり、
アプリコード (app/ resources/ tests/ routes/) の変更は**ゼロ**、変更は依存宣言と lockfile のみ。

レビュー観点:
1. **設計との一致性** — 設計で指定されたバージョン・経路どおりか。設計外の逸脱がないか
2. **advisory の実解消** — 4 件 (undici 3 件 + valibot 1 件) が実際に解消される版に上がっているか。
   `pnpm.overrides` 等の「gate は緑になるが実依存とずれる」迂回をしていないか
3. **accept-risk への逃げがないか** — `docs/supply-chain/accepted-advisories.yaml` は空 (`[]`) のままか
4. **付随ドリフトの妥当性** — lockfile に混入した無関係な再解決 (peer edge の再計算等) が
   危険な範囲まで広がっていないか
5. **テスト網羅性** — 依存更新に対する回帰網が十分か (この変更に固有のテストを書くべきか、
   既存の gate/契約テストで足りるか)
6. 禁止事項違反の有無

出力形式:
- ファイルごとに判定
- 指摘は [Critical] / [Warning] / [Suggestion] に分類
- 最後に全体判定を `APPROVED` または `CHANGES_REQUESTED` の 1 行で明記

---
## user: 詳細設計書 (該当節: 施策 D1 のみ。A/B/C は本 TODO のスコープ外)

```markdown
# 施策 D1: 未受容 advisory 4 件の upgrade

### 変更箇所

- `packages/cli/package.json`（`undici` の解決版を上げる。**caret 範囲内なので宣言変更は不要**）
- `package.json`（`eslint-plugin-better-tailwindcss` の厳密 pin `4.4.1` → `4.7.0`）
- `pnpm-lock.yaml`

### 実測（レジストリ照会で確認済み）

| eco | パッケージ | 現在 | 修正版 | 経路 | 確認 |
|---|---|---|---|---|---|
| npm | `undici` (GHSA-8xcm-r25x-g524 / -m8rv-5g2x-5cg5 / -v3r7-h72x-cjcm) | 6.27.0 | **>= 6.28.0** | `packages/cli` の直接依存 `"undici": "^6.27.0"` | `pnpm view undici versions` に **6.28.0 が存在**。caret 範囲内なので `pnpm update undici` で解決 |
| npm | `valibot` (GHSA-5qjj-4xww-7phc) | 1.4.1 | >= 1.4.2 | `eslint-plugin-better-tailwindcss@4.4.1`（root で厳密 pin）の推移依存 | `pnpm view eslint-plugin-better-tailwindcss@4.7.0 dependencies` → **`valibot: ^1.4.2`** を確認 |

現在の `pnpm run audit:gate` は **PASS（moderate 4 / high 0 / critical 0 / accept-risk 0）**。
`docs/supply-chain/accepted-advisories.yaml` は空（`[]`）= 受容した負債はゼロ。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし（既存の `audit:gate` が受入判定）
- **`eslint-plugin-better-tailwindcss` は ESLint ルールの実装**なので、pin を上げると
  **lint の指摘内容が変わりうる**（4.4.1 → 4.7.0 は minor 3 つ分）

### 手順

```bash
# 1) undici (caret 範囲内 = 宣言変更なし)
pnpm -F @app/cli update undici
# 2) eslint plugin の pin 上げ (厳密 pin なので package.json の宣言を書き換える)
#    "eslint-plugin-better-tailwindcss": "4.4.1" → "4.7.0"
pnpm install
# 3) 受入判定
pnpm run audit:gate         # → Total advisories: 0
pnpm lint                   # → 指摘 0 (増えていたら是正)
pnpm test:packages && pnpm build:packages && pnpm typecheck:packages
```

### zod の major 分裂について（**次サイクル送り**）

root `package.json` は `zod: ^4.4.3`（devDependency）、`packages/cli/package.json` は
`zod: ^3.23.0`（runtime dependency）。AGENTS.md 思考原則 3「後方互換の並走を残さない」に
照らすと逸脱に近いが、**本バッチには含めない**:

- 解消には `packages/cli` のスキーマ定義を v3 → v4 へ**移行するコード変更**が必要で、
  「保守」の粒度を超える（本バッチは lockfile 更新に留める）
- `audit:gate` は緑であり、セキュリティ上の緊急性がない
- どのゲートも検出していない = 誰も気づかない負債なので、**TODO 化して追跡する**のが正しい対処

### PHPStan 適合チェック

対象外（npm 依存のみ）。

### テスト計画

- [x] 受入条件: `pnpm run audit:gate` → **Total advisories: 0** / accept-risk 0（`accepted-advisories.yaml` は空のまま）
- [x] 既存テストの更新: なし
- [x] 回帰確認: `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
      `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` が全緑
- [x] `scripts/audit-gate.contract.test.ts` が緑のまま（gate 自体の契約は変えない）
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

| リスク | 対処 |
|---|---|
| `eslint-plugin-better-tailwindcss` 4.4.1 → 4.7.0 で lint 指摘が増え、既存コードが赤くなる | 受入条件に `pnpm lint` 緑を含める。指摘が出たら**コード側を是正**する（ルールを無効化しない）。**分割の判断基準**: 是正が**コード修正 5 ファイルを超える**なら、D1 を `undici` のみに縮小し、plugin の pin 上げは**別 TODO へ分離**する。`pnpm.overrides` による valibot の強制引き上げは**採らない**（supply-chain の追跡が複雑になり、`audit:gate` の判定と実依存関係がずれる） |
| `undici` 6.28.0 で `packages/cli` の HTTP 挙動が変わる | patch/minor 更新であり、`pnpm test:packages`（106 tests）が回帰網。緑を受入条件にする |
| lockfile 変更が他グループと衝突する | **D1 を単独 TODO にする**（下記 実装モード）。lockfile を触るのはこの TODO だけ |

```

## 実装差分 (git diff。アプリコードの変更はゼロ)

```diff
diff --git a/package.json b/package.json
index aa0f22d..e4c6d0c 100644
--- a/package.json
+++ b/package.json
@@ -36,7 +36,7 @@
         "@vitest/ui": "^4.1.8",
         "autoprefixer": "^10.5.0",
         "eslint": "^10.8.0",
-        "eslint-plugin-better-tailwindcss": "4.4.1",
+        "eslint-plugin-better-tailwindcss": "4.7.0",
         "eslint-plugin-svelte": "^3.22.0",
         "globals": "^17.9.0",
         "happy-dom": "^20.10.2",
diff --git a/packages/cli/package.json b/packages/cli/package.json
index 761a981..a3fa020 100644
--- a/packages/cli/package.json
+++ b/packages/cli/package.json
@@ -42,7 +42,7 @@
         "@oclif/core": "^4.13.3",
         "@oclif/plugin-help": "^6.2.56",
         "envinfo": "^7.21.0",
-        "undici": "^6.27.0",
+        "undici": "^6.28.0",
         "yaml": "^2.9.0",
         "zod": "^3.23.0"
     },
diff --git a/pnpm-lock.yaml b/pnpm-lock.yaml
index b4ea8ec..74628b6 100644
--- a/pnpm-lock.yaml
+++ b/pnpm-lock.yaml
@@ -43,7 +43,7 @@ importers:
         version: 25.9.2
       '@typescript-eslint/parser':
         specifier: ^8.66.0
-        version: 8.66.0(eslint@10.8.0(jiti@2.7.0))(typescript@6.0.3)
+        version: 8.66.0(eslint@10.8.0(jiti@2.7.0)(supports-color@8.1.1))(supports-color@8.1.1)(typescript@6.0.3)
       '@vitest/coverage-v8':
         specifier: ^4.1.8
         version: 4.1.9(vitest@4.1.8)
@@ -55,13 +55,13 @@ importers:
         version: 10.5.2(postcss@8.5.25)
       eslint:
         specifier: ^10.8.0
-        version: 10.8.0(jiti@2.7.0)
+        version: 10.8.0(jiti@2.7.0)(supports-color@8.1.1)
       eslint-plugin-better-tailwindcss:
-        specifier: 4.4.1
-        version: 4.4.1(eslint@10.8.0(jiti@2.7.0))(tailwindcss@4.3.0)(typescript@6.0.3)
+        specifier: 4.7.0
+        version: 4.7.0(eslint@10.8.0(jiti@2.7.0)(supports-color@8.1.1))(tailwindcss@4.3.0)(typescript@6.0.3)
       eslint-plugin-svelte:
         specifier: ^3.22.0
-        version: 3.22.0(eslint@10.8.0(jiti@2.7.0))(svelte@5.56.3(@typescript-eslint/types@8.66.0))
+        version: 3.22.0(eslint@10.8.0(jiti@2.7.0)(supports-color@8.1.1))(svelte@5.56.3(@typescript-eslint/types@8.66.0))
       globals:
         specifier: ^17.9.0
         version: 17.9.0
@@ -70,7 +70,7 @@ importers:
         version: 20.10.6
       jsdom:
         specifier: ^27.4.0
-        version: 27.4.0
+        version: 27.4.0(supports-color@8.1.1)
       laravel-vite-plugin:
         specifier: ^3.1.0
         version: 3.1.0(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0))
@@ -100,7 +100,7 @@ importers:
         version: 8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0)
       vitest:
         specifier: ^4.1.8
-        version: 4.1.8(@types/node@25.9.2)(@vitest/coverage-v8@4.1.9)(@vitest/ui@4.1.9)(happy-dom@20.10.6)(jsdom@27.4.0)(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0))
+        version: 4.1.8(@types/node@25.9.2)(@vitest/coverage-v8@4.1.9)(@vitest/ui@4.1.9)(happy-dom@20.10.6)(jsdom@27.4.0(supports-color@8.1.1))(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0))
       yaml:
         specifier: ^2.9.0
         version: 2.9.0
@@ -123,8 +123,8 @@ importers:
         specifier: ^7.21.0
         version: 7.21.0
       undici:
-        specifier: ^6.27.0
-        version: 6.27.0
+        specifier: ^6.28.0
+        version: 6.28.0
       yaml:
         specifier: ^2.9.0
         version: 2.9.0
@@ -1294,6 +1294,10 @@ packages:
     resolution: {integrity: sha512-yJN/BOOLxcOW2aQgeif9mSnaUB8KtvmMMp56oA1kx1CRfBKbhZm2pJ+NBY+3eOboHxix8lfjWpHE0Ei5U8RbSA==}
     engines: {node: '>=10.13.0'}
 
+  enhanced-resolve@5.24.5:
+    resolution: {integrity: sha512-L1l8TNvomm6UVW5B253AGxQagSQr+vGwhMlrrfRS2qmhx46AMpMVJKQYLvWYbysTMY8VoicOvzHzoHMbyzB+4A==}
+    engines: {node: '>=10.13.0'}
+
   entities@7.0.1:
     resolution: {integrity: sha512-TWrgLOFUQTH994YUyl1yT4uyavY5nNB5muff+RtWaqNVCAK408b5ZnnbNAUEWLTCpum9w6arT70i1XdQ4UeOPA==}
     engines: {node: '>=0.12'}
@@ -1326,8 +1330,8 @@ packages:
     resolution: {integrity: sha512-TtpcNJ3XAzx3Gq8sWRzJaVajRs0uVxA2YAkdb1jm2YkPz4G6egUFAyA3n5vtEIZefPk5Wa4UXbKuS5fKkJWdgA==}
     engines: {node: '>=10'}
 
-  eslint-plugin-better-tailwindcss@4.4.1:
-    resolution: {integrity: sha512-ueFciTgj2M+4YklYdtvpbMA3Nn22z60sQoSA4bnctOP4h0daUhJKAsDaGi888N00qWtIUqeK5Ikt6xnNnHPg2g==}
+  eslint-plugin-better-tailwindcss@4.7.0:
+    resolution: {integrity: sha512-lrdlVW4pzLPj/zX5HRqMhKPesGijDfRhnSRJMlNcsrCyJHsndmHCLKTiRNa1eREUZ6G3D3QjdLc+G4tlfGrmkw==}
     engines: {node: ^20.19.0 || ^22.12.0 || >=23.0.0}
     peerDependencies:
       eslint: ^7.0.0 || ^8.0.0 || ^9.0.0 || ^10.0.0
@@ -2186,8 +2190,8 @@ packages:
   undici-types@7.24.6:
     resolution: {integrity: sha512-WRNW+sJgj5OBN4/0JpHFqtqzhpbnV0GuB+OozA9gCL7a993SmU+1JBZCzLNxYsbMfIeDL+lTsphD5jN5N+n0zg==}
 
-  undici@6.27.0:
-    resolution: {integrity: sha512-YmfV3YnEDzXRC5lZ2jWtWWHKGUm1zIt8AhesR1tens+HTNv+YZlN/dp6G727LOvMJ8xjP9Be7Y2Sdr96LDm+pg==}
+  undici@6.28.0:
+    resolution: {integrity: sha512-LIY910g9TI13YS95lrMFrs8Rm/u/irgHeTWoKCoteeJ04CUJ92eEfj0rVn+7VKMPBpUPiUoBKfhNyLI23EE/KA==}
     engines: {node: '>=18.17'}
 
   update-browserslist-db@1.2.3:
@@ -2202,8 +2206,8 @@ packages:
   util-deprecate@1.0.2:
     resolution: {integrity: sha512-EPD5q1uXyFxJpCrLnCc1nHnq3gOa6DZBocAIiI2TaSCA7VCJ1UJDMagCzIkXNsUYfD1daK//LTEQ8xiIbrHtcw==}
 
-  valibot@1.4.1:
-    resolution: {integrity: sha512-klCmFTz2jeDluy9RwX+F884TCiogtdBJ/YaxSx1EOBYXa3NXNWj8kR1jjN8rzluwojJVWWaHJ4r1U5LfICnM3g==}
+  valibot@1.4.2:
+    resolution: {integrity: sha512-gjdCvJ6d3RyHAneqxMYMW9QMCwYMb3jpOO0IyHZV1bnRHFBHrX3VkIILt5XYR0WhwHiH7Mty8ovuPZ/O3gamrg==}
     peerDependencies:
       typescript: '>=5'
     peerDependenciesMeta:
@@ -2607,14 +2611,14 @@ snapshots:
   '@esbuild/win32-x64@0.28.1':
     optional: true
 
-  '@eslint-community/eslint-utils@4.10.1(eslint@10.8.0(jiti@2.7.0))':
+  '@eslint-community/eslint-utils@4.10.1(eslint@10.8.0(jiti@2.7.0)(supports-color@8.1.1))':
     dependencies:
-      eslint: 10.8.0(jiti@2.7.0)
+      eslint: 10.8.0(jiti@2.7.0)(supports-color@8.1.1)
       eslint-visitor-keys: 3.4.3
 
   '@eslint-community/regexpp@4.12.2': {}
 
-  '@eslint/config-array@0.23.5':
+  '@eslint/config-array@0.23.5(supports-color@8.1.1)':
     dependencies:
       '@eslint/object-schema': 3.0.5
       debug: 4.4.3(supports-color@8.1.1)
@@ -3004,7 +3008,7 @@ snapshots:
       dom-accessibility-api: 0.6.3
       picocolors: 1.1.1
       redent: 3.0.0
-      vitest: 4.1.8(@types/node@25.9.2)(@vitest/coverage-v8@4.1.9)(@vitest/ui@4.1.9)(happy-dom@20.10.6)(jsdom@27.4.0)(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0))
+      vitest: 4.1.8(@types/node@25.9.2)(@vitest/coverage-v8@4.1.9)(@vitest/ui@4.1.9)(happy-dom@20.10.6)(jsdom@27.4.0(supports-color@8.1.1))(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0))
 
   '@testing-library/svelte-core@1.0.0(svelte@5.56.3(@typescript-eslint/types@8.66.0))':
     dependencies:
@@ -3017,7 +3021,7 @@ snapshots:
       svelte: 5.56.3(@typescript-eslint/types@8.66.0)
     optionalDependencies:
       vite: 8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0)
-      vitest: 4.1.8(@types/node@25.9.2)(@vitest/coverage-v8@4.1.9)(@vitest/ui@4.1.9)(happy-dom@20.10.6)(jsdom@27.4.0)(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0))
+      vitest: 4.1.8(@types/node@25.9.2)(@vitest/coverage-v8@4.1.9)(@vitest/ui@4.1.9)(happy-dom@20.10.6)(jsdom@27.4.0(supports-color@8.1.1))(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0))
 
   '@tsconfig/svelte@5.0.8': {}
 
@@ -3055,19 +3059,19 @@ snapshots:
     dependencies:
       '@types/node': 25.9.2
 
-  '@typescript-eslint/parser@8.66.0(eslint@10.8.0(jiti@2.7.0))(typescript@6.0.3)':
+  '@typescript-eslint/parser@8.66.0(eslint@10.8.0(jiti@2.7.0)(supports-color@8.1.1))(supports-color@8.1.1)(typescript@6.0.3)':
     dependencies:
       '@typescript-eslint/scope-manager': 8.66.0
       '@typescript-eslint/types': 8.66.0
-      '@typescript-eslint/typescript-estree': 8.66.0(typescript@6.0.3)
+      '@typescript-eslint/typescript-estree': 8.66.0(supports-color@8.1.1)(typescript@6.0.3)
       '@typescript-eslint/visitor-keys': 8.66.0
       debug: 4.4.3(supports-color@8.1.1)
-      eslint: 10.8.0(jiti@2.7.0)
+      eslint: 10.8.0(jiti@2.7.0)(supports-color@8.1.1)
       typescript: 6.0.3
     transitivePeerDependencies:
       - supports-color
 
-  '@typescript-eslint/project-service@8.66.0(typescript@6.0.3)':
+  '@typescript-eslint/project-service@8.66.0(supports-color@8.1.1)(typescript@6.0.3)':
     dependencies:
       '@typescript-eslint/tsconfig-utils': 8.66.0(typescript@6.0.3)
       '@typescript-eslint/types': 8.66.0
@@ -3087,9 +3091,9 @@ snapshots:
 
   '@typescript-eslint/types@8.66.0': {}
 
-  '@typescript-eslint/typescript-estree@8.66.0(typescript@6.0.3)':
+  '@typescript-eslint/typescript-estree@8.66.0(supports-color@8.1.1)(typescript@6.0.3)':
     dependencies:
-      '@typescript-eslint/project-service': 8.66.0(typescript@6.0.3)
+      '@typescript-eslint/project-service': 8.66.0(supports-color@8.1.1)(typescript@6.0.3)
       '@typescript-eslint/tsconfig-utils': 8.66.0(typescript@6.0.3)
       '@typescript-eslint/types': 8.66.0
       '@typescript-eslint/visitor-keys': 8.66.0
@@ -3107,9 +3111,9 @@ snapshots:
       '@typescript-eslint/types': 8.66.0
       eslint-visitor-keys: 5.0.1
 
-  '@valibot/to-json-schema@1.7.1(valibot@1.4.1(typescript@6.0.3))':
+  '@valibot/to-json-schema@1.7.1(valibot@1.4.2(typescript@6.0.3))':
     dependencies:
-      valibot: 1.4.1(typescript@6.0.3)
+      valibot: 1.4.2(typescript@6.0.3)
 
   '@vitest/coverage-v8@4.1.9(vitest@4.1.8)':
     dependencies:
@@ -3123,7 +3127,7 @@ snapshots:
       obug: 2.1.2
       std-env: 4.1.0
       tinyrainbow: 3.1.0
-      vitest: 4.1.8(@types/node@25.9.2)(@vitest/coverage-v8@4.1.9)(@vitest/ui@4.1.9)(happy-dom@20.10.6)(jsdom@27.4.0)(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0))
+      vitest: 4.1.8(@types/node@25.9.2)(@vitest/coverage-v8@4.1.9)(@vitest/ui@4.1.9)(happy-dom@20.10.6)(jsdom@27.4.0(supports-color@8.1.1))(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0))
 
   '@vitest/expect@4.1.8':
     dependencies:
@@ -3181,7 +3185,7 @@ snapshots:
       sirv: 3.0.2
       tinyglobby: 0.2.17
       tinyrainbow: 3.1.0
-      vitest: 4.1.8(@types/node@25.9.2)(@vitest/coverage-v8@4.1.9)(@vitest/ui@4.1.9)(happy-dom@20.10.6)(jsdom@27.4.0)(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0))
+      vitest: 4.1.8(@types/node@25.9.2)(@vitest/coverage-v8@4.1.9)(@vitest/ui@4.1.9)(happy-dom@20.10.6)(jsdom@27.4.0(supports-color@8.1.1))(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0))
 
   '@vitest/utils@4.1.8':
     dependencies:
@@ -3394,6 +3398,11 @@ snapshots:
       graceful-fs: 4.2.11
       tapable: 2.3.3
 
+  enhanced-resolve@5.24.5:
+    dependencies:
+      graceful-fs: 4.2.11
+      tapable: 2.3.3
+
   entities@7.0.1: {}
 
   entities@8.0.0: {}
@@ -3437,28 +3446,28 @@ snapshots:
 
   escape-string-regexp@4.0.0: {}
 
-  eslint-plugin-better-tailwindcss@4.4.1(eslint@10.8.0(jiti@2.7.0))(tailwindcss@4.3.0)(typescript@6.0.3):
+  eslint-plugin-better-tailwindcss@4.7.0(eslint@10.8.0(jiti@2.7.0)(supports-color@8.1.1))(tailwindcss@4.3.0)(typescript@6.0.3):
     dependencies:
       '@eslint/css-tree': 4.0.4
-      '@valibot/to-json-schema': 1.7.1(valibot@1.4.1(typescript@6.0.3))
-      enhanced-resolve: 5.23.0
+      '@valibot/to-json-schema': 1.7.1(valibot@1.4.2(typescript@6.0.3))
+      enhanced-resolve: 5.24.5
       jiti: 2.7.0
       synckit: 0.11.13
       tailwind-csstree: 0.3.3
       tailwindcss: 4.3.0
       tsconfig-paths-webpack-plugin: 4.2.0
-      valibot: 1.4.1(typescript@6.0.3)
+      valibot: 1.4.2(typescript@6.0.3)
     optionalDependencies:
-      eslint: 10.8.0(jiti@2.7.0)
+      eslint: 10.8.0(jiti@2.7.0)(supports-color@8.1.1)
     transitivePeerDependencies:
       - '@eslint/css'
       - typescript
 
-  eslint-plugin-svelte@3.22.0(eslint@10.8.0(jiti@2.7.0))(svelte@5.56.3(@typescript-eslint/types@8.66.0)):
+  eslint-plugin-svelte@3.22.0(eslint@10.8.0(jiti@2.7.0)(supports-color@8.1.1))(svelte@5.56.3(@typescript-eslint/types@8.66.0)):
     dependencies:
-      '@eslint-community/eslint-utils': 4.10.1(eslint@10.8.0(jiti@2.7.0))
+      '@eslint-community/eslint-utils': 4.10.1(eslint@10.8.0(jiti@2.7.0)(supports-color@8.1.1))
       '@jridgewell/sourcemap-codec': 1.5.5
-      eslint: 10.8.0(jiti@2.7.0)
+      eslint: 10.8.0(jiti@2.7.0)(supports-color@8.1.1)
       esutils: 2.0.3
       globals: 16.5.0
       known-css-properties: 0.37.0
@@ -3490,11 +3499,11 @@ snapshots:
 
   eslint-visitor-keys@5.0.1: {}
 
-  eslint@10.8.0(jiti@2.7.0):
+  eslint@10.8.0(jiti@2.7.0)(supports-color@8.1.1):
     dependencies:
-      '@eslint-community/eslint-utils': 4.10.1(eslint@10.8.0(jiti@2.7.0))
+      '@eslint-community/eslint-utils': 4.10.1(eslint@10.8.0(jiti@2.7.0)(supports-color@8.1.1))
       '@eslint-community/regexpp': 4.12.2
-      '@eslint/config-array': 0.23.5
+      '@eslint/config-array': 0.23.5(supports-color@8.1.1)
       '@eslint/config-helpers': 0.7.0
       '@eslint/core': 1.2.1
       '@eslint/plugin-kit': 0.7.2
@@ -3642,14 +3651,14 @@ snapshots:
 
   html-escaper@2.0.2: {}
 
-  http-proxy-agent@7.0.2:
+  http-proxy-agent@7.0.2(supports-color@8.1.1):
     dependencies:
       agent-base: 7.1.4
       debug: 4.4.3(supports-color@8.1.1)
     transitivePeerDependencies:
       - supports-color
 
-  https-proxy-agent@7.0.6:
+  https-proxy-agent@7.0.6(supports-color@8.1.1):
     dependencies:
       agent-base: 7.1.4
       debug: 4.4.3(supports-color@8.1.1)
@@ -3711,7 +3720,7 @@ snapshots:
 
   js-tokens@4.0.0: {}
 
-  jsdom@27.4.0:
+  jsdom@27.4.0(supports-color@8.1.1):
     dependencies:
       '@acemir/cssom': 0.9.31
       '@asamuzakjp/dom-selector': 6.8.1
@@ -3720,8 +3729,8 @@ snapshots:
       data-urls: 6.0.1
       decimal.js: 10.6.0
       html-encoding-sniffer: 6.0.0
-      http-proxy-agent: 7.0.2
-      https-proxy-agent: 7.0.6
+      http-proxy-agent: 7.0.2(supports-color@8.1.1)
+      https-proxy-agent: 7.0.6(supports-color@8.1.1)
       is-potential-custom-element-name: 1.0.1
       parse5: 8.0.1
       saxes: 6.0.0
@@ -4239,7 +4248,7 @@ snapshots:
 
   undici-types@7.24.6: {}
 
-  undici@6.27.0: {}
+  undici@6.28.0: {}
 
   update-browserslist-db@1.2.3(browserslist@4.28.4):
     dependencies:
@@ -4253,7 +4262,7 @@ snapshots:
 
   util-deprecate@1.0.2: {}
 
-  valibot@1.4.1(typescript@6.0.3):
+  valibot@1.4.2(typescript@6.0.3):
     optionalDependencies:
       typescript: 6.0.3
 
@@ -4324,11 +4333,11 @@ snapshots:
       '@vitest/coverage-v8': 4.1.9(vitest@4.1.8)
       '@vitest/ui': 4.1.9(vitest@4.1.8)
       happy-dom: 20.10.6
-      jsdom: 27.4.0
+      jsdom: 27.4.0(supports-color@8.1.1)
     transitivePeerDependencies:
       - msw
 
-  vitest@4.1.8(@types/node@25.9.2)(@vitest/coverage-v8@4.1.9)(@vitest/ui@4.1.9)(happy-dom@20.10.6)(jsdom@27.4.0)(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0)):
+  vitest@4.1.8(@types/node@25.9.2)(@vitest/coverage-v8@4.1.9)(@vitest/ui@4.1.9)(happy-dom@20.10.6)(jsdom@27.4.0(supports-color@8.1.1))(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0)):
     dependencies:
       '@vitest/expect': 4.1.8
       '@vitest/mocker': 4.1.8(vite@8.0.16(@types/node@25.9.2)(esbuild@0.28.1)(jiti@2.7.0)(tsx@4.22.4)(yaml@2.9.0))
@@ -4355,7 +4364,7 @@ snapshots:
       '@vitest/coverage-v8': 4.1.9(vitest@4.1.8)
       '@vitest/ui': 4.1.9(vitest@4.1.8)
       happy-dom: 20.10.6
-      jsdom: 27.4.0
+      jsdom: 27.4.0(supports-color@8.1.1)
     transitivePeerDependencies:
       - msw
 
```

## audit:gate の before / after

before (main):
```
Total advisories: 4 (low=0, moderate=4, high=0, critical=0)
### ✓ Gate passed
- [npm] valibot: GHSA-5qjj-4xww-7phc
- [npm] undici: GHSA-8xcm-r25x-g524
- [npm] undici: GHSA-m8rv-5g2x-5cg5
- [npm] undici: GHSA-v3r7-h72x-cjcm
```

after (todo/T111):
```
Total advisories: 0 (low=0, moderate=0, high=0, critical=0)
### ✓ Gate passed
```

`docs/supply-chain/accepted-advisories.yaml` は **`[]` のまま無変更** (accept-risk 0 件)。

## テスト結果 (全レーン worktree 内で実測)

| レーン | 結果 |
|---|---|
| `composer test` | 3016 tests / **3014 passed** / 0 failed / 2 skipped / 11884 assertions |
| `composer phpstan` (level 10, 786 files) | No errors |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` | 指摘 0 (exit 0) |
| `pnpm typecheck` | OK |
| `pnpm test` | 123 files / **1202 passed** / 0 failed |
| `pnpm build` | OK |
| `pnpm typecheck:packages` / `build:packages` | OK |
| `pnpm test:packages` | 10 files / **106 passed** |
| `pnpm run audit:gate` | **Total advisories: 0** / exit 0 |
| `npx vitest run scripts/audit-gate.contract.test.ts` | 11 passed |
| `pnpm install --frozen-lockfile` | Already up to date (exit 0) |
| `bash scripts/verify-global-test-lock.sh` | passed=65 / failed=0 / skipped=0 |

## 実装上の判断 (レビューしてほしい点)

1. `packages/cli/package.json` の宣言が `"undici": "^6.27.0"` → `"^6.28.0"` に変わっている。
   設計は「caret 範囲内なので宣言変更は**不要**」と書いていたが、`pnpm -F @app/cli update undici` の
   既定挙動が caret の下限を引き上げた。lockfile 無し (fresh resolve) で 6.27.0 に落ちる余地を
   塞ぐため、この上限引き上げを**残す**判断をした。設計からの逸脱として妥当か。

2. lockfile の再解決を最小に抑えるため、`pnpm update` が広く巻き込んだ版
   (`@types/node` 25.9.2→25.9.5 / `@csstools/*` / `tldts` / `tough-cookie` / `ws` / `lru-cache` の
   ドリフト、+110/-94 行) を捨て、manifest 2 行の編集 + `pnpm install` のみで再生成した版
   (+60/-51 行) を採用した。結果、版が動いたのは
   `undici` 6.27.0→6.28.0 / `valibot` 1.4.1→1.4.2 / `eslint-plugin-better-tailwindcss` 4.4.1→4.7.0 /
   `enhanced-resolve` 5.23.0→5.24.5 (plugin の依存範囲変化) の 4 件と、
   `supports-color` の peer edge 再計算のみ。この判断は妥当か。

3. `eslint-plugin-better-tailwindcss` 4.4.1→4.7.0 (minor 3 つ分) で lint 指摘は **増えなかった**
   (`pnpm lint` 指摘 0)。設計のリスク欄にあった「コード修正 5 ファイル超なら D1 を undici のみに縮小」
   の分割条件には**該当しなかった**ため、両方をこの TODO で入れている。

4. この変更に**固有の新規テストは書いていない**。設計のテスト計画が
   「受入条件: `audit:gate` → Total advisories: 0 / 既存テストの更新: なし」であり、
   回帰網は既存の `audit:gate` (T104 で CI blocking 化済み) と `scripts/audit-gate.contract.test.ts`、
   `pnpm test:packages` (undici を使う CLI の 106 tests) が担う設計。
   AGENTS.md 禁止事項 1「テストなしの実装完了報告」との整合をどう判断するか、明示的に評価してほしい。

## スコープ外 (設計で次サイクル送りと判断済み。指摘不要)

- `zod` v3 (packages/cli) / v4 (root) の分裂。移行コード変更を伴い保守の粒度を超えるため別 TODO。
- 同設計書の施策 A / B / C は別 TODO (T108/T109/T110 相当) の担当であり、本 diff の対象外。
