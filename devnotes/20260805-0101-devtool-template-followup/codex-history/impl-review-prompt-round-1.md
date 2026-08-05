【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
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

---

# system: あなたの役割

あなたはコードレビュアーである。Laravel + Svelte アプリ (aicue) の開発ツール追従バッチ (TODO: T100) の実装をレビューする。

**本バッチは PHP / Svelte を一切変更しない**。変更対象は
`.claude/skills/`(Markdown) / `tests/js/architecture/`(vitest) / `packages/cli/`(TypeScript oclif CLI) /
`.github/workflows/ci.yml` / root `package.json` / `AGENTS.md` のみである。
したがって PHPStan / Pest / Pint / DTO / JsonResource / DESIGN.md / Atomic Design の観点は
**適用対象が存在しない**（該当なしと明記してよい。無理に指摘を作らないこと）。

## レビュー観点（本バッチに実際に効くもの）

1. **詳細設計との一致性** — 設計書 (APPROVED Round 5) の判断・不変条件が実装に落ちているか。
   意図的な逸脱があるなら、その逸脱に正当性があるか
2. **正確性** — ロジックの誤り、境界条件、例外経路、リソースリーク
3. **TypeScript 型安全性**（PHPStan widen 禁止に相当する縛り）
   - `packages/cli` は `strict` + `exactOptionalPropertyTypes: true` + `noUncheckedIndexedAccess: true`
   - `any` / ad-hoc な `as` cast を新規に導入していないか
4. **テスト網羅性** — 不変条件がテストで固定されているか。テストが「本当に効いているか」
   (実装を壊すとそのテストが赤くなるか)
5. **セキュリティ** — 資格情報 (API key / OAuth token / per-site credential) の破棄漏れ、
   消してはいけないものを消す事故、秘密の孤児化 (到達不能化)、TOCTOU
6. **CLI 契約** — exit code の整合、確認プロンプトと事前検証の順序、後方互換

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する
  - Critical: 正しさ・安全性が壊れている。マージ前に必ず直すべき
  - Warning: 直すべきだが、判断次第で見送りうる
  - Suggestion: 好みの範囲
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する
- 既に設計書で明示的に検討・決着済みの論点を蒸し返す場合は、
  設計書の当該判断を引用したうえで「なぜそれでは不足か」を述べること

---

# user

## 1. 詳細設計書 (APPROVED Round 5)

以下が本実装の正本である。

```markdown
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
| 3 | `profile:delete` コマンド | `packages/cli/src/profile/delete.ts` (新規), `packages/cli/src/oclif/commands/profile/delete.ts` (新規), `packages/cli/src/profile/writer.ts`, `packages/cli/src/credential/store.ts`, `packages/cli/src/credential/errors.ts`, `packages/cli/src/credential/keychain.ts`, `packages/cli/src/oclif/commands/profile/use.ts` (説明文 1 行) | 高 |
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
| `packages/cli/src/profile/writer.ts` L21-35 / L156-176 | `ProfileWriter` に `readState()` 追加（1 回の読み込みで default + 全プロファイル）、`deleteProfile` に `nextDefault` 追加 |
| `packages/cli/src/credential/store.ts` | `purgeProfile()` を追加（取りこぼしの有無を `complete` で報告する破棄 API） |
| `packages/cli/src/credential/errors.ts` | `CredentialStoreError` に判別子 `kind` を追加（既定 `"unknown"` で既存呼び出し元は無変更） |
| `packages/cli/src/profile/delete.ts` | **新規**（削除オーケストレーションの純粋関数） |
| `packages/cli/src/oclif/commands/profile/delete.ts` | **新規**（薄い oclif シェル） |
| `packages/cli/src/credential/keychain.ts` L1 / L9 | `SERVICE` を `branding.ts` の `KEYCHAIN_SERVICE` 参照へ（値は同一。定義の二重化を解消）|
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

CLI 契約（事前検証の順序 / exit code / 確認プロンプト分岐）は別途コマンド層のテストで固定する
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

- TypeScript 型定義: `ProfileWriter` interface（`readState()` 追加 / `deleteProfile` の opts 拡張）、
  `ProfileState` / `DeleteProfileOptions` / `CredentialLocation` / `ProfileDeletionPlan` の新設。
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

/**
 * 1 回の config 読み込みから得た状態のスナップショット。
 *
 * `get()` / `list()` / default の 3 情報を**別々の loadUser() で**取ると、
 * その間に他プロセスが config を書き替えたとき不整合な計画を作りうる。
 * 削除の計画フェーズは必ずこれ 1 回で読む。
 */
export type ProfileState = {
    defaultProfile: string | undefined;
    profiles: ReadonlyArray<{ name: string; entry: ProfileEntry }>;
};

export interface ProfileWriter {
    list(): ReadonlyArray<{ name: string; entry: ProfileEntry }>;
    get(name: string): ProfileEntry | undefined;
    /** default_profile と全プロファイルを **1 回の読み込みで** 返す。 */
    readState(): ProfileState;
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
    readState(): ProfileState {
        const user = this.loadUser();
        const profiles = user.profiles ?? {};
        const state: ProfileState = {
            defaultProfile: user.default_profile,
            profiles: Object.entries(profiles).map(([name, entry]) => ({
                name,
                entry,
            })),
        };
        return state;
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
export type PurgeProfileResult = {
    /**
     * このプロファイルの資格情報を **取りこぼしなく** 破棄できたか。
     * `false` のとき、呼び出し側は config を消してはならない
     * (api_url を失うと残った資格情報の在り処を二度と導出できなくなる)。
     */
    complete: boolean;
    /** credential index が読めなかったか (診断用)。 */
    indexCorrupted: boolean;
};
```

```ts
    /**
     * `profile:delete` 用の破棄 API。
     *
     * `clearProfile` は index を読めないと
     * `CredentialStoreError`(kind: "corrupted-index") を投げる。
     * その場合の挙動は backend で本質的に異なる:
     *
     *   - file backend: プロファイルディレクトリを丸ごと落とせるので、
     *     index が読めなくても **取りこぼしは発生しない** → complete: true
     *   - keychain backend: 列挙手段が無く、index を失うと個々の item を
     *     特定できない → **complete: false**。呼び出し側は config を残し、
     *     利用者に手動清掃を求めること (config を消すと api_url が失われ、
     *     残った資格情報が永久に到達不能な孤児になる)
     */
    purgeProfile(
        canonicalOrigin: string,
        profileName: string,
    ): PurgeProfileResult {
        try {
            this.clearProfile(canonicalOrigin, profileName);
            return { complete: true, indexCorrupted: false };
        } catch (e) {
            // index 破損**だけ**を握る。復号失敗・削除失敗など他の
            // CredentialStoreError は握り潰さず素通しする。
            if (
                !(e instanceof CredentialStoreError)
                || e.kind !== "corrupted-index"
            ) {
                throw e;
            }
        }
        // index が読めなくても消せるものは消す。
        this.primary().delete(
            canonicalOrigin,
            profileName,
            "meta",
            META_INDEX_ID,
        );
        this.fileStore.clearProfile(canonicalOrigin, profileName);
        // keychain が primary のときだけ取りこぼしがありうる。
        return {
            complete: this.keychain === null,
            indexCorrupted: true,
        };
    }
```

- `clearProfile()` は**そのまま残す**（既存の意味を変えない = 後方互換の並走ではなく、
  `purgeProfile` は `clearProfile` の上に載る薄いラッパ）
- `fileStoreOrNull()` は引き続きテスト専用のまま（本番からは呼ばない）

### (a3) `packages/cli/src/credential/errors.ts` — 判別子 `kind` の追加

`purgeProfile` が「index 破損**だけ**」を握るために、エラー側へ判別子を足す。
これが無いと、将来 `clearProfile` の中から復号失敗・削除失敗が同じ型で飛んだとき
**誤って握り潰す**（詳細設計レビュー Round 2 の [Warning]）。

```ts
export type CredentialStoreErrorKind = "corrupted-index" | "unknown";

export class CredentialStoreError extends Error {
    public readonly exitCode: ExitCodeValue;
    public readonly kind: CredentialStoreErrorKind;

    constructor(
        message: string,
        exitCode: ExitCodeValue,
        kind: CredentialStoreErrorKind = "unknown",
    ) {
        super(message);
        this.name = "CredentialStoreError";
        this.exitCode = exitCode;
        this.kind = kind;
    }

    static corruptedIndex(
        profileName: string,
        cause: string,
    ): CredentialStoreError {
        return new CredentialStoreError(
            `credential index is corrupted for profile "${profileName}" `
                + `(${cause}). Manual repair required.`,
            ExitCode.CredentialStoreFailure,
            "corrupted-index",
        );
    }
}
```

既定値 `"unknown"` により**既存の呼び出し元は 1 箇所も変えなくてよい**
（`corruptedIndex` ファクトリのみが `"corrupted-index"` を名乗る）。

### (b) `packages/cli/src/profile/delete.ts`（新規）

**plan / execute の 2 段構成**にする。CLI の事前検証（存在確認・default 競合）は
**確認プロンプトより前**に済ませなければならないため、副作用のない計画フェーズを分ける
（詳細設計レビュー Round 3 の [Warning]）。

```ts
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
 * 削除計画。**副作用ゼロ**で作られ、コマンド層は確認プロンプトの前にこれを
 * 組み立てて競合を検出する。
 */
/**
 * credential の在り処。判別共用体にして「origin と reason が両方 null」
 * のような表現不能状態を型で排除する。
 */
export type CredentialLocation =
    | { kind: "located"; origin: string }
    | { kind: "unlocatable"; reason: string };

export type ProfileDeletionPlan = {
    name: string;
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
 * api_url から canonical origin を導く。欠落・不正なら **理由つきで null を返す**
 * (throw しない)。壊れた profile を削除できないほうが害が大きいため。
 * 計画フェーズから呼ばれるので **stderr へ書かない**（副作用ゼロを守る）。
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
```

#### keychain サービス名の単一化（Round 3 の [Suggestion]）

手動清掃の案内でサービス名を出す以上、案内と実際の保存先が一致していなければならない。
現状は**同じ値の定義が 2 箇所**にある:

- `branding.ts:27` — `export const KEYCHAIN_SERVICE = APP_SLUG;`（「OS keychain のサービス名」と明記された正本）
- `credential/keychain.ts:9` — `const SERVICE = \`${BIN_NAME}\`;`（module 内の独自定義）

`keychain.ts` を正本参照に変える（1 行）:

```ts
import { ENV, KEYCHAIN_SERVICE } from "../branding.js";
...
const SERVICE = KEYCHAIN_SERVICE;
```

値は現状と同一（`APP_SLUG === BIN_NAME`）なので**挙動は変わらない**。
暗黙の一致前提を消すのが目的である。

### (c) `packages/cli/src/oclif/commands/profile/delete.ts`（新規）

