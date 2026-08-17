# アプリの使命 (North Star)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

# 思考原則・ツール使用制限

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

# system

あなたは Laravel + Svelte アプリケーションのコードレビュアーである。以下の実装差分を、添付の詳細設計書と照らしてレビューせよ。

【レビュー観点】
1. 設計との一致性: 詳細設計の施策 1〜5 と受け入れ条件 A1〜A5 を満たしているか
2. 正確性: 検出器・判定器のロジックに fail-open (見落として緑になる) 経路が無いか
3. TypeScript の型安全性 (strict。any / 非 null 断言 / 握り潰しの catch が無いか)
4. テスト網羅性: 負の検体 23 件 (N-01〜N-23)・正の検体 4 件 (P-01〜P-04) が実ファイル検査と同じ判定器を通っているか
5. セキュリティ: 本変更は開発ツール (スキル文書 4 本と vitest の Architecture テスト 1 本) のみで、app/ resources/ routes/ database/ config/ に触れない。新たな攻撃面が生じていないか
6. 誇張の有無: 「保証しないもの」の記述が実装の実際の守備範囲と一致しているか

【本 diff に DESIGN.md / Atomic Design の観点は無い】resources/js / resources/css の変更は 1 件も無い。

【設計からの意図した逸脱 2 件】(妥当性を判定せよ)
- 逸脱 1: 候補抽出の正規表現を詳細設計の /gpt-[A-Za-z0-9._-]*/gi から /gpt-[0-9][A-Za-z0-9._-]*/gi へ変更した。理由: 走査根の scripts/codex が VSCode 拡張名 openai.chatgpt- を 4 箇所持ち、設計どおりの式では chatgpt- の中の gpt- が候補になり「受理できなかった候補はすべて違反」の規則で必ず赤になるため。前方境界の検査 (直前が識別子文字なら違反) は残しているので xgpt-5.6-sol は依然として違反である。
- 逸脱 2: .claude/skills/app-design/SKILL.md の外側コードフェンス 2 行を 3 バッククォートから 4 バッククォートへ変更した。理由: 中に ```php の入れ子があるため CommonMark でも本検査でも外側が途中で閉じたと読まれ、§2-3 のモデル宣言・ラベル宣言がフェンス内に落ちて層 2 が割当を検出できなかった。

【出力形式】
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] で分類し、Critical と Warning には必ず修正案を添える
- 最後に「## 全体判定」として APPROVED または CHANGES_REQUESTED を書く
- 日本語で出力する

---

# user

## 詳細設計書

# 詳細設計: Codex 正典モデルの現行世代への追従 (aicue:T209)

> ステータス: **APPROVED** (Codex `gpt-5.6-sol` / high、Round 4。Round 1 の Critical 1 件・Warning 9 件を含む全指摘に対応済み)。履歴は `codex-history/`

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
| 新規 (検証記録) | `devnotes/20260817-1309-todo-t209-codex-model-canon-update/test-first-red.md` | 段 0 (検出器の赤) と段 1 (現物の赤) の失敗出力 |
| 新規 (検証記録) | `devnotes/20260817-1309-todo-t209-codex-model-canon-update/codex-history/impl-review-prompt-round-{N}.md` ほか実装レビューの記録 | `app-implement` スキルの規定どおりに残す |
| 削除 | なし | — |

**触らないと決めたファイル** (概念設計の調査結果より):

| パス | 扱い | 理由 |
|---|---|---|
| `docs/TODO-closed.md` の 6 行 (10 箇所) | 履歴として残す | 完了済み作業の記録 (T100 のほか、当時どのモデルでレビューしたかの記録)。当時の事実であり書き換えは記録の改竄 |
| `devnotes/` 配下 843 箇所 | 履歴として残す | 過去のレビュー実績。現行の検査も同じ理由で走査対象外にしている |
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
- **走査根に `tests/` を含めない理由**: 本ファイルは負のコントロールの検体として
  旧世代の綴りや不正な綴り (`GPT-5.6-SOL` / `xgpt-5.6-sol` など) を**必ず持つ**。
  走査根に入れると検査が自分自身を違反として落とす。
  検体を持つ場所を走査の外に置くのは、家系の他リポジトリの検査と同じ扱いである
  (自分が検出したい語を負のコントロールの入力として持つファイルは走査から外す)。

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

/**
 * 候補の抽出は**貪欲**に行う。接尾辞・下線・記号の続きまで 1 つの候補に巻き込み、
 * 「正典の一部だけを取り出して合格にする」経路を作らない。
 * (現行の `/gpt-\d+(?:\.\d+)?(?:-[a-z0-9]+)*/gi` は境界を持たないため、
 *  `gpt-5.6-sol_preview` から正典部分だけを抜き出して数えてしまう)
 */
const MODEL_CANDIDATE_PATTERN = /gpt-[A-Za-z0-9._-]*/gi;

/** モデル名に使える文字。候補の直前がこれなら別語の一部である。 */
const IDENTIFIER_CHAR = /[A-Za-z0-9_.-]/;
```

**受理の規則 (2 段)**:

1. `MODEL_CANDIDATE_PATTERN` で候補を貪欲に取る。
2. 候補が正典 3 綴りと**大文字小文字も含めて完全一致**し、
   かつ**候補の直前の文字が `IDENTIFIER_CHAR` でない**ときだけ正典として受理する。
   受理できなかった候補は**すべて違反**である。

これにより次はすべて違反になる:
`xgpt-5.6-sol` (直前が識別子文字) / `gpt-5.6-sol_preview` (候補が長い) /
`gpt-5.6-sol-` (候補が長い) / `GPT-5.6-SOL` (大文字小文字が違う) / 旧世代の綴り。

#### 検出器と判定器 (どちらも純粋関数。実ファイル検査と負のコントロールが**同じ関数**を呼ぶ)

収集だけを純粋関数にすると、比較のロジックが `it` の中に残り、
**配線が壊れても検体側だけ緑**になりうる。そこで判定まで純粋関数にする。

| 種別 | 関数 | 入力 | 出力 |
|---|---|---|---|
| 収集 | `collectModelTokens(content)` | ファイル本文 | `readonly { token, line, accepted }[]` (層 1) |
| 収集 | `collectAssignments(content)` | ファイル本文 | `{ assignments, diagnostics }` (層 2) |
| 収集 | `collectCanonTableModels(content)` | 正本の本文 | `{ models, diagnostics }` (層 3) |
| 判定 | `validateOccurrences(files)` | `{ path, content }[]` | 違反の配列 (許可外の綴り + 出現回数の不一致) |
| 判定 | `validateAssignments(files)` | 同上 | 違反の配列 (読めない書き方 + 重複キー + 写像の不一致) |
| 判定 | `validateCanonTable(content)` | 正本の本文 | 違反の配列 |

- 判定器の戻り値は**違反の配列**で、空配列が合格である。
  比較は `expect(violations).toEqual([])` の形に統一する。
- 違反はパス → 行 → トークンの順で**並べ替えてから**返す
  (`git ls-files` の順序に失敗ログを依存させない)。
- `collectAssignments` の戻り値は**正常値と診断を同時に**返す:

```ts
interface Assignment { readonly label: string; readonly model: string; readonly line: number; }
interface ParseDiagnostic { readonly line: number; readonly reason: string; }
interface AssignmentCollection {
    readonly assignments: readonly Assignment[];
    readonly diagnostics: readonly ParseDiagnostic[];
}
```

  候補行 (`**model**:` / `**label**:` で始まる行) を先に見つけ、
  そのうえで**行全体をアンカーした厳密な構文**で値を取る。
  **候補行なのに構文が合わないものは必ず診断に入れる**
  (「宣言が無い」と同じ扱いにしない = 引用符を外して検査をすり抜ける経路を塞ぐ)。
- `collectAssignments` は**パスを知らない**ので `label` までを返し、
  複合キー `{パス}#{label}` は `validateAssignments` が組み立てる (責務を分ける)。
- 用途割当のキー `{パス}#{label}` が**重複したら違反**である
  (写像に畳むと最後の値で上書きされて重複を見逃すため)。
  比較は**件数・キーの多重集合・値**の 3 つで行う。

**節の切り方の共通規約** (層 2 と層 3 が同じ規則を使う):

- 見出しとして数えるのは **`^#{1,6}(?:[ \t]+|$)` に一致する行**である
  (**本検査が採用する行頭限定の見出し規則**。`#` の並びの後ろに空白か行末が要る。
  CommonMark は先行する空白を 3 文字まで許すが、本検査は行頭固定に限る =
  保証範囲はこの字句規則までである)。
  `#not-a-heading` のような行は見出しではない。
  層 3 は一致した `#` の数を**レベル**として読み、1 か 2 なら節を終える。
