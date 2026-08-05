# Round 2: Round 1 指摘への対応と再レビュー依頼

Round 1 の Critical 2 件のうち、
- 施策3 の `fileStoreOrNull()` 境界迂回は **設計変更で対応**（`CredentialStore.purgeProfile()` を新設）
- 施策2 の `__dirname` は **実測に基づいて反論**（既存 11 本と同じ作法、実行して 1 passed）
Warning 2 件・Suggestion 2 件はすべて対応した。Suggestion 1 件は設計書に紛れ込んでいた
リテラル NUL バイトという実バグの発見につながった。

同じ判定基準（施策ごとの APPROVE / REQUEST_CHANGES、[Critical]/[Warning]/[Suggestion]、
全体判定 APPROVED / CHANGES_REQUESTED）で改訂版を再レビューしてほしい。

---

## 対応マトリクス (Round 1)

# 対応マトリクス: design-review Round 1

## [Critical] 施策2: `__dirname` は Vitest + ESM で未定義になりテストが落ちる

- 判断: **反論する**（設計は変えない。根拠を設計書へ追記した）
- 根拠: 実測で否定された。
  1. 本リポジトリの既存 architecture テスト **11 本すべてが `__dirname` を使用**しており
     （`svg-inline-allowlist.test.ts:16` / `ds-purity.test.ts` / `atomic-import-graph.test.ts` ほか）、
     CI の `pnpm test` で常時 green である
  2. JST 2026-08-05 に `tests/js/architecture/` へ
     `expect(typeof __dirname).toBe("string")` を置いた一時テストを追加して
     `pnpm exec vitest run` を実行 → **1 passed**。Vitest はテストファイルへ
     `__dirname` / `__filename` を注入する
- 対応内容: 設計書の施策 2 に「`__dirname` を使う根拠」節を追加し、上記の実測と
  「既存 11 本と作法を揃える（1 本だけ `import.meta.url` にすると読み手が差分の理由を探す）」
  を明記した。**コードは変更しない**。

## [Critical] 施策3: `fileStoreOrNull()` を本番ロジックで使うのは `CredentialStore` 境界の迂回

- 判断: **対応する**
- 根拠: 完全に正当。`fileStoreOrNull()` の JSDoc は
  「Used by tests to exercise corruption paths」と明記されており、
  テスト用の露出を本番から呼ぶのは抽象の破壊である
  （概念設計 §型安全方針 の「`ProfileWriter` 抽象を迂回しない」と同じ原則が
  `CredentialStore` にも当然かかる）。
- 対応内容: **`CredentialStore.purgeProfile()` を正式 API として追加**した。
  - 「破損 index を含む best-effort な全破棄」という意図を store 側に閉じ込める
  - 戻り値 `{ indexCorrupted: boolean }` で取りこぼしの可能性を呼び出し側へ報告
    （keychain は index を失うと個々の item を列挙できないため）
  - `clearProfile()` は意味を変えずそのまま残す（`purgeProfile` はその上のラッパ）
  - `fileStoreOrNull()` は引き続きテスト専用
  - 施策一覧 / 施策 3 の変更箇所表 / 型適合チェック / リスク表を更新。
    概念設計の受け入れ条件「`store.ts` に変更が無い」も
    「`store.ts` の変更は `purgeProfile()` の追加のみ」へ同期した

## [Warning] 施策3: `api_url` が「空でないが不正形式」だと `canonicalOrigin()` の例外で削除不能

- 判断: **対応する**
- 根拠: 正当。`canonicalOrigin()` は `new URL()` 失敗と非 http(s) スキームで throw する
  （`profile/canonical-origin.ts:1-14`）。手編集で壊れた profile ほど消せないという
  逆転した挙動になり、「壊れた状態からの回復手段」という `profile:delete` の役割を潰す。
- 対応内容: `resolveOriginOrNull(apiUrl, name)` を切り出し、
  **欠落・空・不正形式のすべてで `null` を返して警告**し、config 削除を続行する形にした。
  警告文言は共通ヘルパ `warnCredentialsUnlocatable()` に集約。
  リスク表にも 1 行追加した。

## [Suggestion] 施策3: `profile:use` の説明文「default_profile を変更できる唯一のコマンド」が嘘になる

- 判断: **対応する**
- 根拠: 正当。`profile:delete --clear-default` が `default_profile` を変えるようになる。
  ヘルプ出力の嘘は「エラーメッセージの嘘を消す」という本バッチの目的
  （課題 B の `profile:add` の `profile:delete` 案内）と同じ性質の問題である。
- 対応内容: 施策 3 に **(d) `profile/use.ts` の説明文訂正**（1 行）を追加し、
  施策一覧の変更ファイル欄にも載せた。ロジックには触れない。

## [Warning] 施策4: 関数レイヤ中心で CLI 契約（exit code / stdout / stderr / `--yes`）の回帰を固定できない

- 判断: **対応する**
- 根拠: 正当。設計した exit code 対応表（10 / 11 / 13 / 1）と確認プロンプト分岐は
  ロジック層のテストでは 1 つも固定されない。
- 対応内容: `packages/cli/tests/commands/profile/delete.test.ts` を追加し、
  検証 6 本（11 / 10 / 13 / 1 / 正常削除 / default 付け替えの stdout）を定義した。
  **技法は JST 2026-08-05 に実測で検証済み**:
  - `ProfileUse.run(argv, CLI_ROOT)` は **`dist/` をビルドしていなくても** Config.load が通る
  - `HOME` を一時ディレクトリへ向けると `homedir()` がそこを返し、
    `userConfigPath()` と `FileStore` の既定 baseDir が閉じる（hermetic 化できる）
  - **重要な落とし穴**: `process.exit` のモックを throw 実装にすると、
    その throw を `BaseCommand.catch` が拾って**もう一度 exit(1) を呼ぶ**。
    素朴に `rejects.toThrow("EXIT:11")` と書くと常に 1 を見る（実測で確認）。
    **最初に記録された code** で判定する設計にした
  - この落とし穴と実測結果を設計書に明記（実装者が同じ罠を踏まないように）

## [Suggestion] 施策4: fake keychain の複合キー区切りを不可視文字直書きでなく `"\u0000"` に

- 判断: **対応する**（かつ**実害を 1 件発見した**）
- 根拠: 正当。指摘を受けて確認したところ、設計書のコードブロックに
  **リテラルの NUL バイトが 1 個混入**していた（`${this.service}<NUL>${this.username}`）。
  そのせいで `grep` が当該ファイルをバイナリ扱いし、以降の検索が無言で 0 件を返していた。
  まさに「事故耐性」の指摘どおりの事故が起きていた。
- 対応内容: NUL バイトを除去し、`const KEY_SEP = "\u0000";` を定義して
  テンプレートリテラルから参照する形に書き換えた。

---

## 判定

Critical 2 件のうち 1 件は実測に基づき反論、1 件は設計変更で対応。
Warning 2 件・Suggestion 2 件はすべて対応（うち 1 件は実バグの発見につながった）。


---

## 改訂後の詳細設計書（全文）

# 詳細設計: devtool-template-followup

> 本書の日付・時刻は**すべて JST (UTC+9)**。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

**本バッチは PHP を一切変更しない**（`.claude/skills/`・`packages/cli/`・`tests/js/`・`.github/workflows/`・`AGENTS.md`・root `package.json` のみ）。
したがって PHPStan / Pest / Pint / Factory の規約は**適用対象が無い**。
代わりに TypeScript 側の等価な縛りを適用する:

- **`packages/cli` の tsconfig**: `strict` + **`exactOptionalPropertyTypes: true`** + **`noUncheckedIndexedAccess: true`** + `noUnusedLocals` / `noUnusedParameters`
- `any` / ad-hoc な `as` cast を**新規に導入しない**（PHPStan の widen 禁止に対応）
- **アーリーリターン**推奨
- テストは **vitest**。app 側 = `tests/js/**/*.test.ts`(root `vitest.config.ts`)、CLI 側 = `packages/cli/tests/**/*.test.ts`(独立 lane)
- **テストファースト**: 各施策は「fail を確認してから実装」（AGENTS.md 思考原則 5）
- 検証コマンド: `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm typecheck:packages` / `pnpm test:packages`
- コードフォーマット: `pnpm lint:fix`

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) — **APPROVED (Round 5)**
- レビュー履歴: `conceptual-review-round-{1..5}.md` / `codex-history/conceptual-review-decisions-round-{1..5}.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | Codex レビューモデルの `gpt-5.5` 一本化 | `.claude/skills/app-codex-vscode/SKILL.md`, `.claude/skills/app-codex-review/SKILL.md`, `.claude/skills/app-design/SKILL.md`, `.claude/skills/app-implement/SKILL.md` | 高 |
| 2 | `codex-model-consistency` アーキテクチャテスト | `tests/js/architecture/codex-model-consistency.test.ts` (新規) | 高 |
| 5 | packages 検証の CI / 規約配線 | `package.json`, `.github/workflows/ci.yml`, `AGENTS.md` | 高 |
| 6 | config 保存の atomic replacement 化 | `packages/cli/src/util/atomic-write.ts` (移動), `packages/cli/src/credential/file-store.ts` (import 1 行), `packages/cli/src/config/saver.ts`, `packages/cli/tests/config/saver.test.ts` (新規) | 高 |
| 3 | `profile:delete` コマンド | `packages/cli/src/profile/delete.ts` (新規), `packages/cli/src/oclif/commands/profile/delete.ts` (新規), `packages/cli/src/profile/writer.ts`, `packages/cli/src/credential/store.ts`, `packages/cli/src/oclif/commands/profile/use.ts` (説明文 1 行) | 高 |
| 4 | `profile:delete` の 3 backend 横断テスト + CLI 契約テスト | `packages/cli/tests/profile/delete.test.ts` (新規), `packages/cli/tests/commands/profile/delete.test.ts` (新規) | 高 |

実装順（ロールバック単位＝4 コミット）は概念設計 §実装順 のとおり:
**A: 施策 2 → 1** / **B-0: 施策 5** / **B-1: 施策 6** / **B-2: 施策 4 → 3**。

---

# 施策 1: Codex レビューモデルの `gpt-5.5` 一本化

## 変更箇所

| ファイル | 行 |
|---------|-----|
| `.claude/skills/app-codex-vscode/SKILL.md` | 32-53 (利用可能モデル表 / Reasoning Effort 表 / 旧モデル注記) |
| `.claude/skills/app-codex-review/SKILL.md` | 100 |
| `.claude/skills/app-design/SKILL.md` | 58 / 113 / 283 |
| `.claude/skills/app-implement/SKILL.md` | 178 |

## 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 2 の `codex-model-consistency.test.ts` が本変更の受け入れ判定を兼ねる
- `devnotes/`: **変更しない**（148 ファイルの過去レビュー実績。概念設計 判断 4）
- `skills-lock.json`: 対象外（外部 skill のみ管理）
- `scripts/codex`: モデル名を持たないので**変更不要**（実査済み）

## 現行コード

`.claude/skills/app-codex-vscode/SKILL.md` L32-53:

```markdown
## 利用可能モデル

