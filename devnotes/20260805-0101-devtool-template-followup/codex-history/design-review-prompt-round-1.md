## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

（アプリの使命・禁止事項は app-codex-review スキルにより AGENTS.md から自動挿入済み）

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【本件固有の前提 — 重要】
本バッチは **PHP を一切変更しない**。変更対象は
(a) `.claude/skills/app-*/SKILL.md`（Claude に読ませる手順書。Markdown）
(b) `packages/cli`（oclif v4 + TypeScript の first-party CLI。独立 vitest lane、
    tsconfig は strict + exactOptionalPropertyTypes + noUncheckedIndexedAccess）
(c) `tests/js/architecture/`（app 側 vitest の architecture テスト）
(d) `.github/workflows/ci.yml` / root `package.json` / `AGENTS.md`
したがってレビュー観点 3(PHPStan) / 5(DTO/JsonResource) / 6(Inertia Props) は
**TypeScript の型安全性・既存 API の再利用・CLI の出力契約**として読み替えて評価すること。
観点 10(DESIGN.md) / 11(Atomic Design) は UI 変更が無いため該当なし。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. 型安全性（strict / exactOptionalPropertyTypes / noUncheckedIndexedAccess 適合、any・cast の不使用）
4. テスト計画の網羅性（各施策にテスト、テストファースト = 実装前に赤くなるか、CI で実際に走るか）
5. 既存抽象の遵守（ProfileWriter / CredentialStore の境界を迂回していないか）
6. CLI の出力契約（exit code の一貫性、stderr/stdout の使い分け、詰みを作らないか）
7. 副作用・後退リスク（既存コマンド profile:add / profile:use / auth:logout への影響）
8. 波及変更の網羅性（型定義、import 元、CI、規約ドキュメントが変更対象に含まれているか）
9. セキュリティ（credential の破棄漏れ、孤児化、master key の破壊、OWASP、AGENTS.md のセキュリティ不変条件）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---
## 詳細設計書

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
| 3 | `profile:delete` コマンド | `packages/cli/src/profile/delete.ts` (新規), `packages/cli/src/oclif/commands/profile/delete.ts` (新規), `packages/cli/src/profile/writer.ts` | 高 |
| 4 | `profile:delete` の 3 backend 横断テスト | `packages/cli/tests/profile/delete.test.ts` (新規) | 高 |

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
| `packages/cli/src/profile/delete.ts` | **新規**（削除オーケストレーションの純粋関数） |
| `packages/cli/src/oclif/commands/profile/delete.ts` | **新規**（薄い oclif シェル） |

### なぜロジックをコマンドから切り出すのか

本リポジトリの既存テスト作法がそうなっている。`doctor` は
`src/doctor/runner.ts` の `runDoctor(opts)` に本体を置き、
oclif コマンドは薄いシェルで、テストは `runDoctor` を直接叩いて
依存（`credentialStore` / `packageJsonPath` / `stdout`）を注入している
（`tests/commands/doctor/doctor.test.ts`）。

`profile:delete` も同じ形にする。`resolveProfileBundle()` は
`new CredentialStore()` / `new FileProfileWriter()` を**引数なしで**生成する
（`oclif/base/profile-context.ts:178-183`）ため、コマンドを直接叩くテストは
実ホームディレクトリを触ってしまう。ロジックを注入可能な関数にすれば
一時ディレクトリの `FileProfileWriter(tmpPath)` / `FileStore(tmpDir)` を渡せる。

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

### (b) `packages/cli/src/profile/delete.ts`（新規）