- **コードフェンス (``` または ~~~ で囲まれた区間) の中の行は、
  構造の解析 (層 2 と層 3) から丸ごと除外する** — 見出し・宣言・**表の行**の
  3 つすべてが対象である。スキル文書は書式の見本をフェンスで囲んで載せるため、
  見本を実体と取り違えない。
  ただし**層 1 の綴りの走査はフェンスの中も見る** (非対称は意図したもので、
  見本の中に綴りを隠して逃げる経路を作らない)。
  フェンスの開始と終了は**記号の種類を対応させる** (バッククォートで開いた区間は
  バッククォートで閉じる。チルダで閉じない)。
- **層 2 の節**: 見出し行から次の見出し行の直前までが 1 節 (レベルは問わない)。
  最初の見出しより前は「前文の節」として同じ規則で扱う。
- **層 3 の節**: `## 利用可能モデル` の見出し行から、
  **次のレベル 1 または 2 の見出し** (`# ` / `## ` で始まる行) の直前までが 1 節。
  この節の中の表だけを解析する (`###` 以下の小見出しは節を切らない)。

**層 2 の宣言の個数の規約**:

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
| 5 | 正本の表が 3 綴りちょうど (層 3) | `CANON_FILE` の中に `## 利用可能モデル` の見出しが**ちょうど 1 つ**あることを確かめ、**その節の中の表だけ**を解析する。取れた綴りが `CANONICAL_MODELS` と完全一致し、行数も 3 であること |
| 6 | 負のコントロール (判定器が実際に点灯する) | 下表の **負の検体 23 件**を `it.each` で**1 件ずつ独立に**実行する。**実ファイル検査と同じ判定器**を呼ぶ |
| 7 | 正のコントロール (誤って点灯しない) | **正の検体 4 件** (フェンスの中を実体として読まない / `###` で層 3 の節を終えない) を同じ判定器で実行し、違反 0 件を確かめる |

#### 負のコントロールの 23 検体

**1 検体 = 1 つの `it`** である (`it.each` で回す)。
1 つの `it` に複数の検体を並べると、最初の assert で止まって後続の検体が
実際に赤になったことを確認できないためである。

| ID | 検体 | 期待 (どの判定器が点灯するか) |
|---|---|---|
| N-01 | 旧世代の綴りを含む行 | 層 1 |
| N-02 | 接尾辞を落とした綴り (`gpt-5.6` 単体) | 層 1 |
| N-03 | 直前に文字が続く綴り (`xgpt-5.6-sol`) | 層 1 (前方の境界) |
| N-04 | 後ろに続く綴り (`gpt-5.6-sol_preview`) | 層 1 (貪欲な候補抽出) |
| N-05 | 末尾に区切りが残る綴り (`gpt-5.6-sol-`) | 層 1 |
| N-06 | 大文字の綴り (`GPT-5.6-SOL`) | 層 1 (大文字小文字を区別する) |
| N-07 | 期待より 1 件多い出現 | 層 1 (回数の比較) |
| N-08 | 期待より 1 件少ない出現 | 層 1 (回数の比較) |
| N-09 | **期待表に無いパス**に正典綴りが 1 件ある | 層 1 (表に無いファイルは 0 件が期待値) |
| N-10 | コードフェンスの中に許可外の綴りがある | 層 1 (フェンスの中も走査する) |
| N-11 | 節にモデル宣言があってラベル宣言が無い | 層 2 (読めない書き方) |
| N-12 | 節にラベル宣言があってモデル宣言が無い | 層 2 (読めない書き方) |
| N-13 | 同じ節にモデル宣言が 2 個 | 層 2 (読めない書き方) |
| N-14 | バッククォートで囲まれていない宣言 | 層 2 (候補行なのに構文不正) |
| N-15 | 用途と綴りの対応が期待と違う (議論向けとコード向けの入れ替え) | 層 2 (値の比較) |
| N-16 | 期待していた割当が消えた | 層 2 (件数・キーの比較) |
| N-17 | 未知の割当キーが増えた | 層 2 (キーの比較) |
| N-18 | 同じ複合キーが 2 回現れた | 層 2 (重複の検出) |
| N-19 | 正本の表が 2 行しかない | 層 3 (行数の一致) |
| N-20 | 正本の表が 4 行ある | 層 3 (行数の一致) |
| N-21 | `## 利用可能モデル` の見出しが 2 つある | 層 3 (節の固定) |
| N-22 | `## 利用可能モデル` の見出しが 1 つも無い | 層 3 (節の固定) |
| N-23 | 表が `## 利用可能モデル` の節の外 (別のレベル 2 の節) へ移動した | 層 3 (節の終端の規則) |

- 負の検体は **23 件**である (ID は N-01〜N-23)。実装時に検体を足したら、
  この表にも同じ ID で足す (検体の一覧の正本は**この表と実装の 2 つ**で、
  食い違ったらどちらが古いかを見る)。
- 走査 0 件の分岐は検査項目 1 が担う (検体ではなく実測で守る)。

#### 正のコントロールの 4 検体 (違反 0 件を期待する)

負の検体だけでは「フェンスの中を実体として読む誤実装」と
「`###` で層 3 の節を終える誤実装」が**現物でも検体でも緑になりうる**。
違反 0 件を期待する検体を別に置く。**1 検体 = 1 つの `it`** は同じである。

| ID | 検体 | 期待 |
|---|---|---|
| P-01 | コードフェンスの中にモデル宣言とラベル宣言がある | 層 2 の割当が増えない (違反 0 件) |
| P-02 | **正しい見出しと表を持つ合格の文書**へ、フェンスの中に `## 利用可能モデル` の見出しを足した | 層 3 の見出し数が増えない (違反 0 件) |
| P-03 | **正しい見出しと表を持つ合格の文書**へ、フェンスの中にモデルの表を足した | 層 3 がそれを正本の表として読まない (違反 0 件) |
| P-04 | `## 利用可能モデル` の直下に `### 補足` があり、その後に正しい 3 行の表がある | 層 3 が同じ節として解析する (違反 0 件) |

正の検体は「点灯しないこと」を見るため、段 0 のスタブ (常に空配列を返す) では
**緑になってしまう**。したがって正の検体は**判定関数を実装した後**に
「実装が正しいこと」を確かめる用途で使う (テストファーストの赤は負の検体が担う)。

### 型安全性チェック

- [x] 許可綴りは `as const` からユニオン型 `CanonicalModel` を導出し、期待表の値をその型に限る
- [x] 検出器の戻り値は `readonly` 配列で受ける
- [x] `git ls-files` の失敗・ファイル読込の失敗を `catch` して**再 throw** する (握り潰さない)
- [x] `String.prototype.match` に `/g` 付き正規表現を使う (毎回全件を返し `lastIndex` を持ち越さない)。
      直前の文字を見る必要があるため、候補の位置が要る箇所は `matchAll` を使い `index` を読む

### テスト計画

- [x] 本施策そのものがテストである
- [x] **段 0 (検出器のテストファースト)**: 判定関数を未実装のスタブにしたまま
      負のコントロール 23 検体 (N-01〜N-23) を先に書き、赤を実測する
- [x] **段 1 (現物のテストファースト)**: 施策 1 を単独で入れた時点で
      **呼び出し側 7 箇所が赤**になることを実測する
- [x] 段 0 / 段 1 の失敗出力を
      `devnotes/20260817-1309-todo-t209-codex-model-canon-update/test-first-red.md` に**分けて**記録する
- [x] 負の検体 23 件と正の検体 4 件は**実ファイル検査と同じ判定器**を呼び、1 検体 = 1 つの `it` として独立に走る
- [x] 正の検体 (P-01〜P-04) は判定関数の実装後に走らせる (スタブでは緑になるため、テストファーストの赤は負の検体が担う)
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
- 前の世代の名前・末尾に codex が付く名前は**新たに指定しない**。提供が終了しているものは
  呼び出しに失敗し、期限つきでまだ使えるものも移行の対象である。
  本書は**その綴りを持たない**（個別の区分は家系の機能台帳 `skill-codex-integration` の
  裁定 AG-186 が指す spirux 側の呼び出し規約スキルの表が正本である）。
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
- `-m {model}`: モデルを指定（本スキルが指定する既定値は `gpt-5.6-sol`。用途別の割当は `app-codex-vscode` が正本）
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
| 0 | 判定関数 (`validateOccurrences` / `validateAssignments` / `validateCanonTable`) を**未実装のスタブ** (常に空配列を返す) にしたまま、負のコントロール 23 検体を先に書いて走らせる | 23 検体が**すべて赤** (点灯すべき違反が返ってこない)。判定器そのものに対するテストファースト |
| 1 | 判定関数を実装し、施策 1 だけを適用して `pnpm test tests/js/architecture/codex-model-consistency.test.ts` を走らせる | **層 1** が `app-codex-vscode` 2 件 / `app-codex-review` 1 件 / `app-design` 3 件 / `app-implement` 1 件 = **計 7 件**の許可外綴り (旧世代) を並べて赤。**層 2** は 3 つの対がすべて期待と違う綴りなので赤。**層 3** は正本の表の綴りが 1 件で不一致。現物に対するテストファースト |
| 2 | 段 0 と段 1 の失敗出力を `devnotes/20260817-1309-todo-t209-codex-model-canon-update/test-first-red.md` に**分けて**貼る | — (記録) |
| 3 | 施策 2 → 3 → 4 → 5 を順に適用し、各段で残りの赤の件数が減っていくことを確認する | 施策 5 の適用で 0 件 |
| 4 | 全レーンを走らせる | 全 green |

「実装より先に赤を見る」を **2 つの粒度**で満たす
(段 0 = 判定器そのものの生存、段 1 = 現物に対する赤)。

## 受け入れ条件 (機械検証可能な形で)

| # | 条件 | 検証方法 |
|---|---|---|
| A1 | `.claude/` と `scripts/` の git 追跡下に、**`gpt-` で始まる形に一致するトークン**のうち正典 3 綴り以外のものが 1 件も無い (別の形の名前は検出の対象外。「保証しないもの」参照) | `pnpm test tests/js/architecture/codex-model-consistency.test.ts` (層 1) |
| A2 | 綴りの出現回数が期待表と完全一致する (4 ファイル・合計 9 件 = 正本 3 + `app-codex-review` 1 + `app-design` 4 + `app-implement` 1、他のファイルは 0 件) | 同上 (層 1) |
| A3 | 用途割当が `conceptual-review → gpt-5.6-terra` / `design-review → gpt-5.6-sol` / `impl-review → gpt-5.6-sol` の 3 対ちょうどである | 同上 (層 2) |
| A4 | 正本の表が正典 3 綴りちょうどを持つ | 同上 (層 3) |
| A5 | 判定器が**負の検体 23 件** (N-01〜N-23) すべてで点灯し、**正の検体 4 件** (P-01〜P-04) では 1 件も点灯しない (検体ごとに独立した `it` が立つ) | 同上 (判定器は実ファイル検査と同じ関数) |
| A6 | 全検証コマンドが green | 下節 |

### 機械では確かめない条件 (運用確認)

| # | 条件 | 確かめ方 | なぜ機械の受け入れ条件にしないか |
|---|---|---|---|
| B1 | 差し替え先のモデルで Codex レビューが実際に走る | 実装レビューを `gpt-5.6-sol` / reasoning `high` で実走し、記録を `codex-history/` に残す。本設計の概念設計レビューは `gpt-5.6-terra` / `medium`、詳細設計レビューは `gpt-5.6-sol` / `high` で**実走済み**であり、3 綴りのうち 2 つは既に実証されている | 外部サービスへの疎通を含むため、リポジトリ内の検査では成立を証明できない (記録ファイルが在ることは応答があったことの証明にならない) |
| B2 | 旧綴りが `.claude/` と `scripts/` に残っていないことの目視確認 | `git grep -nEI 'gpt-[A-Za-z0-9._-]*' -- .claude scripts` の出力が正典 3 綴りだけであること | 判定は層 1 に一本化する。この grep は**診断用**であり、出力を人が読む形なのでコマンド自体は失敗しない |

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
| **`gpt-` で始まらない形のモデル名** (別の提供元の名前・別の命名体系の名前) | 検出は `gpt-` で始まる候補だけを見る。別形式の名前が `.claude/` や `scripts/` に書かれても**沈黙する**。「モデル名は 3 つしか書かれていない」とは読めない |
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


## 実装差分 (git diff)

```diff
diff --git a/.claude/skills/app-codex-review/SKILL.md b/.claude/skills/app-codex-review/SKILL.md
index 6c32743..79833fb 100644
--- a/.claude/skills/app-codex-review/SKILL.md
+++ b/.claude/skills/app-codex-review/SKILL.md
@@ -97,7 +97,7 @@ ## One-shotモード
 
 - `--ephemeral`: セッションファイルを永続化しない
 - `--sandbox read-only`: コマンド実行・ファイル書き込みを禁止（読み込みは許可）
-- `-m {model}`: モデルを指定（`gpt-5.5`）
+- `-m {model}`: モデルを指定（本スキルが指定する既定値は `gpt-5.6-sol`。用途別の割当は `app-codex-vscode` が正本）
 - `-c 'model_reasoning_effort="{reasoning}"'`: reasoning effortを指定（モデル互換性のため常に明示指定。詳細は `app-codex-vscode` 参照）
 - `-o {出力ファイル}`: 結果をファイルに保存
 
diff --git a/.claude/skills/app-codex-vscode/SKILL.md b/.claude/skills/app-codex-vscode/SKILL.md
index f341e68..a463fdb 100644
--- a/.claude/skills/app-codex-vscode/SKILL.md
+++ b/.claude/skills/app-codex-vscode/SKILL.md
@@ -31,12 +31,22 @@ ## 基本コマンド（One-shot）
 
 ## 利用可能モデル
 
+モデル指定の**正本は本節**である。呼び出し側のスキルは下の割当に従って綴りを書く。
+
 | モデル | 用途 |
 |--------|------|
-| `gpt-5.5` | 唯一の指定モデル。コード分析・レビュー・技術設計・概念設計のすべて |
-
-用途別のモデル使い分けは行わない（`tests/js/architecture/codex-model-consistency.test.ts`
-が `gpt-5.5` 以外のモデル名を deny-by-default で検出する）。
+| `gpt-5.6-sol` | コードの分析・レビュー・技術設計（**既定**） |
+| `gpt-5.6-terra` | 議論・概念設計 |
+| `gpt-5.6-luna` | 軽い判定 |
+
+- **名前は接尾辞まで含めて 1 つ**である。接尾辞を落とした名前を書くと呼び出しが失敗する。
+- 前の世代の名前・末尾に codex が付く名前は**新たに指定しない**。提供が終了しているものは
+  呼び出しに失敗し、期限つきでまだ使えるものも移行の対象である。
+  本書は**その綴りを持たない**（個別の区分は家系の機能台帳 `skill-codex-integration` の
+  裁定 AG-186 が指す spirux 側の呼び出し規約スキルの表が正本である）。
+- 用途と綴りの対応、および綴りが本書と呼び出し側スキルの外へ漏れていないことは
+  `tests/js/architecture/codex-model-consistency.test.ts` が既定拒否で固定する
+  （許可表に無い綴りは 1 件でも赤になる）。
 
 ---
 
diff --git a/.claude/skills/app-design/SKILL.md b/.claude/skills/app-design/SKILL.md
index 09aed20..b5acefc 100644
--- a/.claude/skills/app-design/SKILL.md
+++ b/.claude/skills/app-design/SKILL.md
@@ -55,7 +55,7 @@ ## 重要原則
 
 - **全ての成果物は `devnotes/{YYYYMMDD-HHMM}-{topic}/` に保存**する
 - **Codexとの合議は「全CriticalとWarningが解消されるまで」繰り返す**（最大5ラウンド）
-- 概念設計レビュー・詳細設計レビューとも **`gpt-5.5`** を使用（reasoning effort で使い分ける）
+- 概念設計レビューは **`gpt-5.6-terra`**（議論・概念設計）、詳細設計レビューは **`gpt-5.6-sol`**（コードの分析・技術設計）を使用する（割当の正本は `app-codex-vscode`）
 
 ---
 
@@ -110,7 +110,7 @@ ### 1-3. Codexによる概念設計レビュー
 
 `app-codex-review` スキルの**セッションモード**に従い、プロンプトファイルを作成してCodexに概念設計のレビューを依頼する。
 
-**model**: `gpt-5.5`
+**model**: `gpt-5.6-terra`
 **reasoning**: `medium`
 **label**: `conceptual-review`
 
@@ -195,7 +195,7 @@ ### 2-2. 詳細設計書の作成
 
 **詳細設計書には必ず以下のセクションを含める**:
 
-```markdown
+````markdown
 # 詳細設計: {topic}
 
 ## 使命・制約（絶対遵守）
@@ -269,7 +269,7 @@ ## 実装モード
 | 推奨モード | incremental / standalone |
 | 判断根拠 | [なぜそのモードか] |
 | 競合リスク | [他施策との干渉可能性] |
-```
+````
 
 保存先:
 ```
@@ -280,7 +280,7 @@ ### 2-3. Codexによる詳細設計レビュー
 
 `app-codex-review` スキルの**セッションモード**に従い、プロンプトファイルを作成してCodexにレビューを依頼する。
 
-**model**: `gpt-5.5`
+**model**: `gpt-5.6-sol`
 **reasoning**: `high`
 **label**: `design-review`
 
diff --git a/.claude/skills/app-implement/SKILL.md b/.claude/skills/app-implement/SKILL.md
index 409e03a..a4f5d67 100644
--- a/.claude/skills/app-implement/SKILL.md
+++ b/.claude/skills/app-implement/SKILL.md
@@ -181,7 +181,7 @@ ### A-2. Codexによる実装レビュー
 
 `app-codex-review` スキルの**セッションモード**に従い、プロンプトファイルを作成してCodexに依頼する。
 
-**model**: `gpt-5.5`
+**model**: `gpt-5.6-sol`
 **reasoning**: `high`
 **label**: `impl-review`
 
diff --git a/tests/js/architecture/codex-model-consistency.test.ts b/tests/js/architecture/codex-model-consistency.test.ts
index cb4870b..88ade02 100644
--- a/tests/js/architecture/codex-model-consistency.test.ts
+++ b/tests/js/architecture/codex-model-consistency.test.ts
@@ -1,129 +1,1035 @@
 import { describe, it, expect } from "vitest";
-import fs from "fs/promises";
-import path from "path";
+import fs from "node:fs/promises";
+import path from "node:path";
+import { execFileSync } from "node:child_process";
+import { fileURLToPath } from "node:url";
 
 /**
- * Codex 呼び出しモデルの一本化を deny-by-default で固定する。
+ * Codex に指定するモデルの正典を deny-by-default で固定する。
  *
- * c2c 台帳 (skill-codex-integration t1 / skill-design-flow / skill-implement-flow) は
- * 「gpt-5.5 一本化」を正典としており、aicue はこれに追従した
- * (devnotes/20260805-0101-devtool-template-followup/)。
+ * 家系の機能台帳 lctl の機能 skill-codex-integration に対するオーナー裁定
+ * AG-186 (2026-08-15) が「現行世代の 3 綴り」を正典と定めた。本テストは
+ * 設計 devnotes/20260817-1309-todo-t209-codex-model-canon-update/ の 3 層構成で、
+ * 綴りが正典どおりであること・用途と綴りの対応が入れ替わっていないこと・
+ * 正本の表が痩せても増えてもいないことを固定する。
  *
- * 本テストが守るのは 2 つ:
- *   1. app-* スキルの SKILL.md に canonical (gpt-5.5) 以外のモデル名が現れないこと
- *   2. 走査対象そのものがドリフトしていないこと (inventory と実測の集合一致)
+ *   層 1: 走査根の全ファイルで、正典 3 綴り以外の出現を 0 件で固定する。
+ *         併せてファイルごとの出現回数も期待表と完全一致で突き合わせる。
+ *   層 2: モデル宣言とラベル宣言の対から (用途 → 綴り) の写像を作り、
+ *         期待写像と完全一致で突き合わせる (ファイル内の取り違えの検出)。
+ *   層 3: 正本の「利用可能モデル」表の綴りが正典ちょうどであることを固定する。
  *
- * devnotes/ は過去のレビュー実績の記録 (どのモデルが何を指摘したか) であり、
- * 書き換えは履歴の改竄にあたるため **走査対象に含めない**。
+ * **走査根は `.claude` と `scripts` の git 追跡下の全ファイル**である。
+ * `tests/` を含めないのは、本ファイルが負のコントロールの検体として旧世代の綴りや
+ * 不正な綴りを必ず持つためである (自分が検出したい語を入力として持つファイルは
+ * 走査から外す)。`devnotes/` と `docs/TODO-closed.md` は過去のレビュー実績の記録で
+ * あり、書き換えは履歴の改竄にあたるため意図的に走査の外にある。
+ *
+ * **保証しないもの**: 検査はリポジトリ内の文字だけを見る (提供元へ疎通しないので
+ * 「その綴りが今も使える」ことは保証しない)。検出は `gpt-` に数字が続く候補だけを
+ * 見るので、別の命名体系のモデル名には沈黙する。ラベルの語が用途を正しく表して
+ * いるかも見ない (人のレビュー対象)。
  */
 
-const SKILLS_ROOT = path.resolve(__dirname, "../../../.claude/skills");
+const HERE = path.dirname(fileURLToPath(import.meta.url));
+const REPO_ROOT = path.resolve(HERE, "../../../");
+
+/** 正典の綴り。裁定 AG-186 (家系の機能台帳 skill-codex-integration) が正本。 */
+const CANONICAL_MODELS = ["gpt-5.6-sol", "gpt-5.6-terra", "gpt-5.6-luna"] as const;
+type CanonicalModel = (typeof CANONICAL_MODELS)[number];
+
+/** 走査根 (repo root からの相対)。git 追跡下の全ファイルを見る。 */
+const SCAN_ROOTS = [".claude", "scripts"] as const;
 
-/** 唯一許可されるモデル。世代更新時はここだけを書き換える。 */
-const CANONICAL_MODEL = "gpt-5.5";
+/** モデル指定の正本 (唯一の「利用可能モデル」表を持つファイル)。 */
+const CANON_FILE = ".claude/skills/app-codex-vscode/SKILL.md";
+/** 正本の表を載せる節の見出し (レベル 2 ちょうど)。 */
+const CANON_HEADING = "利用可能モデル";
+
+const REVIEW_PATH = ".claude/skills/app-codex-review/SKILL.md";
+const DESIGN_PATH = ".claude/skills/app-design/SKILL.md";
+const IMPL_PATH = ".claude/skills/app-implement/SKILL.md";
 
 /**
- * 走査対象の明示 inventory (`.claude/skills` からの相対パス)。
+ * 綴りが現れてよいファイルと、その出現回数の期待値。
+ * ここに無いファイルは **0 件** が期待値である (既定拒否)。
  *
- * 現時点でモデル指定を持たないスキルも登録する = 将来そこにモデル記述が
- * 生えたときも自動で検査対象になる。スキルを増減したらここも更新する
- * (更新を忘れると下の集合一致検査が fail する)。
+ * 正本のスキル文書と同じ割当がここにも書かれるのは意図した形である。
+ * 意図 (この表) と実測 (ファイルの中身) を独立に突き合わせるので、片方だけ直すと
+ * 赤になる。自動抽出で一本化すると、正本が壊れたときに検査も一緒に壊れて
+ * 緑のまま通る側へ倒れる。**モデル世代を上げるときは正本と本表を同じ変更で直す。**
  */
-const SKILL_INVENTORY: readonly string[] = [
-    "app-autopilot/SKILL.md",
-    "app-bug-hunt/SKILL.md",
-    "app-codex-review/SKILL.md",
-    "app-codex-vscode/SKILL.md",
-    "app-design/SKILL.md",
-    "app-implement/SKILL.md",
-    "app-todo-add/SKILL.md",
-    "app-todo-close/SKILL.md",
-    "app-update-docs/SKILL.md",
-] as const;
+const EXPECTED_OCCURRENCES: Readonly<
+    Record<string, Readonly<Partial<Record<CanonicalModel, number>>>>
+> = {
+    [CANON_FILE]: {
+        "gpt-5.6-sol": 1,
+        "gpt-5.6-terra": 1,
+        "gpt-5.6-luna": 1,
+    },
+    [REVIEW_PATH]: { "gpt-5.6-sol": 1 },
+    [DESIGN_PATH]: { "gpt-5.6-terra": 2, "gpt-5.6-sol": 2 },
+    [IMPL_PATH]: { "gpt-5.6-sol": 1 },
+};
 
 /**
- * `gpt-4` / `gpt-5` / `gpt-5.3-codex` / `gpt-5.1-codex-max` などのモデル
- * トークンを拾う。`\.\d+` は数字を要求するので文末の句点を巻き込まない。
+ * 用途 (label) → 綴り の期待写像。キーは「相対パス#label」。
+ * 概念設計に議論向け・詳細設計と実装レビューにコード向けを充てる、が意図。
  */
-const MODEL_TOKEN_PATTERN = /gpt-\d+(?:\.\d+)?(?:-[a-z0-9]+)*/gi;
-
-/** `.claude/skills/app-*\/SKILL.md` を実測で列挙する。 */
-const discoverAppSkillFiles = async (): Promise<readonly string[]> => {
-    const entries = await fs.readdir(SKILLS_ROOT, { withFileTypes: true });
-    const found: string[] = [];
-    for (const entry of entries) {
-        if (!entry.isDirectory()) continue;
-        if (!entry.name.startsWith("app-")) continue;
-        const rel = `${entry.name}/SKILL.md`;
-        try {
-            await fs.access(path.join(SKILLS_ROOT, rel));
-        } catch {
+const EXPECTED_ASSIGNMENTS: Readonly<Record<string, CanonicalModel>> = {
+    [`${DESIGN_PATH}#conceptual-review`]: "gpt-5.6-terra",
+    [`${DESIGN_PATH}#design-review`]: "gpt-5.6-sol",
+    [`${IMPL_PATH}#impl-review`]: "gpt-5.6-sol",
+};
+
+/**
+ * 候補の抽出は **貪欲** に行う。接尾辞・下線・記号の続きまで 1 つの候補に巻き込み、
+ * 「正典の一部だけを取り出して合格にする」経路を作らない
+ * (境界を持たない書き方だと `gpt-5.6-sol_preview` から正典部分だけを抜き出して
+ *  数えてしまう)。
+ *
+ * `gpt-` の直後に **数字** を要求するのは、`openai.chatgpt-` のような別語の一部を
+ * 候補にしないためである (`scripts/codex` が VSCode 拡張名として実際に持つ)。
+ * 帰結として「`gpt-` に数字が続かない形のモデル名」には沈黙する。
+ */
+const MODEL_CANDIDATE_PATTERN = /gpt-[0-9][A-Za-z0-9._-]*/gi;
+
+/** モデル名に使える文字。候補の直前がこれなら別語の一部である。 */
+const IDENTIFIER_CHAR = /[A-Za-z0-9_.-]/;
+
+/**
+ * 本検査が採用する **行頭限定** の見出し規則。`#` の並びの後ろに空白か行末が要る。
+ * (CommonMark は先行する空白を 3 文字まで許すが、本検査は行頭固定に限る =
+ *  保証範囲はこの字句規則までである。`#not-a-heading` は見出しではない)
+ */
+const HEADING_PATTERN = /^(#{1,6})(?:[ \t]+|$)/;
+
+const MODEL_DECL_HEAD = /^[ \t]*\*\*model\*\*[ \t]*:/;
+const LABEL_DECL_HEAD = /^[ \t]*\*\*label\*\*[ \t]*:/;
+const MODEL_DECL_STRICT = /^[ \t]*\*\*model\*\*[ \t]*:[ \t]*`([^`]+)`[ \t]*$/;
+const LABEL_DECL_STRICT = /^[ \t]*\*\*label\*\*[ \t]*:[ \t]*`([^`]+)`[ \t]*$/;
+
+const FENCE_OPEN = /^[ \t]*(`{3,}|~{3,})/;
+const FENCE_CLOSE = /^[ \t]*(`{3,}|~{3,})[ \t]*$/;
+const TABLE_DELIMITER = /^\|(?:[ \t]*:?-+:?[ \t]*\|)+$/;
+const FIRST_CELL_MODEL = /^[ \t]*`([^`]+)`[ \t]*$/;
+
+interface ScannedFile {
+    readonly path: string;
+    readonly content: string;
+}
+
+interface Violation {
+    readonly path: string;
+    readonly line: number;
+    readonly message: string;
+}
+
+interface TokenHit {
+    readonly token: string;
+    readonly line: number;
+    readonly accepted: boolean;
+}
+
+interface Assignment {
+    readonly label: string;
+    readonly model: string;
+    readonly line: number;
+}
+
+interface ParseDiagnostic {
+    readonly line: number;
+    readonly reason: string;
+}
+
+interface AssignmentCollection {
+    readonly assignments: readonly Assignment[];
+    readonly diagnostics: readonly ParseDiagnostic[];
+}
+
+interface CanonTableCollection {
+    readonly models: readonly string[];
+    readonly diagnostics: readonly ParseDiagnostic[];
+}
+
+interface Heading {
+    readonly index: number;
+    readonly level: number;
+    readonly text: string;
+}
+
+/** 違反はパス → 行 → 内容の順に並べ替える (失敗ログを走査順に依存させない)。 */
+const formatViolations = (violations: readonly Violation[]): readonly string[] =>
+    [...violations]
+        .sort((a, b) => {
+            if (a.path !== b.path) return a.path < b.path ? -1 : 1;
+            if (a.line !== b.line) return a.line - b.line;
+            if (a.message === b.message) return 0;
+            return a.message < b.message ? -1 : 1;
+        })
+        .map((v) => `${v.path}:${String(v.line)}: ${v.message}`);
+
+/**
+ * コードフェンス (``` または ~~~ で囲まれた区間) に属する行に印を付ける。
+ * 開始と終了は記号の種類を対応させる (バッククォートで開いた区間はチルダで閉じない)。
+ * フェンスの区切り行そのものも「中」として扱う (見出し・宣言・表の行にはなり得ない)。
+ */
+const computeFenceMask = (lines: readonly string[]): readonly boolean[] => {
+    const mask: boolean[] = [];
+    let openMarker = "";
+    let openLength = 0;
+    for (const line of lines) {
+        if (openMarker === "") {
+            const opener = FENCE_OPEN.exec(line);
+            if (opener !== null) {
+                const run = opener[1] ?? "";
+                openMarker = run.charAt(0);
+                openLength = run.length;
+                mask.push(true);
+                continue;
+            }
+            mask.push(false);
             continue;
         }
-        found.push(rel);
+        mask.push(true);
+        const closer = FENCE_CLOSE.exec(line);
+        if (closer !== null) {
+            const run = closer[1] ?? "";
+            if (run.charAt(0) === openMarker && run.length >= openLength) {
+                openMarker = "";
+                openLength = 0;
+            }
+        }
     }
-    return found.sort();
+    return mask;
 };
 
-describe("codex model consistency", () => {
-    it("走査対象 SKILL.md の集合が inventory と一致する (drift ガード)", async () => {
-        const discovered = await discoverAppSkillFiles();
+/** フェンスの外にある見出し行だけを集める。 */
+const collectHeadings = (
+    lines: readonly string[],
+    mask: readonly boolean[],
+): readonly Heading[] => {
+    const found: Heading[] = [];
+    lines.forEach((line, index) => {
+        if (mask[index] === true) return;
+        const matched = HEADING_PATTERN.exec(line);
+        if (matched === null) return;
+        const hashes = matched[1] ?? "";
+        found.push({
+            index,
+            level: hashes.length,
+            text: line.slice(hashes.length).trim(),
+        });
+    });
+    return found;
+};
+
+const isCanonical = (token: string): boolean =>
+    (CANONICAL_MODELS as readonly string[]).includes(token);
+
+/**
+ * 層 1 の収集器。**フェンスの中も見る** (見本の中に綴りを隠して逃げる経路を作らない。
+ * 構造の解析だけがフェンスを避けるという非対称は意図したものである)。
+ */
+const collectModelTokens = (content: string): readonly TokenHit[] => {
+    const hits: TokenHit[] = [];
+    content.split(/\r?\n/).forEach((line, index) => {
+        for (const match of line.matchAll(MODEL_CANDIDATE_PATTERN)) {
+            const token = match[0];
+            const at = match.index ?? 0;
+            const before = at === 0 ? "" : line.charAt(at - 1);
+            hits.push({
+                token,
+                line: index + 1,
+                accepted: isCanonical(token) && !IDENTIFIER_CHAR.test(before),
+            });
+        }
+    });
+    return hits;
+};
+
+/**
+ * 層 2 の収集器。見出しで節に切り、節ごとにモデル宣言とラベル宣言の対を取る。
+ * **パスを知らないので label までを返す** (複合キーの組み立ては判定器の責務)。
+ *
+ * 1 つの節が持てる宣言はどちらも 0 個か 1 個である。それ以外 (片方だけ /
+ * 2 個以上 / バッククォートで囲まれていない) は **読めない書き方**として診断に入れる
+ * (「読めなかったから緑」を構造的に無くす)。
+ */
+const collectAssignments = (content: string): AssignmentCollection => {
+    const lines = content.split(/\r?\n/);
+    const mask = computeFenceMask(lines);
+    const headings = collectHeadings(lines, mask);
+
+    // 見出し行から次の見出し行の直前までが 1 節 (レベルは問わない)。
+    // 最初の見出しより前は「前文の節」として同じ規則で扱う。
+    const sections: { readonly start: number; readonly end: number }[] = [];
+    let cursor = 0;
+    for (const heading of headings) {
+        sections.push({ start: cursor, end: heading.index });
+        cursor = heading.index;
+    }
+    sections.push({ start: cursor, end: lines.length });
+
+    const assignments: Assignment[] = [];
+    const diagnostics: ParseDiagnostic[] = [];
+
+    for (const section of sections) {
+        const models: { value: string | null; line: number }[] = [];
+        const labels: { value: string | null; line: number }[] = [];
+        for (let i = section.start; i < section.end; i += 1) {
+            if (mask[i] === true) continue;
+            const line = lines[i] ?? "";
+            if (MODEL_DECL_HEAD.test(line)) {
+                const matched = MODEL_DECL_STRICT.exec(line);
+                models.push({
+                    value: matched === null ? null : (matched[1] ?? null),
+                    line: i + 1,
+                });
+            }
+            if (LABEL_DECL_HEAD.test(line)) {
+                const matched = LABEL_DECL_STRICT.exec(line);
+                labels.push({
+                    value: matched === null ? null : (matched[1] ?? null),
+                    line: i + 1,
+                });
+            }
+        }
+        if (models.length === 0 && labels.length === 0) continue;
+
+        const anchor = models.length > 0 ? models[0].line : labels[0].line;
+        if (models.length !== 1 || labels.length !== 1) {
+            diagnostics.push({
+                line: anchor,
+                reason:
+                    `節のモデル宣言とラベル宣言が対になっていません `
+                    + `(model 宣言 ${String(models.length)} 個 / label 宣言 ${String(labels.length)} 個。`
+                    + `対にできない書き方は読めない書き方として赤にする)`,
+            });
+            continue;
+        }
+
+        const model = models[0];
+        const label = labels[0];
+        if (model.value === null) {
+            diagnostics.push({
+                line: model.line,
+                reason: "model 宣言の書式が読めません (値はバッククォートで囲んだ 1 語で書く)",
+            });
+        }
+        if (label.value === null) {
+            diagnostics.push({
+                line: label.line,
+                reason: "label 宣言の書式が読めません (値はバッククォートで囲んだ 1 語で書く)",
+            });
+        }
+        if (model.value === null || label.value === null) continue;
+
+        assignments.push({ label: label.value, model: model.value, line: model.line });
+    }
+
+    return { assignments, diagnostics };
+};
+
+/**
+ * 層 3 の収集器。`## 利用可能モデル` の節の中の表だけを解析する。
+ * 節の終端は **次のレベル 1 または 2 の見出し**である (`###` 以下は節を切らない)。
+ */
+const collectCanonTableModels = (content: string): CanonTableCollection => {
+    const lines = content.split(/\r?\n/);
+    const mask = computeFenceMask(lines);
+    const headings = collectHeadings(lines, mask);
+    const targets = headings.filter(
+        (h) => h.level === 2 && h.text === CANON_HEADING,
+    );
+    if (targets.length !== 1) {
+        return {
+            models: [],
+            diagnostics: [
+                {
+                    line: 0,
+                    reason:
+                        `正本の見出し "## ${CANON_HEADING}" がちょうど 1 つではありません `
+                        + `(実測 ${String(targets.length)} 個)`,
+                },
+            ],
+        };
+    }
+
+    const head = targets[0];
+    const after = headings.filter((h) => h.index > head.index && h.level <= 2);
+    const end = after.length > 0 ? after[0].index : lines.length;
+
+    const rows: { readonly text: string; readonly line: number }[] = [];
+    for (let i = head.index + 1; i < end; i += 1) {
+        if (mask[i] === true) continue;
+        const text = (lines[i] ?? "").trim();
+        if (!text.startsWith("|")) continue;
+        rows.push({ text, line: i + 1 });
+    }
+
+    if (rows.length < 2 || !TABLE_DELIMITER.test(rows[1].text)) {
+        return {
+            models: [],
+            diagnostics: [
+                {
+                    line: head.index + 1,
+                    reason:
+                        `正本の節に表 (見出し行 + 区切り行 + データ行) が読めません `
+                        + `(表らしき行 ${String(rows.length)} 行)`,
+                },
+            ],
+        };
+    }
+
+    const models: string[] = [];
+    const diagnostics: ParseDiagnostic[] = [];
+    for (const row of rows.slice(2)) {
+        const cells = row.text.replace(/^\|/, "").split("|");
+        const matched = FIRST_CELL_MODEL.exec(cells[0] ?? "");
+        if (matched === null) {
+            diagnostics.push({
+                line: row.line,
+                reason: `表の 1 列目がバッククォートで囲んだ綴り 1 語ではありません: ${row.text}`,
+            });
+            continue;
+        }
+        models.push(matched[1] ?? "");
+    }
+    return { models, diagnostics };
+};
+
+/** 層 1 の判定器。許可外の綴りと、期待表との出現回数の食い違いを返す。 */
+const validateOccurrences = (
+    files: readonly ScannedFile[],
+    expected: Readonly<
+        Record<string, Readonly<Partial<Record<CanonicalModel, number>>>>
+    > = EXPECTED_OCCURRENCES,
+): readonly string[] => {
+    const violations: Violation[] = [];
+    for (const file of files) {
+        const counts = new Map<string, number>();
+        for (const hit of collectModelTokens(file.content)) {
+            if (!hit.accepted) {
+                violations.push({
+                    path: file.path,
+                    line: hit.line,
+                    message:
+                        `正典に無いモデル名 ${hit.token} が現れています `
+                        + `(許可: ${CANONICAL_MODELS.join(" / ")}。裁定 AG-186)`,
+                });
+                continue;
+            }
+            counts.set(hit.token, (counts.get(hit.token) ?? 0) + 1);
+        }
+        const table = expected[file.path] ?? {};
+        for (const model of CANONICAL_MODELS) {
+            const want = table[model] ?? 0;
+            const got = counts.get(model) ?? 0;
+            if (want === got) continue;
+            violations.push({
+                path: file.path,
+                line: 0,
+                message:
+                    `${model} の出現回数が期待表と違います `
+                    + `(期待 ${String(want)} 件 / 実測 ${String(got)} 件)`,
+            });
+        }
+    }
+    return formatViolations(violations);
+};
+
+/** 層 2 の判定器。読めない書き方・複合キーの重複・写像の食い違いを返す。 */
+const validateAssignments = (
+    files: readonly ScannedFile[],
+    expected: Readonly<Record<string, CanonicalModel>> = EXPECTED_ASSIGNMENTS,
+): readonly string[] => {
+    const violations: Violation[] = [];
+    const found = new Map<
+        string,
+        { readonly model: string; readonly path: string; readonly line: number }
+    >();
+
+    for (const file of files) {
+        const collected = collectAssignments(file.content);
+        for (const diagnostic of collected.diagnostics) {
+            violations.push({
+                path: file.path,
+                line: diagnostic.line,
+                message: diagnostic.reason,
+            });
+        }
+        for (const assignment of collected.assignments) {
+            const key = `${file.path}#${assignment.label}`;
+            const previous = found.get(key);
+            if (previous !== undefined) {
+                violations.push({
+                    path: file.path,
+                    line: assignment.line,
+                    message:
+                        `用途割当のキー ${key} が重複しています `
+                        + `(${String(previous.line)} 行目と同じキー)`,
+                });
+                continue;
+            }
+            found.set(key, {
+                model: assignment.model,
+                path: file.path,
+                line: assignment.line,
+            });
+        }
+    }
+
+    for (const [key, value] of found) {
+        const want = expected[key];
+        if (want === undefined) {
+            violations.push({
+                path: value.path,
+                line: value.line,
+                message: `期待表に無い用途割当 ${key} → ${value.model} があります`,
+            });
+            continue;
+        }
+        if (want === value.model) continue;
+        violations.push({
+            path: value.path,
+            line: value.line,
+            message: `用途割当が期待と違います ${key}: 期待 ${want} / 実測 ${value.model}`,
+        });
+    }
+
+    for (const key of Object.keys(expected)) {
+        if (found.has(key)) continue;
+        violations.push({
+            path: key.split("#")[0] ?? key,
+            line: 0,
+            message: `期待していた用途割当 ${key} が見つかりません`,
+        });
+    }
 
-        // 「検査件数 0 なら fail」はこの一致検査に含まれる (inventory は非空)。
-        expect(SKILL_INVENTORY.length).toBeGreaterThan(0);
-        expect(discovered.length).toBeGreaterThan(0);
+    return formatViolations(violations);
+};
+
+/** 層 3 の判定器。正本の表が正典 3 綴りちょうどであることを見る。 */
+const validateCanonTable = (content: string): readonly string[] => {
+    const collected = collectCanonTableModels(content);
+    const violations: Violation[] = collected.diagnostics.map((diagnostic) => ({
+        path: CANON_FILE,
+        line: diagnostic.line,
+        message: diagnostic.reason,
+    }));
 
-        const missing = SKILL_INVENTORY.filter((p) => !discovered.includes(p));
-        const unregistered = discovered.filter(
-            (p) => !SKILL_INVENTORY.includes(p),
+    if (collected.models.length !== CANONICAL_MODELS.length) {
+        violations.push({
+            path: CANON_FILE,
+            line: 0,
+            message:
+                `正本の表の行数が違います `
+                + `(期待 ${String(CANONICAL_MODELS.length)} 行 / 実測 ${String(collected.models.length)} 行)`,
+        });
+    }
+
+    const got = [...collected.models].sort().join(" / ");
+    const want = [...CANONICAL_MODELS].sort().join(" / ");
+    if (got !== want) {
+        violations.push({
+            path: CANON_FILE,
+            line: 0,
+            message: `正本の表の綴りが正典と一致しません (期待 ${want} / 実測 ${got})`,
+        });
+    }
+
+    return formatViolations(violations);
+};
+
+/**
+ * 走査対象を git 追跡下から列挙する。
+ * git 不在は環境不備であり、silent skip せず明瞭に fail させる。
+ */
+const listScanFiles = (): readonly string[] => {
+    let raw: string;
+    try {
+        raw = execFileSync("git", ["ls-files", "-z", "--", ...SCAN_ROOTS], {
+            cwd: REPO_ROOT,
+            encoding: "utf8",
+            maxBuffer: 64 * 1024 * 1024,
+        });
+    } catch (e) {
+        throw new Error(
+            `git ls-files の実行に失敗 (git worktree 前提の architecture invariant): ${String(e)}`,
         );
+    }
+    return raw.split("\0").filter((p) => p !== "");
+};
+
+const readScanFiles = async (): Promise<readonly ScannedFile[]> => {
+    const files: ScannedFile[] = [];
+    for (const rel of listScanFiles()) {
+        let content: string;
+        try {
+            content = await fs.readFile(path.join(REPO_ROOT, rel), "utf8");
+        } catch (e) {
+            throw new Error(`走査対象の読込に失敗しました (${rel}): ${String(e)}`);
+        }
+        files.push({ path: rel, content });
+    }
+    return files;
+};
+
+// ---------------------------------------------------------------------------
+// コントロール検体 (実ファイル検査と **同じ判定器** を呼ぶ)
+// ---------------------------------------------------------------------------
+
+const l1 = (content: string, filePath: string = REVIEW_PATH): readonly string[] =>
+    validateOccurrences([{ path: filePath, content }]);
+
+const l2 = (
+    content: string,
+    expected: Readonly<Record<string, CanonicalModel>>,
+    filePath: string = IMPL_PATH,
+): readonly string[] => validateAssignments([{ path: filePath, content }], expected);
+
+const IMPL_EXPECTED: Readonly<Record<string, CanonicalModel>> = {
+    [`${IMPL_PATH}#impl-review`]: "gpt-5.6-sol",
+};
+
+const DESIGN_EXPECTED: Readonly<Record<string, CanonicalModel>> = {
+    [`${DESIGN_PATH}#conceptual-review`]: "gpt-5.6-terra",
+    [`${DESIGN_PATH}#design-review`]: "gpt-5.6-sol",
+};
+
+const FENCE = "```";
+const ROW_SOL = "| `gpt-5.6-sol` | コードの分析・レビュー・技術設計 |";
+const ROW_TERRA = "| `gpt-5.6-terra` | 議論・概念設計 |";
+const ROW_LUNA = "| `gpt-5.6-luna` | 軽い判定 |";
+
+/** 正本の体裁を持つ文書を組み立てる (検体の土台)。 */
+const canonDoc = (rows: readonly string[], inSection: readonly string[] = []): string =>
+    [
+        "# 表題",
+        "",
+        `## ${CANON_HEADING}`,
+        "",
+        "| モデル | 用途 |",
+        "|--------|------|",
+        ...rows,
+        "",
+        ...inSection,
+        "## 次の節",
+        "",
+        "本文。",
+        "",
+    ].join("\n");
+
+interface Specimen {
+    readonly id: string;
+    readonly title: string;
+    readonly run: () => readonly string[];
+}
 
+/** 負のコントロール (判定器が実際に点灯することを 1 検体 1 テストで確かめる)。 */
+const NEGATIVE_SPECIMENS: readonly Specimen[] = [
+    {
+        id: "N-01",
+        title: "旧世代の綴りを含む行",
+        run: () => l1("既定は `gpt-5.6-sol`\n旧世代の `gpt-5.4-mini` は使わない\n"),
+    },
+    {
+        id: "N-02",
+        title: "接尾辞を落とした綴り",
+        run: () => l1("既定は `gpt-5.6-sol`\n接尾辞を落とした gpt-5.6 は書かない\n"),
+    },
+    {
+        id: "N-03",
+        title: "直前に文字が続く綴り",
+        run: () => l1("既定は `gpt-5.6-sol`\nxgpt-5.6-sol\n"),
+    },
+    {
+        id: "N-04",
+        title: "後ろに続く綴り",
+        run: () => l1("既定は `gpt-5.6-sol`\ngpt-5.6-sol_preview\n"),
+    },
+    {
+        id: "N-05",
+        title: "末尾に区切りが残る綴り",
+        run: () => l1("既定は `gpt-5.6-sol`\ngpt-5.6-sol-\n"),
+    },
+    {
+        id: "N-06",
+        title: "大文字の綴り",
+        run: () => l1("既定は `gpt-5.6-sol`\nGPT-5.6-SOL\n"),
+    },
+    {
+        id: "N-07",
+        title: "期待より 1 件多い出現",
+        run: () => l1("既定は `gpt-5.6-sol`\nもう一度 `gpt-5.6-sol`\n"),
+    },
+    {
+        id: "N-08",
+        title: "期待より 1 件少ない出現",
+        run: () => l1("モデル名を 1 つも書かない本文\n"),
+    },
+    {
+        id: "N-09",
+        title: "期待表に無いパスに正典綴りがある",
+        run: () => l1("`gpt-5.6-sol`\n", ".claude/skills/app-todo-add/SKILL.md"),
+    },
+    {
+        id: "N-10",
+        title: "コードフェンスの中に許可外の綴りがある",
+        run: () =>
+            l1(["既定は `gpt-5.6-sol`", "", FENCE, "gpt-5.4-mini", FENCE, ""].join("\n")),
+    },
+    {
+        id: "N-11",
+        title: "節にモデル宣言があってラベル宣言が無い",
+        run: () =>
+            l2(
+                ["### レビュー", "", "**model**: `gpt-5.6-sol`", ""].join("\n"),
+                IMPL_EXPECTED,
+            ),
+    },
+    {
+        id: "N-12",
+        title: "節にラベル宣言があってモデル宣言が無い",
+        run: () =>
+            l2(
+                ["### レビュー", "", "**label**: `impl-review`", ""].join("\n"),
+                IMPL_EXPECTED,
+            ),
+    },
+    {
+        id: "N-13",
+        title: "同じ節にモデル宣言が 2 個",
+        run: () =>
+            l2(
+                [
+                    "### レビュー",
+                    "",
+                    "**model**: `gpt-5.6-sol`",
+                    "**model**: `gpt-5.6-terra`",
+                    "**label**: `impl-review`",
+                    "",
+                ].join("\n"),
+                IMPL_EXPECTED,
+            ),
+    },
+    {
+        id: "N-14",
+        title: "バッククォートで囲まれていない宣言",
+        run: () =>
+            l2(
+                [
+                    "### レビュー",
+                    "",
+                    "**model**: gpt-5.6-sol",
+                    "**label**: `impl-review`",
+                    "",
+                ].join("\n"),
+                IMPL_EXPECTED,
+            ),
+    },
+    {
+        id: "N-15",
+        title: "用途と綴りの対応が期待と違う (入れ替え)",
+        run: () =>
+            l2(
+                [
+                    "### 1-3. 概念設計レビュー",
+                    "",
+                    "**model**: `gpt-5.6-sol`",
+                    "**label**: `conceptual-review`",
+                    "",
+                    "### 2-3. 詳細設計レビュー",
+                    "",
+                    "**model**: `gpt-5.6-terra`",
+                    "**label**: `design-review`",
+                    "",
+                ].join("\n"),
+                DESIGN_EXPECTED,
+                DESIGN_PATH,
+            ),
+    },
+    {
+        id: "N-16",
+        title: "期待していた割当が消えた",
+        run: () =>
+            l2(
+                [
+                    "### 1-3. 概念設計レビュー",
+                    "",
+                    "**model**: `gpt-5.6-terra`",
+                    "**label**: `conceptual-review`",
+                    "",
+                ].join("\n"),
+                DESIGN_EXPECTED,
+                DESIGN_PATH,
+            ),
+    },
+    {
+        id: "N-17",
+        title: "未知の割当キーが増えた",
+        run: () =>
+            l2(
+                [
+                    "### A-2. 実装レビュー",
+                    "",
+                    "**model**: `gpt-5.6-sol`",
+                    "**label**: `impl-review`",
+                    "",
+                    "### A-4. 追加のレビュー",
+                    "",
+                    "**model**: `gpt-5.6-sol`",
+                    "**label**: `extra-review`",
+                    "",
+                ].join("\n"),
+                IMPL_EXPECTED,
+            ),
+    },
+    {
+        id: "N-18",
+        title: "同じ複合キーが 2 回現れた",
+        run: () =>
+            l2(
+                [
+                    "### A-2. 実装レビュー",
+                    "",
+                    "**model**: `gpt-5.6-sol`",
+                    "**label**: `impl-review`",
+                    "",
+                    "### A-5. 再レビュー",
+                    "",
+                    "**model**: `gpt-5.6-sol`",
+                    "**label**: `impl-review`",
+                    "",
+                ].join("\n"),
+                IMPL_EXPECTED,
+            ),
+    },
+    {
+        id: "N-19",
+        title: "正本の表が 2 行しかない",
+        run: () => validateCanonTable(canonDoc([ROW_SOL, ROW_TERRA])),
+    },
+    {
+        id: "N-20",
+        title: "正本の表が 4 行ある",
+        run: () => validateCanonTable(canonDoc([ROW_SOL, ROW_TERRA, ROW_LUNA, ROW_SOL])),
+    },
+    {
+        id: "N-21",
+        title: "正本の見出しが 2 つある",
+        run: () =>
+            validateCanonTable(
+                [
+                    "# 表題",
+                    "",
+                    `## ${CANON_HEADING}`,
+                    "",
+                    "| モデル | 用途 |",
+                    "|--------|------|",
+                    ROW_SOL,
+                    ROW_TERRA,
+                    ROW_LUNA,
+                    "",
+                    `## ${CANON_HEADING}`,
+                    "",
+                    "本文。",
+                    "",
+                ].join("\n"),
+            ),
+    },
+    {
+        id: "N-22",
+        title: "正本の見出しが 1 つも無い",
+        run: () =>
+            validateCanonTable(
+                [
+                    "# 表題",
+                    "",
+                    "## モデルの話",
+                    "",
+                    "| モデル | 用途 |",
+                    "|--------|------|",
+                    ROW_SOL,
+                    ROW_TERRA,
+                    ROW_LUNA,
+                    "",
+                ].join("\n"),
+            ),
+    },
+    {
+        id: "N-23",
+        title: "表が正本の節の外へ移動した",
+        run: () =>
+            validateCanonTable(
+                [
+                    "# 表題",
+                    "",
+                    `## ${CANON_HEADING}`,
+                    "",
+                    "説明だけがある。",
+                    "",
+                    "## 別の節",
+                    "",
+                    "| モデル | 用途 |",
+                    "|--------|------|",
+                    ROW_SOL,
+                    ROW_TERRA,
+                    ROW_LUNA,
+                    "",
+                ].join("\n"),
+            ),
+    },
+];
+
+/**
+ * 正のコントロール (誤って点灯しないことを確かめる)。
+ * 負の検体だけでは「フェンスの中を実体として読む誤実装」と
+ * 「`###` で層 3 の節を終える誤実装」が現物でも検体でも緑になりうる。
+ */
+const POSITIVE_SPECIMENS: readonly Specimen[] = [
+    {
+        id: "P-01",
+        title: "コードフェンスの中のモデル宣言とラベル宣言は割当にしない",
+        run: () =>
+            l2(
+                [
+                    "### レビュー",
+                    "",
+                    `${FENCE}markdown`,
+                    "**model**: `gpt-5.6-sol`",
+                    "**label**: `impl-review`",
+                    FENCE,
+                    "",
+                ].join("\n"),
+                {},
+            ),
+    },
+    {
+        id: "P-02",
+        title: "合格の正本にフェンス内の偽の見出しを足しても見出し数が増えない",
+        run: () =>
+            validateCanonTable(
+                canonDoc(
+                    [ROW_SOL, ROW_TERRA, ROW_LUNA],
+                    [`${FENCE}markdown`, `## ${CANON_HEADING}`, FENCE, ""],
+                ),
+            ),
+    },
+    {
+        id: "P-03",
+        title: "合格の正本にフェンス内の偽の表を足しても正本の表として読まない",
+        run: () =>
+            validateCanonTable(
+                canonDoc(
+                    [ROW_SOL, ROW_TERRA, ROW_LUNA],
+                    [
+                        `${FENCE}markdown`,
+                        "| モデル | 用途 |",
+                        "|--------|------|",
+                        "| `gpt-5.4-mini` | 見本 |",
+                        FENCE,
+                        "",
+                    ],
+                ),
+            ),
+    },
+    {
+        id: "P-04",
+        title: "`###` の小見出しは層 3 の節を終えない",
+        run: () =>
+            validateCanonTable(
+                [
+                    "# 表題",
+                    "",
+                    `## ${CANON_HEADING}`,
+                    "",
+                    "### 補足",
+                    "",
+                    "| モデル | 用途 |",
+                    "|--------|------|",
+                    ROW_SOL,
+                    ROW_TERRA,
+                    ROW_LUNA,
+                    "",
+                    "## 次の節",
+                    "",
+                ].join("\n"),
+            ),
+    },
+];
+
+describe("codex model consistency", () => {
+    it("走査対象の列挙が成立する (git 追跡下・0 件は赤)", () => {
+        const files = listScanFiles();
+        expect(
+            files.length,
+            `走査対象が 0 件です。走査根 (${SCAN_ROOTS.join(" / ")}) の指定か `
+                + `git 追跡状態を確認してください (空振りを合格と読まない)。`,
+        ).toBeGreaterThan(0);
+    });
+
+    it("期待表のキーが全て実在する (移動・改名・削除で守備範囲が痩せない)", () => {
+        const files = new Set(listScanFiles());
+        const wanted = [
+            ...Object.keys(EXPECTED_OCCURRENCES),
+            ...Object.keys(EXPECTED_ASSIGNMENTS).map((k) => k.split("#")[0] ?? k),
+            CANON_FILE,
+        ];
+        const missing = [...new Set(wanted)].filter((p) => !files.has(p)).sort();
         expect(
             missing,
-            `inventory にあるのに実在しない SKILL.md があります (移動/改名/削除で\n`
-                + `モデル検査の守備範囲が痩せます)。意図した削除なら inventory からも\n`
-                + `外してください:\n  ${missing.join("\n  ")}`,
+            `期待表が指すファイルが走査対象に実在しません。移動・改名したなら `
+                + `期待表 (EXPECTED_OCCURRENCES / EXPECTED_ASSIGNMENTS / CANON_FILE) も `
+                + `同じ変更で直してください:\n  ${missing.join("\n  ")}`,
         ).toEqual([]);
+    });
 
+    it("層 1: 正典 3 綴り以外が現れず、出現回数が期待表と一致する", async () => {
+        const files = await readScanFiles();
+        expect(files.length).toBeGreaterThan(0);
         expect(
-            unregistered,
-            `inventory に無い app-* スキルがあります。モデル指定が野放しになるため\n`
-                + `SKILL_INVENTORY へ追加してください:\n  ${unregistered.join("\n  ")}`,
+            validateOccurrences(files),
+            `Codex に指定するモデルの正典は裁定 AG-186 の 3 綴り `
+                + `(${CANONICAL_MODELS.join(" / ")}) である。綴りを変えるときは `
+                + `正本 (${CANON_FILE}) と本テストの期待表を同じ変更で直すこと。`,
         ).toEqual([]);
     });
 
-    it(`SKILL.md に ${CANONICAL_MODEL} 以外のモデル名が現れない`, async () => {
-        const offenders: string[] = [];
-        let scanned = 0;
-
-        for (const rel of SKILL_INVENTORY) {
-            const content = await fs.readFile(
-                path.join(SKILLS_ROOT, rel),
-                "utf8",
-            );
-            scanned += 1;
-            const lines = content.split("\n");
-            lines.forEach((line, index) => {
-                const matches = line.match(MODEL_TOKEN_PATTERN);
-                if (matches === null) return;
-                for (const token of matches) {
-                    if (token.toLowerCase() === CANONICAL_MODEL) continue;
-                    offenders.push(
-                        `${rel}:${String(index + 1)}: ${token} — ${line.trim()}`,
-                    );
-                }
-            });
-        }
-
-        // 走査 0 件を「合格」と誤読しないための drift ガード。
-        expect(scanned).toBe(SKILL_INVENTORY.length);
+    it("層 2: 用途と綴りの対応が期待写像と完全一致する", async () => {
+        const files = await readScanFiles();
+        expect(
+            validateAssignments(files),
+            `用途 (label) と綴りの対応が期待と食い違っています。`
+                + `割当の正本は ${CANON_FILE} の「${CANON_HEADING}」節である。`,
+        ).toEqual([]);
+    });
 
+    it("層 3: 正本の表が正典 3 綴りちょうどを持つ", async () => {
+        const files = await readScanFiles();
+        const canon = files.find((f) => f.path === CANON_FILE);
+        expect(canon, `正本 ${CANON_FILE} が走査対象にありません`).toBeDefined();
         expect(
-            offenders,
-            `canonical (${CANONICAL_MODEL}) 以外のモデル名が残っています。\n`
-                + `用途別の使い分けは廃止済みです (概念設計 判断 2/5)。\n`
-                + `モデル世代を更新する場合は CANONICAL_MODEL を書き換えてください:\n`
-                + `  ${offenders.join("\n  ")}`,
+            validateCanonTable(canon?.content ?? ""),
+            `正本の「${CANON_HEADING}」表が正典と一致しません。`,
         ).toEqual([]);
     });
+
+    it.each(NEGATIVE_SPECIMENS)(
+        "負のコントロール $id: $title",
+        ({ run }: Specimen) => {
+            expect(
+                run(),
+                "判定器が点灯しませんでした (空振りを合格として扱わないための検体)",
+            ).not.toEqual([]);
+        },
+    );
+
+    it.each(POSITIVE_SPECIMENS)(
+        "正のコントロール $id: $title",
+        ({ run }: Specimen) => {
+            expect(run(), "点灯すべきでない検体で判定器が点灯しました").toEqual([]);
+        },
+    );
 });

```

## テスト結果

```
pnpm test tests/js/architecture/codex-model-consistency.test.ts

段 0 (判定器がスタブ): Tests 23 failed (23)  ← 負の検体が全件赤
段 1 (施策 1 のみ適用): Tests 3 failed | 29 passed (32)  ← 層 1 / 層 2 / 層 3 が現物に対して赤
段 3 (施策 2〜5 適用後): 全レーンの実行結果は本レビュー後に確定させる (単体では 32 件緑を確認済み)
```