| モデル | 用途 |
|--------|------|
| `gpt-5.3-codex` | デフォルト。コード分析・レビュー・技術設計 |
| `gpt-5.4` | 自然言語中心の議論・概念設計 |

---

## Reasoning Effort

`-c 'model_reasoning_effort="{reasoning}"'` で推論の深さを制御する。
`~/.codex/config.toml` のグローバル設定（`model_reasoning_effort`）はモデルとの互換性問題を起こす場合があるため、**常にコマンドラインで明示指定すること**。

| レベル | 対応モデル | 用途 |
|--------|-----------|------|
| `low` | 全モデル | 高速・軽量な応答 |
| `medium` | 全モデル | 議論・分析・ブレスト用（**デフォルト推奨** — Claudeが評価・選別する場面） |
| `high` | 全モデル | コードレビュー・安全性判定用（Codex判断が直接品質に影響する場面） |
| `xhigh` | `gpt-5.3-codex`, `gpt-5.4`, `gpt-5.2-codex`, `gpt-5.1-codex-max` のみ | 最大の推論深度 |

**注意**: `gpt-5-codex`, `gpt-5.1-codex`, `gpt-5` 等の旧モデルは `xhigh` 非対応。
```

## 変更後コード

```markdown
## 利用可能モデル

| モデル | 用途 |
|--------|------|
| `gpt-5.5` | 唯一の指定モデル。コード分析・レビュー・技術設計・概念設計のすべて |

用途別のモデル使い分けは行わない（`tests/js/architecture/codex-model-consistency.test.ts`
が `gpt-5.5` 以外のモデル名を deny-by-default で検出する）。

---

## Reasoning Effort

`-c 'model_reasoning_effort="{reasoning}"'` で推論の深さを制御する。
`~/.codex/config.toml` のグローバル設定（`model_reasoning_effort`）はモデルとの互換性問題を起こす場合があるため、**常にコマンドラインで明示指定すること**。

| レベル | 用途 |
|--------|------|
| `low` | 高速・軽量な応答 |
| `medium` | 議論・分析・ブレスト用（**デフォルト推奨** — Claudeが評価・選別する場面） |
| `high` | コードレビュー・安全性判定用（Codex判断が直接品質に影響する場面） |
| `xhigh` | 最大の推論深度 |
```

「対応モデル」列と旧モデル注記は**削除**する（単一モデルになり分岐が消えるため。概念設計 判断 5）。
`xhigh` が `gpt-5.5` で動くことは JST 2026-08-05 に実測済み（session `019fcd82`、
`scripts/codex exec --ephemeral --sandbox read-only -m gpt-5.5 -c 'model_reasoning_effort="xhigh"'` が exit 0）。

その他 3 ファイルはモデル名の置換のみ:

| 位置 | 変更前 | 変更後 |
|------|-------|-------|
| `app-codex-review/SKILL.md:100` | `` - `-m {model}`: モデルを指定（`gpt-5.3-codex` / `gpt-5.4` 等）`` | `` - `-m {model}`: モデルを指定（`gpt-5.5`）`` |
| `app-design/SKILL.md:58` | `- 概念設計レビューは **`gpt-5.4`**、詳細設計レビューは **`gpt-5.3-codex`** を使用` | `- 概念設計レビュー・詳細設計レビューとも **`gpt-5.5`** を使用（reasoning effort で使い分ける）` |
| `app-design/SKILL.md:113` | `**model**: `gpt-5.4`` | `**model**: `gpt-5.5`` |
| `app-design/SKILL.md:283` | `**model**: `gpt-5.3-codex`` | `**model**: `gpt-5.5`` |
| `app-implement/SKILL.md:178` | `**model**: `gpt-5.3-codex`` | `**model**: `gpt-5.5`` |

**reasoning effort は 1 文字も変えない**（`app-design` 1-3 = `medium` / 2-3 = `high` /
`app-implement` A-2 = `high`。概念設計 判断 1）。
**レビュー観点・出力形式・セッション管理・保存規約も変えない**（判断 3）。

## 型適合チェック

- [x] TypeScript の変更なし（Markdown のみ）

## テスト計画

- [ ] 施策 2 の `codex-model-consistency.test.ts` を**先に**書き、本施策の実装前に赤いことを確認
- [ ] 実装後 `pnpm test` で green
- [ ] `git status` に `devnotes/` の変更が出ないことを確認

## リスク

| リスク | 対応 |
|--------|------|
| `gpt-5.5` が将来 codex バイナリから消える | `scripts/codex` はモデル名を持たないので、SKILL.md の 1 語 + テストの canonical 定数を変えれば追従できる |
| 概念設計レビューの性格が変わる | 概念設計 判断 2 の逆転条件（逸失欠陥の追跡 + 旧モデル比較確認）で観測する |

---

# 施策 2: `codex-model-consistency` アーキテクチャテスト

## 変更箇所

- 新規: `tests/js/architecture/codex-model-consistency.test.ts`

root `vitest.config.ts` の `include` は `tests/js/**/*.test.ts` を含むため**配線は不要**。

## 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本ファイルが新規テスト本体
- `devnotes/`: **走査対象外**（判断 4）

## 変更後コード

```ts
import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * Codex 呼び出しモデルの一本化を deny-by-default で固定する。
 *
 * c2c 台帳 (skill-codex-integration t1 / skill-design-flow / skill-implement-flow) は
 * 「gpt-5.5 一本化」を正典としており、aicue はこれに追従した
 * (devnotes/20260805-0101-devtool-template-followup/)。
 *
 * 本テストが守るのは 2 つ:
 *   1. app-* スキルの SKILL.md に canonical (gpt-5.5) 以外のモデル名が現れないこと
 *   2. 走査対象そのものがドリフトしていないこと (inventory と実測の集合一致)
 *
 * devnotes/ は過去のレビュー実績の記録 (どのモデルが何を指摘したか) であり、
 * 書き換えは履歴の改竄にあたるため **走査対象に含めない**。
 */

const SKILLS_ROOT = path.resolve(__dirname, "../../../.claude/skills");

/** 唯一許可されるモデル。世代更新時はここだけを書き換える。 */
const CANONICAL_MODEL = "gpt-5.5";

/**
 * 走査対象の明示 inventory (`.claude/skills` からの相対パス)。
 *
 * 現時点でモデル指定を持たないスキルも登録する = 将来そこにモデル記述が
 * 生えたときも自動で検査対象になる。スキルを増減したらここも更新する
 * (更新を忘れると下の集合一致検査が fail する)。
 */
const SKILL_INVENTORY: readonly string[] = [
  "app-autopilot/SKILL.md",
  "app-bug-hunt/SKILL.md",
  "app-codex-review/SKILL.md",
  "app-codex-vscode/SKILL.md",
  "app-design/SKILL.md",
  "app-implement/SKILL.md",
  "app-todo-add/SKILL.md",
  "app-todo-close/SKILL.md",
  "app-update-docs/SKILL.md",
] as const;

/**
 * `gpt-4` / `gpt-5` / `gpt-5.3-codex` / `gpt-5.1-codex-max` などのモデル
 * トークンを拾う。`\.\d+` は数字を要求するので文末の句点を巻き込まない。
 */
const MODEL_TOKEN_PATTERN = /gpt-\d+(?:\.\d+)?(?:-[a-z0-9]+)*/gi;

/** `.claude/skills/app-*/SKILL.md` を実測で列挙する。 */
const discoverAppSkillFiles = async (): Promise<readonly string[]> => {
  const entries = await fs.readdir(SKILLS_ROOT, { withFileTypes: true });
  const found: string[] = [];
  for (const entry of entries) {
    if (!entry.isDirectory()) continue;
    if (!entry.name.startsWith("app-")) continue;
    const rel = `${entry.name}/SKILL.md`;
    try {
      await fs.access(path.join(SKILLS_ROOT, rel));
    } catch {
      continue;
    }
    found.push(rel);
  }
  return found.sort();
};

describe("codex model consistency", () => {
  it("走査対象 SKILL.md の集合が inventory と一致する (drift ガード)", async () => {
    const discovered = await discoverAppSkillFiles();

    // 「検査件数 0 なら fail」はこの一致検査に含まれる (inventory は非空)。
    expect(SKILL_INVENTORY.length).toBeGreaterThan(0);

    const missing = SKILL_INVENTORY.filter((p) => !discovered.includes(p));
    const unregistered = discovered.filter(
      (p) => !SKILL_INVENTORY.includes(p),
    );

    expect(
      missing,
      `inventory にあるのに実在しない SKILL.md があります (移動/改名/削除で\n`
        + `モデル検査の守備範囲が痩せます)。意図した削除なら inventory からも\n`
        + `外してください:\n  ${missing.join("\n  ")}`,
    ).toEqual([]);

    expect(
      unregistered,
      `inventory に無い app-* スキルがあります。モデル指定が野放しになるため\n`
        + `SKILL_INVENTORY へ追加してください:\n  ${unregistered.join("\n  ")}`,
    ).toEqual([]);
  });

  it(`SKILL.md に ${CANONICAL_MODEL} 以外のモデル名が現れない`, async () => {
    const offenders: string[] = [];

    for (const rel of SKILL_INVENTORY) {
      const content = await fs.readFile(
        path.join(SKILLS_ROOT, rel),
        "utf8",
      );
      const lines = content.split("\n");
      lines.forEach((line, index) => {
        const matches = line.match(MODEL_TOKEN_PATTERN);
        if (matches === null) return;
        for (const token of matches) {
          if (token.toLowerCase() === CANONICAL_MODEL) continue;
          offenders.push(
            `${rel}:${String(index + 1)}: ${token} — ${line.trim()}`,
          );
        }
      });
    }

    expect(
      offenders,
      `canonical (${CANONICAL_MODEL}) 以外のモデル名が残っています。\n`
        + `用途別の使い分けは廃止済みです (概念設計 判断 2/5)。\n`
        + `モデル世代を更新する場合は CANONICAL_MODEL を書き換えてください:\n`
        + `  ${offenders.join("\n  ")}`,
    ).toEqual([]);
  });
});
```