```ts
import { Args, Flags } from "@oclif/core";
import { BIN_NAME } from "../../../branding.js";
import { confirmPrompt } from "../../../credential/prompt.js";
import { ExitCode, exitWith } from "../../../exit-codes.js";
import {
    executeProfileDeletion,
    planProfileDeletion,
} from "../../../profile/delete.js";
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

        // 事前検証は **確認プロンプトより前**。ここで
        // profile 不在 (11) / default 競合 (10) が確定する。
        // 後ろに置くと非 TTY 環境で「本当は 10 なのに確認が取れず 1」になる。
        const plan = planProfileDeletion(writer, name, {
            clearDefault: flags["clear-default"] === true,
        });

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

        const result = executeProfileDeletion({ writer, store }, plan);

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

1. 名前検証（`assertProfileName`）
2. **`planProfileDeletion()`（副作用ゼロ）** — 存在確認 / `default_profile` 判定 /
   残プロファイル列挙 / `nextDefault` 確定 / credential の在り処解決。
   **ここで exit 11 と exit 10 が確定する**
3. 確認プロンプト（`--yes` でスキップ）
4. `executeProfileDeletion()` —
   4a. **TOCTOU ガード**: 再計画して最初の計画と突き合わせ、食い違えば**何もせず** exit 10
   4b. **credential 破棄**（OAuth token → indexed items）
5. → **`writer.deleteProfile(name, { clearDefault, nextDefault })` を 1 回だけ**
6. 結果案内

> **不変条件**: 事前検証（2）は確認プロンプト（3）より**必ず前**。
> 逆にすると `--yes` の有無で exit code が変わる（非 TTY で 10 → 1 に化ける）。
> 施策 4 の CLI 契約テスト #2 がこの順序を固定する。

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
| 確認待ちの間に config が別プロセスで変わった | 10 `ProfileConflict` | `executeProfileDeletion` の TOCTOU ガード。**何も削除しない** |
| 確認待ちの間に profile 自体が消えた | 11 `ProfileNotFound` | 再計画時の `notFound`。**何も削除しない** |
| credential index 破損 / **file backend** | **0**（警告つきで削除は完遂）| ディレクトリごと消えるので取りこぼしが無い (`complete: true`) |
| credential index 破損 / **keychain backend** | 18 `CredentialStoreFailure` | 列挙できず取りこぼしがありうるため **config を残して停止**。api_url を失って資格情報が孤児化するのを防ぐ。手動清掃 → 再実行を案内 |
| config 保存失敗 | 1 `GeneralError` | 生の `Error` → `BaseCommand.catch` |

**新しい exit code は 1 つも追加しない**。

> 表の 11 / 10 は **`--yes` の有無に関わらず**同じ値になる。
> `planProfileDeletion()` を確認プロンプトより前に呼ぶ設計がその保証であり、
> 施策 4 の CLI 契約テスト #2 が回帰を固定する。

## 型適合チェック

- [x] 戻り値の型が明示されている（`DeleteProfileResult` / `void`）
- [x] `exactOptionalPropertyTypes`: `nextDefault` は**値があるときだけプロパティを生やす**
- [x] `noUncheckedIndexedAccess`: `remaining[0]` は `string | undefined` なので `?? null`
- [x] `entry.api_url` は schema 上 optional なので `undefined` / `""` を両方判定
- [x] `any` / ad-hoc cast なし。`purgeProfile` 内の例外は
      `instanceof CredentialStoreError && kind === "corrupted-index"` で二重に絞る
- [x] catch 節の `unknown` は `e instanceof Error ? e.message : String(e)` で絞る（`as Error` を使わない）
- [x] `CredentialStoreErrorKind` の既定値 `"unknown"` により既存呼び出し元は無変更
- [x] 本番ロジックからテスト専用 API (`fileStoreOrNull()`) を呼ばない
- [x] `planProfileDeletion()` は **stderr へ書かない**（副作用ゼロ）。
      警告文言は `CredentialLocation` の `reason` として計画に載せ、
      `executeProfileDeletion()` が出力する
- [x] `CredentialLocation` は判別共用体。「origin と reason が両方 null」のような
      表現不能状態を型で排除し、`String(null)` 防御を不要にする
- [x] 計画フェーズの config 読み込みは `readState()` の **1 回だけ**
      （`get()` / `list()` / default を別々に読むと計画自体が不整合になりうる）
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
| credential index 破損 (file backend) | `purgeProfile()` がディレクトリごと落として完遂（`complete: true`）|
| credential index 破損 (keychain backend) | 列挙不能なので **config を残して exit 18**。`config` を消すと api_url を失い、keychain に到達不能な秘密が固定されるため（Round 2 [Critical]）。手動清掃 → 再実行を案内する |
| **`api_url` が不正形式**（`canonicalOrigin` が throw）で削除できない | `locateCredentials()` で握って理由を計画に載せ、警告 + config 削除を続行（Round 1 [Warning]）|
| 事前検証が確認プロンプトの後ろへ戻る改変 | CLI 契約テスト #2（`--yes` 無しでも最初の exit が 10）が赤くなる |
| **確認待ちの間に別プロセスが `api_url` を A→B に変更**し、A の credential を消して B の config を消す（B の credential が孤児化）| `executeProfileDeletion` の TOCTOU ガードが再計画して突き合わせ、**credential に触れる前に** exit 10 で停止（Round 4 [Critical]）|
| 計画フェーズ内での複数回読み込みによる不整合 | `readState()` で 1 回にまとめる |
| 手動清掃案内のサービス名が実際の保存先とずれる | `keychain.ts` の `SERVICE` を `branding.KEYCHAIN_SERVICE` 参照に一本化（Round 3 [Suggestion]）|
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

    executeProfileDeletion(
        { writer, store },
        planProfileDeletion(writer, "prod", { clearDefault: true }),
    );

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
| 対象が default / `clearDefault: false` | **`planProfileDeletion()` が** `ProfileResolutionError` を throw（`exitCode === ExitCode.ProfileConflict`）。**config も credential も変わらない**（計画フェーズは副作用ゼロ）|
| 対象が default / `clearDefault: true` / 残 1 件 | `writer.readState().defaultProfile === 残り 1 件`、戻り値 `nextDefault` が同じ値 |
| 対象が default / `clearDefault: true` / 残 0 件 | `readState().defaultProfile === undefined`、`remaining` が空 |
| 対象が default / `clearDefault: true` / 残 2 件以上 | `readState().defaultProfile === undefined`、`nextDefault === null`、`remaining.length === 2` |
| 対象が default でない | `readState().defaultProfile` が変わらない |

> 1 ケース目は「credential も変わらない」ことまで検証する
> （default 判定が credential 破棄より**前**にあることの固定）。

#### 4. 他プロファイルの master key / credential の生存

```ts
it("他プロファイルの credential が生存し、同じ master key で復号できる", async () => {
    // ... prod / staging の両方に apikey を書く
    executeProfileDeletion(
        { writer, store },
        planProfileDeletion(writer, "prod", { clearDefault: true }),
    );

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
        executeProfileDeletion(
            { writer, store },
            planProfileDeletion(writer, "prod", { clearDefault: true }),
        ),
    ).not.toThrow();
    expect(writer.get("prod")).toBeUndefined();
});
```

#### 5b. 壊れた profile / 破損 index の分岐（Round 2 [Warning] 対応）

| # | ケース | 期待 |
|---|--------|------|
| a | `api_url` が不正 URL（`"not a url"`）| 警告が出て **credential に一切触れず** config だけ消える。`credentialsSkipped === true` |
| b | `api_url` が非 http(s) スキーム（`"ftp://x"`）| 同上（`canonicalOrigin` が throw する経路）|
| c | `api_url` が空文字 / 未設定 | 同上 |
| d | **file backend** で index を破損させる（`meta_index.dat` に不正 JSON を書く）| 削除が**完遂**する。`credentialIndexCorrupted === true`、`writer.get(name)` が undefined、プロファイルディレクトリが消える |
| e | **keychain backend** で index を破損させる（Fake の Map に不正 JSON を入れる）| `CredentialStoreError`（`exitCode === 18`）を throw。**config は残る**（`writer.get(name)` が定義済み）。他プロファイルの credential も無傷 |
| f | index 破損**以外**の `CredentialStoreError` を握り潰さない | `purgeProfile` に `kind: "unknown"` の `CredentialStoreError` を投げるスタブ backend を渡し、そのまま伝播することを検証（`kind` による絞り込みが効いていること）|

> d と e は「同じ入力で backend により契約が変わる」ことを固定する。
> e は **config を消さない**ことが本質（消すと api_url を失い、
> keychain に到達不能な秘密が固定される）。

#### 5c. 確認待ち中の config 変更（TOCTOU、Round 4 [Critical] 対応）

`planProfileDeletion()` と `executeProfileDeletion()` の**間で** config を書き替え、
「何も削除されず競合終了する」ことを固定する。

| # | 計画後に起こす変化 | 期待 |
|---|------------------|------|
| a | **`api_url` を A → B に変更**（孤児化防止の必須ケース）| `ProfileResolutionError`（`exitCode === 10`）。**A の credential も B の credential も無傷**、config も無傷 |
| b | `default_profile` を他プロファイルへ付け替える | 同上（`wasDefault` / `nextDefault` の食い違いを検知）|
| c | 別プロファイルを追加する | 同上（`remaining` の食い違いを検知）|
| d | 対象プロファイル自体を消す | `ProfileResolutionError`（`exitCode === 11`）。credential 無傷 |
| e | 何も変えない | 正常に削除される（ガードが誤検知しないこと）|

```ts
it("確認待ちの間に api_url が変わったら何も削除せず競合終了する", async () => {
    const originA = canonicalOrigin("https://a.example.com");
    const originB = canonicalOrigin("https://b.example.com");
    await store.writeWithPreflight(originA, "prod", "apikey", "", "secret-a");
    await store.writeWithPreflight(originB, "prod", "apikey", "", "secret-b");

    const plan = planProfileDeletion(writer, "prod", { clearDefault: true });

    // 別プロセス相当の設定更新。`applyAtomic()` の patch 型 では api_url を
    // 変更できないため、config を直接書き戻して「他プロセスが書き替えた」状態を作る。
    saveConfigToPath(configPath, {
        default_profile: "prod",
        profiles: { prod: { api_url: "https://b.example.com" } },
    });

    expect(() => executeProfileDeletion({ writer, store }, plan)).toThrow(
        ProfileResolutionError,
    );
    // credential も config も無傷
    expect(store.read(originA, "prod", "apikey", "")).toBe("secret-a");
    expect(store.read(originB, "prod", "apikey", "")).toBe("secret-b");
    expect(writer.get("prod")).toBeDefined();
});
```

> **これが無いと何が壊れるか**: 古い計画のまま A の credential を消し、
> B の config を消す。B の credential は在り処を導出する `api_url` を失って
> **永久に到達不能な孤児**になる。fail-closed の意義は「消し損ね」ではなく
> 「**消してはいけないものを消さない**」ことにある。

#### 6. `ProfileWriter.deleteProfile` の原子性

```ts
describe("deleteProfile の原子性", () => {
    it("1 回の呼び出しで profiles 除去と default 付け替えが同時に反映される", () => {
        writer.deleteProfile("prod", { clearDefault: true, nextDefault: "staging" });
        const reloaded = new FileProfileWriter(configPath);
        expect(reloaded.get("prod")).toBeUndefined();
        expect(reloaded.readState().defaultProfile).toBe("staging");
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
        executeProfileDeletion(
            { writer: flaky, store },
            planProfileDeletion(flaky, "prod", { clearDefault: true }),
        ),
    ).toThrow("disk full");

    // (b) 再実行コマンド文字列が stderr に出る
    expect(errors.join("\n")).toContain(`${BIN_NAME} profile:delete prod`);
    // (c) config 側には profile が残る (状態が観測可能)
    expect(real.get("prod")).toBeDefined();

    // (d) 同じコマンドの再実行で収束する (credential 不在パスを通って成功)
    expect(() =>
        executeProfileDeletion(
            { writer: flaky, store },
            planProfileDeletion(flaky, "prod", { clearDefault: true }),
        ),
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
| 2 | default を `--clear-default` 無し **かつ `--yes` 無し**で削除 | `codes[0] === ExitCode.ProfileConflict` (10)。**確認プロンプトへ入らない**（`confirmPrompt` の spy が 0 回）。config・credential とも無傷 |
| 2b | 同上 + `--yes` あり | `codes[0]` は **同じく 10**（`--yes` の有無で exit が変わらないこと）|
| 3 | 不正なプロファイル名（`Prod` 等） | `codes[0] === ExitCode.ProfileInvalidName` (13) |
| 4 | 非 TTY かつ `--yes` 無し（確認が取れない） | `codes[0] === ExitCode.GeneralError` (1)。**config も credential も無傷** |
| 5 | `--yes` 付きの正常削除 | `process.exit` が呼ばれない（exit 0）。config から消えている |
| 6 | default を `--clear-default --yes` で削除・残 1 件 | stdout に `default_profile = <残り>` が出る |

- `HOME` は `beforeEach`/`afterEach` の対で退避・復帰する
- 実際の keychain を触らないよう `ENV.DISABLE_KEYCHAIN=1`（グローバル setup の既定）はそのまま使う
- backend は file-plaintext 1 本でよい（backend 別の網羅はロジック層のテストが担当。
  ここで見るのは**コマンドの契約**）
- #2 / #2b は `credential/prompt.js` の `confirmPrompt` を `vi.spyOn` で監視し、
  **呼ばれていない**ことまで検証する（事前検証がプロンプトより前にある証拠）

## 型適合チェック

- [x] スタブは `ProfileWriter` interface を**明示的に満たす**（`as unknown as` を使わない）
- [x] `describe.each` の引数に型注釈を付ける（`readonly BackendName[]`）
- [x] `console.error` の spy は `vi.spyOn` を `mockRestore()` まで含めて使う
      （`file-store.test.ts` の作法に合わせる）
- [x] env 退避・復帰は `beforeEach` / `afterEach` の対で行い、他テストへ漏らさない
- [x] `packages/cli/tsconfig.test.json` の対象なので `pnpm typecheck:packages` で検査される

## テスト計画

- [ ] 両ファイルを施策 3 の**前に**追加し、`planProfileDeletion` / `executeProfileDeletion` / `ProfileDelete` が未実装で赤いことを確認
- [ ] 施策 3 の実装後に全ケース green
- [ ] **`deleteOAuthToken` の呼び出しを外すと keychain ケースだけが赤くなる**ことを手元で確認
      （このテストが本当に効いているかの逆確認）
- [ ] **`purgeProfile` の `complete` 判定を `true` 固定に改悪すると 5b-e が赤くなる**ことを確認
      （keychain 孤児化ガードが本当に効いているかの逆確認）
- [ ] **`kind` による絞り込みを外すと 5b-f が赤くなる**ことを確認
- [ ] **`planProfileDeletion()` を確認プロンプトの後ろへ動かすと CLI 契約テスト #2 が赤くなる**ことを確認
- [ ] **`executeProfileDeletion()` の TOCTOU ガードを外すと 5c-a が赤くなる**ことを確認
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
```

## 2. 概念設計の主要判断 (参考)

設計ディレクトリ: `devnotes/20260805-0101-devtool-template-followup/` (conceptual-design.md も同ディレクトリにある。必要なら読んでよい)

## 3. テスト結果 (すべて worktree `.claude/worktrees/tasks/T100` 上で実測)

```
pnpm lint                 : OK (eslint resources/js)
pnpm typecheck            : OK (tsc --noEmit)
pnpm test                 : 107 files / 970 passed / 0 failed
pnpm build                : OK (vite build)
pnpm typecheck:packages   : OK (tsc --noEmit -p packages/cli/tsconfig.test.json)
pnpm test:packages        : 10 files / 102 passed / 0 failed
composer phpstan          : [OK] No errors (747 files)
vendor/bin/pint --test    : passed
composer test (Pest)      : **未実行**。この devcontainer に PostgreSQL が無く
                            (docker CLI 無し / DB_HOST=db は未起動の compose サービス)、
                            main でも同じ理由で落ちる既存事象。本バッチは PHP を
                            1 行も変更しておらず DB 非依存
```

### テストファースト (fail → 実装 → green) の実測記録

| 施策 | 実装前 | 実装後 |
|------|--------|--------|
| 2 → 1 (モデル一本化) | `codex-model-consistency.test.ts` の 2 本目が赤 (9 箇所ヒット)、1 本目 (inventory 一致) は緑 | 2 本とも緑 |
| 6 (atomic replacement) | `saver.test.ts` の 1 本目 (失敗注入) と 3 本目 (構造ガード) が赤、2 本目は緑 | 3 本とも緑 |
| 4 → 3 (profile:delete) | `tests/profile/delete.test.ts` / `tests/commands/profile/delete.test.ts` が import 解決不能で赤 | 全 43 本緑 |

### 逆確認 (mutation。「テストが本当に効いているか」の検証)

設計書 §施策 4 テスト計画に挙がった 6 種すべてを実測した。各ミューテーションは実測後に revert 済み。

| # | 改悪 | 期待 | 実測結果 |
|---|------|------|---------|
| M1 | `clearCredentials` から `deleteOAuthToken(...)` を削除 | keychain ケースだけ赤 | 1 failed: `profile:delete (keychain) > 1. credential (apikey + OAuth token) が消える` (file 系 2 backend は緑のまま) |
| M2 | `purgeProfile` の `complete: this.keychain === null` を `complete: true` 固定 | 5b-e が赤 | 1 failed: `e. keychain backend の index 破損では config を残して exit 18 で止まる` |
| M3 | `purgeProfile` の catch から `\|\| e.kind !== "corrupted-index"` を削除 | 5b-f が赤 | 1 failed: `f. index 破損以外の CredentialStoreError は握り潰さない` |
| M4 | コマンドの `planProfileDeletion()` を確認プロンプトの**後ろ**へ移動 | CLI 契約 #2 が赤 | 1 failed: `2. default を --clear-default 無し・--yes 無しで消すと exit 10 (プロンプトに入らない)` |
| M5 | `executeProfileDeletion` の TOCTOU ガードを無効化 | 5c-a が赤 | 3 failed: 5c-a / 5c-b / 5c-c |
| M6 | `SKILL_INVENTORY` から 1 行削除 | drift ガードが赤 | 1 failed: `走査対象 SKILL.md の集合が inventory と一致する (drift ガード)` |

## 4. 設計からの意図的な逸脱 (レビュー対象)

実装者が設計書と違う判断をした箇所は以下 4 点。**それぞれ妥当か判定してほしい**。

### (a) テストケース 5b-a (`api_url` が不正 URL `"not a url"`) を実装しなかった

理由: `packages/cli/src/config/schema.ts` の `ProfileEntrySchema` は
`api_url: z.string().url().optional()` であり、`loadConfigFromPath` は
`RootConfigInputSchema.parse` を通す。したがって「api_url が URL として不正な profile」は
**config を読み込んだ時点で ZodError になり、`readState()` に到達しない**。
5b-a は到達不能なケースである。
代わりに 5b-b (`ftp://` = URL としては valid だが `canonicalOrigin` が
"Unsupported protocol" を投げる) と 5b-c (`api_url` 未設定) で
`locateCredentials()` の 2 分岐を両方カバーした。
`locateCredentials()` の catch 節自体は 5b-b が通る。

### (b) CLI 契約テスト #6 の stdout 検証を `process.stdout.write` の spy ではなく `ProfileDelete.prototype.log` の spy で行った

理由: oclif の `this.log` は `process.stdout.write` への参照を module 読み込み時に束縛するため、
テスト内で stream に張った spy では捕捉できないことを実測した (spy が空、出力は素通り)。
コマンドの出力契約そのものを見る目的は `log` の観測で満たせると判断した。

### (c) CLI 契約テスト #2 / #4 で `confirmPrompt` を `vi.mock` で spy 化した

`vi.mock` の既定実装は `async () => false` で、これは非 TTY 時の実 `confirmPrompt`
(`credential/prompt.ts:35` の `if (!process.stdin.isTTY) return false;`) と同じ挙動である。
#2 は「exit 10 が出る」だけでなく「spy が 0 回呼ばれた」ことまで検証している。

### (d) impl-review の成果物を `devnotes/{YYYYMMDD-HHMM}-todo-T100/` ではなく設計と同じ `devnotes/20260805-0101-devtool-template-followup/` に置いた

設計・レビュー・実装レビューの履歴を 1 ディレクトリにまとめるため。

## 5. スコープ外だが発見した既存不具合 (修正していない — 判断を求む)

`pnpm -F "./packages/*" build` (`tsc -p packages/cli/tsconfig.json`、`noUnusedLocals: true`) が
**本バッチ以前から** 7 件の TS6133/TS6192 で失敗する:

```
src/api/client.ts(1,1): TS6192: All imports in import declaration are unused.
src/credential/encryption.ts(19,1): TS6133: 'ENV' is declared but its value is never read.
src/http/schemas.ts(1,1): TS6133: 'BIN_NAME' ...
src/oauth/client-id.ts(1,15): TS6133: 'BIN_NAME' ...
src/oauth/login.ts(1,1): TS6133: 'ENV' ...
src/oclif/commands/profile/add.ts(1,1): TS6133: 'BIN_NAME' ...
src/oclif/commands/whoami.ts(1,1): TS6133: 'BIN_NAME' ...
```

- main ブランチでも同一の 7 件が再現することを実測済み (本変更の回帰ではない)
- 7 件のいずれも本バッチの変更ファイルではない
- 設計書 施策 5 は packages の検証契約を **`typecheck:packages` + `test:packages` の 2 本セット**と
  明記しており、`build:packages` は CI 配線対象に入っていない
  (`tsconfig.test.json` は `noUnusedLocals: false` なので typecheck:packages は緑)
- いずれも「JSDoc 内でしか参照されない import」で、機械的に消すと doc 参照が壊れる

本バッチのスコープ (設計書に忠実に実装する) を守って**修正しなかった**。
別 TODO として起票すべきか、本バッチで直すべきか意見がほしい。

## 6. 実装差分 (git diff)

4 コミット (A: 施策 2→1 / B-0: 施策 5 / B-1: 施策 6 / B-2: 施策 4→3) の累積差分。

```diff
diff --git a/.claude/skills/app-codex-review/SKILL.md b/.claude/skills/app-codex-review/SKILL.md
index bbec657..6c32743 100644
--- a/.claude/skills/app-codex-review/SKILL.md
+++ b/.claude/skills/app-codex-review/SKILL.md
@@ -97,7 +97,7 @@ ## One-shotモード
 
 - `--ephemeral`: セッションファイルを永続化しない
 - `--sandbox read-only`: コマンド実行・ファイル書き込みを禁止（読み込みは許可）
-- `-m {model}`: モデルを指定（`gpt-5.3-codex` / `gpt-5.4` 等）
+- `-m {model}`: モデルを指定（`gpt-5.5`）
 - `-c 'model_reasoning_effort="{reasoning}"'`: reasoning effortを指定（モデル互換性のため常に明示指定。詳細は `app-codex-vscode` 参照）
 - `-o {出力ファイル}`: 結果をファイルに保存
 
diff --git a/.claude/skills/app-codex-vscode/SKILL.md b/.claude/skills/app-codex-vscode/SKILL.md
index bbf1afb..f341e68 100644
--- a/.claude/skills/app-codex-vscode/SKILL.md
+++ b/.claude/skills/app-codex-vscode/SKILL.md
@@ -33,8 +33,10 @@ ## 利用可能モデル
 
 | モデル | 用途 |
 |--------|------|
-| `gpt-5.3-codex` | デフォルト。コード分析・レビュー・技術設計 |
-| `gpt-5.4` | 自然言語中心の議論・概念設計 |
+| `gpt-5.5` | 唯一の指定モデル。コード分析・レビュー・技術設計・概念設計のすべて |
+
+用途別のモデル使い分けは行わない（`tests/js/architecture/codex-model-consistency.test.ts`
+が `gpt-5.5` 以外のモデル名を deny-by-default で検出する）。
 
 ---
 
@@ -43,14 +45,12 @@ ## Reasoning Effort
 `-c 'model_reasoning_effort="{reasoning}"'` で推論の深さを制御する。
 `~/.codex/config.toml` のグローバル設定（`model_reasoning_effort`）はモデルとの互換性問題を起こす場合があるため、**常にコマンドラインで明示指定すること**。
 
-| レベル | 対応モデル | 用途 |
-|--------|-----------|------|
-| `low` | 全モデル | 高速・軽量な応答 |
-| `medium` | 全モデル | 議論・分析・ブレスト用（**デフォルト推奨** — Claudeが評価・選別する場面） |
-| `high` | 全モデル | コードレビュー・安全性判定用（Codex判断が直接品質に影響する場面） |
-| `xhigh` | `gpt-5.3-codex`, `gpt-5.4`, `gpt-5.2-codex`, `gpt-5.1-codex-max` のみ | 最大の推論深度 |
-
-**注意**: `gpt-5-codex`, `gpt-5.1-codex`, `gpt-5` 等の旧モデルは `xhigh` 非対応。
+| レベル | 用途 |
+|--------|------|
+| `low` | 高速・軽量な応答 |
+| `medium` | 議論・分析・ブレスト用（**デフォルト推奨** — Claudeが評価・選別する場面） |
+| `high` | コードレビュー・安全性判定用（Codex判断が直接品質に影響する場面） |
+| `xhigh` | 最大の推論深度 |
 
 ---
 
diff --git a/.claude/skills/app-design/SKILL.md b/.claude/skills/app-design/SKILL.md
index 8437d57..09aed20 100644
--- a/.claude/skills/app-design/SKILL.md
+++ b/.claude/skills/app-design/SKILL.md
@@ -55,7 +55,7 @@ ## 重要原則
 
 - **全ての成果物は `devnotes/{YYYYMMDD-HHMM}-{topic}/` に保存**する
 - **Codexとの合議は「全CriticalとWarningが解消されるまで」繰り返す**（最大5ラウンド）
-- 概念設計レビューは **`gpt-5.4`**、詳細設計レビューは **`gpt-5.3-codex`** を使用
+- 概念設計レビュー・詳細設計レビューとも **`gpt-5.5`** を使用（reasoning effort で使い分ける）
 
 ---
 
@@ -110,7 +110,7 @@ ### 1-3. Codexによる概念設計レビュー
 
 `app-codex-review` スキルの**セッションモード**に従い、プロンプトファイルを作成してCodexに概念設計のレビューを依頼する。
 
-**model**: `gpt-5.4`
+**model**: `gpt-5.5`
 **reasoning**: `medium`
 **label**: `conceptual-review`
 
@@ -280,7 +280,7 @@ ### 2-3. Codexによる詳細設計レビュー
 
 `app-codex-review` スキルの**セッションモード**に従い、プロンプトファイルを作成してCodexにレビューを依頼する。
 
-**model**: `gpt-5.3-codex`
+**model**: `gpt-5.5`
 **reasoning**: `high`
 **label**: `design-review`
 
diff --git a/.claude/skills/app-implement/SKILL.md b/.claude/skills/app-implement/SKILL.md
index bcf5f4c..e099a27 100644
--- a/.claude/skills/app-implement/SKILL.md
+++ b/.claude/skills/app-implement/SKILL.md
@@ -175,7 +175,7 @@ ### A-2. Codexによる実装レビュー
 
 `app-codex-review` スキルの**セッションモード**に従い、プロンプトファイルを作成してCodexに依頼する。
 
-**model**: `gpt-5.3-codex`
+**model**: `gpt-5.5`
 **reasoning**: `high`
 **label**: `impl-review`
 
diff --git a/.github/workflows/ci.yml b/.github/workflows/ci.yml
index 789da74..fb66f1f 100644
--- a/.github/workflows/ci.yml
+++ b/.github/workflows/ci.yml
@@ -66,5 +66,9 @@ jobs:
         run: pnpm typecheck
       - name: Vitest
         run: pnpm test
+      - name: TypeScript (workspace packages)
+        run: pnpm typecheck:packages
+      - name: Vitest (workspace packages)
+        run: pnpm test:packages
       - name: Build
         run: pnpm build
diff --git a/AGENTS.md b/AGENTS.md
index 04caf31..e6e22c9 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -71,7 +71,8 @@ ## 実装規約
   `@lucide/svelte` のみ。Lucide に無いブランド/SSO ロゴの SVG 内包は
   `components/atoms/icons/` 配下に限る(`svg-inline-allowlist.test.ts` が強制)
 - 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
-  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`(全 green でコミット)
+  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
+  `pnpm typecheck:packages` / `pnpm test:packages`(全 green でコミット)
 
 ## コードベース探索
 
diff --git a/package.json b/package.json
index 0ce417b..50741bf 100644
--- a/package.json
+++ b/package.json
@@ -15,6 +15,7 @@
         "test:coverage": "bash scripts/with-global-test-lock.sh pnpm exec vitest run --coverage",
         "test:watch": "vitest --watch",
         "build:packages": "pnpm -F \"./packages/*\" build",
+        "typecheck:packages": "pnpm -F \"./packages/*\" typecheck",
         "test:packages": "bash scripts/with-global-test-lock.sh pnpm -F \"./packages/*\" test"
     },
     "dependencies": {
diff --git a/packages/cli/src/config/saver.ts b/packages/cli/src/config/saver.ts
index 1614672..b4222d8 100644
--- a/packages/cli/src/config/saver.ts
+++ b/packages/cli/src/config/saver.ts
@@ -1,14 +1,23 @@
-import { writeFileSync, mkdirSync, existsSync } from "node:fs";
+import { mkdirSync, existsSync } from "node:fs";
 import { dirname } from "node:path";
 import { stringify as stringifyYaml } from "yaml";
+import { atomicWriteFile } from "../util/atomic-write.js";
 import { RootConfigInputSchema, type RootConfigInput } from "./schema.js";
 
 /**
- * Atomically write a RootConfigInput to the given path.
- * Creates the parent directory if it does not exist.
+ * Write a RootConfigInput to the given path with **atomic replacement**
+ * (tmp write -> fsync -> rename), so a failed/partial write never leaves a
+ * truncated config behind — losing the file would drop every registered
+ * profile at once.
  *
- * The input is re-validated with `RootConfigInputSchema.parse` before writing
- * so that we never persist an invalid config.
+ * Note: this is atomic *replacement*, not crash durability. The parent
+ * directory is not fsynced, so a power loss right after the rename may still
+ * lose the update. Full durability would need a directory fsync and is out
+ * of scope (see devnotes/20260805-0101-devtool-template-followup).
+ *
+ * Creates the parent directory if it does not exist. The input is
+ * re-validated with `RootConfigInputSchema.parse` before writing so that we
+ * never persist an invalid config.
  */
 export function saveConfigToPath(path: string, config: RootConfigInput): void {
     const validated = RootConfigInputSchema.parse(config);
@@ -17,5 +26,5 @@ export function saveConfigToPath(path: string, config: RootConfigInput): void {
         mkdirSync(parent, { recursive: true });
     }
     const yaml = stringifyYaml(validated);
-    writeFileSync(path, yaml, { encoding: "utf-8", mode: 0o600 });
+    atomicWriteFile(path, yaml, 0o600);
 }
diff --git a/packages/cli/src/credential/errors.ts b/packages/cli/src/credential/errors.ts
index 36f07b1..a2ba6dd 100644
--- a/packages/cli/src/credential/errors.ts
+++ b/packages/cli/src/credential/errors.ts
@@ -11,13 +11,29 @@ import { ExitCode } from "../exit-codes.js";
  * the human message, and translates the `exitCode` to a real
  * `process.exit` call.
  */
+/**
+ * Discriminator so callers can narrow *which* store failure they caught.
+ *
+ * `profile:delete` must swallow a corrupted credential index (it deletes the
+ * whole directory instead) while letting every other store failure through.
+ * Without this discriminator a future decrypt/delete failure raised from the
+ * same code path would be silently swallowed too.
+ */
+export type CredentialStoreErrorKind = "corrupted-index" | "unknown";
+
 export class CredentialStoreError extends Error {
     public readonly exitCode: ExitCodeValue;
+    public readonly kind: CredentialStoreErrorKind;
 
-    constructor(message: string, exitCode: ExitCodeValue) {
+    constructor(
+        message: string,
+        exitCode: ExitCodeValue,
+        kind: CredentialStoreErrorKind = "unknown",
+    ) {
         super(message);
         this.name = "CredentialStoreError";
         this.exitCode = exitCode;
+        this.kind = kind;
     }
 
     static corruptedIndex(profileName: string, cause: string): CredentialStoreError {
@@ -25,6 +41,7 @@ export class CredentialStoreError extends Error {
             `credential index is corrupted for profile "${profileName}" `
                 + `(${cause}). Manual repair required.`,
             ExitCode.CredentialStoreFailure,
+            "corrupted-index",
         );
     }
 }
diff --git a/packages/cli/src/credential/file-store.ts b/packages/cli/src/credential/file-store.ts
index c6aba03..4759905 100644
--- a/packages/cli/src/credential/file-store.ts
+++ b/packages/cli/src/credential/file-store.ts
@@ -10,7 +10,7 @@ import { homedir } from "node:os";
 import { dirname, join } from "node:path";
 import { randomBytes } from "node:crypto";
 import { ExitCode, exitWith } from "../exit-codes.js";
-import { atomicWriteFile, atomicWriteFileBinary } from "./atomic-write.js";
+import { atomicWriteFile, atomicWriteFileBinary } from "../util/atomic-write.js";
 import type { BackendStore } from "./backend.js";
 import {
     decryptString,
diff --git a/packages/cli/src/credential/keychain.ts b/packages/cli/src/credential/keychain.ts
index d59842e..758880a 100644
--- a/packages/cli/src/credential/keychain.ts
+++ b/packages/cli/src/credential/keychain.ts
@@ -1,4 +1,4 @@
-import { ENV, BIN_NAME } from "../branding.js";
+import { ENV, KEYCHAIN_SERVICE } from "../branding.js";
 import { createRequire } from "node:module";
 import { ExitCode, exitWith } from "../exit-codes.js";
 import type { BackendStore } from "./backend.js";
@@ -6,7 +6,10 @@ import { deriveKeychainKey, type ItemKind } from "./key-derivation.js";
 
 const requireFromHere = createRequire(import.meta.url);
 
-const SERVICE = `${BIN_NAME}`;
+// 正本は branding.ts の KEYCHAIN_SERVICE。`profile:delete` は index 破損時に
+// このサービス名を手動清掃の案内として表示するため、案内と実際の保存先が
+// 同じ定数から来ることを構造的に保証する (定義の二重化を解消)。
+const SERVICE = KEYCHAIN_SERVICE;
 
 type EntryCtor = new (service: string, username: string) => {
     getPassword: () => string | null;
diff --git a/packages/cli/src/credential/store.ts b/packages/cli/src/credential/store.ts
index a1db33b..dc8c9fa 100644
--- a/packages/cli/src/credential/store.ts
+++ b/packages/cli/src/credential/store.ts
@@ -36,6 +36,17 @@ type IndexItem = z.infer<typeof IndexSchema>[number];
 
 const META_INDEX_ID = "index";
 
+export type PurgeProfileResult = {
+    /**
+     * このプロファイルの資格情報を **取りこぼしなく** 破棄できたか。
+     * `false` のとき、呼び出し側は config を消してはならない
+     * (api_url を失うと残った資格情報の在り処を二度と導出できなくなる)。
+     */
+    complete: boolean;
+    /** credential index が読めなかったか (診断用)。 */
+    indexCorrupted: boolean;
+};
+
 function assertMetaIdSafe(metaId: string): void {
     if (metaId === META_INDEX_ID) {
         throw new Error(
@@ -284,6 +295,52 @@ export class CredentialStore {
         );
     }
 
+    /**
+     * `profile:delete` 用の破棄 API。
+     *
+     * `clearProfile` は index を読めないと
+     * `CredentialStoreError`(kind: "corrupted-index") を投げる。
+     * その場合の挙動は backend で本質的に異なる:
+     *
+     *   - file backend: プロファイルディレクトリを丸ごと落とせるので、
+     *     index が読めなくても **取りこぼしは発生しない** → complete: true
+     *   - keychain backend: 列挙手段が無く、index を失うと個々の item を
+     *     特定できない → **complete: false**。呼び出し側は config を残し、
+     *     利用者に手動清掃を求めること (config を消すと api_url が失われ、
+     *     残った資格情報が永久に到達不能な孤児になる)
+     */
+    purgeProfile(
+        canonicalOrigin: string,
+        profileName: string,
+    ): PurgeProfileResult {
+        try {
+            this.clearProfile(canonicalOrigin, profileName);
+            return { complete: true, indexCorrupted: false };
+        } catch (e) {
+            // index 破損**だけ**を握る。復号失敗・削除失敗など他の
+            // CredentialStoreError は握り潰さず素通しする。
+            if (
+                !(e instanceof CredentialStoreError)
+                || e.kind !== "corrupted-index"
+            ) {
+                throw e;
+            }
+        }
+        // index が読めなくても消せるものは消す。
+        this.primary().delete(
+            canonicalOrigin,
+            profileName,
+            "meta",
+            META_INDEX_ID,
+        );
+        this.fileStore.clearProfile(canonicalOrigin, profileName);
+        // keychain が primary のときだけ取りこぼしがありうる。
+        return {
+            complete: this.keychain === null,
+            indexCorrupted: true,
+        };
+    }
+
     /**
      * Expose the underlying FileStore for low-level enumeration. Returns
      * null when the keychain backend is active — keychain does not expose
diff --git a/packages/cli/src/oclif/commands/profile/delete.ts b/packages/cli/src/oclif/commands/profile/delete.ts
new file mode 100644
index 0000000..90771bd
--- /dev/null
+++ b/packages/cli/src/oclif/commands/profile/delete.ts
@@ -0,0 +1,85 @@
+import { Args, Flags } from "@oclif/core";
+import { BIN_NAME } from "../../../branding.js";
+import { confirmPrompt } from "../../../credential/prompt.js";
+import { ExitCode, exitWith } from "../../../exit-codes.js";
+import {
+    executeProfileDeletion,
+    planProfileDeletion,
+} from "../../../profile/delete.js";
+import { assertProfileName } from "../../../profile/name.js";
+import { ProfileCommand } from "../../base/ProfileCommand.js";
+
+/**
+ * `${BIN_NAME} profile:delete <name>` — remove a profile and destroy the
+ * credentials stored for it (API key, OAuth token, per-site credentials).
+ *
+ * Uses `resolveMode: "if-needed"` because deletion acts purely on the local
+ * config + credential store; no server round-trip and no resolution of an
+ * existing profile context is required (same shape as `profile:use`).
+ */
+export default class ProfileDelete extends ProfileCommand {
+    static override description =
+        "Delete a profile and destroy its stored credentials.";
+    static override args = {
+        name: Args.string({ description: "profile name", required: true }),
+    };
+    static override flags = {
+        "clear-default": Flags.boolean({
+            description:
+                "allow deleting the profile that default_profile points at",
+        }),
+        yes: Flags.boolean({ description: "skip confirmations (CI mode)" }),
+    };
+
+    protected override persistentRequired = false;
+    protected override resolveMode: "if-needed" = "if-needed";
+
+    public async run(): Promise<void> {
+        const { args, flags } = await this.parse(ProfileDelete);
+        this.latchCiFlag(flags.ci);
+        const { writer, store } = await this.resolveContext(flags);
+        const name = args.name;
+        assertProfileName(name);
+
+        // 事前検証は **確認プロンプトより前**。ここで
+        // profile 不在 (11) / default 競合 (10) が確定する。
+        // 後ろに置くと非 TTY 環境で「本当は 10 なのに確認が取れず 1」になる。
+        const plan = planProfileDeletion(writer, name, {
+            clearDefault: flags["clear-default"] === true,
+        });
+
+        if (flags.yes !== true) {
+            const ok = await confirmPrompt(
+                `Delete profile "${name}" and destroy its stored `
+                    + "credentials? This cannot be undone.",
+            );
+            if (!ok) {
+                console.error(
+                    "Aborted (pass --yes to skip this confirmation).",
+                );
+                exitWith(ExitCode.GeneralError);
+            }
+        }
+
+        const result = executeProfileDeletion({ writer, store }, plan);
+
+        this.log(`Profile "${name}" deleted.`);
+        if (!result.wasDefault) return;
+        if (result.nextDefault !== null) {
+            this.log(`default_profile = ${result.nextDefault}`);
+            return;
+        }
+        if (result.remaining.length === 0) {
+            this.log(
+                "default_profile is now unset and no profiles remain. "
+                    + `Run \`${BIN_NAME} profile:add <name> --api-url <url>\`.`,
+            );
+            return;
+        }
+        this.log(
+            "default_profile is now unset. Pick one with "
+                + `\`${BIN_NAME} profile:use <name>\`: `
+                + result.remaining.join(", "),
+        );
+    }
+}
diff --git a/packages/cli/src/oclif/commands/profile/use.ts b/packages/cli/src/oclif/commands/profile/use.ts
index cd92fae..f90d541 100644
--- a/packages/cli/src/oclif/commands/profile/use.ts
+++ b/packages/cli/src/oclif/commands/profile/use.ts
@@ -5,7 +5,7 @@ import { ProfileCommand } from "../../base/ProfileCommand.js";
 
 export default class ProfileUse extends ProfileCommand {
     static override description =
-        "Set default_profile (the only command that can change it).";
+        "Set default_profile (profile:delete --clear-default can also change it).";
     static override args = {
         name: Args.string({ description: "profile name", required: true }),
     };
diff --git a/packages/cli/src/profile/delete.ts b/packages/cli/src/profile/delete.ts
new file mode 100644
index 0000000..a082107
--- /dev/null
+++ b/packages/cli/src/profile/delete.ts
@@ -0,0 +1,267 @@
+import { BIN_NAME, KEYCHAIN_SERVICE } from "../branding.js";
+import { CredentialStoreError } from "../credential/errors.js";
+import type { CredentialStore } from "../credential/store.js";
+import { ExitCode } from "../exit-codes.js";
+import { deleteOAuthToken } from "../oauth/token-store.js";
+import { canonicalOrigin } from "./canonical-origin.js";
+import { ProfileResolutionError } from "./errors.js";
+import type { DeleteProfileOptions, ProfileWriter } from "./writer.js";
+
+export type DeleteProfileDeps = {
+    writer: ProfileWriter;
+    store: CredentialStore;
+};
+
+/**
+ * credential の在り処。判別共用体にして「origin と reason が両方 null」
+ * のような表現不能状態を型で排除する。
+ */
+export type CredentialLocation =
+    | { kind: "located"; origin: string }
+    | { kind: "unlocatable"; reason: string };
+
+/**
+ * 削除計画。**副作用ゼロ**で作られ、コマンド層は確認プロンプトの前にこれを
+ * 組み立てて競合を検出する。
+ */
+export type ProfileDeletionPlan = {
+    name: string;
+    credentials: CredentialLocation;
+    wasDefault: boolean;
+    /** 削除と同じ save で付け替える先 (無ければ null)。 */
+    nextDefault: string | null;
+    /** 削除後に残るプロファイル名。 */
+    remaining: readonly string[];
+    clearDefault: boolean;
+};
+
+export type DeleteProfileResult = {
+    wasDefault: boolean;
+    nextDefault: string | null;
+    remaining: readonly string[];
+    /** credential 破棄をスキップしたか (api_url が欠落/不正なとき true)。 */
+    credentialsSkipped: boolean;
+    /** credential index が壊れていたか (file backend でのみ到達しうる)。 */
+    credentialIndexCorrupted: boolean;
+};
+
+/**
+ * 削除計画を組み立てる。**config も credential も一切変更しない**。
+ *
+ * ここで投げる例外がそのまま CLI の事前検証エラーになる:
+ *   - プロファイル不在      -> ProfileResolutionError.notFound  (exit 11)
+ *   - default を守らず削除  -> ProfileResolutionError.conflict  (exit 10)
+ *
+ * **確認プロンプトより前に呼ぶこと**。後に呼ぶと、非 TTY 環境で
+ * 「本当は exit 10 なのに確認が取れず exit 1」になる。
+ * `executeProfileDeletion` は TOCTOU ガードとしてこれを**もう一度**呼ぶ。
+ */
+export function planProfileDeletion(
+    writer: ProfileWriter,
+    name: string,
+    opts: { clearDefault: boolean },
+): ProfileDeletionPlan {
+    // 1 回の読み込みで entry / default / 全プロファイルを取る。
+    // 3 回に分けて読むと、その間の書き替えで不整合な計画ができる。
+    const state = writer.readState();
+    const entry = state.profiles.find((row) => row.name === name)?.entry;
+    if (entry === undefined) {
+        throw ProfileResolutionError.notFound(
+            `profile "${name}" not found in user config.`,
+        );
+    }
+
+    const wasDefault = state.defaultProfile === name;
+    const remaining = state.profiles
+        .map((row) => row.name)
+        .filter((candidate) => candidate !== name);
+
+    if (wasDefault && !opts.clearDefault) {
+        throw ProfileResolutionError.conflict(
+            `profile "${name}" is the current default_profile. `
+                + `Re-run with --clear-default, or point the default at `
+                + `another profile first with \`${BIN_NAME} profile:use <name>\`.`,
+        );
+    }
+
+    return {
+        name,
+        credentials: locateCredentials(entry.api_url),
+        wasDefault,
+        nextDefault:
+            wasDefault && remaining.length === 1 ? (remaining[0] ?? null) : null,
+        remaining,
+        clearDefault: opts.clearDefault,
+    };
+}
+
+/**
+ * 2 つの計画が同一状態から作られたかを判定する。
+ * 確認プロンプト中に config が書き替わっていないかの検査に使う。
+ */
+function plansMatch(a: ProfileDeletionPlan, b: ProfileDeletionPlan): boolean {
+    if (a.name !== b.name) return false;
+    if (a.clearDefault !== b.clearDefault) return false;
+    if (a.wasDefault !== b.wasDefault) return false;
+    if (a.nextDefault !== b.nextDefault) return false;
+    if (a.credentials.kind !== b.credentials.kind) return false;
+    if (
+        a.credentials.kind === "located"
+        && b.credentials.kind === "located"
+        && a.credentials.origin !== b.credentials.origin
+    ) {
+        return false;
+    }
+    if (a.remaining.length !== b.remaining.length) return false;
+    return a.remaining.every((v, i) => v === b.remaining[i]);
+}
+
+/**
+ * 計画を実行する。credential -> config の順で落とす。
+ *
+ * **順序は反転させないこと**: credential の物理位置は
+ * `deriveProfileHash12(canonicalOrigin(api_url), name)` から導出されるため、
+ * config を先に消すと api_url を失い、credential ディレクトリを二度と
+ * 特定できなくなる (永久に孤児化する)。
+ *
+ * **収束契約（限定つき）**:
+ *   - 通常経路 / config 保存失敗 -> **同じコマンドの再実行で収束する**
+ *     (credential 不在は 3 backend すべてで no-op 成功)
+ *   - keychain の credential index 破損 -> **fail-closed**。config を残して
+ *     exit 18 で停止し、**OS keychain の手動清掃という外部操作**を要求する。
+ *     再実行だけでは収束しない (取りこぼした秘密を到達不能にしないための仕様)
+ *   - 確認待ちの間に config が書き替わった -> **何も触らず exit 10** で停止
+ */
+export function executeProfileDeletion(
+    deps: DeleteProfileDeps,
+    plan: ProfileDeletionPlan,
+): DeleteProfileResult {
+    const { writer, store } = deps;
+    const { name } = plan;
+
+    // --- 0. TOCTOU ガード: 計画作成から今までに config が変わっていないか ---
+    // 確認プロンプトの間に別プロセスが同名 profile の api_url を A -> B に
+    // 書き替えると、古い計画のまま **A の credential を消して B の config を
+    // 消す** = B の credential が孤児化する。credential に触れる前に再計画して
+    // 突き合わせ、食い違ったら何もせず停止する。
+    const current = planProfileDeletion(writer, name, {
+        clearDefault: plan.clearDefault,
+    });
+    if (!plansMatch(plan, current)) {
+        throw ProfileResolutionError.conflict(
+            `profile "${name}" changed while this command was waiting for `
+                + "confirmation (another process modified the config). "
+                + "Nothing was deleted. Re-run the command.",
+        );
+    }
+
+    // --- 1. credential を破棄する (config より先) ---
+    let credentialIndexCorrupted = false;
+    if (plan.credentials.kind === "unlocatable") {
+        console.error(
+            `Warning: profile "${name}" cannot be mapped to a credential `
+                + `location because ${plan.credentials.reason}. `
+                + `Removing the config entry only; inspect `
+                + `~/.${BIN_NAME}/credentials manually if needed.`,
+        );
+    } else {
+        credentialIndexCorrupted = clearCredentials(
+            store,
+            plan.credentials.origin,
+            name,
+        );
+    }
+
+    // --- 2. config を 1 回の save で落とす ---
+    const writerOpts: DeleteProfileOptions = {
+        clearDefault: plan.clearDefault,
+    };
+    // exactOptionalPropertyTypes: 未指定はプロパティ自体を省略する。
+    if (plan.nextDefault !== null) writerOpts.nextDefault = plan.nextDefault;
+
+    try {
+        writer.deleteProfile(name, writerOpts);
+    } catch (e) {
+        if (plan.credentials.kind === "located") {
+            const flag = plan.clearDefault ? " --clear-default" : "";
+            console.error(
+                `Error: credentials for profile "${name}" were destroyed but `
+                    + "the config update failed. The profile entry is still "
+                    + "present. Re-run to finish cleaning up:\n"
+                    + `  ${BIN_NAME} profile:delete ${name}${flag} --yes`,
+            );
+        }
+        throw e;
+    }
+
+    return {
+        wasDefault: plan.wasDefault,
+        nextDefault: plan.nextDefault,
+        remaining: plan.remaining,
+        credentialsSkipped: plan.credentials.kind === "unlocatable",
+        credentialIndexCorrupted,
+    };
+}
+
+/**
+ * api_url から canonical origin を導く。欠落・不正なら **理由つきで
+ * `unlocatable` を返す** (throw しない)。壊れた profile を削除できないほうが
+ * 害が大きいため。計画フェーズから呼ばれるので **stderr へ書かない**
+ * (副作用ゼロを守る)。
+ */
+function locateCredentials(apiUrl: string | undefined): CredentialLocation {
+    if (apiUrl === undefined || apiUrl === "") {
+        return { kind: "unlocatable", reason: "it has no api_url" };
+    }
+    try {
+        return { kind: "located", origin: canonicalOrigin(apiUrl) };
+    } catch (e) {
+        // 「ad-hoc な as cast を入れない」規約に従い instanceof で絞る。
+        const message = e instanceof Error ? e.message : String(e);
+        return {
+            kind: "unlocatable",
+            reason: `its api_url is invalid (${message})`,
+        };
+    }
+}
+
+/**
+ * credential (indexed items + credential index + OAuth token) を落とす。
+ * 戻り値は「index が壊れていたか」。
+ *
+ * `CredentialStore.clearProfile()` は index に載った item と meta:index しか
+ * 消さない。OAuth token bundle は **meta 名前空間の非 index エントリ**
+ * (`oauth:token`) なので、keychain backend では clearProfile だけでは残る
+ * (file backend はディレクトリごと消えるので結果的に消える)。
+ * 3 backend で挙動を揃えるため deleteOAuthToken を明示的に呼ぶ。
+ */
+function clearCredentials(
+    store: CredentialStore,
+    origin: string,
+    name: string,
+): boolean {
+    deleteOAuthToken(store, origin, name);
+    const { complete, indexCorrupted } = store.purgeProfile(origin, name);
+    if (!complete) {
+        // ここで **config を消してはならない**。api_url を失うと、
+        // 残った資格情報の在り処 (profile_hash12) を二度と導出できず、
+        // OS keychain に到達不能な秘密が固定される。
+        throw new CredentialStoreError(
+            `the credential index for profile "${name}" is corrupted and the `
+                + "OS keychain cannot be enumerated, so some credentials may "
+                + "still be stored. The profile was NOT deleted (removing it "
+                + "would make those credentials unreachable). Remove the "
+                + `entries for keychain service "${KEYCHAIN_SERVICE}" `
+                + "manually, then re-run this command.",
+            ExitCode.CredentialStoreFailure,
+        );
+    }
+    if (indexCorrupted) {
+        console.error(
+            `Warning: the credential index for profile "${name}" was `
+                + "corrupted. The whole credential directory was removed "
+                + "instead, so nothing is left behind.",
+        );
+    }
+    return indexCorrupted;
+}
diff --git a/packages/cli/src/profile/writer.ts b/packages/cli/src/profile/writer.ts
index 7a1c485..3321d33 100644
--- a/packages/cli/src/profile/writer.ts
+++ b/packages/cli/src/profile/writer.ts
@@ -18,13 +18,39 @@ export type ProfileInit = {
     allow_insecure?: boolean;
 };
 
+export type DeleteProfileOptions = {
+    /** default_profile が対象を指していても削除を許可する。 */
+    clearDefault?: boolean;
+    /**
+     * 削除と同時に default_profile を付け替える先。
+     * `clearDefault === true` かつ対象が現在の default のときのみ指定できる
+     * (それ以外は throw して **何も保存しない**)。
+     * 削除と default 遷移を 1 回の save() に畳むために存在する。
+     */
+    nextDefault?: string;
+};
+
+/**
+ * 1 回の config 読み込みから得た状態のスナップショット。
+ *
+ * `get()` / `list()` / default の 3 情報を**別々の loadUser() で**取ると、
+ * その間に他プロセスが config を書き替えたとき不整合な計画を作りうる。
+ * 削除の計画フェーズは必ずこれ 1 回で読む。
+ */
+export type ProfileState = {
+    defaultProfile: string | undefined;
+    profiles: ReadonlyArray<{ name: string; entry: ProfileEntry }>;
+};
+
 export interface ProfileWriter {
     list(): ReadonlyArray<{ name: string; entry: ProfileEntry }>;
     get(name: string): ProfileEntry | undefined;
+    /** default_profile と全プロファイルを **1 回の読み込みで** 返す。 */
+    readState(): ProfileState;
     snapshot(name: string): ProfileEntry;
     addProfile(name: string, init: ProfileInit): void;
     updateExpectedEnv(name: string, expected: string | null): void;
-    deleteProfile(name: string, opts?: { clearDefault?: boolean }): void;
+    deleteProfile(name: string, opts?: DeleteProfileOptions): void;
     useDefaultProfile(name: string): void;
     applyAtomic(
         name: string,
@@ -113,6 +139,19 @@ export class FileProfileWriter implements ProfileWriter {
         return this.loadUser().profiles?.[name];
     }
 
+    readState(): ProfileState {
+        const user = this.loadUser();
+        const profiles = user.profiles ?? {};
+        const state: ProfileState = {
+            defaultProfile: user.default_profile,
+            profiles: Object.entries(profiles).map(([name, entry]) => ({
+                name,
+                entry,
+            })),
+        };
+        return state;
+    }
+
     snapshot(name: string): ProfileEntry {
         const entry = this.get(name);
         if (!entry) throw new Error(`profile "${name}" not found`);
@@ -153,24 +192,60 @@ export class FileProfileWriter implements ProfileWriter {
         });
     }
 
-    deleteProfile(
-        name: string,
-        opts: { clearDefault?: boolean } = {},
-    ): void {
+    /**
+     * プロファイルの削除。`nextDefault` を渡すと **同じ 1 回の save() で**
+     * default_profile を付け替える (削除保存 → 付け替え保存の 2 段階にすると、
+     * 間の「default 不在」状態が永続化しうるため)。
+     */
+    deleteProfile(name: string, opts: DeleteProfileOptions = {}): void {
         const user = this.loadUser();
-        if (!user.profiles?.[name]) {
+        const profiles = user.profiles;
+        if (!profiles?.[name]) {
             throw new Error(`profile "${name}" not found`);
         }
-        if (user.default_profile === name && !opts.clearDefault) {
+        const isDefault = user.default_profile === name;
+
+        // --- nextDefault の受理条件 (満たさなければ save を呼ばない) ---
+        const nextDefault = opts.nextDefault;
+        if (nextDefault !== undefined) {
+            if (!isDefault) {
+                throw new Error(
+                    `nextDefault is only valid when deleting the default `
+                        + `profile (default_profile is `
+                        + `${String(user.default_profile)}).`,
+                );
+            }
+            if (opts.clearDefault !== true) {
+                throw new Error(
+                    "nextDefault requires clearDefault (the intent to change "
+                        + "default_profile must be explicit).",
+                );
+            }
+            if (nextDefault === name) {
+                throw new Error(
+                    `nextDefault "${nextDefault}" is the profile being deleted.`,
+                );
+            }
+            if (!profiles[nextDefault]) {
+                throw new Error(`profile "${nextDefault}" not found`);
+            }
+        }
+
+        if (isDefault && opts.clearDefault !== true) {
             throw new Error(
                 `profile "${name}" is the default. `
                     + "Use --clear-default or run `profile:use` first.",
             );
         }
-        const { [name]: _removed, ...rest } = user.profiles;
+
+        const { [name]: _removed, ...rest } = profiles;
         const next: RootConfigInput = { ...user, profiles: rest };
-        if (opts.clearDefault && user.default_profile === name) {
-            delete next.default_profile;
+        if (isDefault) {
+            if (nextDefault !== undefined) {
+                next.default_profile = nextDefault;
+            } else {
+                delete next.default_profile;
+            }
         }
         this.save(next);
     }
diff --git a/packages/cli/src/credential/atomic-write.ts b/packages/cli/src/util/atomic-write.ts
similarity index 100%
rename from packages/cli/src/credential/atomic-write.ts
rename to packages/cli/src/util/atomic-write.ts
diff --git a/packages/cli/tests/commands/profile/delete.test.ts b/packages/cli/tests/commands/profile/delete.test.ts
new file mode 100644
index 0000000..681a57d
--- /dev/null
+++ b/packages/cli/tests/commands/profile/delete.test.ts
@@ -0,0 +1,177 @@
+import { mkdtempSync, rmSync } from "node:fs";
+import { tmpdir } from "node:os";
+import { join } from "node:path";
+import { fileURLToPath } from "node:url";
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { BIN_NAME } from "../../../src/branding.js";
+import type { RootConfigInput } from "../../../src/config/schema.js";
+import { saveConfigToPath } from "../../../src/config/saver.js";
+import { ExitCode } from "../../../src/exit-codes.js";
+import ProfileDelete from "../../../src/oclif/commands/profile/delete.js";
+
+/**
+ * `profile:delete` の **CLI 契約** テスト。
+ *
+ * ロジック層 (tests/profile/delete.test.ts) では固定できない
+ * 「事前検証がプロンプトより前にある」「exit code が `--yes` の有無で
+ * 変わらない」といったコマンド層の約束を押さえる。
+ *
+ * backend は file-plaintext 1 本でよい (backend 別の網羅はロジック層の担当)。
+ */
+
+/** 確認プロンプトは spy 化する。既定は「拒否」= 非 TTY の実挙動と同じ。 */
+const confirmSpy = vi.hoisted(() =>
+    vi.fn<(msg: string) => Promise<boolean>>(async () => false),
+);
+
+vi.mock("../../../src/credential/prompt.js", async (importOriginal) => {
+    const actual =
+        await importOriginal<
+            typeof import("../../../src/credential/prompt.js")
+        >();
+    return { ...actual, confirmPrompt: confirmSpy };
+});
+
+/** oclif Config のルート。dist をビルドしていなくても Config.load は通る。 */
+const CLI_ROOT = fileURLToPath(new URL("../../../", import.meta.url));
+
+const API_URL_A = "https://a.example.com";
+
+let home: string;
+let origHome: string | undefined;
+let exitCodes: number[];
+
+function seedConfig(config: RootConfigInput): void {
+    saveConfigToPath(join(home, `.${BIN_NAME}`, "config.yaml"), config);
+}
+
+beforeEach(() => {
+    origHome = process.env["HOME"];
+    home = mkdtempSync(join(tmpdir(), "cli-pdel-cmd-"));
+    process.env["HOME"] = home;
+    exitCodes = [];
+    confirmSpy.mockClear();
+    confirmSpy.mockImplementation(async () => false);
+    // `process.exit` の最初の記録だけが本当の意図。BaseCommand.catch が
+    // その throw を拾って **もう一度** exit(1) を呼ぶため、単純な
+    // rejects.toThrow では常に 1 を見てしまう。
+    vi.spyOn(process, "exit").mockImplementation(((
+        code?: string | number | null,
+    ): never => {
+        exitCodes.push(typeof code === "number" ? code : Number(code ?? 0));
+        throw new Error(`EXIT:${String(code)}`);
+    }) as (code?: string | number | null) => never);
+    vi.spyOn(console, "error").mockImplementation(() => {});
+});
+
+afterEach(() => {
+    vi.restoreAllMocks();
+    rmSync(home, { recursive: true, force: true });
+    if (origHome !== undefined) process.env["HOME"] = origHome;
+    else delete process.env["HOME"];
+});
+
+async function runDelete(argv: readonly string[]): Promise<void> {
+    await ProfileDelete.run([...argv], CLI_ROOT);
+}
+
+describe("profile:delete — CLI 契約", () => {
+    it("1. 未登録プロファイルは exit 11", async () => {
+        seedConfig({
+            default_profile: "prod",
+            profiles: { prod: { api_url: API_URL_A } },
+        });
+        await expect(runDelete(["ghost", "--yes"])).rejects.toThrow(/EXIT:/);
+        expect(exitCodes[0]).toBe(ExitCode.ProfileNotFound);
+    });
+
+    it("2. default を --clear-default 無し・--yes 無しで消すと exit 10 (プロンプトに入らない)", async () => {
+        seedConfig({
+            default_profile: "prod",
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+        });
+        await expect(runDelete(["prod"])).rejects.toThrow(/EXIT:/);
+        expect(exitCodes[0]).toBe(ExitCode.ProfileConflict);
+        // 事前検証が確認プロンプトより前にある証拠。
+        expect(confirmSpy).not.toHaveBeenCalled();
+    });
+
+    it("2b. --yes を付けても exit code は同じ 10", async () => {
+        seedConfig({
+            default_profile: "prod",
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+        });
+        await expect(runDelete(["prod", "--yes"])).rejects.toThrow(/EXIT:/);
+        expect(exitCodes[0]).toBe(ExitCode.ProfileConflict);
+    });
+
+    it("3. 不正なプロファイル名は exit 13", async () => {
+        seedConfig({ profiles: { prod: { api_url: API_URL_A } } });
+        await expect(runDelete(["Prod", "--yes"])).rejects.toThrow(/EXIT:/);
+        expect(exitCodes[0]).toBe(ExitCode.ProfileInvalidName);
+    });
+
+    it("4. 確認が取れなければ exit 1 で config は無傷", async () => {
+        // 非 TTY の実 confirmPrompt は false を返す (credential/prompt.ts)。
+        // spy の既定もそれに揃えている。
+        seedConfig({
+            default_profile: "staging",
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+        });
+        await expect(runDelete(["prod"])).rejects.toThrow(/EXIT:/);
+        expect(exitCodes[0]).toBe(ExitCode.GeneralError);
+        expect(confirmSpy).toHaveBeenCalledTimes(1);
+
+        const { FileProfileWriter } = await import(
+            "../../../src/profile/writer.js"
+        );
+        expect(new FileProfileWriter().get("prod")).toBeDefined();
+    });
+
+    it("5. --yes 付きの正常削除では exit せず config から消える", async () => {
+        seedConfig({
+            default_profile: "staging",
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+        });
+        await runDelete(["prod", "--yes"]);
+        expect(exitCodes).toEqual([]);
+
+        const { FileProfileWriter } = await import(
+            "../../../src/profile/writer.js"
+        );
+        expect(new FileProfileWriter().get("prod")).toBeUndefined();
+    });
+
+    it("6. default を --clear-default --yes で消すと付け替え先が stdout に出る", async () => {
+        seedConfig({
+            default_profile: "prod",
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+        });
+        // oclif の `this.log` は `process.stdout.write` への参照を module 読み込み
+        // 時に束縛するため、stream への spy では捕捉できない。コマンドの出力
+        // 契約そのものを見るために `log` を直接観測する。
+        const lines: string[] = [];
+        vi.spyOn(ProfileDelete.prototype, "log").mockImplementation(
+            (message?: string): void => {
+                lines.push(String(message));
+            },
+        );
+        await runDelete(["prod", "--clear-default", "--yes"]);
+        expect(lines).toContain("default_profile = staging");
+    });
+});
diff --git a/packages/cli/tests/config/saver.test.ts b/packages/cli/tests/config/saver.test.ts
new file mode 100644
index 0000000..4caab25
--- /dev/null
+++ b/packages/cli/tests/config/saver.test.ts
@@ -0,0 +1,79 @@
+import {
+    existsSync,
+    mkdirSync,
+    mkdtempSync,
+    readFileSync,
+    readdirSync,
+    rmSync,
+} from "node:fs";
+import { tmpdir } from "node:os";
+import { join } from "node:path";
+import { afterEach, beforeEach, describe, expect, it } from "vitest";
+import { saveConfigToPath } from "../../src/config/saver.js";
+import { loadConfigFromPath } from "../../src/config/loader.js";
+
+let tmp: string;
+let configPath: string;
+/** atomicWriteFile が使う一時パス (pid 依存)。 */
+let tmpWritePath: string;
+
+beforeEach(() => {
+    tmp = mkdtempSync(join(tmpdir(), "cli-saver-"));
+    configPath = join(tmp, "config.yaml");
+    tmpWritePath = `${configPath}.${String(process.pid)}.tmp`;
+});
+
+afterEach(() => {
+    // 失敗注入用ディレクトリを必ず除去する。残すと **同一 pid の後続テストの
+    // atomicWriteFile を巻き添えで失敗させる** (vitest は同一プロセスで
+    // 複数テストファイルを走らせうる)。
+    rmSync(tmpWritePath, { recursive: true, force: true });
+    rmSync(tmp, { recursive: true, force: true });
+});
+
+describe("saveConfigToPath — atomic replacement", () => {
+    it("一時ファイル書き込みが失敗しても既存 config が旧内容のまま残る", () => {
+        saveConfigToPath(configPath, {
+            default_profile: "prod",
+            profiles: { prod: { api_url: "https://a.example.com" } },
+        });
+        const before = readFileSync(configPath, "utf-8");
+
+        // 一時パスをディレクトリとして先に作ると tmp 書き込みが必ず失敗する
+        // (EISDIR)。決定的に再現できる失敗注入。
+        mkdirSync(tmpWritePath, { recursive: true });
+
+        expect(() =>
+            saveConfigToPath(configPath, {
+                default_profile: "staging",
+                profiles: { staging: { api_url: "https://b.example.com" } },
+            }),
+        ).toThrow();
+
+        // 直接上書き実装 (旧実装) ではここで新内容に化けて赤くなる。
+        expect(readFileSync(configPath, "utf-8")).toBe(before);
+        const reloaded = loadConfigFromPath(configPath);
+        expect(reloaded?.default_profile).toBe("prod");
+    });
+
+    it("正常保存後に .tmp 残骸が無く、内容が読み戻せる", () => {
+        saveConfigToPath(configPath, {
+            default_profile: "prod",
+            profiles: { prod: { api_url: "https://a.example.com" } },
+        });
+        expect(existsSync(tmpWritePath)).toBe(false);
+        expect(readdirSync(tmp).filter((f) => f.endsWith(".tmp"))).toEqual([]);
+        expect(loadConfigFromPath(configPath)?.default_profile).toBe("prod");
+    });
+});
+
+describe("saveConfigToPath — 構造ガード", () => {
+    it("saver.ts は writeFileSync を直接使わず atomicWriteFile 経由である", () => {
+        const src = readFileSync(
+            new URL("../../src/config/saver.ts", import.meta.url),
+            "utf-8",
+        );
+        expect(src).toContain("atomicWriteFile");
+        expect(src).not.toContain("writeFileSync");
+    });
+});
diff --git a/packages/cli/tests/profile/delete.test.ts b/packages/cli/tests/profile/delete.test.ts
new file mode 100644
index 0000000..358c60f
--- /dev/null
+++ b/packages/cli/tests/profile/delete.test.ts
@@ -0,0 +1,897 @@
+import { existsSync, mkdtempSync, readFileSync, rmSync } from "node:fs";
+import { tmpdir } from "node:os";
+import { join } from "node:path";
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { BIN_NAME, ENV } from "../../src/branding.js";
+import type { ProfileEntry, RootConfigInput } from "../../src/config/schema.js";
+import { saveConfigToPath } from "../../src/config/saver.js";
+import { CredentialStoreError } from "../../src/credential/errors.js";
+import { FileStore } from "../../src/credential/file-store.js";
+import { deriveProfileHash12 } from "../../src/credential/key-derivation.js";
+import { KeychainStore } from "../../src/credential/keychain.js";
+import {
+    getGlobalMasterKeyRegistry,
+    MasterKeyRegistry,
+    resetGlobalMasterKeyRegistryForTests,
+} from "../../src/credential/master-key-registry.js";
+import { CredentialStore } from "../../src/credential/store.js";
+import { ExitCode } from "../../src/exit-codes.js";
+import {
+    deleteOAuthToken,
+    readOAuthToken,
+    writeOAuthToken,
+    type OAuthTokenBundle,
+} from "../../src/oauth/token-store.js";
+import { canonicalOrigin } from "../../src/profile/canonical-origin.js";
+import {
+    executeProfileDeletion,
+    planProfileDeletion,
+} from "../../src/profile/delete.js";
+import { ProfileResolutionError } from "../../src/profile/errors.js";
+import {
+    FileProfileWriter,
+    type ProfileWriter,
+} from "../../src/profile/writer.js";
+import { setGlobalAllowPlaintextFlag } from "../../src/runtime-state.js";
+
+/**
+ * `profile:delete` のロジック層テスト。
+ *
+ * 3 backend (keychain / file-encrypted / file-plaintext) を横断して
+ * 「credential が確実に落ちる」「config が壊れない」「消してはいけないものを
+ * 消さない」を固定する。CLI としての契約 (exit code / プロンプト順序) は
+ * tests/commands/profile/delete.test.ts が担当する。
+ */
+
+type BackendName = "keychain" | "file-encrypted" | "file-plaintext";
+
+const BACKENDS: readonly BackendName[] = [
+    "keychain",
+    "file-encrypted",
+    "file-plaintext",
+];
+
+const API_URL_A = "https://a.example.com";
+const API_URL_B = "https://b.example.com";
+const ORIGIN_A = canonicalOrigin(API_URL_A);
+const ORIGIN_B = canonicalOrigin(API_URL_B);
+
+// ---------------------------------------------------------------------------
+// env の退避・復帰 (file-store.test.ts の作法に合わせる)
+// ---------------------------------------------------------------------------
+
+const origEnv = {
+    key: process.env[ENV.CREDENTIAL_KEY],
+    pw: process.env[ENV.MASTER_PASSWORD],
+    allow: process.env[ENV.ALLOW_PLAINTEXT],
+    disableKeychain: process.env[ENV.DISABLE_KEYCHAIN],
+    ci: process.env["CI"],
+};
+
+let tmpDirs: string[] = [];
+
+beforeEach(() => {
+    tmpDirs = [];
+    delete process.env[ENV.CREDENTIAL_KEY];
+    delete process.env[ENV.MASTER_PASSWORD];
+    delete process.env[ENV.ALLOW_PLAINTEXT];
+    delete process.env["CI"];
+    // 既定は「keychain 無効」。keychain ケースだけが自スコープで解除する。
+    process.env[ENV.DISABLE_KEYCHAIN] = "1";
+    setGlobalAllowPlaintextFlag(false);
+    resetGlobalMasterKeyRegistryForTests();
+});
+
+afterEach(() => {
+    for (const dir of tmpDirs) rmSync(dir, { recursive: true, force: true });
+    for (const [k, v] of [
+        [ENV.CREDENTIAL_KEY, origEnv.key],
+        [ENV.MASTER_PASSWORD, origEnv.pw],
+        [ENV.ALLOW_PLAINTEXT, origEnv.allow],
+        [ENV.DISABLE_KEYCHAIN, origEnv.disableKeychain],
+        ["CI", origEnv.ci],
+    ] as const) {
+        if (v !== undefined) process.env[k] = v;
+        else delete process.env[k];
+    }
+    setGlobalAllowPlaintextFlag(false);
+    resetGlobalMasterKeyRegistryForTests();
+    vi.restoreAllMocks();
+});
+
+// ---------------------------------------------------------------------------
+// keychain の in-memory Fake
+// ---------------------------------------------------------------------------
+
+type Stored = Map<string, string>;
+
+/** 複合キーの区切り。不可視文字を直書きせず明示エスケープで書く。 */
+const KEY_SEP = "\u0000";
+
+function fakeKeychain(store: Stored): KeychainStore {
+    class FakeEntry {
+        constructor(
+            private readonly service: string,
+            private readonly username: string,
+        ) {}
+        private get key(): string {
+            return `${this.service}${KEY_SEP}${this.username}`;
+        }
+        getPassword(): string | null {
+            return store.get(this.key) ?? null;
+        }
+        setPassword(password: string): void {
+            store.set(this.key, password);
+        }
+        deletePassword(): boolean {
+            return store.delete(this.key);
+        }
+    }
+    return new KeychainStore(FakeEntry);
+}
+
+// ---------------------------------------------------------------------------
+// ハーネス
+// ---------------------------------------------------------------------------
+
+type Harness = {
+    configPath: string;
+    credDir: string;
+    writer: FileProfileWriter;
+    store: CredentialStore;
+    fileStore: FileStore;
+    /** primary backend。破損 index の注入に使う。 */
+    primary: FileStore | KeychainStore;
+};
+
+type Seed = {
+    profiles: Record<string, ProfileEntry>;
+    defaultProfile?: string;
+};
+
+function seedConfig(configPath: string, seed: Seed): void {
+    const cfg: RootConfigInput = { profiles: seed.profiles };
+    if (seed.defaultProfile !== undefined) {
+        cfg.default_profile = seed.defaultProfile;
+    }
+    saveConfigToPath(configPath, cfg);
+}
+
+function makeTmp(): string {
+    const dir = mkdtempSync(join(tmpdir(), "cli-pdel-"));
+    tmpDirs.push(dir);
+    return dir;
+}
+
+function setupHarness(backend: BackendName, seed: Seed): Harness {
+    const tmp = makeTmp();
+    const configPath = join(tmp, "config.yaml");
+    const credDir = join(tmp, "credentials");
+    seedConfig(configPath, seed);
+
+    let keychain: KeychainStore | null = null;
+
+    if (backend === "keychain") {
+        // isAvailable() の早期 false を自スコープだけ解除する。
+        delete process.env[ENV.DISABLE_KEYCHAIN];
+        keychain = fakeKeychain(new Map<string, string>());
+    } else if (backend === "file-encrypted") {
+        process.env[ENV.CREDENTIAL_KEY] = Buffer.alloc(32)
+            .fill(0x33)
+            .toString("base64");
+    } else {
+        setGlobalAllowPlaintextFlag(true);
+    }
+
+    // 暗号化 file-store は writeWithPreflight が **グローバル** registry を
+    // ensure するため、FileStore にも同じインスタンスを渡して二重管理を防ぐ。
+    const fileStore = new FileStore(credDir, getGlobalMasterKeyRegistry());
+    const store = new CredentialStore({ keychain, fileStore });
+
+    return {
+        configPath,
+        credDir,
+        writer: new FileProfileWriter(configPath),
+        store,
+        fileStore,
+        primary: keychain ?? fileStore,
+    };
+}
+
+function sampleBundle(): OAuthTokenBundle {
+    return {
+        accessToken: "access-token",
+        refreshToken: "refresh-token",
+        expiresAt: 1_900_000_000_000,
+        clientId: "client-id",
+        sessionId: null,
+        scopes: [],
+    };
+}
+
+async function writeApiKey(
+    h: Harness,
+    origin: string,
+    profile: string,
+    value: string,
+): Promise<void> {
+    await h.store.writeWithPreflight(origin, profile, "apikey", "", value, {
+        printBanner: false,
+    });
+}
+
+function silenceStderr(): void {
+    vi.spyOn(console, "error").mockImplementation(() => {});
+}
+
+// ---------------------------------------------------------------------------
+// 1 / 2 / 4 / 5: backend 横断
+// ---------------------------------------------------------------------------
+
+describe.each(BACKENDS)("profile:delete (%s)", (backend: BackendName) => {
+    it("1. credential (apikey + OAuth token) が消える", async () => {
+        const h = setupHarness(backend, {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
+        await writeOAuthToken(h.store, ORIGIN_A, "prod", sampleBundle(), {
+            printBanner: false,
+        });
+        expect(readOAuthToken(h.store, ORIGIN_A, "prod")).not.toBeNull();
+
+        executeProfileDeletion(
+            { writer: h.writer, store: h.store },
+            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
+        );
+
+        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBeNull();
+        // OAuth token は meta 名前空間 = clearProfile では消えない (keychain)。
+        // deleteOAuthToken を明示的に呼ぶ設計であることを固定する。
+        expect(readOAuthToken(h.store, ORIGIN_A, "prod")).toBeNull();
+        expect(h.store.listItems(ORIGIN_A, "prod")).toEqual([]);
+
+        if (backend !== "keychain") {
+            expect(
+                existsSync(
+                    join(h.credDir, deriveProfileHash12(ORIGIN_A, "prod")),
+                ),
+            ).toBe(false);
+        }
+    });
+
+    it("2. config エントリが消える", async () => {
+        const h = setupHarness(backend, {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
+
+        executeProfileDeletion(
+            { writer: h.writer, store: h.store },
+            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
+        );
+
+        expect(h.writer.get("prod")).toBeUndefined();
+        expect(h.writer.list().map((r) => r.name)).toEqual(["staging"]);
+    });
+
+    it("4. 他プロファイルの credential が生存する", async () => {
+        const h = setupHarness(backend, {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
+        await writeApiKey(h, ORIGIN_A, "staging", "staging-secret");
+
+        executeProfileDeletion(
+            { writer: h.writer, store: h.store },
+            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
+        );
+
+        if (backend === "file-encrypted") {
+            // 偽陽性の排除: MasterKeyRegistry のプロセス内キャッシュを捨て、
+            // FileStore / CredentialStore を新インスタンスで組み直す
+            // (別プロセス相当)。共有 master key が壊れていないことまで見る。
+            resetGlobalMasterKeyRegistryForTests();
+            const freshRegistry = new MasterKeyRegistry();
+            const freshFileStore = new FileStore(h.credDir, freshRegistry);
+            const freshStore = new CredentialStore({
+                keychain: null,
+                fileStore: freshFileStore,
+            });
+            await freshRegistry.ensure(ORIGIN_A, "staging", {
+                env: process.env,
+                isTty: false,
+            });
+            expect(
+                freshStore.read(ORIGIN_A, "staging", "apikey", ""),
+            ).toBe("staging-secret");
+            expect(freshStore.listItems(ORIGIN_A, "staging")).toEqual([
+                { kind: "apikey", id: "" },
+            ]);
+        } else {
+            expect(h.store.read(ORIGIN_A, "staging", "apikey", "")).toBe(
+                "staging-secret",
+            );
+            expect(h.store.listItems(ORIGIN_A, "staging")).toEqual([
+                { kind: "apikey", id: "" },
+            ]);
+        }
+    });
+
+    it("5. credential が無いプロファイルの削除も成功する (冪等)", () => {
+        const h = setupHarness(backend, {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+
+        expect(() =>
+            executeProfileDeletion(
+                { writer: h.writer, store: h.store },
+                planProfileDeletion(h.writer, "prod", { clearDefault: true }),
+            ),
+        ).not.toThrow();
+        expect(h.writer.get("prod")).toBeUndefined();
+    });
+});
+
+// ---------------------------------------------------------------------------
+// 3. default_profile の 5 ケース (判断 8)
+// ---------------------------------------------------------------------------
+
+describe("default_profile transitions", () => {
+    it("対象が default / clearDefault:false → 計画段階で conflict、副作用ゼロ", async () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
+        const before = readFileSync(h.configPath, "utf-8");
+
+        let thrown: unknown = null;
+        try {
+            planProfileDeletion(h.writer, "prod", { clearDefault: false });
+        } catch (e) {
+            thrown = e;
+        }
+        expect(thrown).toBeInstanceOf(ProfileResolutionError);
+        expect((thrown as ProfileResolutionError).exitCode).toBe(
+            ExitCode.ProfileConflict,
+        );
+        // config も credential も変わらない (計画フェーズは副作用ゼロ)。
+        expect(readFileSync(h.configPath, "utf-8")).toBe(before);
+        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBe(
+            "prod-secret",
+        );
+    });
+
+    it("対象が default / clearDefault:true / 残 1 件 → default が付け替わる", () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        const result = executeProfileDeletion(
+            { writer: h.writer, store: h.store },
+            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
+        );
+        expect(result.nextDefault).toBe("staging");
+        expect(h.writer.readState().defaultProfile).toBe("staging");
+    });
+
+    it("対象が default / clearDefault:true / 残 0 件 → default が消える", () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: { prod: { api_url: API_URL_A } },
+            defaultProfile: "prod",
+        });
+        const result = executeProfileDeletion(
+            { writer: h.writer, store: h.store },
+            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
+        );
+        expect(result.nextDefault).toBeNull();
+        expect(result.remaining).toEqual([]);
+        expect(h.writer.readState().defaultProfile).toBeUndefined();
+    });
+
+    it("対象が default / clearDefault:true / 残 2 件以上 → 勝手に選ばない", () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+                dev: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        const result = executeProfileDeletion(
+            { writer: h.writer, store: h.store },
+            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
+        );
+        expect(result.nextDefault).toBeNull();
+        expect(result.remaining).toHaveLength(2);
+        expect(h.writer.readState().defaultProfile).toBeUndefined();
+    });
+
+    it("対象が default でない → default は変わらない", () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        executeProfileDeletion(
+            { writer: h.writer, store: h.store },
+            planProfileDeletion(h.writer, "staging", { clearDefault: false }),
+        );
+        expect(h.writer.readState().defaultProfile).toBe("prod");
+    });
+});
+
+// ---------------------------------------------------------------------------
+// 5b. 壊れた profile / 破損 index の分岐
+// ---------------------------------------------------------------------------
+
+describe("壊れた profile / 破損 index", () => {
+    it("b. api_url が非 http(s) スキームなら credential に触れず config だけ消える", () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: {
+                broken: { api_url: "ftp://x.example.com" },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "staging",
+        });
+        silenceStderr();
+        const result = executeProfileDeletion(
+            { writer: h.writer, store: h.store },
+            planProfileDeletion(h.writer, "broken", { clearDefault: false }),
+        );
+        expect(result.credentialsSkipped).toBe(true);
+        expect(h.writer.get("broken")).toBeUndefined();
+    });
+
+    it("c. api_url 未設定なら credential に触れず config だけ消える", () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: { broken: {}, staging: { api_url: API_URL_A } },
+            defaultProfile: "staging",
+        });
+        silenceStderr();
+        const result = executeProfileDeletion(
+            { writer: h.writer, store: h.store },
+            planProfileDeletion(h.writer, "broken", { clearDefault: false }),
+        );
+        expect(result.credentialsSkipped).toBe(true);
+        expect(h.writer.get("broken")).toBeUndefined();
+    });
+
+    it("d. file backend の index 破損では削除が完遂する", async () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
+        // credential index を不正 JSON で上書きする。
+        h.fileStore.write(ORIGIN_A, "prod", "meta", "index", "{not json");
+        silenceStderr();
+
+        const result = executeProfileDeletion(
+            { writer: h.writer, store: h.store },
+            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
+        );
+
+        expect(result.credentialIndexCorrupted).toBe(true);
+        expect(h.writer.get("prod")).toBeUndefined();
+        expect(
+            existsSync(join(h.credDir, deriveProfileHash12(ORIGIN_A, "prod"))),
+        ).toBe(false);
+    });
+
+    it("e. keychain backend の index 破損では config を残して exit 18 で止まる", async () => {
+        const h = setupHarness("keychain", {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
+        await writeApiKey(h, ORIGIN_A, "staging", "staging-secret");
+        h.primary.write(ORIGIN_A, "prod", "meta", "index", "{not json");
+        silenceStderr();
+
+        let thrown: unknown = null;
+        try {
+            executeProfileDeletion(
+                { writer: h.writer, store: h.store },
+                planProfileDeletion(h.writer, "prod", { clearDefault: true }),
+            );
+        } catch (e) {
+            thrown = e;
+        }
+        expect(thrown).toBeInstanceOf(CredentialStoreError);
+        expect((thrown as CredentialStoreError).exitCode).toBe(
+            ExitCode.CredentialStoreFailure,
+        );
+        // config を消すと api_url を失い、keychain の秘密が到達不能になる。
+        expect(h.writer.get("prod")).toBeDefined();
+        // 他プロファイルの credential は無傷。
+        expect(h.store.read(ORIGIN_A, "staging", "apikey", "")).toBe(
+            "staging-secret",
+        );
+    });
+
+    it("f. index 破損以外の CredentialStoreError は握り潰さない", async () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
+
+        /** kind 既定 ("unknown") の CredentialStoreError を投げるスタブ。 */
+        class UnknownFailureStore extends CredentialStore {
+            override clearProfile(): void {
+                throw new CredentialStoreError(
+                    "backend exploded",
+                    ExitCode.CredentialStoreFailure,
+                );
+            }
+        }
+        const store = new UnknownFailureStore({
+            keychain: null,
+            fileStore: h.fileStore,
+        });
+        silenceStderr();
+
+        expect(() =>
+            executeProfileDeletion(
+                { writer: h.writer, store },
+                planProfileDeletion(h.writer, "prod", { clearDefault: true }),
+            ),
+        ).toThrow("backend exploded");
+        // config は残る (credential を落とし切れていないため)。
+        expect(h.writer.get("prod")).toBeDefined();
+    });
+});
+
+// ---------------------------------------------------------------------------
+// 5c. 確認待ち中の config 変更 (TOCTOU)
+// ---------------------------------------------------------------------------
+
+describe("確認待ち中の config 変更 (TOCTOU ガード)", () => {
+    it("a. api_url が A→B に変わったら何も削除せず競合終了する", async () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: { prod: { api_url: API_URL_A } },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "secret-a");
+        await writeApiKey(h, ORIGIN_B, "prod", "secret-b");
+
+        const plan = planProfileDeletion(h.writer, "prod", {
+            clearDefault: true,
+        });
+
+        // 別プロセス相当の設定更新。applyAtomic の patch 型では api_url を
+        // 変更できないため、config を直接書き戻して「他プロセスが書き替えた」
+        // 状態を作る。
+        seedConfig(h.configPath, {
+            profiles: { prod: { api_url: API_URL_B } },
+            defaultProfile: "prod",
+        });
+
+        expect(() =>
+            executeProfileDeletion({ writer: h.writer, store: h.store }, plan),
+        ).toThrow(ProfileResolutionError);
+        // credential も config も無傷。
+        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBe("secret-a");
+        expect(h.store.read(ORIGIN_B, "prod", "apikey", "")).toBe("secret-b");
+        expect(h.writer.get("prod")).toBeDefined();
+    });
+
+    it("b. default_profile が付け替わったら競合終了する", async () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "secret-a");
+        const plan = planProfileDeletion(h.writer, "prod", {
+            clearDefault: true,
+        });
+        h.writer.useDefaultProfile("staging");
+
+        expect(() =>
+            executeProfileDeletion({ writer: h.writer, store: h.store }, plan),
+        ).toThrow(ProfileResolutionError);
+        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBe("secret-a");
+        expect(h.writer.get("prod")).toBeDefined();
+    });
+
+    it("c. 別プロファイルが追加されたら競合終了する", async () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "secret-a");
+        const plan = planProfileDeletion(h.writer, "prod", {
+            clearDefault: true,
+        });
+        h.writer.addProfile("dev", { api_url: API_URL_A });
+
+        expect(() =>
+            executeProfileDeletion({ writer: h.writer, store: h.store }, plan),
+        ).toThrow(ProfileResolutionError);
+        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBe("secret-a");
+        expect(h.writer.get("prod")).toBeDefined();
+    });
+
+    it("d. 対象プロファイル自体が消えていたら not-found で止まる", async () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "secret-a");
+        const plan = planProfileDeletion(h.writer, "prod", {
+            clearDefault: true,
+        });
+        h.writer.deleteProfile("prod", { clearDefault: true });
+
+        let thrown: unknown = null;
+        try {
+            executeProfileDeletion({ writer: h.writer, store: h.store }, plan);
+        } catch (e) {
+            thrown = e;
+        }
+        expect(thrown).toBeInstanceOf(ProfileResolutionError);
+        expect((thrown as ProfileResolutionError).exitCode).toBe(
+            ExitCode.ProfileNotFound,
+        );
+        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBe("secret-a");
+    });
+
+    it("e. 何も変わっていなければ正常に削除される (誤検知しない)", async () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "secret-a");
+        const plan = planProfileDeletion(h.writer, "prod", {
+            clearDefault: true,
+        });
+
+        expect(() =>
+            executeProfileDeletion({ writer: h.writer, store: h.store }, plan),
+        ).not.toThrow();
+        expect(h.writer.get("prod")).toBeUndefined();
+    });
+});
+
+// ---------------------------------------------------------------------------
+// 6. ProfileWriter.deleteProfile の原子性
+// ---------------------------------------------------------------------------
+
+describe("deleteProfile の原子性", () => {
+    function writerWith(seed: Seed): { writer: FileProfileWriter; path: string } {
+        const tmp = makeTmp();
+        const path = join(tmp, "config.yaml");
+        seedConfig(path, seed);
+        return { writer: new FileProfileWriter(path), path };
+    }
+
+    const twoProfiles: Seed = {
+        profiles: {
+            prod: { api_url: API_URL_A },
+            staging: { api_url: API_URL_A },
+        },
+        defaultProfile: "prod",
+    };
+
+    it("1 回の呼び出しで profiles 除去と default 付け替えが同時に反映される", () => {
+        const { writer, path } = writerWith(twoProfiles);
+        writer.deleteProfile("prod", {
+            clearDefault: true,
+            nextDefault: "staging",
+        });
+        const reloaded = new FileProfileWriter(path);
+        expect(reloaded.get("prod")).toBeUndefined();
+        expect(reloaded.readState().defaultProfile).toBe("staging");
+    });
+
+    it.each([
+        [
+            "対象が default でない",
+            "staging",
+            { clearDefault: true, nextDefault: "prod" },
+        ],
+        ["clearDefault 無し", "prod", { nextDefault: "staging" }],
+        [
+            "nextDefault が自分自身",
+            "prod",
+            { clearDefault: true, nextDefault: "prod" },
+        ],
+        [
+            "nextDefault が不在",
+            "prod",
+            { clearDefault: true, nextDefault: "ghost" },
+        ],
+    ] as const)(
+        "不正な組合せ (%s) では config が一切変わらない",
+        (_label, target, opts) => {
+            const { writer, path } = writerWith(twoProfiles);
+            const before = readFileSync(path, "utf-8");
+            expect(() => writer.deleteProfile(target, opts)).toThrow();
+            expect(readFileSync(path, "utf-8")).toBe(before);
+        },
+    );
+});
+
+// ---------------------------------------------------------------------------
+// 7. 部分失敗の収束
+// ---------------------------------------------------------------------------
+
+describe("部分失敗の収束", () => {
+    it("credential 破棄後に config 保存が失敗しても再実行で収束する", async () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: {
+                prod: { api_url: API_URL_A },
+                staging: { api_url: API_URL_A },
+            },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
+
+        const real = h.writer;
+        let failNext = true;
+        const flaky: ProfileWriter = {
+            list: () => real.list(),
+            get: (name) => real.get(name),
+            readState: () => real.readState(),
+            snapshot: (name) => real.snapshot(name),
+            addProfile: (name, init) => {
+                real.addProfile(name, init);
+            },
+            updateExpectedEnv: (name, expected) => {
+                real.updateExpectedEnv(name, expected);
+            },
+            deleteProfile: (name, opts) => {
+                if (failNext) {
+                    failNext = false;
+                    throw new Error("disk full");
+                }
+                real.deleteProfile(name, opts);
+            },
+            useDefaultProfile: (name) => {
+                real.useDefaultProfile(name);
+            },
+            applyAtomic: (name, patch, verifyResult) => {
+                real.applyAtomic(name, patch, verifyResult);
+            },
+            persistVerificationMeta: (name, meta) => {
+                real.persistVerificationMeta(name, meta);
+            },
+        };
+
+        const errors: string[] = [];
+        vi.spyOn(console, "error").mockImplementation((msg: unknown) => {
+            errors.push(String(msg));
+        });
+
+        // (a) 1 回目: throw する
+        expect(() =>
+            executeProfileDeletion(
+                { writer: flaky, store: h.store },
+                planProfileDeletion(flaky, "prod", { clearDefault: true }),
+            ),
+        ).toThrow("disk full");
+
+        // (b) 再実行コマンド文字列が stderr に出る
+        expect(errors.join("\n")).toContain(`${BIN_NAME} profile:delete prod`);
+        // (c) config 側には profile が残る (状態が観測可能)
+        expect(real.get("prod")).toBeDefined();
+        // credential は落ちている
+        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBeNull();
+
+        // (d) 同じコマンドの再実行で収束する (credential 不在パスを通って成功)
+        expect(() =>
+            executeProfileDeletion(
+                { writer: flaky, store: h.store },
+                planProfileDeletion(flaky, "prod", { clearDefault: true }),
+            ),
+        ).not.toThrow();
+        expect(real.get("prod")).toBeUndefined();
+    });
+});
+
+// ---------------------------------------------------------------------------
+// 事前検証 (計画フェーズ) の失敗モード
+// ---------------------------------------------------------------------------
+
+describe("planProfileDeletion の事前検証", () => {
+    it("未登録プロファイルは not-found (exit 11)", () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: { prod: { api_url: API_URL_A } },
+            defaultProfile: "prod",
+        });
+        let thrown: unknown = null;
+        try {
+            planProfileDeletion(h.writer, "ghost", { clearDefault: true });
+        } catch (e) {
+            thrown = e;
+        }
+        expect(thrown).toBeInstanceOf(ProfileResolutionError);
+        expect((thrown as ProfileResolutionError).exitCode).toBe(
+            ExitCode.ProfileNotFound,
+        );
+    });
+
+    it("計画フェーズは stderr に何も書かない (副作用ゼロ)", () => {
+        const h = setupHarness("file-plaintext", {
+            profiles: { broken: {}, staging: { api_url: API_URL_A } },
+            defaultProfile: "staging",
+        });
+        const spy = vi.spyOn(console, "error").mockImplementation(() => {});
+        const plan = planProfileDeletion(h.writer, "broken", {
+            clearDefault: false,
+        });
+        expect(spy).not.toHaveBeenCalled();
+        expect(plan.credentials.kind).toBe("unlocatable");
+    });
+});
+
+// ---------------------------------------------------------------------------
+// OAuth token 破棄が clearProfile 任せでないことの直接固定
+// ---------------------------------------------------------------------------
+
+describe("OAuth token の破棄経路", () => {
+    it("keychain backend では clearProfile だけでは OAuth token が残る", async () => {
+        const h = setupHarness("keychain", {
+            profiles: { prod: { api_url: API_URL_A } },
+            defaultProfile: "prod",
+        });
+        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
+        await writeOAuthToken(h.store, ORIGIN_A, "prod", sampleBundle(), {
+            printBanner: false,
+        });
+
+        // clearProfile は index 掲載アイテムと meta:index しか消さない。
+        h.store.clearProfile(ORIGIN_A, "prod");
+        expect(readOAuthToken(h.store, ORIGIN_A, "prod")).not.toBeNull();
+
+        // deleteOAuthToken を明示的に呼ぶと消える = 削除経路が必要とする理由。
+        deleteOAuthToken(h.store, ORIGIN_A, "prod");
+        expect(readOAuthToken(h.store, ORIGIN_A, "prod")).toBeNull();
+    });
+});
diff --git a/tests/js/architecture/codex-model-consistency.test.ts b/tests/js/architecture/codex-model-consistency.test.ts
new file mode 100644
index 0000000..cb4870b
--- /dev/null
+++ b/tests/js/architecture/codex-model-consistency.test.ts
@@ -0,0 +1,129 @@
+import { describe, it, expect } from "vitest";
+import fs from "fs/promises";
+import path from "path";
+
+/**
+ * Codex 呼び出しモデルの一本化を deny-by-default で固定する。
+ *
+ * c2c 台帳 (skill-codex-integration t1 / skill-design-flow / skill-implement-flow) は
+ * 「gpt-5.5 一本化」を正典としており、aicue はこれに追従した
+ * (devnotes/20260805-0101-devtool-template-followup/)。
+ *
+ * 本テストが守るのは 2 つ:
+ *   1. app-* スキルの SKILL.md に canonical (gpt-5.5) 以外のモデル名が現れないこと
+ *   2. 走査対象そのものがドリフトしていないこと (inventory と実測の集合一致)
+ *
+ * devnotes/ は過去のレビュー実績の記録 (どのモデルが何を指摘したか) であり、
+ * 書き換えは履歴の改竄にあたるため **走査対象に含めない**。
+ */
+
+const SKILLS_ROOT = path.resolve(__dirname, "../../../.claude/skills");
+
+/** 唯一許可されるモデル。世代更新時はここだけを書き換える。 */
+const CANONICAL_MODEL = "gpt-5.5";
+
+/**
+ * 走査対象の明示 inventory (`.claude/skills` からの相対パス)。
+ *
+ * 現時点でモデル指定を持たないスキルも登録する = 将来そこにモデル記述が
+ * 生えたときも自動で検査対象になる。スキルを増減したらここも更新する
+ * (更新を忘れると下の集合一致検査が fail する)。
+ */
+const SKILL_INVENTORY: readonly string[] = [
+    "app-autopilot/SKILL.md",
+    "app-bug-hunt/SKILL.md",
+    "app-codex-review/SKILL.md",
+    "app-codex-vscode/SKILL.md",
+    "app-design/SKILL.md",
+    "app-implement/SKILL.md",
+    "app-todo-add/SKILL.md",
+    "app-todo-close/SKILL.md",
+    "app-update-docs/SKILL.md",
+] as const;
+
+/**
+ * `gpt-4` / `gpt-5` / `gpt-5.3-codex` / `gpt-5.1-codex-max` などのモデル
+ * トークンを拾う。`\.\d+` は数字を要求するので文末の句点を巻き込まない。
+ */
+const MODEL_TOKEN_PATTERN = /gpt-\d+(?:\.\d+)?(?:-[a-z0-9]+)*/gi;
+
+/** `.claude/skills/app-*\/SKILL.md` を実測で列挙する。 */
+const discoverAppSkillFiles = async (): Promise<readonly string[]> => {
+    const entries = await fs.readdir(SKILLS_ROOT, { withFileTypes: true });
+    const found: string[] = [];
+    for (const entry of entries) {
+        if (!entry.isDirectory()) continue;
+        if (!entry.name.startsWith("app-")) continue;
+        const rel = `${entry.name}/SKILL.md`;
+        try {
+            await fs.access(path.join(SKILLS_ROOT, rel));
+        } catch {
+            continue;
+        }
+        found.push(rel);
+    }
+    return found.sort();
+};
+
+describe("codex model consistency", () => {
+    it("走査対象 SKILL.md の集合が inventory と一致する (drift ガード)", async () => {
+        const discovered = await discoverAppSkillFiles();
+
+        // 「検査件数 0 なら fail」はこの一致検査に含まれる (inventory は非空)。
+        expect(SKILL_INVENTORY.length).toBeGreaterThan(0);
+        expect(discovered.length).toBeGreaterThan(0);
+
+        const missing = SKILL_INVENTORY.filter((p) => !discovered.includes(p));
+        const unregistered = discovered.filter(
+            (p) => !SKILL_INVENTORY.includes(p),
+        );
+
+        expect(
+            missing,
+            `inventory にあるのに実在しない SKILL.md があります (移動/改名/削除で\n`
+                + `モデル検査の守備範囲が痩せます)。意図した削除なら inventory からも\n`
+                + `外してください:\n  ${missing.join("\n  ")}`,
+        ).toEqual([]);
+
+        expect(
+            unregistered,
+            `inventory に無い app-* スキルがあります。モデル指定が野放しになるため\n`
+                + `SKILL_INVENTORY へ追加してください:\n  ${unregistered.join("\n  ")}`,
+        ).toEqual([]);
+    });
+
+    it(`SKILL.md に ${CANONICAL_MODEL} 以外のモデル名が現れない`, async () => {
+        const offenders: string[] = [];
+        let scanned = 0;
+
+        for (const rel of SKILL_INVENTORY) {
+            const content = await fs.readFile(
+                path.join(SKILLS_ROOT, rel),
+                "utf8",
+            );
+            scanned += 1;
+            const lines = content.split("\n");
+            lines.forEach((line, index) => {
+                const matches = line.match(MODEL_TOKEN_PATTERN);
+                if (matches === null) return;
+                for (const token of matches) {
+                    if (token.toLowerCase() === CANONICAL_MODEL) continue;
+                    offenders.push(
+                        `${rel}:${String(index + 1)}: ${token} — ${line.trim()}`,
+                    );
+                }
+            });
+        }
+
+        // 走査 0 件を「合格」と誤読しないための drift ガード。
+        expect(scanned).toBe(SKILL_INVENTORY.length);
+
+        expect(
+            offenders,
+            `canonical (${CANONICAL_MODEL}) 以外のモデル名が残っています。\n`
+                + `用途別の使い分けは廃止済みです (概念設計 判断 2/5)。\n`
+                + `モデル世代を更新する場合は CANONICAL_MODEL を書き換えてください:\n`
+                + `  ${offenders.join("\n  ")}`,
+        ).toEqual([]);
+    });
+});
```