```ts
import { BIN_NAME } from "../branding.js";
import { CredentialStoreError } from "../credential/errors.js";
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
    /** credential 破棄をスキップしたか (api_url 欠落時のみ true)。 */
    credentialsSkipped: boolean;
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
    // api_url が欠落した壊れた profile も削除できなければならない
    // (assertProfileHasApiUrl のエラー文言自体が profile:delete を案内している)。
    // その場合 credential の位置を導出できないので破棄はスキップする。
    const apiUrl = entry.api_url;
    let credentialsSkipped = false;
    if (apiUrl === undefined || apiUrl === "") {
        credentialsSkipped = true;
        console.error(
            `Warning: profile "${name}" has no api_url, so its stored `
                + "credentials cannot be located. Removing the config entry "
                + "only; check ~/." + BIN_NAME + "/credentials manually.",
        );
    } else {
        const origin = canonicalOrigin(apiUrl);
        clearCredentials(store, origin, name);
    }

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

    return { wasDefault, nextDefault, remaining, credentialsSkipped };
}

/**
 * credential (indexed items + credential index + OAuth token) を落とす。
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
): void {
    deleteOAuthToken(store, origin, name);
    try {
        store.clearProfile(origin, name);
    } catch (e) {
        // credential index が壊れていると readIndex が throw する。
        // profile:delete は「壊れた状態からの回復手段」なので、ここで
        // 詰ませない: file backend ならディレクトリごと落として収束させる。
        if (!(e instanceof CredentialStoreError)) throw e;
        const fileStore = store.fileStoreOrNull();
        if (fileStore === null) throw e;
        console.error(
            `Warning: the credential index for profile "${name}" is corrupted; `
                + "removing the whole credential directory instead.",
        );
        fileStore.clearProfile(origin, name);
    }
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
| credential index 破損 かつ keychain backend | 18 `CredentialStoreFailure` | `CredentialStoreError` → `BaseCommand.catch` |
| config 保存失敗 | 1 `GeneralError` | 生の `Error` → `BaseCommand.catch` |

**新しい exit code は 1 つも追加しない**。

## 型適合チェック

- [x] 戻り値の型が明示されている（`DeleteProfileResult` / `void`）
- [x] `exactOptionalPropertyTypes`: `nextDefault` は**値があるときだけプロパティを生やす**
- [x] `noUncheckedIndexedAccess`: `remaining[0]` は `string | undefined` なので `?? null`
- [x] `entry.api_url` は schema 上 optional なので `undefined` / `""` を両方判定
- [x] `any` / ad-hoc cast なし。`instanceof CredentialStoreError` で型を絞る
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
| credential index 破損で削除できない | file backend ならディレクトリごと落として収束。keychain は列挙不能なので exit 18 + 手動修復案内（既存 `corruptedIndex` の文言どおり） |
| `writer.get()` が interface に無い実装が将来現れる | `ProfileWriter` は interface として全メソッドを宣言済み。実装追加時に型で強制される |

---

# 施策 4: `profile:delete` の 3 backend 横断テスト

## 変更箇所

- 新規: `packages/cli/tests/profile/delete.test.ts`

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

function fakeKeychain(store: Stored): KeychainStore {
    class FakeEntry {
        constructor(
            private readonly service: string,
            private readonly username: string,
        ) {}
        private get key(): string {
            return `${this.service}\u0000${this.username}`;
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

## 型適合チェック

- [x] スタブは `ProfileWriter` interface を**明示的に満たす**（`as unknown as` を使わない）
- [x] `describe.each` の引数に型注釈を付ける（`readonly BackendName[]`）
- [x] `console.error` の spy は `vi.spyOn` を `mockRestore()` まで含めて使う
      （`file-store.test.ts` の作法に合わせる）
- [x] env 退避・復帰は `beforeEach` / `afterEach` の対で行い、他テストへ漏らさない
- [x] `packages/cli/tsconfig.test.json` の対象なので `pnpm typecheck:packages` で検査される

## テスト計画

- [ ] 本ファイルを施策 3 の**前に**追加し、`deleteProfileWithCredentials` が未実装で赤いことを確認
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

## 関連する現行コード（抜粋）

### `packages/cli/src/profile/writer.ts` (L1-60)

```ts
import { loadConfigFromPath } from "../config/loader.js";
import { userConfigPath } from "../config/paths.js";
import { saveConfigToPath } from "../config/saver.js";
import {
    RootConfigInputSchema,
    type ProfileEntry,
    type RootConfigInput,
} from "../config/schema.js";
import { isValidProfileName } from "./name.js";
import type {
    MutableConnectionOptionsPatch,
    VerificationMetadata,
} from "./patch-types.js";

export type ProfileInit = {
    api_url: string;
    expected_environment_tag?: string | null;
    allow_insecure?: boolean;
};

export interface ProfileWriter {
    list(): ReadonlyArray<{ name: string; entry: ProfileEntry }>;
    get(name: string): ProfileEntry | undefined;
    snapshot(name: string): ProfileEntry;
    addProfile(name: string, init: ProfileInit): void;
    updateExpectedEnv(name: string, expected: string | null): void;
    deleteProfile(name: string, opts?: { clearDefault?: boolean }): void;
    useDefaultProfile(name: string): void;
    applyAtomic(
        name: string,
        patch: MutableConnectionOptionsPatch,
        verifyResult: VerificationMetadata,
    ): void;
    persistVerificationMeta(name: string, meta: VerificationMetadata): void;
}

type ExtraEntryFields = {
    [K in keyof ProfileEntry]?: ProfileEntry[K];
};