### `__dirname` を使う根拠（詳細設計レビュー Round 1 の [Critical] への反論）

Vitest は ESM 実行でもテストファイルに `__dirname` / `__filename` を注入する。
本リポジトリの既存 architecture テストも全て `__dirname` を使っており
（`svg-inline-allowlist.test.ts:16`, `ds-purity.test.ts`, `atomic-import-graph.test.ts` ほか）、
CI で常時 green である。

JST 2026-08-05 に実測でも確認した（`tests/js/architecture/` に
`expect(typeof __dirname).toBe("string")` を置いた一時テストを
`pnpm exec vitest run` して 1 passed）。
**既存 11 本の architecture テストと作法を揃える**ため `__dirname` を採用する
（1 本だけ `import.meta.url` にすると読み手が差分の理由を探すことになる）。

### 誤検知が出たときの方針（Round 1 の [Suggestion] への回答）

**行単位の allow コメントは用意しない**。例外は必ず腐り、
「これは履歴記述か実指定か」の判断を将来に押し付ける（概念設計 判断 5 と同じ理由）。
SKILL.md の解説文でモデル名の例が要るときは、
実在するモデル名ではなく **`{model}` プレースホルダ**を使う
（`app-codex-vscode/SKILL.md` の基本コマンド例が既にこの形）。
本当に別モデルを併用する決定をした場合は、`CANONICAL_MODEL` を配列へ広げ、
その判断を `docs/template-divergence.md` に起票してから行う。

## 型適合チェック

- [x] 戻り値の型が明示されている（`discoverAppSkillFiles(): Promise<readonly string[]>`）
- [x] `any` / ad-hoc cast なし
- [x] 検出結果は `readonly string[]`（概念設計 §型安全方針）
- [x] `String(index + 1)` で明示変換（root の ESLint は `@typescript-eslint/restrict-template-expressions` 相当を持つため数値の暗黙変換を避ける）

## テスト計画

- [ ] **本ファイルが新規テスト本体**。施策 1 の**前に**追加し、2 本目の it が**赤い**ことを確認（現状 9 箇所ヒット）
- [ ] 1 本目の it（inventory 一致）は追加時点で green
- [ ] 施策 1 の適用後、両方 green
- [ ] drift ガードの実効確認: inventory から 1 行削ると 1 本目が赤くなることを手元で確認する

## リスク

| リスク | 対応 |
|--------|------|
| スキル追加のたび inventory 更新が要る | それが狙い（deny-by-default）。エラーメッセージに追加手順を書く |
| `app-bug-hunt/` 配下の `stories/*.md` 等はモデル名を持ちうる | 現状 0 件（実査）。SKILL.md 以外は台帳の対象外なので走査しない。必要になったら inventory を拡張する |
| `devnotes/` を誤って走査対象にする改変 | `SKILLS_ROOT` が `.claude/skills` 固定であり、devnotes へ到達する経路が無い |

---

# 施策 5: packages 検証の CI / 規約配線

## 変更箇所

- `package.json`（root）— `scripts` に 1 行追加
- `.github/workflows/ci.yml` — 既存 `frontend` job に 2 ステップ追加（L61-70 付近）
- `AGENTS.md` — §実装規約 の検証コマンド行（L73-74）

## 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし（本施策は「テストを走らせる配線」そのもの）
- CI job 数: **2 のまま**（`ci-multi-lane-workflow` の裁定を先取りしない。概念設計 スコープ外）

## 現行コード

`package.json`:

```json
"build:packages": "pnpm -F \"./packages/*\" build",
"test:packages": "bash scripts/with-global-test-lock.sh pnpm -F \"./packages/*\" test"
```

`.github/workflows/ci.yml`（`frontend` job）:

```yaml
      - name: Install dependencies
        run: pnpm install --frozen-lockfile
      - name: ESLint
        run: pnpm lint
      - name: TypeScript
        run: pnpm typecheck
      - name: Vitest
        run: pnpm test
      - name: Build
        run: pnpm build
```

`AGENTS.md` L73-74:

```markdown
- 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`(全 green でコミット)
```

## 変更後コード

`package.json`:

```json
"build:packages": "pnpm -F \"./packages/*\" build",
"typecheck:packages": "pnpm -F \"./packages/*\" typecheck",
"test:packages": "bash scripts/with-global-test-lock.sh pnpm -F \"./packages/*\" test"
```

`.github/workflows/ci.yml`（`frontend` job — `Vitest` の直後に 2 本挿入）:

```yaml
      - name: Vitest
        run: pnpm test
      - name: TypeScript (workspace packages)
        run: pnpm typecheck:packages
      - name: Vitest (workspace packages)
        run: pnpm test:packages
      - name: Build
        run: pnpm build
```

`AGENTS.md`:

```markdown
- 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm test:packages`(全 green でコミット)
```

**packages の検証契約は `typecheck:packages` + `test:packages` の 2 本セット**であり、
受け入れ条件・CI・AGENTS.md の 3 箇所すべてに両方を登録する（概念設計 Round 2 対応）。

## 型適合チェック

- [x] TypeScript の変更なし（設定ファイルのみ）

## テスト計画

- [ ] `pnpm typecheck:packages` がローカルで green（既存 7 テストの型が通ること = 初回実行なので**赤くなりうる**。赤ければ**この施策の中で**直す）
- [ ] `pnpm test:packages` がローカルで green（既存 7 テスト）
- [ ] `packages/cli/package.json` の `typecheck` は `tsconfig.test.json` を見る = テストコードも型検査対象であることを確認

> **注意**: `packages/cli` の typecheck / test は本施策まで CI で 1 度も走っていない。
> 初回実行で既存コードの型エラーやテスト失敗が出る可能性がある。
> **出たら本施策の中で直す**（施策 3/4/6 に持ち込まない）。修正が大きい場合は
> 発見内容を devnotes に追記したうえで、本施策のコミットを分割する。

## リスク

| リスク | 対応 |
|--------|------|
| 既存 packages に型エラー/テスト失敗が眠っていて CI が赤くなる | 上記注意のとおり本施策内で解消する。これは**隠れていた負債の露出**であり、配線しない理由にはならない |
| `frontend` job の実行時間が伸びる | 既存 7 テスト + 新規 2 テストの vitest。数十秒オーダーで許容 |
| global test lock との競合 | `test:packages` は `scripts/with-global-test-lock.sh` 経由（aicue:T099 でマージ済み）。`pnpm test` も同じロックを取るため CI 内で直列化される |

---

# 施策 6: config 保存の atomic replacement 化

## 変更箇所

| ファイル | 変更 |
|---------|------|
| `packages/cli/src/credential/atomic-write.ts` → `packages/cli/src/util/atomic-write.ts` | **移動**（中身は無変更） |
| `packages/cli/src/credential/file-store.ts` L13 | import パス 1 行 |
| `packages/cli/src/config/saver.ts` L1 / L20 | import と書き込み 1 行 |
| `packages/cli/tests/config/saver.test.ts` | **新規** |

### なぜ移動するのか

`atomicWriteFile` は「tmp write → fsync → rename」という**汎用 fs ユーティリティ**であり、
credential 固有の知識を一切持たない。`config/` から `credential/` を import すると
**config 層が credential 層に依存する**という筋の悪い向きの依存が生まれる。
`src/util/`（既に `abort.ts` がある）へ移すのが正しい置き場である。
import 元は 1 ファイル（`credential/file-store.ts`）だけなので機械的に済む（実査済み）。

## 波及変更

- TypeScript 型定義: なし（エクスポートするシグネチャは不変）
- API Resource/DTO: なし
- テストファイル: `packages/cli/tests/config/saver.test.ts`（新規）。
  既存テストで `atomic-write` を import しているものは**無い**（実査済み）
- ビルド: `tsconfig.json` の `include` は `src/**/*.ts` なので移動先も自動で対象

## 現行コード

`packages/cli/src/config/saver.ts`:

```ts
import { writeFileSync, mkdirSync, existsSync } from "node:fs";
import { dirname } from "node:path";
import { stringify as stringifyYaml } from "yaml";
import { RootConfigInputSchema, type RootConfigInput } from "./schema.js";

/**
 * Atomically write a RootConfigInput to the given path.
 * ...
 */
export function saveConfigToPath(path: string, config: RootConfigInput): void {
    const validated = RootConfigInputSchema.parse(config);
    const parent = dirname(path);
    if (!existsSync(parent)) {
        mkdirSync(parent, { recursive: true });
    }
    const yaml = stringifyYaml(validated);
    writeFileSync(path, yaml, { encoding: "utf-8", mode: 0o600 });
}
```

`packages/cli/src/credential/file-store.ts` L13:

```ts
import { atomicWriteFile, atomicWriteFileBinary } from "./atomic-write.js";
```

## 変更後コード

`packages/cli/src/config/saver.ts`:

```ts
import { mkdirSync, existsSync } from "node:fs";
import { dirname } from "node:path";
import { stringify as stringifyYaml } from "yaml";
import { atomicWriteFile } from "../util/atomic-write.js";
import { RootConfigInputSchema, type RootConfigInput } from "./schema.js";

/**
 * Write a RootConfigInput to the given path with **atomic replacement**
 * (tmp write -> fsync -> rename), so a failed/partial write never leaves a
 * truncated config behind — losing the file would drop every registered
 * profile at once.
 *
 * Note: this is atomic *replacement*, not crash durability. The parent
 * directory is not fsynced, so a power loss right after the rename may still
 * lose the update. Full durability would need a directory fsync and is out
 * of scope (see devnotes/20260805-0101-devtool-template-followup).
 *
 * Creates the parent directory if it does not exist. The input is
 * re-validated with `RootConfigInputSchema.parse` before writing so that we
 * never persist an invalid config.
 */
export function saveConfigToPath(path: string, config: RootConfigInput): void {
    const validated = RootConfigInputSchema.parse(config);
    const parent = dirname(path);
    if (!existsSync(parent)) {
        mkdirSync(parent, { recursive: true });
    }
    const yaml = stringifyYaml(validated);
    atomicWriteFile(path, yaml, 0o600);
}
```

