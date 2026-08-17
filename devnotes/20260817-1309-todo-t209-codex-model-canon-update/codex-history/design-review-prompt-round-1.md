【アプリの使命 (North Star) — AGENTS.md より】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【思考原則 — AGENTS.md より】
1. フレームワークのレンジ内でやる 2. 今必要なものだけ作る(オーバーエンジニアリング禁止) 3. 後方互換の並走を残さない 4. 別物の概念を「似ているから」で統合しない 5. テストファースト 6. タコツボ実装を避ける

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory → 窓口 PromptDefense → GuardedPrompt の 1 本道のみ)
6. prompt 文字列のコード直書き(resources/prompts/*.yaml に置く)
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript (strict) + vitest
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC
- 本件は開発ツール (Claude 用スキル文書と vitest の Architecture テスト) のみの変更である

【レビュー観点】
1. コードの正確性 (検出器のロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性 (命名規約、既存 gate のパターン)
3. TypeScript strict 適合性
4. テスト計画の網羅性 (テストファーストの赤の取り方、負のコントロール)
5. 波及変更の網羅性 (走査根を広げたことによる副作用、vitest の収集定義)
6. 副作用・後退リスク (検査が空振りで緑になる経路、fail-open の芽)
7. セキュリティ (該当が薄い領域なので、該当しなければその旨を明記してよい)
8. 受け入れ条件が機械検証可能な形になっているか
9. 保証範囲の記述が誇張になっていないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: Codex 正典モデルの現行世代への追従 (aicue:T209)

## 目的と台帳の根拠

家系の機能台帳 lctl の機能 `skill-codex-integration` に対するオーナー裁定
**AG-186 (2026-08-15)** に追従する。裁定は Codex に指定するモデルの正典を
現行世代の 3 本へ上げ、追従先の 1 つとして aicue のセルを `update_pending` にしている。
aicue 宛ての申し送りには、スキル 2 本の差し替えに加えて
「機械検査 `tests/js/architecture/codex-model-consistency.test.ts` の
正典モデル名一覧の更新も必須 (更新しないと新モデル名を既定拒否で赤にする)」と明記されている。

正典 (裁定 AG-186 の本文):

| 綴り | 用途 |
|---|---|
| `gpt-5.6-sol` | コードの分析・レビュー・技術設計 (既定) |
| `gpt-5.6-terra` | 議論・概念設計 |
| `gpt-5.6-luna` | 軽い判定 |

- 「指定してはいけない名前」「期限つきで使えるが新たに指定しない名前」の区分の正本は
  spirux 側の呼び出し規約スキルの表である (aicue は綴りを持たない。概念設計 判断 1)。
- 名前は**接尾辞まで含めて 1 つ**であり、接尾辞を落とした名前を書かない。
- 動くのは**モデルの軸だけ**である (使命・禁止事項の 3 小節構造は本裁定の範囲外)。

他リポジトリの到達状況: spirux 到達済み (作業項目 spirux:T1181 / spirux:T1188)、
laravel-claude-template 到達済み (作業項目 laravel-claude-template:T115)、
aigenba / motivation / metamovics は追従待ち。
aicue 側の作業項目番号は **aicue:T209** を予定している。

関連する裁定 **AG-193 (2026-08-16)**: Codex の会話の握り (継続用の一時状態) は
記録・成果物に当たらない。aicue の `app-codex-review` スキルは既にセッション JSONL を
リポジトリ外へ置く運用なので**コード変更は不要**である (確認のみ。受け入れ条件には入れない)。

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書 (SOP) を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ (PWA) でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合 (OJT を撮って形式化する tebiki) と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置 (SECI)。

本件はアプリ機能ではなく**開発の進め方**に効く。設計・実装レビューの工程が
提供終了で止まると、上の使命に向けた改善そのものが止まる。

### 禁止事項 (AGENTS.md より。本件に効くもの)

1. テストなしの実装完了報告 (不変条件は対応する Architecture テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

本件の変更はスキル文書 4 本と vitest の Architecture テスト 1 本のみで、
`app/` `resources/` `routes/` `database/` `config/` には触れない。
上記 2〜8 に該当する変更は発生しない。1 (テスト必須) と 9 は本設計で守る。

### コーディングルール

- 変更対象は TypeScript (vitest) と Markdown のみ。PHP の変更は無いが、
  `composer test` / `composer phpstan` / `vendor/bin/pint --test` は退行確認として全数走らせる。
- TypeScript は strict。許可綴りは `as const` 配列から**ユニオン型を導出**し、
  期待表の値をその型に限る (任意文字列を書けなくする)。
- 既定拒否 (deny-by-default)。空振りを合格と誤読しないガードを必ず持つ。
- 後方互換の並走を残さない — 旧 `CANONICAL_MODEL` (単数) と `SKILL_INVENTORY` は削除する。

## 概念設計リファレンス

`devnotes/20260817-1309-todo-t209-codex-model-canon-update/conceptual-design.md`
(Codex `gpt-5.6-terra` / medium レビュー Round 2 で **APPROVED**。履歴は `codex-history/`)

## 変更ファイル一覧

| 区分 | パス | 内容 |
|---|---|---|
| 変更 | `tests/js/architecture/codex-model-consistency.test.ts` | 検査を 3 層構成へ差し替え (施策 1) |
| 変更 | `.claude/skills/app-codex-vscode/SKILL.md` | モデル指定の正本。表を 3 行へ (施策 2) |
| 変更 | `.claude/skills/app-codex-review/SKILL.md` | 既定モデルの記述 (施策 3) |
| 変更 | `.claude/skills/app-design/SKILL.md` | 概念設計 / 詳細設計の指定 3 箇所 (施策 4) |
| 変更 | `.claude/skills/app-implement/SKILL.md` | 実装レビューの指定 1 箇所 (施策 5) |
| 新規 | なし | — |
| 削除 | なし | — |

**触らないと決めたファイル** (概念設計の調査結果より):

| パス | 扱い | 理由 |
|---|---|---|
| `docs/TODO-closed.md` の T100 行 | 履歴として残す | 完了済み作業の記録。当時の事実であり書き換えは記録の改竄 |
| `devnotes/` 配下 851 箇所 | 履歴として残す | 過去のレビュー実績。現行の検査も同じ理由で走査対象外にしている |
| `tests/Feature/Support/StrayLlmCallGuardTest.php` / `tests/Support/Prompts/MinimalLlmCallPrompt.php` | 対象外 | Codex ではなくアプリの LLM 呼び出し (Prism 経由) の値。呼び出し口が違えば使える名前の体系も違い、本裁定の正本の管轄外 |
| `docs/template-divergence.md` | 登録しない | 検査の形の正典は台帳上まだ未決であり、登録すべき「正典からの逸脱」が定義されていない |
| `AGENTS.md` | 変更しない | モデル名を 1 箇所も持たない (実測)。二重管理を作らない |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|---|---|---|
| 1 | 検査を正典 3 本 + 用途割当 + 走査根拡大へ差し替える | `tests/js/architecture/codex-model-consistency.test.ts` | Critical |
| 2 | 正本 (`app-codex-vscode`) のモデル表を差し替える | `.claude/skills/app-codex-vscode/SKILL.md` | Critical |
| 3 | `app-codex-review` の既定モデルを差し替える | `.claude/skills/app-codex-review/SKILL.md` | Critical |
| 4 | `app-design` の概念設計 / 詳細設計の指定を差し替える | `.claude/skills/app-design/SKILL.md` | Critical |
| 5 | `app-implement` の実装レビューの指定を差し替える | `.claude/skills/app-implement/SKILL.md` | Critical |

実装順序は **1 → 2 → 3 → 4 → 5**。1 を先に入れて赤を実測してから 2〜5 を入れる。

---

## 施策 1: 検査を正典 3 本 + 用途割当 + 走査根拡大へ差し替える

### 変更箇所

- ファイル: `tests/js/architecture/codex-model-consistency.test.ts` (全面差し替え、現行 129 行)
- ファイル名と置き場所は**変えない** (台帳の gates 欄が
  `aicue:tests/js/architecture/codex-model-consistency.test.ts` を名指しで参照しているため)。

### 波及変更

- TypeScript 型定義: なし (テスト内で完結)
- API Resource / DTO: なし
- vitest の収集定義 (`scripts/test-inventory-config.ts`): **変更不要**
  (root project の include が `tests/js/**/*.test.ts` で、ファイル名を変えないため)
- テストファイル: 本ファイル自身

### 現行コードの要点 (差し替え前)

```ts
const SKILLS_ROOT = path.resolve(__dirname, "../../../.claude/skills");
const CANONICAL_MODEL = "gpt-5.5";            // 許可綴りが 1 つ
const SKILL_INVENTORY: readonly string[] = [ /* app- で始まるスキルの SKILL.md 9 本 */ ];
const MODEL_TOKEN_PATTERN = /gpt-\d+(?:\.\d+)?(?:-[a-z0-9]+)*/gi;
// it 1: discoverAppSkillFiles() と SKILL_INVENTORY の集合一致 (drift ガード)
// it 2: SKILL_INVENTORY の各ファイルに CANONICAL_MODEL 以外の綴りが無いこと
```

### 変更後の構造

#### 定数 (意図の宣言 = 目録)

```ts
/** 正典の綴り。裁定 AG-186 (家系の機能台帳 skill-codex-integration) が正本。 */
const CANONICAL_MODELS = ["gpt-5.6-sol", "gpt-5.6-terra", "gpt-5.6-luna"] as const;
type CanonicalModel = (typeof CANONICAL_MODELS)[number];

/** 走査根 (repo root からの相対)。git 追跡下の全ファイルを見る。 */
const SCAN_ROOTS = [".claude", "scripts"] as const;

/**
 * 綴りが現れてよいファイルと、その出現回数の期待値。
 * ここに無いファイルは **0 件** が期待値である (既定拒否)。
 *
 * 正本のスキル文書と同じ割当がここにも書かれるのは意図した形である。
 * 意図 (この表) と実測 (ファイルの中身) を独立に突き合わせるので、
 * 片方だけ直すと赤になる。自動抽出で一本化すると、正本が壊れたときに
 * 検査も一緒に壊れて緑のまま通る側へ倒れる。
 */
const EXPECTED_OCCURRENCES: Readonly<
    Record<string, Readonly<Partial<Record<CanonicalModel, number>>>>
> = {
    ".claude/skills/app-codex-vscode/SKILL.md": {
        "gpt-5.6-sol": 1, "gpt-5.6-terra": 1, "gpt-5.6-luna": 1,
    },
    ".claude/skills/app-codex-review/SKILL.md": { "gpt-5.6-sol": 1 },
    ".claude/skills/app-design/SKILL.md": { "gpt-5.6-terra": 2, "gpt-5.6-sol": 2 },
    ".claude/skills/app-implement/SKILL.md": { "gpt-5.6-sol": 1 },
} as const;

/**
 * 用途 (label) → 綴り の期待写像。キーは「相対パス#label」。
 * 概念設計に議論向け・詳細設計と実装レビューにコード向けを充てる、が意図。
 */
const EXPECTED_ASSIGNMENTS: Readonly<Record<string, CanonicalModel>> = {
    ".claude/skills/app-design/SKILL.md#conceptual-review": "gpt-5.6-terra",
    ".claude/skills/app-design/SKILL.md#design-review": "gpt-5.6-sol",
    ".claude/skills/app-implement/SKILL.md#impl-review": "gpt-5.6-sol",
} as const;

/** 正本 (モデル指定の唯一の表を持つファイル)。 */
const CANON_FILE = ".claude/skills/app-codex-vscode/SKILL.md";

/** `gpt-5.6-sol` / `gpt-5.5` / `gpt-5.3-codex` などの綴りを拾う (現行と同じ)。 */
const MODEL_TOKEN_PATTERN = /gpt-\d+(?:\.\d+)?(?:-[a-z0-9]+)*/gi;
```

#### 検出器 (純粋関数。負のコントロールから直接呼ぶ)

| 関数 | 入力 | 出力 | 役割 |
|---|---|---|---|
| `collectModelTokens(content)` | ファイル本文 | `{ token, line }[]` | 層 1。行単位で綴りを全数え上げする |
| `collectAssignments(content)` | ファイル本文 | `{ label, model }[]` または読めない理由の配列 | 層 2。節ごとにモデル宣言とラベル宣言を対にする |
| `collectCanonTableModels(content)` | 正本の本文 | `string[]` | 層 3。表の行から綴りを取る |

**層 2 の節の切り方と個数の規約** (Round 2 の Suggestion に対応):

- 節の境界は **`#` で始まる見出し行**である (レベルは問わない)。
  見出し行から次の見出し行の直前までが 1 節。最初の見出しより前は「前文の節」として同じ規則で扱う。
- モデル宣言は `**model**:` で始まる行、ラベル宣言は `**label**:` で始まる行
  (いずれも行頭。前後の空白は許容)。値は**バッククォートで囲まれた 1 語**として取る。
- 1 つの節が持てるモデル宣言とラベル宣言は **どちらも 0 個か 1 個**である。
  - 両方 0 個 → その節は対象外 (対を作らない)
  - 両方 1 個 → 対を作る
  - **それ以外はすべて「読めない書き方」として赤にする**
    (片方だけ / 同じ節に 2 個以上 / バッククォートで囲まれていない)。
    「読めなかったから緑」を構造的に無くすためである。

#### 検査項目 (it)

| # | 名前 | 内容 |
|---|---|---|
| 1 | 走査対象の列挙が成立する | `git ls-files -z` を `SCAN_ROOTS` に対して実行し、**NUL 区切り**で分解する。実行に失敗したら握り潰さず throw する (git 前提の Architecture 不変条件)。取得件数が 0 なら赤 |
| 2 | 期待表のキーが全て実在する | `EXPECTED_OCCURRENCES` / `EXPECTED_ASSIGNMENTS` / `CANON_FILE` の対象パスが列挙結果に含まれること (移動・改名・削除で守備範囲が痩せるのを検出) |
| 3 | 綴りの既定拒否と出現回数の一致 (層 1) | 列挙した全ファイルを読み (読込失敗は赤)、綴りを数える。許可外の綴りは 1 件でも赤。ファイルごとの綴り別出現回数が `EXPECTED_OCCURRENCES` と**完全一致** (表に無いファイルは全綴り 0 件) |
| 4 | 用途割当の完全一致 (層 2) | 全ファイルから対を集め、`{パス}#{label}` → 綴り の写像が `EXPECTED_ASSIGNMENTS` と**完全一致** (キー集合も値も)。読めない書き方は赤 |
| 5 | 正本の表が 3 綴りちょうど (層 3) | `CANON_FILE` の表の行から取った綴りの集合が `CANONICAL_MODELS` と完全一致し、行数も 3 であること |
| 6 | 負のコントロール (検出器が実際に点灯する) | 下表の 8 分岐を検体文字列で実測する |

#### 負のコントロールの 8 分岐

| # | 検体 | 期待 |
|---|---|---|
| 1 | 旧世代の綴りを含む行 | 層 1 が違反として拾う |
| 2 | 接尾辞を落とした綴り (`gpt-5.6` 単体) を含む行 | 層 1 が違反として拾う (許可集合に無いため) |
| 3 | 期待より 1 件多い出現 | 層 1 の回数比較が不一致になる |
| 4 | 節にモデル宣言があってラベル宣言が無い | 層 2 が「読めない書き方」として拾う |
| 5 | 同じ節にモデル宣言が 2 個 | 層 2 が「読めない書き方」として拾う |
| 6 | ラベルと綴りの対応が期待と違う (議論向けとコード向けの入れ替え) | 層 2 の写像比較が不一致になる |
| 7 | バッククォートで囲まれていない宣言 | 層 2 が「読めない書き方」として拾う |
| 8 | 正本の表の行が 2 行 / 4 行 | 層 3 が不一致になる |

走査 0 件の分岐は検査項目 1 が担う (検体ではなく実測で守る)。

### 型安全性チェック

- [x] 許可綴りは `as const` からユニオン型 `CanonicalModel` を導出し、期待表の値をその型に限る
- [x] 検出器の戻り値は `readonly` 配列で受ける
- [x] `git ls-files` の失敗・ファイル読込の失敗を `catch` して**再 throw** する (握り潰さない)
- [x] `String.prototype.match` に `/g` 付き正規表現を使う (毎回全件を返し `lastIndex` を持ち越さない)

### テスト計画

- [x] 本施策そのものがテストである
- [x] 施策 1 を単独で入れた時点で**呼び出し側 7 箇所が赤**になることを実測し、
      失敗出力を `devnotes/.../test-first-red.md` に記録する
- [x] 負のコントロール 8 分岐で検出器の生存を実測する
- [x] 個別の `DatabaseTransactions` は使わない (PHP のテストではない)

### リスク

| リスク | 対応 |
|---|---|
| 走査根を広げたことで、将来 `.claude/` や `scripts/` に説明目的で綴りを書きたくなったとき赤になる | それが狙いである。書きたい場合は正本 (`CANON_FILE`) の表を通すか、記録を `devnotes/` へ置く |
| `git` が無い環境でテストが落ちる | 前例 (`pages-path-case-invariant.test.ts`) と同じ扱い。git 前提の Architecture 不変条件として**明瞭に失敗させる** (silent skip しない) |
| 期待表と正本の二重管理 | 意図した形 (概念設計の「保証しないもの」に明記)。同じ変更で直す |

---

## 施策 2: 正本 (`app-codex-vscode`) のモデル表を差し替える

### 変更箇所

- ファイル: `.claude/skills/app-codex-vscode/SKILL.md` (L32〜L39 の「利用可能モデル」節)

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 施策 1 の `EXPECTED_OCCURRENCES` (`gpt-5.6-sol` / `gpt-5.6-terra` /
  `gpt-5.6-luna` を各 1 件) と層 3 の表検査が本節に対応する

### 現行

```markdown
## 利用可能モデル

| モデル | 用途 |
|--------|------|
| `gpt-5.5` | 唯一の指定モデル。コード分析・レビュー・技術設計・概念設計のすべて |

用途別のモデル使い分けは行わない（`tests/js/architecture/codex-model-consistency.test.ts`
が `gpt-5.5` 以外のモデル名を deny-by-default で検出する）。
```

### 変更後

```markdown
## 利用可能モデル

モデル指定の**正本は本節**である。呼び出し側のスキルは下の割当に従って綴りを書く。

| モデル | 用途 |
|--------|------|
| `gpt-5.6-sol` | コードの分析・レビュー・技術設計（**既定**） |
| `gpt-5.6-terra` | 議論・概念設計 |
| `gpt-5.6-luna` | 軽い判定 |

- **名前は接尾辞まで含めて 1 つ**である。接尾辞を落とした名前を書くと呼び出しが失敗する。
- 前の世代の名前・末尾に codex が付く名前は**指定しない**。提供が終了しているもの・
  終了日が決まっているものがあり、指定した時点で呼び出しが失敗する。
  本書は**その綴りを持たない**（区分の正本は家系の機能台帳 `skill-codex-integration` の
  裁定 AG-186 が指す spirux 側の呼び出し規約スキルの表である）。
- 用途と綴りの対応、および綴りが本書と呼び出し側スキルの外へ漏れていないことは
  `tests/js/architecture/codex-model-consistency.test.ts` が既定拒否で固定する
  （許可表に無い綴りは 1 件でも赤になる）。
```

「本リポジトリに軽い判定の呼び出しは現在 1 つも無い」ことは書かない
(実装が増えたときに古くなる記述を残さない。目録は検査側が持つ)。

### テスト計画

- [x] 施策 1 の層 1 (出現回数) と層 3 (表の行) が本節を固定する
- [x] 施策 1 単独では本ファイルが赤 → 施策 2 適用で当該ファイル分が緑になることを実測

### リスク

- 旧綴りの禁止表を持たないため、「なぜこの綴りを書いてはいけないか」を
  本書だけでは追えない。→ 台帳の裁定番号 (AG-186) を本文に書いて追跡できるようにする。

---

## 施策 3: `app-codex-review` の既定モデルを差し替える

### 変更箇所

- ファイル: `.claude/skills/app-codex-review/SKILL.md` (L100)

### 現行 → 変更後

```markdown
- `-m {model}`: モデルを指定（`gpt-5.5`）
```
↓
```markdown
- `-m {model}`: モデルを指定（既定は `gpt-5.6-sol`。用途別の割当は `app-codex-vscode` が正本）
```

### 波及変更

- テストファイル: 施策 1 の `EXPECTED_OCCURRENCES` (`gpt-5.6-sol` 1 件)
- 本ファイルの「session_label の命名規則」節はラベルの一覧を箇条書きで持つが、
  `**label**:` 形式の宣言ではないため層 2 の対象にならない (変更不要)

### テスト計画 / リスク

- [x] 層 1 の出現回数で固定される
- リスク: 無し (記述 1 行)

---

## 施策 4: `app-design` の概念設計 / 詳細設計の指定を差し替える

### 変更箇所

- ファイル: `.claude/skills/app-design/SKILL.md` (L58 / L113 / L283)

### 現行 → 変更後

| 行 | 現行 | 変更後 |
|---|---|---|
| L58 | `- 概念設計レビュー・詳細設計レビューとも **\`gpt-5.5\`** を使用（reasoning effort で使い分ける）` | `- 概念設計レビューは **\`gpt-5.6-terra\`**（議論・概念設計）、詳細設計レビューは **\`gpt-5.6-sol\`**（コードの分析・技術設計）を使用する（割当の正本は \`app-codex-vscode\`）` |
| L113 | `**model**: \`gpt-5.5\`` (§1-3 概念設計レビュー) | `**model**: \`gpt-5.6-terra\`` |
| L283 | `**model**: \`gpt-5.5\`` (§2-3 詳細設計レビュー) | `**model**: \`gpt-5.6-sol\`` |

`**reasoning**` の行 (概念設計 = `medium` / 詳細設計 = `high`) は**変更しない**
(裁定が動かすのはモデルの軸だけ。概念設計 判断 3)。

### 波及変更

- テストファイル: 層 1 (`gpt-5.6-terra` 2 件 / `gpt-5.6-sol` 2 件) と
  層 2 (`#conceptual-review` → terra / `#design-review` → sol)

### テスト計画

- [x] 層 2 が用途の取り違え (terra と sol の入れ替え) を赤にすることを負のコントロールで実測
- [x] L113 / L283 はそれぞれ `### 1-3.` / `### 2-3.` の節に属し、
      節内のモデル宣言・ラベル宣言はどちらも 1 個ずつであることを実読で確認済み

### リスク

- 概念設計レビューのモデルが変わることで、レビューの粒度・語彙が変わりうる。
  → 本設計自身が新しい割当 (terra/medium・sol/high) で実走しており、
  合議が成立することを実証している (下の受け入れ条件 A6)。

---

## 施策 5: `app-implement` の実装レビューの指定を差し替える

### 変更箇所

- ファイル: `.claude/skills/app-implement/SKILL.md` (L184、`### A-2. Codexによる実装レビュー` の節)

### 現行 → 変更後

```markdown
**model**: `gpt-5.5`
```
↓
```markdown
**model**: `gpt-5.6-sol`
```

### 波及変更 / テスト計画 / リスク

- テストファイル: 層 1 (`gpt-5.6-sol` 1 件) と層 2 (`#impl-review` → sol)
- リスク: 無し (記述 1 行)

---

## テストファースト計画 (どのテストを先に赤にするか)

| 段 | 操作 | 期待される赤 |
|---|---|---|
| 1 | 施策 1 だけを適用して `pnpm test tests/js/architecture/codex-model-consistency.test.ts` を走らせる | **層 1** が `.claude/skills/app-codex-vscode/SKILL.md` 2 件 / `app-codex-review` 1 件 / `app-design` 3 件 / `app-implement` 1 件 = **計 7 件**の許可外綴り (`gpt-5.5`) を並べて赤。**層 2** は 3 つの対がすべて期待と違う綴りなので赤。**層 3** は正本の表の綴りが 1 件で不一致 |
| 2 | 段 1 の失敗出力を `devnotes/20260817-1309-todo-t209-codex-model-canon-update/test-first-red.md` に貼る | — (記録) |
| 3 | 負のコントロール 8 分岐を書き、検出器が点灯することを確認する | 検体に対する各分岐が違反を返す (点灯しない分岐があれば検出器の欠陥) |
| 4 | 施策 2 → 3 → 4 → 5 を順に適用し、各段で残りの赤の件数が減っていくことを確認する | 施策 5 の適用で 0 件 |
| 5 | 全レーンを走らせる | 全 green |

「実装より先に赤を見る」ことを段 1 と段 3 の 2 つで満たす
(段 1 = 現物に対する赤、段 3 = 検出器そのものの生存)。

## 受け入れ条件 (機械検証可能な形で)

| # | 条件 | 検証方法 |
|---|---|---|
| A1 | `.claude/` と `scripts/` の git 追跡下に、正典 3 綴り以外のモデル名が 1 件も無い | `pnpm test tests/js/architecture/codex-model-consistency.test.ts` (層 1) |
| A2 | 綴りの出現回数が期待表と完全一致する (4 ファイル・合計 9 件 = 正本 3 + `app-codex-review` 1 + `app-design` 4 + `app-implement` 1、他のファイルは 0 件) | 同上 (層 1) |
| A3 | 用途割当が `conceptual-review → gpt-5.6-terra` / `design-review → gpt-5.6-sol` / `impl-review → gpt-5.6-sol` の 3 対ちょうどである | 同上 (層 2) |
| A4 | 正本の表が正典 3 綴りちょうどを持つ | 同上 (層 3) |
| A5 | 検出器が 8 分岐すべてで点灯する | 同上 (負のコントロール) |
| A6 | 差し替え先のモデルで Codex レビューが実際に走る | 実装レビューを `gpt-5.6-sol` / reasoning `high` で実走し、`codex-history/impl-review-*.md` を残す (本設計の概念設計レビューは `gpt-5.6-terra` / `medium` で実走済み = 実証済み) |
| A7 | `git grep` で `.claude/` と `scripts/` の旧綴りが 0 件である | `git grep -nEI 'gpt-[0-9]+(\.[0-9]+)?(-[a-z0-9]+)*' -- .claude scripts` の出力が正典 3 綴りだけになる |
| A8 | 全検証コマンドが green | 下節 |

## 全検証コマンド (すべて green であること)

```
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

PHP 側に変更は無いが、退行が無いことの確認として全数走らせる
(AGENTS.md の検証コマンド節が正本。マーカー区間ごと消さない)。

## 保証しないもの / やらないと決めたこと

| 項目 | 理由 |
|---|---|
| `docs/` `app/` `resources/` `tests/` のモデル名の統制 | 走査根は `.claude/` と `scripts/` だけである。`devnotes/` は履歴として意図的に外す |
| 「その綴りが今も提供されている」ことの保証 | 検査はリポジトリ内の文字だけを見る。提供元へ疎通しない。提供終了の追跡は人の仕事 |
| ラベルの語が用途を正しく表しているか | 層 2 が見るのは対応の一致だけである。語の意味は人のレビュー対象 |
| 動的に組み立てた綴り・リポジトリ外の手順 | 字句走査の外である |
| 推論の深さ (reasoning effort) の表の書き換え | 裁定が動かすのはモデルの軸だけ。aicue に実測が無い |
| 「指定してはいけない名前」の一覧を綴りで持つこと | 既定拒否の検査と両立させるには走査から外す区間の機構が要る。その形は台帳上まだ未決 |
| 使命・禁止事項の 3 小節構造の見直し | 本裁定の範囲外 |
| `docs/template-divergence.md` への登録 | 検査の形の正典が未決であり、登録すべき逸脱が定義されていない |
| 台帳への書き込み (`append_event`) | 設計フェーズの責務ではない。実装完了時に別途行う |
| 軽い判定 (`gpt-5.6-luna`) を使う呼び出しの新設 | 今必要ではない (思考原則 2)。正本には割当の規則としてだけ載せる |

## 確認事項 (コード変更を伴わない)

- 裁定 AG-193 (Codex の会話の握りは記録・成果物に当たらない) と、
  `app-codex-review` スキルの現行運用 (セッション JSONL を
  `${TMPDIR:-/tmp}/codex-review/` に置き、プロンプト・返答・対応マトリクスは
  `devnotes/` へコミットする) は**一致している**。変更は不要である。

## 実装モード

| 項目 | 内容 |
|---|---|
| 推奨モード | standalone |
| 判断根拠 | 施策 1 を先に入れると呼び出し側 4 ファイルが同時に赤になる。5 施策は 1 つのコミット単位で閉じる必要があり、他施策と並行させると赤の帰属が分からなくなる |
| 競合リスク | 他の作業が `.claude/skills/app-*` を触ると衝突する。TODO 実装は worktree で行うため、main 側の同時変更が無いことをマージ前に確認する |

## 関連する現行コード

### tests/js/architecture/codex-model-consistency.test.ts (現行 全文)
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

/** `.claude/skills/app-*\/SKILL.md` を実測で列挙する。 */
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
        expect(discovered.length).toBeGreaterThan(0);

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
        let scanned = 0;

        for (const rel of SKILL_INVENTORY) {
            const content = await fs.readFile(
                path.join(SKILLS_ROOT, rel),
                "utf8",
            );
            scanned += 1;
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

        // 走査 0 件を「合格」と誤読しないための drift ガード。
        expect(scanned).toBe(SKILL_INVENTORY.length);

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

### .claude/skills/app-codex-vscode/SKILL.md (現行 全文)
```markdown
---
name: app-codex-vscode
description: scripts/codex exec を使ったOpenAIモデル呼び出しの共通規約
user-invocable: false
---

# codex 呼び出し規約

OpenAI モデルへの問い合わせは `scripts/codex` 経由で実行する
(VSCode 拡張 `openai.chatgpt` のネイティブバイナリを動的検出して使用するラッパ)。

---

## 基本コマンド（One-shot）

```bash
scripts/codex exec --ephemeral --sandbox read-only -m {model} \
  -c 'model_reasoning_effort="{reasoning}"' \
  -o {出力ファイル} - < {プロンプトファイル}
```

**必須オプション**:
- `--ephemeral`: セッションファイルを永続化しない
- `--sandbox read-only`: コマンド実行・ファイル書き込みを禁止（ファイル読み込みは許可）
- `-m {model}`: モデルを指定
- `-c 'model_reasoning_effort="{reasoning}"'`: reasoning effortを指定（`~/.codex/config.toml` のグローバル設定を上書き）
- `-o {出力ファイル}`: 結果をファイルに保存
- `- < {プロンプトファイル}`: プロンプトをstdin経由で渡す

---

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

---

## プロンプトの渡し方

1. **Write ツール**でプロンプトファイルを作成（`{design_dir}/codex-history/{label}-prompt-round-{N}.md`）
2. **stdin経由**で `scripts/codex exec` に渡す（`- < {ファイルパス}`）
3. 結果は `-o` で指定したファイルに出力される
4. **シェル引数でプロンプトを渡してはならない**（エスケープ・長さ制限の問題を回避）
5. **プロンプト・返答・判断記録はリポジトリ外（`/tmp` 等）に書き出さない**。議論履歴として `devnotes/` にコミットするため（セッションJSONLのみ例外。`app-codex-review` 参照）

詳細は `app-codex-review` スキルの「議論履歴の保存方針」を参照。

---

## セッション管理（文脈保持が必要な場合）

複数ラウンドの会話で文脈を維持する場合は `app-codex-review` スキルのセッションモードを参照。

---

## エラーハンドリング

- `scripts/codex exec` が非ゼロ終了コードを返した場合、30秒待って1回リトライ
- 2回連続失敗時は呼び出し元の規定に従う
```

### .claude/skills/app-design/SKILL.md の該当箇所
```markdown

- **全ての成果物は `devnotes/{YYYYMMDD-HHMM}-{topic}/` に保存**する
- **Codexとの合議は「全CriticalとWarningが解消されるまで」繰り返す**（最大5ラウンド）
- 概念設計レビュー・詳細設計レビューとも **`gpt-5.5`** を使用（reasoning effort で使い分ける）

---

### 1-3. Codexによる概念設計レビュー

`app-codex-review` スキルの**セッションモード**に従い、プロンプトファイルを作成してCodexに概念設計のレビューを依頼する。

**model**: `gpt-5.5`
**reasoning**: `medium`
**label**: `conceptual-review`

**system**:
```
あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。


### 2-3. Codexによる詳細設計レビュー

`app-codex-review` スキルの**セッションモード**に従い、プロンプトファイルを作成してCodexにレビューを依頼する。

**model**: `gpt-5.5`
**reasoning**: `high`
**label**: `design-review`

**system**:
```
あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

（アプリの使命・禁止事項は app-codex-review スキルにより AGENTS.md から自動挿入済み）

```

### .claude/skills/app-implement/SKILL.md の該当箇所
```markdown
### A-2. Codexによる実装レビュー

**`--skip-consensus` 時は A-2/A-3 をスキップ**。テスト全通過 + PHPStan通過をもって実装完了とみなし、Phase B へ進む。

全施策の実装完了・テスト通過後、git diffでコード差分を取得し、Codexにレビューを依頼する。

**差分の取得**（worktree内で実行）:
```bash
cd {repo_root}/.claude/worktrees/tasks/{todo_id} && \
  git add -N app/ resources/ tests/ routes/ && \
  git diff HEAD --no-color -- app/ resources/ tests/ routes/
```

`app-codex-review` スキルの**セッションモード**に従い、プロンプトファイルを作成してCodexに依頼する。

**model**: `gpt-5.5`
**reasoning**: `high`
**label**: `impl-review`

- **system**: コードレビュアーとしてLaravel + Svelteの改善実装をレビュー。レビュー観点（設計との一致性、正確性、PHPStan適合性、DTO/JsonResourceパターン、テスト網羅性、セキュリティ、**DESIGN.md準拠**、**Atomic Design準拠**）、出力形式（ファイルごとに判定、Critical/Warning/Suggestion分類、全体判定APPROVED/CHANGES_REQUESTED）
  - **DESIGN.md準拠**: `/DESIGN.md` が design token の canonical source。color / radius / typography は token 経由で参照し hex 直書き（`#RRGGBB`）を増やさない。token 値を変更する diff は `resources/css/tokens.css` と同一 diff 内で同期しているか（運用契約は `docs/design-system.md`）
  - **Atomic Design準拠**: `resources/js/components/` は `atoms/molecules/organisms/templates` の責務分離に従う。atom は単機能・状態を持たない、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide を使い、SVG 直書きを増やさない
```

### 前例: git ls-files を使う既存 Architecture テスト
```ts
        expect(
            offenders.sort(), // 失敗メッセージを走査順の環境差で揺らさない
            `大文字 'Pages/' path 参照を検出。resolver 規約は小文字 './pages/' 固定 ` +
                `(resources/js/inertia.ts の import.meta.glob と一致させること): ${offenders.join(", ")}`,
        ).toEqual([]);
    });

    it("git tracked path に大文字 resources/js/Pages/ で始まるものが存在しない", () => {
        // architecture invariant: git 不在は環境不備。silent skip せず明瞭に fail させる。
        let tracked: string;
        try {
            tracked = execFileSync("git", ["ls-files", "resources/js/"], {
                cwd: REPO_ROOT,
                encoding: "utf8",
            });
        } catch (e) {
            throw new Error(
                `git ls-files の実行に失敗 (git worktree 前提の architecture invariant): ${String(e)}`,
            );
        }
        const offenders = tracked.split("\n").filter((p) => p.startsWith("resources/js/Pages/"));
        expect(
            offenders,
            `大文字 'resources/js/Pages/' で始まる tracked file を検出。case-insensitive FS の ` +
                `case-fold エイリアスを誤って git add したもの。小文字 'resources/js/pages/' に統一すること: ` +
                `${offenders.join(", ")}`,
        ).toEqual([]);
    });

    /*
     * 負のコントロール: 検出器が実際に点灯することを fixture 文字列で確認する
```