function applyPatchToEntry(
    base: ProfileEntry,
    patch: MutableConnectionOptionsPatch,
): ProfileEntry {
    const out: ProfileEntry = { ...base };
    // Iterate explicitly so we can delete keys set to `undefined` (which the
    // strict schema rejects as `undefined` values). Null is kept.
    const keys: Array<keyof MutableConnectionOptionsPatch> = [
        "ca_bundle",
        "http_proxy",
        "https_proxy",
        "allow_insecure",
        "timeout_ms",
        "retry_max",
        "retry_backoff_ms",
    ];
    for (const k of keys) {
        if (!Object.prototype.hasOwnProperty.call(patch, k)) continue;
        const v = patch[k];
        if (v === undefined) {
```

...(中略: applyPatchToEntry / mergeVerificationMeta)...

### `packages/cli/src/profile/writer.ts` (L88-215)

```ts
export class FileProfileWriter implements ProfileWriter {
    constructor(private readonly userPath: string = userConfigPath()) {}

    private loadUser(): RootConfigInput {
        const loaded = loadConfigFromPath(this.userPath);
        return loaded ?? {};
    }

    private save(next: RootConfigInput): void {
        // Clone and strip any `undefined` leaves that strict() would reject.
        const sanitized = stripUndefined(next);
        const validated = RootConfigInputSchema.parse(sanitized);
        saveConfigToPath(this.userPath, validated);
    }

    list(): ReadonlyArray<{ name: string; entry: ProfileEntry }> {
        const user = this.loadUser();
        const profiles = user.profiles ?? {};
        return Object.entries(profiles).map(([name, entry]) => ({
            name,
            entry,
        }));
    }

    get(name: string): ProfileEntry | undefined {
        return this.loadUser().profiles?.[name];
    }

    snapshot(name: string): ProfileEntry {
        const entry = this.get(name);
        if (!entry) throw new Error(`profile "${name}" not found`);
        return structuredClone(entry);
    }

    addProfile(name: string, init: ProfileInit): void {
        if (!isValidProfileName(name)) {
            throw new Error(`invalid profile name: ${name}`);
        }
        const user = this.loadUser();
        const profiles = { ...(user.profiles ?? {}) };
        if (profiles[name]) {
            throw new Error(`profile "${name}" already exists`);
        }
        const entry: ProfileEntry = { api_url: init.api_url };
        if (init.expected_environment_tag !== undefined) {
            entry.expected_environment_tag = init.expected_environment_tag;
        }
        if (init.allow_insecure !== undefined) {
            entry.allow_insecure = init.allow_insecure;
        }
        profiles[name] = entry;
        this.save({ ...user, profiles });
    }

    updateExpectedEnv(name: string, expected: string | null): void {
        const user = this.loadUser();
        const entry = user.profiles?.[name];
        if (!entry) throw new Error(`profile "${name}" not found`);
        const next: ProfileEntry = {
            ...entry,
            expected_environment_tag: expected,
        };
        this.save({
            ...user,
            profiles: { ...(user.profiles ?? {}), [name]: next },
        });
    }

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

    useDefaultProfile(name: string): void {
        const user = this.loadUser();
        if (!user.profiles?.[name]) {
            throw new Error(`profile "${name}" not found`);
        }
        this.save({ ...user, default_profile: name });
    }

    applyAtomic(
        name: string,
        patch: MutableConnectionOptionsPatch,
        verifyResult: VerificationMetadata,
    ): void {
        const user = this.loadUser();
        const entry = user.profiles?.[name];
        if (!entry) throw new Error(`profile "${name}" not found`);
        const patched = applyPatchToEntry(entry, patch);
        const merged = mergeVerificationMeta(patched, verifyResult);
        this.save({
            ...user,
            profiles: { ...(user.profiles ?? {}), [name]: merged },
        });
    }

    persistVerificationMeta(
        name: string,
        meta: VerificationMetadata,
    ): void {
        const user = this.loadUser();
        const entry = user.profiles?.[name];
        if (!entry) throw new Error(`profile "${name}" not found`);
        const merged = mergeVerificationMeta(entry, meta);
        this.save({
            ...user,
            profiles: { ...(user.profiles ?? {}), [name]: merged },
        });
    }
}
```

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

### `packages/cli/src/credential/file-store.ts` (L183-250)

```ts
    delete(
        canonicalOrigin: string,
        profileName: string,
        itemKind: ItemKind,
        itemId: string,
    ): void {
        const ph12 = deriveProfileHash12(canonicalOrigin, profileName);
        for (const path of [
            this.encPath(ph12, itemKind, itemId),
            this.datPath(ph12, itemKind, itemId),
        ]) {
            if (existsSync(path)) rmSync(path);
        }
    }

    clearProfile(canonicalOrigin: string, profileName: string): void {
        const ph12 = deriveProfileHash12(canonicalOrigin, profileName);
        const dir = this.profileDir(ph12);
        if (existsSync(dir)) rmSync(dir, { recursive: true, force: true });
    }

    listItemsOnDisk(
        canonicalOrigin: string,
        profileName: string,
    ): ReadonlyArray<{ kind: ItemKind; id: string }> {
        const ph12 = deriveProfileHash12(canonicalOrigin, profileName);
        const dir = this.profileDir(ph12);
        if (!existsSync(dir)) return [];
        const seen = new Set<string>();
        const out: Array<{ kind: ItemKind; id: string }> = [];
        for (const fname of readdirSync(dir)) {
            const parsed = parseFilename(fname);
            if (parsed === null) continue;
            const dedupKey = `${parsed.kind}::${parsed.id}`;
            if (seen.has(dedupKey)) continue;
            seen.add(dedupKey);
            out.push(parsed);
        }
        return out;
    }
}

function hasEncryptionEnv(): boolean {
    const rawKey = process.env[ENV_CREDENTIAL_KEY];
    if (rawKey !== undefined && rawKey !== "") return true;
    const password = process.env[ENV_MASTER_PASSWORD];
    if (password !== undefined && password !== "") return true;
    return false;
}

function isPlaintextAllowed(): boolean {
    return isPlaintextOptInAllowed();
}

/**
 * Single source of truth for "does this write need the encrypted path (and
 * therefore a loaded master key)?" (F-0-02). Used by `FileStore.write`,
 * `FileStore.detectMode` AND `CredentialStore.prepareForWrite` so the
 * preflight decision can never drift from the actual write routing.
 */
export function shouldUseEncryptedPath(): boolean {
    // encrypted when encryption env present; otherwise encrypted unless the
    // user explicitly opted into plaintext (encrypted is the secure default).
    return hasEncryptionEnv() || !isPlaintextAllowed();
}

function parseFilename(
    fname: string,
```

### `packages/cli/src/credential/keychain.ts` (L44-70)

```ts
export class KeychainStore implements BackendStore {
    private readonly ctor: EntryCtor | null;

    constructor(ctor: EntryCtor | null = loadEntryCtor()) {
        this.ctor = ctor;
    }

    isAvailable(): boolean {
        // Test-only escape hatch. The read-only probe below returns
        // `true` on GitHub Actions Linux runners because `@napi-rs/keyring`'s
        // fallback store answers the probe but rejects the real write with
        // `QuotaExceeded`. Setting this flag in CI/test harnesses forces the
        // file backend consistently. Never read in production code paths.
        if (process.env[ENV.DISABLE_KEYCHAIN] === "1") return false;
        if (this.ctor === null) return false;
        try {
            const e = new this.ctor(SERVICE, "_uxi_probe_");
            // Probe read — both "value found" and "not found" confirm the
            // backend is reachable. Any other error means unavailable.
            e.getPassword();
            return true;
        } catch (e) {
            return isNotFoundError(e);
        }
    }

    write(
```

### `packages/cli/src/credential/keychain.ts` (L129-152)

```ts
    }

    delete(
        canonicalOrigin: string,
        profileName: string,
        itemKind: ItemKind,
        itemId: string,
    ): void {
        if (this.ctor === null) return;
        const key = deriveKeychainKey(
            canonicalOrigin,
            profileName,
            itemKind,
            itemId,
        );
        try {
            new this.ctor(SERVICE, key).deletePassword();
        } catch (e) {
            if (isNotFoundError(e)) return;
            console.error(
                `Error: keychain delete failed for ${profileName}/${itemKind}/`
                    + `${itemId}: ${(e as Error).message}`,
            );
            exitWith(ExitCode.CredentialStoreFailure);
```

### `packages/cli/src/credential/atomic-write.ts`

```ts
import {
    closeSync,
    fsyncSync,
    openSync,
    renameSync,
    writeFileSync,
} from "node:fs";

/**
 * tmp write -> fsync -> atomic rename. Same-filesystem is required; cross-
 * device rename is intentionally not handled.
 */
export function atomicWriteFile(
    path: string,
    content: string,
    mode: number = 0o600,
): void {
    writeThenRename(path, content, mode);
}

/** Binary variant used by the UXI1 encrypted file-store. */
export function atomicWriteFileBinary(
    path: string,
    content: Uint8Array,
    mode: number = 0o600,
): void {
    writeThenRename(path, content, mode);
}

function writeThenRename(
    path: string,
    content: string | Uint8Array,
    mode: number,
): void {
    const tmp = `${path}.${String(process.pid)}.tmp`;
    if (typeof content === "string") {
        writeFileSync(tmp, content, { encoding: "utf-8", mode });
    } else {
        writeFileSync(tmp, content, { mode });
    }
    const fd = openSync(tmp, "r");
    try {
        fsyncSync(fd);
    } finally {
        closeSync(fd);
    }
    renameSync(tmp, path);
}

```

### `packages/cli/src/config/saver.ts`

```ts
import { writeFileSync, mkdirSync, existsSync } from "node:fs";
import { dirname } from "node:path";
import { stringify as stringifyYaml } from "yaml";
import { RootConfigInputSchema, type RootConfigInput } from "./schema.js";

/**
 * Atomically write a RootConfigInput to the given path.
 * Creates the parent directory if it does not exist.
 *
 * The input is re-validated with `RootConfigInputSchema.parse` before writing
 * so that we never persist an invalid config.
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

### `packages/cli/src/oclif/commands/profile/use.ts`

```ts
import { Args } from "@oclif/core";
import { ExitCode, exitWith } from "../../../exit-codes.js";
import { assertProfileName } from "../../../profile/name.js";
import { ProfileCommand } from "../../base/ProfileCommand.js";

export default class ProfileUse extends ProfileCommand {
    static override description =
        "Set default_profile (the only command that can change it).";
    static override args = {
        name: Args.string({ description: "profile name", required: true }),
    };

    protected override persistentRequired = false;
    protected override resolveMode: "if-needed" = "if-needed";

    public async run(): Promise<void> {
        const { args, flags } = await this.parse(ProfileUse);
        this.latchCiFlag(flags.ci);
        const { writer } = await this.resolveContext(flags);
        assertProfileName(args.name);
        if (!writer.get(args.name)) exitWith(ExitCode.ProfileNotFound);
        writer.useDefaultProfile(args.name);
        this.log(`default_profile = ${args.name}`);
    }
}

```

### `packages/cli/src/oclif/commands/profile/add.ts` (L23-137)

```ts
export default class ProfileAdd extends ProfileCommand {
    static override description =
        "Register a new profile and verify connection (/version + optional /me).";
    static override args = {
        name: Args.string({ description: "profile name", required: true }),
    };
    static override flags = {
        "expected-env": Flags.string({
            description: "pin expected environment_tag",
        }),
        "allow-insecure": Flags.boolean({ description: "permit http:// URL" }),
        // F-0-02: declared explicitly so the plaintext opt-in is unambiguous
        // on `profile:add` (no longer relying on baseFlags inheritance).
        "allow-plaintext-credentials":
            profileFlags["allow-plaintext-credentials"],
        yes: Flags.boolean({ description: "skip confirmations (CI mode)" }),
        force: Flags.boolean({
            description: "bypass environment_tag mismatch",
        }),
    };

    protected override persistentRequired = false;
    protected override resolveMode: "if-needed" = "if-needed";

    public async run(): Promise<void> {
        const { args, flags } = await this.parse(ProfileAdd);
        this.latchCiFlag(flags.ci);
        const { writer, store } = await this.resolveContext(flags);
        const name = args.name;
        assertProfileName(name);
        const apiUrl = flags["api-url"];
        if (apiUrl === undefined) {
            console.error("Error: profile:add requires --api-url <url>.");
            exitWith(ExitCode.GeneralError);
        }
        if (writer.get(name)) {
            console.error(
                `Error: profile "${name}" already exists. `
                    + `Run \`profile:delete ${name}\` first to recreate.`,
            );
            exitWith(ExitCode.ProfileAlreadyExists);
        }
        const connOpts = {
            ...defaultConnectionOptions(),
            allow_insecure: flags["allow-insecure"] === true,
        };
        const result = await verifyOrExitGranular(
            apiUrl,
            flags["api-key"] ?? null,
            connOpts,
            flags["expected-env"] ?? null,
            getCliVersion(),
        );

        const init: {
            api_url: string;
            expected_environment_tag?: string | null;
            allow_insecure?: boolean;
        } = { api_url: apiUrl };
        if (flags["expected-env"] !== undefined) {
            init.expected_environment_tag = flags["expected-env"];
        }
        if (flags["allow-insecure"] === true) {
            init.allow_insecure = true;
        }
        writer.addProfile(name, init);

        try {
            if (
                flags["expected-env"] === undefined
                && result.environment_tag_source === "explicit"
                && result.environment_tag !== null
            ) {
                writer.updateExpectedEnv(name, result.environment_tag);
            } else if (
                flags["expected-env"] === undefined
                && flags.yes !== true
                && process.stdin.isTTY
            ) {
                const ok = await confirmPrompt(
                    `Server reports environment_tag=`
                        + `"${String(result.environment_tag)}" `
                        + `(source: ${result.environment_tag_source}). `
                        + "Save without expected_environment_tag pinning?",
                );
                if (!ok) {
                    writer.deleteProfile(name);
                    exitWith(ExitCode.GeneralError);
                }
            }
            writer.persistVerificationMeta(name, result);

            if (flags["api-key"] !== undefined) {
                await store.writeWithPreflight(
                    canonicalOrigin(apiUrl),
                    name,
                    "apikey",
                    "",
                    flags["api-key"],
                );
            }
            this.log(
                `Profile "${name}" added (endpoint: `
                    + `${endpointFingerprint(canonicalOrigin(apiUrl))}).`,
            );
        } catch (e) {
            try {
                writer.deleteProfile(name);
            } catch {
                /* best-effort rollback */
            }
            throw e;
        }
    }
}
```

### `packages/cli/src/oclif/base/ProfileCommand.ts` (L66-116)

```ts
export abstract class ProfileCommand extends BaseCommand {
    static override baseFlags = { ...BaseCommand.baseFlags, ...profileFlags };

    /**
     * Whether the command can only be run against a persistent profile.
     * Defaults to `false`; write-side commands override to `true` so the
     * wrapper fails fast (`exit 17`) when the user passes `--api-url`
     * instead of naming a persistent profile.
     */
    protected persistentRequired: boolean = false;

    /**
     * Whether profile resolution must run even when the command wasn't
     * given `--profile` / `--api-url` (e.g. `profile:show` falling back
     * to the default profile). "always" means the wrapper will call
     * resolveProfile; "if-needed" skips resolution for commands that act
     * purely on the local profile config (like `profile:use`).
     */
    protected resolveMode: ResolveMode = "if-needed";

    /**
     * Thin wrapper over {@link resolveProfileBundle} that reads instance
     * fields (`persistentRequired`, `resolveMode`) and flags into the
     * shared helper. See the helper's JSDoc for failure modes.
     *
     * Commands that accept a positional profile name (e.g. `profile:show
     * [name]`) should merge the positional into `flags.profile` before
     * calling this — the wrapper does not special-case positional args.
     */
    protected async resolveContext(
        flags: ProfileFlags,
        options: { printBanner?: boolean } = {},
    ): Promise<ResolvedProfile> {
        const bundle: ProfileResolveBundle = await resolveProfileBundle(
            {
                profile: flags.profile,
                apiUrl: flags["api-url"],
                apiKey: flags["api-key"],
                allowPlaintextCredentials: flags["allow-plaintext-credentials"],
            },
            {
                resolveMode: this.resolveMode,
                persistentRequired: this.persistentRequired,
                ...(options.printBanner !== undefined
                    ? { printBanner: options.printBanner }
                    : {}),
            },
        );
        return bundle;
    }
}
```

### `packages/cli/src/oclif/base/BaseCommand.ts` (L31-70)

```ts
    static override baseFlags = { ...ciFlag };

    /**
     * Latch the global `--ci` state. Subclasses call this immediately
     * after `this.parse(...)` so every downstream helper (prompt, plaintext
     * guard, playwright auto-install) sees a consistent CI boolean.
     */
    protected latchCiFlag(ci: boolean | undefined): void {
        const value = ci === true;
        setGlobalCiFlag(value);
        setGlobalCiFlagForPlaintextGuard(value);
    }

    /**
     * Translate typed errors thrown by core helpers into the CLI's
     * canonical exit-code contract. Unknown errors print their message
     * and exit {@link ExitCode.GeneralError}.
     */
    protected override async catch(
        err: Error & { exitCode?: number; oclif?: { exit?: number } },
    ): Promise<never> {
        // Re-throw oclif's own exit errors so --help / --version keep
        // working (they throw a CLIError with oclif.exit=0).
        if (err.oclif !== undefined) {
            throw err;
        }
        if (err instanceof CredentialStoreError) {
            console.error(`Error: ${err.message}`);
            exitWith(err.exitCode);
        }
        if (err instanceof ProfileResolutionError) {
            console.error(`Error: ${err.message}`);
            exitWith(err.exitCode);
        }
        const message = err.message !== "" ? err.message : "Unknown error";
        console.error(`Error: ${message}`);
        exitWith(ExitCode.GeneralError);
    }
}

```

### `packages/cli/src/oclif/base/profile-context.ts` (L171-237)

```ts

```

### `packages/cli/src/profile/errors.ts` (L21-37)

```ts
export class ProfileResolutionError extends Error {
    public readonly exitCode: ExitCodeValue;

    constructor(message: string, exitCode: ExitCodeValue) {
        super(message);
        this.name = "ProfileResolutionError";
        this.exitCode = exitCode;
    }

    static conflict(message: string): ProfileResolutionError {
        return new ProfileResolutionError(message, ExitCode.ProfileConflict);
    }

    static notFound(message: string): ProfileResolutionError {
        return new ProfileResolutionError(message, ExitCode.ProfileNotFound);
    }
}
```

### `packages/cli/src/credential/errors.ts` (L14-31)

```ts
export class CredentialStoreError extends Error {
    public readonly exitCode: ExitCodeValue;

    constructor(message: string, exitCode: ExitCodeValue) {
        super(message);
        this.name = "CredentialStoreError";
        this.exitCode = exitCode;
    }

    static corruptedIndex(profileName: string, cause: string): CredentialStoreError {
        return new CredentialStoreError(
            `credential index is corrupted for profile "${profileName}" `
                + `(${cause}). Manual repair required.`,
            ExitCode.CredentialStoreFailure,
        );
    }
}

```

### `packages/cli/src/profile/assertions.ts` (L11-26)

```ts
 * profile via `profile:add`.
 */
export function assertProfileHasApiUrl(
    entry: ProfileEntry,
    name: string,
): string {
    const url = entry.api_url;
    if (!url || typeof url !== "string" || url.length === 0) {
        console.error(
            `Error: profile "${name}" is missing api_url (ConfigInconsistent). `
                + `Run \`profile:delete ${name}\` and re-create with profile:add.`,
        );
        exitWith(ExitCode.ConfigInconsistent);
    }
    return url;
}
```

### `packages/cli/src/profile/resolve.ts` (L157-190)

```ts
    }

    const profileSource: ResolvedBy = argv.profile
        ? "argv-profile"
        : envProfile
          ? `env-${ENV.PROFILE}`
          : project?.profile
            ? "project-profile"
            : user?.default_profile
              ? "user-default-profile"
              : "builtin-production";

    const profileName = argv.profile
        ?? envProfile
        ?? project?.profile
        ?? user?.default_profile
        ?? "production";

    const entry = user?.profiles?.[profileName];
    if (!entry) {
        throw ProfileResolutionError.notFound(
            `profile "${profileName}" not found in user config. `
                + `Run \`${BIN_NAME} profile:add ${profileName} --api-url <url>\` first.`,
        );
    }

    return await makeNamedContext(
        profileName,
        entry,
        argv,
        env,
        profileSource,
        apiKeyLoader,
    );