`packages/cli/src/credential/file-store.ts` L13:

```ts
import { atomicWriteFile, atomicWriteFileBinary } from "../util/atomic-write.js";
```

`packages/cli/src/util/atomic-write.ts` — `credential/atomic-write.ts` を**そのまま移動**
（`git mv`。内容は 1 文字も変えない。JSDoc の "the UXI1 encrypted file-store" もそのまま）。

## 型適合チェック

- [x] `writeFileSync` の import を削除（`noUnusedLocals` に抵触しないこと）
- [x] `atomicWriteFile(path: string, content: string, mode?: number): void` のシグネチャに一致
- [x] `any` / cast なし
- [x] 移動によりエクスポート名・型は不変（`file-store.ts` の 2 関数の使い方も不変）

## テスト計画

新規 `packages/cli/tests/config/saver.test.ts`:

```ts
import {
    existsSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    readdirSync,
    rmSync,
    writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { saveConfigToPath } from "../../src/config/saver.js";
import { loadConfigFromPath } from "../../src/config/loader.js";

let tmp: string;
let configPath: string;
/** atomicWriteFile が使う一時パス (pid 依存)。 */
let tmpWritePath: string;

beforeEach(() => {
    tmp = mkdtempSync(join(tmpdir(), "cli-saver-"));
    configPath = join(tmp, "config.yaml");
    tmpWritePath = `${configPath}.${String(process.pid)}.tmp`;
});

afterEach(() => {
    // 失敗注入用ディレクトリを必ず除去する。残すと **同一 pid の後続テストの
    // atomicWriteFile を巻き添えで失敗させる** (vitest は同一プロセスで
    // 複数テストファイルを走らせうる)。
    rmSync(tmpWritePath, { recursive: true, force: true });
    rmSync(tmp, { recursive: true, force: true });
});

describe("saveConfigToPath — atomic replacement", () => {
    it("一時ファイル書き込みが失敗しても既存 config が旧内容のまま残る", () => {
        saveConfigToPath(configPath, {
            default_profile: "prod",
            profiles: { prod: { api_url: "https://a.example.com" } },
        });
        const before = readFileSync(configPath, "utf-8");

        // 一時パスをディレクトリとして先に作ると tmp 書き込みが必ず失敗する
        // (EISDIR)。決定的に再現できる失敗注入。
        mkdirSync(tmpWritePath, { recursive: true });

        expect(() =>
            saveConfigToPath(configPath, {
                default_profile: "staging",
                profiles: { staging: { api_url: "https://b.example.com" } },
            }),
        ).toThrow();

        // 直接上書き実装 (現行) ではここで新内容に化けて赤くなる。
        expect(readFileSync(configPath, "utf-8")).toBe(before);
        const reloaded = loadConfigFromPath(configPath);
        expect(reloaded?.default_profile).toBe("prod");
    });

    it("正常保存後に .tmp 残骸が無く、内容が読み戻せる", () => {
        saveConfigToPath(configPath, {
            default_profile: "prod",
            profiles: { prod: { api_url: "https://a.example.com" } },
        });
        expect(existsSync(tmpWritePath)).toBe(false);
        expect(
            readdirSync(tmp).filter((f) => f.endsWith(".tmp")),
        ).toEqual([]);
        expect(loadConfigFromPath(configPath)?.default_profile).toBe("prod");
    });
});

describe("saveConfigToPath — 構造ガード", () => {
    it("saver.ts は writeFileSync を直接使わず atomicWriteFile 経由である", () => {
        const src = readFileSync(
            new URL("../../src/config/saver.ts", import.meta.url),
            "utf-8",
        );
        expect(src).toContain("atomicWriteFile");
        expect(src).not.toContain("writeFileSync");
    });
});
```

- [ ] **実装前に 1 本目と 3 本目が赤い**ことを確認（テストファースト）
- [ ] 2 本目は実装前でも green（回帰用）
- [ ] 実装後に 3 本すべて green
- [ ] 既存の `profile:add` / `profile:use` 経路が壊れていないことを `pnpm test:packages` 全体で確認

> 上記の `writeFileSync` を含む未使用 import（`writeFileSync`）はテスト側では使わない。
> 実際のテストコードでは import 一覧から落とす（`noUnusedLocals` はテストにも効く）。

## リスク

| リスク | 対応 |
|--------|------|
| tmp ファイルと本体が別ファイルシステムだと `rename` が失敗する | tmp は `{path}.{pid}.tmp` = **同一ディレクトリ**なので常に同一 FS。ヘルパの JSDoc も同一 FS 前提を明示 |
| 同一 pid の並行書き込みで tmp パスが衝突する | 既存の credential file-store が同じ命名で運用済み。CLI は 1 プロセス 1 コマンドなので実害なし（本施策で新たに悪化しない） |
| テストの失敗注入ディレクトリが残る | `afterEach` の `rmSync(..., { force: true })` で必ず除去（Round 5 指摘） |
| 構造ガード（3 本目）が文字列一致で脆い | `writeFileSync` は node:fs の関数名であり改名され得ない。誤検知したら 1 行の allow を足すのではなく、まず本当に直接書き込みが復活していないか確認する |

---

# 施策 3: `profile:delete` コマンド

## 変更箇所

| ファイル | 変更 |
|---------|------|
| `packages/cli/src/profile/writer.ts` L21-35 / L156-176 | `ProfileWriter` に `defaultProfileName()` 追加、`deleteProfile` に `nextDefault` 追加 |
| `packages/cli/src/credential/store.ts` | `purgeProfile()` を追加（破損 index を含む best-effort 破棄の正式 API） |
| `packages/cli/src/profile/delete.ts` | **新規**（削除オーケストレーションの純粋関数） |
| `packages/cli/src/oclif/commands/profile/delete.ts` | **新規**（薄い oclif シェル） |
| `packages/cli/src/oclif/commands/profile/use.ts` L7-8 | 説明文の「the only command that can change it」を訂正（`profile:delete` も変えるため） |

> **概念設計からの refinement**: 概念設計の受け入れ条件は「`store.ts` 無変更」だったが、
> 詳細設計レビュー Round 1 の [Critical] を受けて **`CredentialStore.purgeProfile()` の追加**へ変更した。
> 理由は下記「なぜ `fileStoreOrNull()` を使わないのか」。概念設計側の受け入れ条件も同期済み。

### なぜロジックをコマンドから切り出すのか

本リポジトリの既存テスト作法がそうなっている。`doctor` は
`src/doctor/runner.ts` の `runDoctor(opts)` に本体を置き、
oclif コマンドは薄いシェルで、テストは `runDoctor` を直接叩いて
依存（`credentialStore` / `packageJsonPath` / `stdout`）を注入している
（`tests/commands/doctor/doctor.test.ts`）。

`profile:delete` も同じ形にする。`resolveProfileBundle()` は
`new CredentialStore()` / `new FileProfileWriter()` を**引数なしで**生成する
（`oclif/base/profile-context.ts:178-183`）ため、ロジックを注入可能な関数にすれば
一時ディレクトリの `FileProfileWriter(tmpPath)` / `FileStore(tmpDir)` を渡して
backend 別の検証ができる。

CLI 契約（exit code / 確認プロンプト分岐）は別途コマンド層のテストで固定する
（施策 4 の後半。`HOME` を一時ディレクトリへ向けて実際に oclif コマンドを走らせる）。

### なぜ `fileStoreOrNull()` を使わないのか

`CredentialStore.fileStoreOrNull()` は JSDoc に
「Used by tests to exercise corruption paths」と明記された**テスト用の露出**であり、
本番ロジックから呼ぶと `CredentialStore` の境界を迂回することになる
（詳細設計レビュー Round 1 の [Critical]）。

代わりに **`CredentialStore.purgeProfile()`** を正式 API として追加し、
「破損 index を含む best-effort な全破棄」という意図を store 側に閉じ込める。
コマンド／オーケストレーション層は `purgeProfile()` だけを呼ぶ。

## 波及変更

- TypeScript 型定義: `ProfileWriter` interface（`defaultProfileName` 追加 / `deleteProfile` の opts 拡張）。
  実装は `FileProfileWriter` のみ（実査で他の実装クラスは存在しない）
- 既存呼び出し元: `oclif/commands/profile/add.ts` L109 / L131 の `writer.deleteProfile(name)`
  — **opts 省略のため挙動不変**（引数追加は optional）
- API Resource/DTO: なし
- テストファイル: 施策 4 の `tests/profile/delete.test.ts`
- oclif manifest: `package.json` の `oclif.topics.profile` は既存 = **追加不要**。
  `commands: "./dist/oclif/commands"` のディレクトリ規約で `profile:delete` として自動登録される
- `exit-codes.ts`: **変更しない**（既存の 10 / 11 のみ使用）

## 現行コード

`packages/cli/src/profile/writer.ts` L21-35（interface）と L156-176（実装）:

```ts
export interface ProfileWriter {
    list(): ReadonlyArray<{ name: string; entry: ProfileEntry }>;
    get(name: string): ProfileEntry | undefined;
    snapshot(name: string): ProfileEntry;
    addProfile(name: string, init: ProfileInit): void;
    updateExpectedEnv(name: string, expected: string | null): void;
    deleteProfile(name: string, opts?: { clearDefault?: boolean }): void;
    useDefaultProfile(name: string): void;
    applyAtomic(...): void;
    persistVerificationMeta(name: string, meta: VerificationMetadata): void;
}
```

```ts
    deleteProfile(
        name: string,
        opts: { clearDefault?: boolean } = {},
    ): void {
        const user = this.loadUser();
        if (!user.profiles?.[name]) {
            throw new Error(`profile "${name}" not found`);
        }
        if (user.default_profile === name && !opts.clearDefault) {
            throw new Error(
                `profile "${name}" is the default. `
                    + "Use --clear-default or run `profile:use` first.",
            );
        }
        const { [name]: _removed, ...rest } = user.profiles;
        const next: RootConfigInput = { ...user, profiles: rest };
        if (opts.clearDefault && user.default_profile === name) {
            delete next.default_profile;
        }
        this.save(next);
    }
```

## 変更後コード

### (a) `packages/cli/src/profile/writer.ts`

```ts
export type DeleteProfileOptions = {
    /** default_profile が対象を指していても削除を許可する。 */
    clearDefault?: boolean;
    /**
     * 削除と同時に default_profile を付け替える先。
     * `clearDefault === true` かつ対象が現在の default のときのみ指定できる
     * (それ以外は throw して **何も保存しない**)。
     * 削除と default 遷移を 1 回の save() に畳むために存在する。
     */
    nextDefault?: string;
};

export interface ProfileWriter {
    list(): ReadonlyArray<{ name: string; entry: ProfileEntry }>;
    get(name: string): ProfileEntry | undefined;
    /** user config の default_profile (未設定なら undefined)。 */
    defaultProfileName(): string | undefined;
    snapshot(name: string): ProfileEntry;
    addProfile(name: string, init: ProfileInit): void;
    updateExpectedEnv(name: string, expected: string | null): void;
    deleteProfile(name: string, opts?: DeleteProfileOptions): void;
    useDefaultProfile(name: string): void;
    applyAtomic(
        name: string,
        patch: MutableConnectionOptionsPatch,
        verifyResult: VerificationMetadata,
    ): void;
    persistVerificationMeta(name: string, meta: VerificationMetadata): void;
}
```

`FileProfileWriter`:

```ts
    defaultProfileName(): string | undefined {
        return this.loadUser().default_profile;
    }

    /**
     * プロファイルの削除。`nextDefault` を渡すと **同じ 1 回の save() で**
     * default_profile を付け替える (削除保存 → 付け替え保存の 2 段階にすると、
     * 間の「default 不在」状態が永続化しうるため)。
     */
    deleteProfile(name: string, opts: DeleteProfileOptions = {}): void {
        const user = this.loadUser();
        const profiles = user.profiles;
        if (!profiles?.[name]) {
            throw new Error(`profile "${name}" not found`);
        }
        const isDefault = user.default_profile === name;

        // --- nextDefault の受理条件 (満たさなければ save を呼ばない) ---
        const nextDefault = opts.nextDefault;
        if (nextDefault !== undefined) {
            if (!isDefault) {
                throw new Error(
                    `nextDefault is only valid when deleting the default `
                        + `profile (default_profile is `
                        + `${String(user.default_profile)}).`,
                );
            }
            if (opts.clearDefault !== true) {
                throw new Error(
                    "nextDefault requires clearDefault (the intent to change "
                        + "default_profile must be explicit).",
                );
            }
            if (nextDefault === name) {
                throw new Error(
                    `nextDefault "${nextDefault}" is the profile being deleted.`,
                );
            }
            if (!profiles[nextDefault]) {
                throw new Error(`profile "${nextDefault}" not found`);
            }
        }

        if (isDefault && opts.clearDefault !== true) {
            throw new Error(
                `profile "${name}" is the default. `
                    + "Use --clear-default or run `profile:use` first.",
            );
        }

        const { [name]: _removed, ...rest } = profiles;
        const next: RootConfigInput = { ...user, profiles: rest };
        if (isDefault) {
            if (nextDefault !== undefined) {
                next.default_profile = nextDefault;
            } else {
                delete next.default_profile;
            }
        }
        this.save(next);
    }
```

### (a2) `packages/cli/src/credential/store.ts` — `purgeProfile()` 追加

```ts
    /**
     * `profile:delete` 用の best-effort な全破棄 (T-delete)。
     *
     * `clearProfile` は index を読めないと `CredentialStoreError.corruptedIndex`
     * を投げる。しかし `profile:delete` は **壊れた状態からの回復手段** なので、
     * index 破損を理由に削除を拒否すると利用者を詰ませる。
     *
     * そこで index が読めない場合でも、backend 側で機械的に消せるもの
     * (meta:index と file-store のプロファイルディレクトリ) は消し、
     * 「index が壊れていたので取りこぼしがありうる」旨を戻り値で報告する。
     * keychain backend は列挙手段が無いため、index を失うと個々の item を
     * 特定できない — 呼び出し側はこの戻り値を見て手動確認を促すこと。
     */
    purgeProfile(
        canonicalOrigin: string,
        profileName: string,
    ): { indexCorrupted: boolean } {
        try {
            this.clearProfile(canonicalOrigin, profileName);
            return { indexCorrupted: false };
        } catch (e) {
            if (!(e instanceof CredentialStoreError)) throw e;
        }
        // index が壊れていても消せるものは消す。
        this.primary().delete(
            canonicalOrigin,
            profileName,
            "meta",
            META_INDEX_ID,
        );
        this.fileStore.clearProfile(canonicalOrigin, profileName);
        return { indexCorrupted: true };
    }
```

- `clearProfile()` は**そのまま残す**（既存の意味を変えない = 後方互換の並走ではなく、
  `purgeProfile` は `clearProfile` の上に載る薄いラッパ）
- `fileStoreOrNull()` は引き続きテスト専用のまま（本番からは呼ばない）
- 例外の型を `CredentialStoreError` に絞ってから握る。それ以外は素通し

### (b) `packages/cli/src/profile/delete.ts`（新規）

```ts
import { BIN_NAME } from "../branding.js";
import type { CredentialStore } from "../credential/store.js";
import { deleteOAuthToken } from "../oauth/token-store.js";
import { canonicalOrigin } from "./canonical-origin.js";
import { ProfileResolutionError } from "./errors.js";
import type { DeleteProfileOptions, ProfileWriter } from "./writer.js";

export type DeleteProfileDeps = {
    writer: ProfileWriter;
    store: CredentialStore;
};

export type DeleteProfileResult = {
    /** 削除対象が default_profile を握っていたか。 */
    wasDefault: boolean;
    /** 付け替え先 (付け替えなかったときは null)。 */
    nextDefault: string | null;
    /** 削除後に残るプロファイル名。 */
    remaining: readonly string[];
    /** credential 破棄をスキップしたか (api_url が欠落/不正なとき true)。 */
    credentialsSkipped: boolean;
    /** credential index が壊れていて取りこぼしがありうるか。 */
    credentialIndexCorrupted: boolean;
};

/**
 * プロファイル削除の本体。credential -> config の順で落とす。
 *
 * **順序は反転させないこと**: credential の物理位置は
 * `deriveProfileHash12(canonicalOrigin(api_url), name)` から導出されるため、
 * config を先に消すと api_url を失い、credential ディレクトリを二度と
 * 特定できなくなる (永久に孤児化する)。
 *
 * 2 ストアを跨ぐので全体の原子性は無い。代わりに **再実行で必ず収束する**
 * ことを契約とする (credential 不在は 3 backend すべてで no-op 成功)。
 */
export function deleteProfileWithCredentials(
    deps: DeleteProfileDeps,
    name: string,
    opts: { clearDefault: boolean },
): DeleteProfileResult {
    const { writer, store } = deps;

    const entry = writer.get(name);
    if (entry === undefined) {
        throw ProfileResolutionError.notFound(
            `profile "${name}" not found in user config.`,
        );
    }

    // --- 1. default 遷移を「削除前に」確定させる ---
    const wasDefault = writer.defaultProfileName() === name;
    const remaining = writer
        .list()
        .map((row) => row.name)
        .filter((candidate) => candidate !== name);

    if (wasDefault && !opts.clearDefault) {
        throw ProfileResolutionError.conflict(
            `profile "${name}" is the current default_profile. `
                + `Re-run with --clear-default, or point the default at `
                + `another profile first with \`${BIN_NAME} profile:use <name>\`.`,
        );
    }
    const nextDefault =
        wasDefault && remaining.length === 1 ? (remaining[0] ?? null) : null;

    // --- 2. credential を破棄する (config より先) ---
    // api_url が欠落 or 不正な「壊れた profile」も削除できなければならない
    // (assertProfileHasApiUrl のエラー文言自体が profile:delete を案内している)。
    // その場合 credential の物理位置を導出できないので破棄はスキップする。
    // canonicalOrigin() は不正 URL / 非 http(s) スキームで throw するため、
    // ここで握らないと「壊れた profile ほど消せない」という詰みになる。
    const origin = resolveOriginOrNull(entry.api_url, name);
    let credentialIndexCorrupted = false;
    if (origin !== null) {
        credentialIndexCorrupted = clearCredentials(store, origin, name);
    }
    const credentialsSkipped = origin === null;

    // --- 3. config を 1 回の save で落とす ---
    const writerOpts: DeleteProfileOptions = { clearDefault: opts.clearDefault };
    // exactOptionalPropertyTypes: 未指定はプロパティ自体を省略する。
    if (nextDefault !== null) writerOpts.nextDefault = nextDefault;

    try {
        writer.deleteProfile(name, writerOpts);
    } catch (e) {
        if (!credentialsSkipped) {
            const flag = opts.clearDefault ? " --clear-default" : "";
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
        wasDefault,
        nextDefault,
        remaining,
        credentialsSkipped,
        credentialIndexCorrupted,
    };
}

/**
 * api_url から canonical origin を導く。欠落・不正なら **null を返して警告**する
 * (throw しない)。壊れた profile を削除できないほうが害が大きいため。
 */
function resolveOriginOrNull(
    apiUrl: string | undefined,
    name: string,
): string | null {
    if (apiUrl === undefined || apiUrl === "") {
        warnCredentialsUnlocatable(name, "it has no api_url");
        return null;
    }
    try {
        return canonicalOrigin(apiUrl);
    } catch (e) {
        warnCredentialsUnlocatable(
            name,
            `its api_url is invalid (${(e as Error).message})`,
        );
        return null;
    }
}

function warnCredentialsUnlocatable(name: string, reason: string): void {
    console.error(
        `Warning: profile "${name}" cannot be mapped to a credential `
            + `location because ${reason}. Removing the config entry only; `
            + `inspect ~/.${BIN_NAME}/credentials manually if needed.`,
    );
}