```

### `packages/cli/src/oauth/token-store.ts` (L85-96)

```ts
        opts,
    );
}

export function deleteOAuthToken(
    store: CredentialStore,
    canonicalOrigin: string,
    profileName: string,
): void {
    store.deleteMeta(canonicalOrigin, profileName, OAUTH_META_ID);
}

```

### `packages/cli/src/profile/name.ts`

```ts
import { ExitCode, exitWith } from "../exit-codes.js";

const PROFILE_NAME_RE = /^[a-z0-9][a-z0-9_-]{0,62}$/;
const RESERVED = new Set(["default", "ephemeral", "system", "admin", "_internal"]);

export function isValidProfileName(name: string): boolean {
    if (!PROFILE_NAME_RE.test(name)) return false;
    if (RESERVED.has(name)) return false;
    if (name.startsWith("ephemeral-")) return false;
    return true;
}

export function assertProfileName(name: string): void {
    if (!isValidProfileName(name)) {
        console.error(
            `Error: invalid profile name "${name}". `
                + `Must match /^[a-z0-9][a-z0-9_-]{0,62}$/ and must not be one of `
                + `default, ephemeral, system, admin, _internal `
                + `(or start with "ephemeral-").`,
        );
        exitWith(ExitCode.ProfileInvalidName);
    }
}