/**
 * credential (indexed items + credential index + OAuth token) を落とす。
 * 戻り値は「index が壊れていて取りこぼしがありうるか」。
 *
 * `CredentialStore.clearProfile()` は index に載った item と meta:index しか
 * 消さない。OAuth token bundle は **meta 名前空間の非 index エントリ**
 * (`oauth:token`) なので、keychain backend では clearProfile だけでは残る
 * (file backend はディレクトリごと消えるので結果的に消える)。
 * 3 backend で挙動を揃えるため deleteOAuthToken を明示的に呼ぶ。
 *
 * index 破損時の best-effort は `CredentialStore.purgeProfile()` に閉じ込めてある
 * (テスト専用の `fileStoreOrNull()` を本番から呼ばないため)。
 */
function clearCredentials(
    store: CredentialStore,
    origin: string,
    name: string,
): boolean {
    deleteOAuthToken(store, origin, name);
    const { indexCorrupted } = store.purgeProfile(origin, name);
    if (indexCorrupted) {
        console.error(
            `Warning: the credential index for profile "${name}" was `
                + "corrupted. Everything reachable was removed, but items that "
                + "were only listed in the index may remain in the OS keychain. "
                + "Check it manually.",
        );
    }
    return indexCorrupted;
}
```

### (c) `packages/cli/src/oclif/commands/profile/delete.ts`（新規）

```ts
import { Args, Flags } from "@oclif/core";
import { BIN_NAME } from "../../../branding.js";
import { confirmPrompt } from "../../../credential/prompt.js";
import { ExitCode, exitWith } from "../../../exit-codes.js";
import { deleteProfileWithCredentials } from "../../../profile/delete.js";
import { assertProfileName } from "../../../profile/name.js";
import { ProfileCommand } from "../../base/ProfileCommand.js";

/**
 * `${BIN_NAME} profile:delete <name>` — remove a profile and destroy the
 * credentials stored for it (API key, OAuth token, per-site credentials).
 *
 * Uses `resolveMode: "if-needed"` because deletion acts purely on the local
 * config + credential store; no server round-trip and no resolution of an
 * existing profile context is required (same shape as `profile:use`).
 */
export default class ProfileDelete extends ProfileCommand {
    static override description =
        "Delete a profile and destroy its stored credentials.";
    static override args = {
        name: Args.string({ description: "profile name", required: true }),
    };
    static override flags = {
        "clear-default": Flags.boolean({
            description:
                "allow deleting the profile that default_profile points at",
        }),
        yes: Flags.boolean({ description: "skip confirmations (CI mode)" }),
    };

    protected override persistentRequired = false;
    protected override resolveMode: "if-needed" = "if-needed";

    public async run(): Promise<void> {
        const { args, flags } = await this.parse(ProfileDelete);
        this.latchCiFlag(flags.ci);
        const { writer, store } = await this.resolveContext(flags);
        const name = args.name;
        assertProfileName(name);
        if (!writer.get(name)) exitWith(ExitCode.ProfileNotFound);

        if (flags.yes !== true) {
            const ok = await confirmPrompt(
                `Delete profile "${name}" and destroy its stored `
                    + "credentials? This cannot be undone.",
            );
            if (!ok) {
                console.error(
                    "Aborted (pass --yes to skip this confirmation).",
                );
                exitWith(ExitCode.GeneralError);
            }
        }

        const result = deleteProfileWithCredentials({ writer, store }, name, {
            clearDefault: flags["clear-default"] === true,
        });

        this.log(`Profile "${name}" deleted.`);
        if (!result.wasDefault) return;
        if (result.nextDefault !== null) {
            this.log(`default_profile = ${result.nextDefault}`);
            return;
        }
        if (result.remaining.length === 0) {
            this.log(
                "default_profile is now unset and no profiles remain. "
                    + `Run \`${BIN_NAME} profile:add <name> --api-url <url>\`.`,
            );
            return;
        }
        this.log(
            "default_profile is now unset. Pick one with "
                + `\`${BIN_NAME} profile:use <name>\`: `
                + result.remaining.join(", "),
        );
    }
}
```

### (d) `packages/cli/src/oclif/commands/profile/use.ts` — 説明文の訂正

現行 L7-8 は「`Set default_profile (the only command that can change it).`」だが、
`profile:delete --clear-default` も `default_profile` を変えるようになるため嘘になる:

```ts
    static override description =
        "Set default_profile (profile:delete --clear-default can also change it).";
```

**説明文 1 行のみ**。ロジックには触れない。

### 実装順序（判断 6 / 判断 8 と厳密に一致）

1. 名前検証 → 存在確認 → 現在の `default_profile` 判定
2. **残プロファイルを列挙して `nextDefault` を確定**
3. 確認プロンプト（`--yes` でスキップ）
4. **credential 破棄**（OAuth token → indexed items）
5. **`writer.deleteProfile(name, { clearDefault, nextDefault })` を 1 回だけ**
6. 結果案内

> **フラグ名の refinement**: 概念設計では「`--force`（確認スキップ）」としていたが、
> `profile:add` が既に **`--yes` = skip confirmations (CI mode)** /
> **`--force` = bypass environment_tag mismatch** という語彙を持っている
> （`oclif/commands/profile/add.ts:38-42`）。同じ語で別の意味を持たせないため、
> 確認スキップは **`--yes`** に統一する。`--force` は使わない。

### exit code の対応表

| 状況 | exit | 由来 |
|------|------|------|
| 不正なプロファイル名 | 13 `ProfileInvalidName` | `assertProfileName` |
| プロファイル不在 | 11 `ProfileNotFound` | コマンドの事前チェック / `ProfileResolutionError.notFound` → `BaseCommand.catch` |
| default を `--clear-default` 無しで削除 | 10 `ProfileConflict` | `ProfileResolutionError.conflict` → `BaseCommand.catch` |
| 確認プロンプト拒否 / 非 TTY で `--yes` 無し | 1 `GeneralError` | `profile:add` の確認拒否と同じ扱い |
| credential index 破損 | **0**（警告つきで削除は完遂する）| `purgeProfile()` が best-effort で破棄し `indexCorrupted` を報告。削除を拒否して詰ませない |
| config 保存失敗 | 1 `GeneralError` | 生の `Error` → `BaseCommand.catch` |

**新しい exit code は 1 つも追加しない**。

## 型適合チェック

- [x] 戻り値の型が明示されている（`DeleteProfileResult` / `void`）
- [x] `exactOptionalPropertyTypes`: `nextDefault` は**値があるときだけプロパティを生やす**
- [x] `noUncheckedIndexedAccess`: `remaining[0]` は `string | undefined` なので `?? null`
- [x] `entry.api_url` は schema 上 optional なので `undefined` / `""` を両方判定
- [x] `any` / ad-hoc cast なし。`purgeProfile` 内の例外は `instanceof CredentialStoreError` で絞る
- [x] 本番ロジックからテスト専用 API (`fileStoreOrNull()`) を呼ばない
- [x] コマンド層は `ProfileWriter` / `CredentialStore` の型にのみ依存
      （`FileProfileWriter` / `RootConfigInput` / `ProfileEntry` を直接参照しない）
- [x] `DeleteProfileOptions` を export して writer とコマンド側の opts 型を共有

## テスト計画

施策 4（次節）が本施策のテスト。実装前に赤いことを確認してから着手する。

- [ ] `tests/profile/delete.test.ts` を先に書き、fail を確認
- [ ] 既存 `tests/profile/resolve.test.ts` は無変更で green のまま
- [ ] `profile:add` のロールバック 2 箇所が引き続き動く（`deleteProfile(name)` の opts 省略）

## リスク

| リスク | 対応 |
|--------|------|
| **keychain backend で OAuth token が消え残る** | `clearProfile()` は index に載らない meta エントリを消さない。`deleteOAuthToken()` を明示的に呼ぶ設計にし、施策 4 の keychain ケースで固定する |
| `--clear-default` 有りで残 1 件のとき勝手に default が変わる | 曖昧さゼロのケースに限定し、**stdout に付け替え先を必ず出す**。2 件以上では選ばない |
| `api_url` 欠落プロファイルで credential が孤児化する | 警告を出して config だけ落とす。孤児 credential の位置は元々導出不能で、削除を拒否すると詰む方が悪い |
| credential index 破損で削除できない | `CredentialStore.purgeProfile()` が best-effort で破棄し、削除自体は完遂する。keychain は列挙不能なので「取りこぼしがありうる」旨を警告して手動確認を促す |
| **`api_url` が不正形式**（`canonicalOrigin` が throw）で削除できない | `resolveOriginOrNull()` で握って警告 + config 削除を続行（Round 1 [Warning]）|
| `writer.get()` が interface に無い実装が将来現れる | `ProfileWriter` は interface として全メソッドを宣言済み。実装追加時に型で強制される |

---

# 施策 4: `profile:delete` の 3 backend 横断テスト

## 変更箇所

- 新規: `packages/cli/tests/profile/delete.test.ts`（3 backend 横断 = ロジック層）
- 新規: `packages/cli/tests/commands/profile/delete.test.ts`（CLI 契約 = コマンド層）

`packages/cli/vitest.config.ts` の `include: ["tests/**/*.test.ts"]` に自動で乗る。

## 波及変更

- TypeScript 型定義: なし
- テストファイル: 本ファイルが新規テスト本体
- `tests/setup/credential-backend.ts`: **変更しない**。keychain ケースは
  自スコープで `ENV.DISABLE_KEYCHAIN` を解除し、in-memory Fake を注入して戻す

## テスト設計

### 依存注入の形

```ts
function makeWriter(configPath: string): FileProfileWriter {
    return new FileProfileWriter(configPath);
}

function makeFileStore(baseDir: string, registry: MasterKeyRegistry): FileStore {
    return new FileStore(baseDir, registry);
}

function makeStore(opts: {
    keychain?: KeychainStore | null;
    fileStore?: FileStore;
}): CredentialStore {
    return new CredentialStore(opts);
}
```

- `FileProfileWriter(userPath)` / `FileStore(baseDir, registry)` /
  `CredentialStore({ keychain, fileStore })` はすべてコンストラクタ注入を受け付ける（実査済み）
- したがって**実ホームディレクトリを一切触らない**

### keychain の in-memory Fake

`KeychainStore` は `EntryCtor` をコンストラクタ注入できる（`credential/keychain.ts:46-49`）:

```ts
type Stored = Map<string, string>;

/** 複合キーの区切り。不可視文字を直書きせず明示エスケープで書く。 */
const KEY_SEP = "\u0000";

function fakeKeychain(store: Stored): KeychainStore {
    class FakeEntry {
        constructor(
            private readonly service: string,
            private readonly username: string,
        ) {}
        private get key(): string {
            return `${this.service}${KEY_SEP}${this.username}`;
        }
        getPassword(): string | null {
            return store.get(this.key) ?? null;
        }
        setPassword(password: string): void {
            store.set(this.key, password);
        }
        deletePassword(): boolean {
            return store.delete(this.key);
        }
    }
    return new KeychainStore(FakeEntry);
}
```

`isAvailable()` は `ENV.DISABLE_KEYCHAIN === "1"` で早期 false を返すため、
keychain ケースの `beforeEach`/`afterEach` でこの env を**自スコープだけ**解除・復帰する。

### backend ごとの前提

| backend | セットアップ |
|---------|-------------|
| keychain | `ENV.DISABLE_KEYCHAIN` を削除 → `fakeKeychain()` を `CredentialStore` に注入 |
| file-encrypted | `ENV.CREDENTIAL_KEY` に Base64 32 バイトを投入 → `registry.ensure(origin, name)` を各プロファイル分 await |
| file-plaintext | `setGlobalAllowPlaintextFlag(true)` + `delete process.env["CI"]` |

`file-store.test.ts` の `beforeEach`/`afterEach`（env 退避・復帰・`setGlobalAllowPlaintextFlag(false)`）を踏襲する。

### 検証軸（概念設計 施策 4 の 7 軸）

```
describe.each(BACKENDS)("profile:delete (%s)", (backend) => {
  it("1. credential が消える")
  it("2. config エントリが消える")
  it("5. credential 不在プロファイルの削除が成功する (冪等)")
  it("4. 他プロファイルの credential が生存し、同じ master key で復号できる")
      // ※ 4 は file-encrypted のみ master key 復号まで検証。
      //    keychain / plaintext は「他プロファイルの値が読める」ことを検証
})

describe("default_profile transitions (判断 8 の 5 ケース)")   // 3
describe("ProfileWriter.deleteProfile の原子性")               // 6
describe("部分失敗の収束")                                      // 7
```

#### 1. credential が消える（3 backend 共通）

```ts
it("credential (apikey + OAuth token) が消える", async () => {
    const { writer, store, origin } = await setup(backend);
    await store.writeWithPreflight(origin, "prod", "apikey", "", "secret");
    await writeOAuthToken(store, origin, "prod", sampleBundle());

    deleteProfileWithCredentials({ writer, store }, "prod", {
        clearDefault: true,
    });

    expect(store.read(origin, "prod", "apikey", "")).toBeNull();
    // OAuth token は meta 名前空間 = clearProfile では消えない (keychain)。
    // deleteOAuthToken を明示的に呼ぶ設計であることを固定する。
    expect(readOAuthToken(store, origin, "prod")).toBeNull();
    expect(store.listItems(origin, "prod")).toEqual([]);
});
```

file backend では追加で「プロファイルディレクトリが消える」も検証する:

```ts
expect(existsSync(join(credDir, deriveProfileHash12(origin, "prod")))).toBe(false);
```

> **この 1 本が施策 3 の最重要ガード**。`deleteOAuthToken` を落とすと
> keychain ケースだけが赤くなる（file backend はディレクトリごと消えるので緑のまま）。

#### 2. config エントリが消える

```ts
expect(writer.get("prod")).toBeUndefined();
expect(writer.list().map((r) => r.name)).toEqual(["staging"]);
```

#### 3. `default_profile` の 5 ケース（判断 8）

| ケース | 期待 |
|--------|------|
| 対象が default / `clearDefault: false` | `ProfileResolutionError` を throw（`exitCode === ExitCode.ProfileConflict`）。**config も credential も変わらない** |
| 対象が default / `clearDefault: true` / 残 1 件 | `writer.defaultProfileName() === 残り 1 件`、戻り値 `nextDefault` が同じ値 |
| 対象が default / `clearDefault: true` / 残 0 件 | `defaultProfileName() === undefined`、`remaining` が空 |
| 対象が default / `clearDefault: true` / 残 2 件以上 | `defaultProfileName() === undefined`、`nextDefault === null`、`remaining.length === 2` |
| 対象が default でない | `defaultProfileName()` が変わらない |

> 1 ケース目は「credential も変わらない」ことまで検証する
> （default 判定が credential 破棄より**前**にあることの固定）。

#### 4. 他プロファイルの master key / credential の生存

```ts
it("他プロファイルの credential が生存し、同じ master key で復号できる", async () => {
    // ... prod / staging の両方に apikey を書く
    deleteProfileWithCredentials({ writer, store }, "prod", { clearDefault: true });

    // 偽陽性の排除: MasterKeyRegistry のプロセス内キャッシュを捨て、
    // FileStore / CredentialStore を新しいインスタンスで組み直す (別プロセス相当)。
    resetGlobalMasterKeyRegistryForTests();
    const freshRegistry = new MasterKeyRegistry();
    const freshFileStore = new FileStore(credDir, freshRegistry);
    const freshStore = new CredentialStore({
        keychain: null,
        fileStore: freshFileStore,
    });
    await freshRegistry.ensure(origin, "staging");

    expect(freshStore.read(origin, "staging", "apikey", "")).toBe("staging-secret");
});
```

（keychain ケースは registry を使わないので、Fake の `Map` に staging のエントリが
残っていることを検証する）

#### 5. 冪等性（3 backend 共通）

```ts
it("credential が無いプロファイルの削除も成功する", () => {
    // credential を 1 つも書かずに profile だけ登録
    expect(() =>
        deleteProfileWithCredentials({ writer, store }, "prod", {
            clearDefault: true,
        }),
    ).not.toThrow();
    expect(writer.get("prod")).toBeUndefined();
});
```

#### 6. `ProfileWriter.deleteProfile` の原子性

```ts
describe("deleteProfile の原子性", () => {
    it("1 回の呼び出しで profiles 除去と default 付け替えが同時に反映される", () => {
        writer.deleteProfile("prod", { clearDefault: true, nextDefault: "staging" });
        const reloaded = new FileProfileWriter(configPath);
        expect(reloaded.get("prod")).toBeUndefined();
        expect(reloaded.defaultProfileName()).toBe("staging");
    });

    it.each([
        ["対象が default でない", "staging", { clearDefault: true, nextDefault: "prod" }],
        ["clearDefault 無し", "prod", { nextDefault: "staging" }],
        ["nextDefault が自分自身", "prod", { clearDefault: true, nextDefault: "prod" }],
        ["nextDefault が不在", "prod", { clearDefault: true, nextDefault: "ghost" }],
    ])("不正な組合せ (%s) では config が一切変わらない", (_label, target, opts) => {
        const before = readFileSync(configPath, "utf-8");
        expect(() => writer.deleteProfile(target, opts)).toThrow();
        expect(readFileSync(configPath, "utf-8")).toBe(before);
    });
});
```

#### 7. 部分失敗の収束

config 保存だけを失敗させるため、`ProfileWriter` の**テスト用スタブ**を使う
（`FileProfileWriter` を継承せず、interface を満たす最小実装で `deleteProfile` だけ throw させる）:

```ts
it("credential 破棄後に config 保存が失敗しても再実行で収束する", () => {
    const real = new FileProfileWriter(configPath);
    let failNext = true;
    const flaky: ProfileWriter = {
        ...bindAll(real),
        deleteProfile: (name, opts) => {
            if (failNext) {
                failNext = false;
                throw new Error("disk full");
            }
            real.deleteProfile(name, opts);
        },
    };

    const errors: string[] = [];
    const spy = vi
        .spyOn(console, "error")
        .mockImplementation((msg: unknown) => void errors.push(String(msg)));

    // (a) 1 回目: throw する
    expect(() =>
        deleteProfileWithCredentials({ writer: flaky, store }, "prod", {
            clearDefault: true,
        }),
    ).toThrow("disk full");

    // (b) 再実行コマンド文字列が stderr に出る
    expect(errors.join("\n")).toContain(`${BIN_NAME} profile:delete prod`);
    // (c) config 側には profile が残る (状態が観測可能)
    expect(real.get("prod")).toBeDefined();

    // (d) 同じコマンドの再実行で収束する (credential 不在パスを通って成功)
    expect(() =>
        deleteProfileWithCredentials({ writer: flaky, store }, "prod", {
            clearDefault: true,
        }),
    ).not.toThrow();
    expect(real.get("prod")).toBeUndefined();
    spy.mockRestore();
});
```

### CLI 契約テスト（`tests/commands/profile/delete.test.ts`）

ロジック層のテストは exit code / 確認プロンプト / stdout 文言といった
**CLI としての契約**を固定できない（詳細設計レビュー Round 1 の [Warning]）。
コマンド層のテストを別ファイルで最小追加する。

**技法（JST 2026-08-05 に実測で検証済み）**:

```ts
import { fileURLToPath } from "node:url";
import ProfileDelete from "../../../src/oclif/commands/profile/delete.js";

/** oclif Config のルート。dist をビルドしていなくても Config.load は通る（実測）。 */
const CLI_ROOT = fileURLToPath(new URL("../../../", import.meta.url));

/**
 * `homedir()` は $HOME を見るので、HOME を一時ディレクトリへ向ければ
 * `userConfigPath()` も `FileStore` の既定 baseDir もそこに閉じる。
 * resolveProfileBundle() が引数なしで生成する CredentialStore /
 * FileProfileWriter を注入せずに hermetic にできる唯一の手段。
 */
function withTempHome(): { home: string; restore: () => void } { /* ... */ }

/**
 * process.exit のモックは throw する。`BaseCommand.catch` がその throw を
 * 拾って **もう一度** exit(1) を呼ぶため、単純な rejects.toThrow では
 * 常に 1 を見てしまう。**最初に記録された code** で判定すること（実測で確認）。
 */