```

### `packages/cli/src/exit-codes.ts` (L1-60)

```ts
// CLI-wide exit code registry.
// Grouped by domain so future commands can extend without collision.

export const ExitCode = {
    Success: 0,
    GeneralError: 1,
    // config domain: 2-5
    ProtectedKey: 2,
    SchemaViolation: 3,
    KeyNotFound: 4,
    ConfigInconsistent: 5,
    // cross-domain 6-9 (generic):
    InvalidOptionKey: 7,
    // profile domain: 10-19 (U-15)
    ProfileConflict: 10,
    ProfileNotFound: 11,
    ProfileAlreadyExists: 12,
    ProfileInvalidName: 13,
    ConnectionFailed: 14,
    EnvironmentTagMismatch: 15,
    CapabilityMissing: 16,
    EphemeralRestricted: 17,
    CredentialStoreFailure: 18,
    ProfileVerifyRollback: 19,
    // auth domain: 20-29 (U-23 — T119)
    AuthCredentialNotFound: 20,
    AuthTypeUnknown: 21,
    AuthValidationFailed: 22,
    // 23 reserved (intentionally unused — see tests/exit-codes.test.ts)
    AuthTestFailed: 24,
    AuthExportForbidden: 25,
    // 26 reserved (was AuthMigrationConflict, removed in T145/YAGNI-02)
    AuthEnvironmentNotFound: 27,
    AuthDependencyMissing: 28,
    // 29 reserved (intentionally unused)
    // encryption domain: 30-39 (U-21 / U-24)
    // 34/35/39 were the 2-phase migration backup codes, removed in
    // T145/YAGNI-02 — the numbers are left as reserved holes so existing
    // CI scripts never get a new meaning silently reassigned.
    EncryptionKeyMissing: 30,
    EncryptionKeyInvalid: 31,
    MasterPasswordRequired: 32,
    DecryptionFailed: 33,
    // 34/35 reserved (were MigrationBackupMissing / MigrationBackupExpired)
    FormRedirectTooManyHops: 36,
    FormBotDetectionDetected: 37,
    // 38 reserved (intentionally unused)
    // 39 reserved (was MigrationMetaCorrupted)
    // scan domain: 40-49 (U-34 — T131)
    ScanAdapterNotFound: 40,
    ScanNoRoutes: 41,
    ScanAdapterFailed: 42,
    // 50 was ProfileMismatch (expected_profile guard, T142). Removed in
    // T147/YAGNI-05 — URL mismatches surface as 404/401 naturally, so the
    // guard duplicated work. The slot is intentionally left empty: if a
    // future runtime-safety code lands in the 50-59 range it should pick
    // an unused number rather than reuse 50 so operators reading old CI
    // logs never get a stealth meaning change.
} as const;