function captureExitCodes(): { codes: number[]; restore: () => void } { /* ... */ }
```

| # | 検証 | 期待 |
|---|------|------|
| 1 | 未登録プロファイル名を渡す | `codes[0] === ExitCode.ProfileNotFound` (11) |
| 2 | default を `--clear-default` 無しで削除 | `codes[0] === ExitCode.ProfileConflict` (10) |
| 3 | 不正なプロファイル名（`Prod` 等） | `codes[0] === ExitCode.ProfileInvalidName` (13) |
| 4 | 非 TTY かつ `--yes` 無し（確認が取れない） | `codes[0] === ExitCode.GeneralError` (1)。**config も credential も無傷** |
| 5 | `--yes` 付きの正常削除 | `process.exit` が呼ばれない（exit 0）。config から消えている |
| 6 | default を `--clear-default --yes` で削除・残 1 件 | stdout に `default_profile = <残り>` が出る |

- `HOME` は `beforeEach`/`afterEach` の対で退避・復帰する
- 実際の keychain を触らないよう `ENV.DISABLE_KEYCHAIN=1`（グローバル setup の既定）はそのまま使う
- backend は file-plaintext 1 本でよい（backend 別の網羅はロジック層のテストが担当。
  ここで見るのは**コマンドの契約**）

## 型適合チェック

- [x] スタブは `ProfileWriter` interface を**明示的に満たす**（`as unknown as` を使わない）
- [x] `describe.each` の引数に型注釈を付ける（`readonly BackendName[]`）
- [x] `console.error` の spy は `vi.spyOn` を `mockRestore()` まで含めて使う
      （`file-store.test.ts` の作法に合わせる）
- [x] env 退避・復帰は `beforeEach` / `afterEach` の対で行い、他テストへ漏らさない
- [x] `packages/cli/tsconfig.test.json` の対象なので `pnpm typecheck:packages` で検査される

## テスト計画

- [ ] 両ファイルを施策 3 の**前に**追加し、`deleteProfileWithCredentials` / `ProfileDelete` が未実装で赤いことを確認
- [ ] 施策 3 の実装後に全ケース green
- [ ] **`deleteOAuthToken` の呼び出しを外すと keychain ケースだけが赤くなる**ことを手元で確認
      （このテストが本当に効いているかの逆確認）
- [ ] `pnpm test:packages` 全体 green（既存 7 テストへの副作用が無い）

## リスク

| リスク | 対応 |
|--------|------|
| `ENV.DISABLE_KEYCHAIN` の解除が他テストへ漏れる | keychain ケース専用の `describe` 内で `beforeEach`/`afterEach` の対にして復帰させる |
| `MasterKeyRegistry` のプロセス内キャッシュで生存検証が偽陽性になる | `resetGlobalMasterKeyRegistryForTests()` + 全インスタンス再構築（概念設計 判断 7） |
| scrypt (N=16384) が遅くテストがタイムアウトする | `vitest.config.ts` の `testTimeout: 15000` は既存 `file-store.test.ts` の暗号化ケースで実績あり。プロファイル数は最小限（2〜3）に抑える |
| `describe.each` で backend ごとの前提が混線する | backend ごとに一時ディレクトリを作り直し、env を毎回退避・復帰する |
| CLI 契約テストが `HOME` を書き換えて他テストを汚す | `beforeEach`/`afterEach` の対で退避・復帰。ファイルを分けているので混線しない |
| `Config.load` が `dist/` を要求してテストが落ちる | **実測済み**: `dist/` 無しでも `ProfileUse.run(argv, CLI_ROOT)` が期待どおり exit 11 を記録した（JST 2026-08-05） |

---

# 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 4 コミットが順序依存（施策 5 の CI 配線が無いと施策 4/6 のテストが CI で走らない。施策 6 の atomic replacement が無いと施策 3 の「1 回の save」の安全性が担保されない）。また Track A（Markdown + app 側 vitest）と Track B（`packages/cli`）は**ファイルが 1 つも重ならない**ので、同一 worktree で順に積んでも競合しない |
| 競合リスク | **低**。触るのは `.claude/skills/` / `tests/js/architecture/` / `packages/cli/` / `.github/workflows/ci.yml` / root `package.json` / `AGENTS.md` のみ。`app/` `resources/` `routes/` `database/` に一切触れないので、並走中の他タスク（PHP / Svelte 側）とは衝突しない。唯一の共有点は root `package.json` の `scripts`（1 行追加）と `AGENTS.md`（1 行編集） |

## 最終確認（使命・禁止事項チェック）

| 項目 | 確認 |
|------|------|
| 使命への寄与 | 間接（レビュー品質基盤 + CLI の開発運用リスク低減）と**明示的に限定**。動画品質等の過大主張なし |
| 禁止事項 1（テストなしの実装完了） | 施策 1 → 施策 2 のテスト、施策 3 → 施策 4 のテスト、施策 6 → `saver.test.ts` がそれぞれ対応。**施策 5 でそれらが CI で走る配線まで含む** |
| 禁止事項 2（PHPStan widen） | PHP 変更なし。TS 側で `any` / cast の新規導入を禁止し、`exactOptionalPropertyTypes` に適合させる |
| 禁止事項 3（dev DB 破壊操作） | DB を一切使わない。`composer test` にも依存しない（devcontainer に PostgreSQL 無し） |
| 禁止事項 4〜8 | 該当なし（PHP / Blade / Svelte UI を変更しない） |
| 禁止事項 9（Artifact 使用） | 成果物はすべてリポジトリ内ファイル（`devnotes/` + コード） |
| セキュリティ不変条件 | tenant / 認可 / CipherSweet / SSRF いずれも本バッチの変更対象外。ただし **credential 破棄漏れ（keychain の OAuth token）** を新たに塞ぐ方向 |
| テンプレート逸脱の記録 | `docs/template-divergence.md` への追記は不要（判断 5 の理由により逸脱ではない） |


---

## 追加の現行コード（今回の変更対象周辺）

### `packages/cli/src/credential/store.ts` (L196-300)

```ts
        itemId: string,
    ): boolean {
        const idx = this.readIndex(canonicalOrigin, profileName);
        return idx.some((e) => e.kind === itemKind && e.id === itemId);
    }

    delete(
        canonicalOrigin: string,
        profileName: string,
        itemKind: Exclude<ItemKind, "meta">,
        itemId: string,
    ): void {
        this.primary().delete(
            canonicalOrigin,
            profileName,
            itemKind,
            itemId,
        );
        this.removeFromIndex(canonicalOrigin, profileName, {
            kind: itemKind,
            id: itemId,
        });
    }

    listItems(
        canonicalOrigin: string,
        profileName: string,
    ): ReadonlyArray<IndexEntry> {
        return this.readIndex(canonicalOrigin, profileName);
    }

    clearProfile(canonicalOrigin: string, profileName: string): void {
        const items = this.readIndex(canonicalOrigin, profileName);
        const backend = this.primary();
        for (const it of items) {
            backend.delete(canonicalOrigin, profileName, it.kind, it.id);
        }
        backend.delete(canonicalOrigin, profileName, "meta", META_INDEX_ID);
        this.fileStore.clearProfile(canonicalOrigin, profileName);
    }

    /**
     * Low-level meta entry API (U-22). Index is not touched — internal meta
     * keys like `migration:{siteId}` live alongside `index` without being
     * enumerated by `listItems`. Callers are responsible for key namespacing
     * (we reject `"index"` to protect the credential index from clobbering).
     */
    readMeta(
        canonicalOrigin: string,
        profileName: string,
        metaId: string,
    ): string | null {
        assertMetaIdSafe(metaId);
        return this.primary().read(
            canonicalOrigin,
            profileName,
            "meta",
            metaId,
        );
    }

    writeMeta(
        canonicalOrigin: string,
        profileName: string,
        metaId: string,
        value: string,
    ): void {
        assertMetaIdSafe(metaId);
        this.primary().write(
            canonicalOrigin,
            profileName,
            "meta",
            metaId,
            value,
        );
    }

    deleteMeta(
        canonicalOrigin: string,
        profileName: string,
        metaId: string,
    ): void {
        assertMetaIdSafe(metaId);
        this.primary().delete(
            canonicalOrigin,
            profileName,
            "meta",
            metaId,
        );
    }

    /**
     * Expose the underlying FileStore for low-level enumeration. Returns
     * null when the keychain backend is active — keychain does not expose
     * enumeration. Used by tests to exercise corruption paths.
     */
    fileStoreOrNull(): FileStore | null {
        return this.keychain === null ? this.fileStore : null;
    }

    private readIndex(
        canonicalOrigin: string,
        profileName: string,
    ): IndexItem[] {
        const raw = this.primary().read(
```
### `packages/cli/src/profile/canonical-origin.ts` (L1-20)

```ts
export function canonicalOrigin(apiUrl: string): string {
    let u: URL;
    try {
        u = new URL(apiUrl);
    } catch {
        throw new Error(`Invalid api_url: ${apiUrl}`);
    }
    if (u.protocol !== "https:" && u.protocol !== "http:") {
        throw new Error(`Unsupported protocol: ${u.protocol}`);
    }
    const defaultPort = u.protocol === "https:" ? "443" : "80";
    const port = u.port !== "" ? u.port : defaultPort;
    return `${u.protocol}//${u.hostname.toLowerCase()}:${port}`;
}

```
### `tests/js/architecture/svg-inline-allowlist.test.ts` (L1-20)

```ts
import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * Lucide 以外の inline SVG 直書きを機械統制する。
 *
 * 通常のアイコンは `@lucide/svelte` の <Icon> component を使う（import 経由のため本検知対象外）。
 *
 * file-scoped allowlist は「DOM 要素では描けないデータ可視化（チャート / 座標系オーバーレイ等）」に
 * 限定し、テンプレートは 0 件で出荷する。新規に inline SVG を足したい場合は、本当に SVG が必須か
 * （Lucide / DOM で代替不可か）を検討し、正当なら allowlist へ理由付きで追加する。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");

/** inline SVG 許可ファイル（resources/js からの相対パス）。DOM 不可のデータ可視化のみ登録可。 */
const SVG_INLINE_ALLOWLIST: readonly string[] = [] as const;

/**
```