```

### `packages/cli/tsconfig.json` (L1-30)

```json
{
    "compilerOptions": {
        "target": "ES2022",
        "module": "NodeNext",
        "moduleResolution": "NodeNext",
        "strict": true,
        "noUnusedLocals": true,
        "noUnusedParameters": true,
        "exactOptionalPropertyTypes": true,
        "noUncheckedIndexedAccess": true,
        "declaration": true,
        "declarationMap": true,
        "sourceMap": true,
        "esModuleInterop": true,
        "skipLibCheck": true,
        "forceConsistentCasingInFileNames": true,
        "resolveJsonModule": true,
        "outDir": "./dist",
        "rootDir": "./src"
    },
    "include": ["src/**/*.ts"],
    "exclude": ["node_modules", "dist", "tests"]
}

```

### `packages/cli/vitest.config.ts`

```ts
import { defineConfig } from "vitest/config";

export default defineConfig({
    test: {
        include: ["tests/**/*.test.ts"],
        environment: "node",
        // 資格情報バックエンドをホスト非依存に固定する (setup の解説参照)。
        setupFiles: ["tests/setup/credential-backend.ts"],
        testTimeout: 15000,
    },
});

```

### `vitest.config.ts`

```ts
import { defineConfig } from "vitest/config";
import { svelte } from "@sveltejs/vite-plugin-svelte";
import { svelteTesting } from "@testing-library/svelte/vite";
import path from "path";

export default defineConfig({
    plugins: [
        svelte({
            hot: !process.env.VITEST,
            compilerOptions: {},
        }),
        svelteTesting(),
    ],
    test: {
        globals: true,
        environment: "jsdom",
        // CPU を食い尽くさないよう並列ワーカーをコア数の半分に抑える
        // (環境非依存: 10コア→5, 8コア→4 のように自動追従)
        maxWorkers: "50%",
        minWorkers: 1,
        setupFiles: ["./tests/js/setup.ts"],
        include: ["tests/js/**/*.test.ts", "scripts/**/*.test.ts"],
        coverage: {
            provider: "v8",
            reporter: ["text", "json", "html"],
            exclude: [
                "node_modules/",
                "tests/",
                "**/*.d.ts",
                "**/*.config.*",
                "**/mockData",
            ],
        },
    },
    resolve: {
        alias: {
            "@": path.resolve(__dirname, "./resources/js"),
        },
    },
});

```

### `.claude/skills/app-codex-vscode/SKILL.md` (L30-55)

```markdown
---

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

---
```

### `.github/workflows/ci.yml` (L50-71)

```yaml
  frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with:
          version: 11.3.0
      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: pnpm
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

