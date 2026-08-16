# アプリの使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の階層の責務分離に沿った配置か。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【補足: レビュー対象の位置づけ】
- 本件はフロントエンド (Svelte 5 コンポーネント) 内部の正確性欠陥の修正であり、サーバ側 (PHP/DTO/JsonResource/route/DB/認可) の変更を伴わない。観点 3/5/6/9 は「該当なし」で構わないが、その判断自体が正しいか (本当にサーバ側の変更が要らないか) は検証してほしい。
- 前提タスク T185 (D&D 並べ替え) が並べ替え 2 経路を安定キー化済み。本タスクはその横展開である。T185 の実装レビューが「削除・追加も同じ不変条件で監査すべき」と別タスク化を求めた経緯がある。
- 特に厳しく見てほしい点: (a) 安定キーで再解決する形が本当に全ての取り違えを閉じているか、(b) undo/redo 履歴と dirty 判定の整合が崩れないか、(c) 回帰テスト 6 件が「実装の写し」ではなく欠陥を実際に検出する設計になっているか、(d) 遅延 queue に積まれる操作の棚卸しに漏れが無いか。
- リポジトリルートは /workspace。必要なら実ファイルを読んでよい。

---

## 詳細設計書

# 詳細設計: ime-deferred-structural-ops-stable-key (IME 変換中の構造操作を安定キーで解決する)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 (AGENTS.md 「禁止事項」より)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

**本設計との関係**: 2〜7 は PHP / LLM / HTTP 応答に関する規約で、本設計はサーバ側を一切変更しない
ため無関係である。1 (テスト必須) と 8 (disabled にしない) が直接効く。
8 については、本設計は既存ボタンの `disabled` 状態を**一切増やさない**
(削除・追加ボタンは常に押下可能で、対象が解決できない場合は押下を受けたうえで何もしない)。

### コーディングルール

- **PHPStan level 10** 必須 — **本設計に PHP 変更は無いため該当なし** (実装時は無変更の確認として `composer phpstan` を通す)
- **Pest** テストフレームワーク — **本設計に PHP テストは無い**。テストは Vitest (`pnpm test`)
- **RefreshDatabase** + `--parallel` — 該当なし (DB に触れない)
- **テストデータは必ず Factory で生成** — 該当なし。フロントテストは既存の `makeDocument()` /
  `makeDndDocument()` 相当のヘルパで document を組む (既存流儀に合わせる)
- **DTO + JsonResource** パターン — 該当なし (サーバ応答契約は不変)
- **アーリーリターン** 推奨 — 本設計の解決ロジックはすべて早期 return で書く
- **コードフォーマット**: `pnpm lint:fix` (Prettier + ESLint)
- フロント規約: Svelte 5 runes + DS token のみ / component 階層は単方向 import /
  アイコンは `@lucide/svelte` のみ — **本設計は新しい component / icon / token を増やさない**
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260816-1754-ime-deferred-structural-ops-stable-key/conceptual-design.md`
(Codex conceptual-review Round 1 で **APPROVED**。Warning 4 件は反映済み。
対応マトリクスは `codex-history/conceptual-review-decisions-round-1.md`)

## 不変条件 (本タスクが固定するもの)

> **遅延実行される構造操作は、削除・追加・並べ替えを問わず、対象を安定キー (`clientKey`) で
> 捕捉し、実行時に解決し直してから変異する。解決できなければ何もしない。**

T185 が並べ替え 2 経路で確立した形を、残り 3 経路へ横展開してこの不変条件を
`ScenarioEditor` 全体で成立させる。

### 遅延 queue に積まれる全操作の棚卸し (`runSettled` 呼び出し 8 件。実コード走査で確定)

| # | 操作 | closure が捕捉する値 (現行) | 判定 | 本タスクでの扱い |
|---|------|---------------------------|------|-----------------|
| 1 | `addStep()` (L216) | なし (末尾 push) | 安全 | 変更しない |
| 2 | `addPoint(stepIndex)` (L222) | 親手順の数値 index | **欠陥** | 施策 1: 親を `clientKey` で解決 |
| 3 | `removeStep(index)` (L228) | 手順の数値 index | **欠陥** | 施策 3: 手順を `clientKey` で解決 |
| 4 | `removePoint(stepIndex, pointIndex)` (L233) | 親 + 対象の数値 index | **欠陥** | 施策 2: 両方を `clientKey` で解決 |
| 5 | `moveStepTo(from, to)` (L252) | `from` は `clientKey` (T185) / `to` は位置 | 安全 | 変更しない |
| 6 | `movePointTo(stepIndex, from, to)` (L277) | 親と対象が `clientKey` (T185) / `to` は位置 | 安全 | 変更しない |
| 7 | `undo()` → `doUndo` (L515) | なし (undoStack 先頭) | 安全 | 変更しない |
| 8 | `redo()` → `doRedo` (L519) | なし (redoStack 先頭) | 安全 | 変更しない |

- **`to` (移動先位置) を安定キー化しないのは意図的**。「n 番目へ移動する」は位置そのものが意図。
- **`addStep()` が安全なのは「末尾へ追加」だから**。位置指定の追加を将来足すなら同じ監査が要る。

### queue の外にある同種の弱点

- **`confirmingStepIndex` (削除確認ダイアログ)**: 数値 index が「ボタン押下」と「確定」の間の
  非同期境界をまたぐ。開いている間に `compositionend` で遅延中の並べ替えが実行されると、
  確定時点で index は別の手順を指す。**施策 3 で `confirmingStepKey` へ替えて閉じる。**
- **`save()` は `runSettled` を通さない** (変換確定前の文字列で PUT しうる)。
  「対象の取り違え」ではなく「確定前テキストの送信」という別種の論点なので**本タスクでは扱わない**
  (棚卸しの結果として記録のみ)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `addPoint` を親手順の安定キーで解決する | `resources/js/components/features/manual/ScenarioEditor.svelte` | 高 |
| 2 | `removePoint` を親手順+急所の安定キーで解決する | 同上 | 高 |
| 3 | `removeStep` と削除確認ダイアログを安定キー化する | 同上 | 高 |
| 4 | 回帰テストの追加 (index ずれ後の削除/追加が正しい対象へ当たる) | `tests/js/components/features/manual/ScenarioEditor.test.ts` | 高 |
| 5 | 負のコントロールの実測と記録 | `devnotes/{dir}/negative-control.md` | 高 |

---

## 施策 1: `addPoint` を親手順の安定キーで解決する

### 変更箇所

- ファイル: `resources/js/components/features/manual/ScenarioEditor.svelte` (L222-226 = 関数本体、L1230 = 呼び出し側)

### 波及変更

- TypeScript 型定義: **なし** (`DraftStep.clientKey` / `DraftPoint.clientKey` は
  `resources/js/types/manual.ts` L177-184 に既存。新しい型は増やさない)
- API Resource/DTO: **なし** (PUT payload = `payloadSteps()` は不変。`clientKey` は送らない)
- Inertia Props: **なし** (`Props` interface 不変)
- テストファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts`
  (施策 4 で新規ケース追加。既存の「急所を追加できる (行内の急所を追加ボタン)」L205 は
  `data-testid` 経由の操作なので**変更不要**)
- markup: 呼び出し側 1 箇所 (`step-{stepIndex}-add-point` ボタンの `onclick`)。
  `data-testid` は変えない (既存テストを壊さない)

### 現行コード

```svelte
<script lang="ts">
    function addPoint(stepIndex: number): void {
        runSettled(() =>
            commitStructural(() => steps[stepIndex].points.push({ ...emptyRow("yori"), id: null })),
        );
    }
</script>

<!-- markup (L1226-1236) -->
<Button
    variant="ghost"
    size="sm"
    onclick={() => addPoint(stepIndex)}
    testId="step-{stepIndex}-add-point"
>
```

### 変更後コード

```svelte
<script lang="ts">
    /**
     * 急所の追加。
     * **親手順は数値 index ではなく安定キー (clientKey) で持ち回る**。`runSettled` は IME 変換中に
     * 実行を compositionend まで遅らせるため、実行時点では手順の並びが変わっていることがあり、
     * 数値 index を持ち回ると「押したのとは別の手順」に急所が生える (最悪、範囲外アクセスで
     * TypeError になり、後続の遅延操作ごと失われる)。T185 の moveStepTo / movePointTo と同じ形。
     * 実行時に親手順が消えていたら**何もしない** (「この手順に足す」という意図が無効になったため。
     * 別の手順や末尾へ足すと利用者が指示していない行が生まれる)。
     */
    function addPoint(stepKey: string): void {
        runSettled(() => {
            const at = steps.findIndex((step) => step.clientKey === stepKey); // 実行時点で解決し直す
            if (at < 0) return;
            const parent = steps[at];
            commitStructural(() => parent.points.push({ ...emptyRow("yori"), id: null }));
        });
    }
</script>

<!-- markup -->
<Button
    variant="ghost"
    size="sm"
    onclick={() => addPoint(step.clientKey)}
    testId="step-{stepIndex}-add-point"
>
```

**設計上の注意**:

- `emptyRow("yori")` は closure の**中**で呼ぶ。`nextClientKey()` の採番が実行時に起きるため、
  遅延しても採番が飛ばず、undo/redo の round-trip も現行と同じ。
  (呼び出し時に採番して closure が捕捉する形にすると、解決失敗で捨てられたときに
  採番だけ進む = 実害は無いが無駄な差が出る。現行同様 closure 内で作る)
- `parent` は `commitStructural` の**外**で解決する。`commitStructural` は
  `flushPendingEdit()` → `before` 採取 → `mutate()` の順で走るので、`mutate` の中で
  解決すると「解決前に before を採る」ことになり読み手が混乱する。
  `flushPendingEdit()` は `steps` の**配列構造を変えない** (履歴 push のみ) ので、
  外で解決した参照が `mutate` 時点で無効になることはない。
- `steps[at]` は `at >= 0` を確認した後にのみ触る (早期 return)。
  `noUncheckedIndexedAccess` は無効 (`tsconfig.json` は `strict` のみ) なので型は
  `DraftStep` になるが、読み手に安全性が見えるよう分岐の後に置く。
  これは `movePointTo` (L285-288) と同じ書き方である。

### PHPStan 適合チェック

- [x] **該当なし** (PHP の変更が無い)。TypeScript 側は `pnpm typecheck` で確認する:
  - `addPoint` の引数型が `string` になり、数値を渡す呼び出しが残っていればコンパイルエラー
  - `findIndex` の戻り値 `number` を `< 0` で分岐してから添字アクセス
  - 素の型アサーション (`as`) を追加しない

### テスト計画

施策 4 に集約 (下記)。本施策に対応するケース: 「変換中に並べ替え → 急所追加 を続けて要求すると、
急所は**押した手順**にぶら下がる」「親手順が消えていたら no-op で完走する」。

### リスク

- **呼び出し漏れ**: markup 側の `addPoint(stepIndex)` を替え忘れると型エラーになる
  (`number` → `string`)。`pnpm typecheck` が検出するので silent break にはならない。
- **後退リスクは低い**: 変換中でない通常操作では `findIndex` が押した手順を必ず返すため、
  観測できる挙動は現行と完全に同一。

---

## 施策 2: `removePoint` を親手順+急所の安定キーで解決する

### 変更箇所

- ファイル: `resources/js/components/features/manual/ScenarioEditor.svelte` (L233-235 = 関数本体、L1199 = 呼び出し側)

### 波及変更

- TypeScript 型定義 / API Resource / DTO / Inertia Props: **なし**
- テストファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts` (施策 4)。
  既存の「急所の削除はダイアログなしで行える」(L228) は testId 経由なので**変更不要**
- markup: 呼び出し側 1 箇所 (`point-{stepIndex}-{pointIndex}-remove` ボタンの `onclick`)

### 現行コード

```svelte
<script lang="ts">
    function removePoint(stepIndex: number, pointIndex: number): void {
        runSettled(() => commitStructural(() => steps[stepIndex].points.splice(pointIndex, 1)));
    }
</script>

<!-- markup (L1194-1203) -->
<Button
    variant="danger-ghost"
    size="sm"
    iconOnly
    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を削除`}
    onclick={() => removePoint(stepIndex, pointIndex)}
    testId="point-{stepIndex}-{pointIndex}-remove"
>
```

### 変更後コード

```svelte
<script lang="ts">
    /**
     * 急所の削除。
     * **親手順・対象の急所とも安定キー (clientKey) で持ち回る**。理由は addPoint と同じで、
     * IME 変換中に遅延されると実行時点では手順の並びも急所の並びも変わっていることがある。
     * 実行時に対象が見つからなければ**何もしない** (もう消えている = 意図はすでに満たされており、
     * 失敗として知らせる意味がない。履歴にも触れない)。
     */
    function removePoint(stepKey: string, pointKey: string): void {
        runSettled(() => {
            const stepAt = steps.findIndex((step) => step.clientKey === stepKey);
            if (stepAt < 0) return;
            const parent = steps[stepAt];
            const at = parent.points.findIndex((point) => point.clientKey === pointKey);
            if (at < 0) return;
            commitStructural(() => parent.points.splice(at, 1));
        });
    }
</script>

<!-- markup -->
<Button
    variant="danger-ghost"
    size="sm"
    iconOnly
    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を削除`}
    onclick={() => removePoint(step.clientKey, point.clientKey)}
    testId="point-{stepIndex}-{pointIndex}-remove"
>
```

**設計上の注意**:

- 親 → 子の順に解決する。急所の `clientKey` は**手順をまたいで一意**
  (`nextClientKey()` はコンポーネントインスタンス内の単調増加カウンタ、L63-67) なので、
  理屈の上では全手順を横断して探しても同じ結果になる。しかし
  「急所は手順をまたがない」という既存のドメイン制約 (L237 のコメント、`movePointTo` の実装) を
  コードに残すため、**親を先に解決してからその配下だけを探す**形にする。
  こうすると「親が消えているのに子だけ見つかる」という状態が構造的に起きない。
- `at` は `splice` の直前に解決済みの値をそのまま使う。`commitStructural` は
  `flushPendingEdit()` (履歴 push のみ) しか挟まないので、`at` が無効化されることはない。

### PHPStan 適合チェック

- [x] 該当なし (PHP 変更無し)。TypeScript は `pnpm typecheck`:
  - 引数 2 つとも `string`。markup 側で `number` を渡していればコンパイルエラー
  - `findIndex` 2 回とも `< 0` 早期 return の後に添字アクセス

### テスト計画

施策 4 に集約。対応ケース: 「変換中に手順の並べ替え → 急所の削除 を続けて要求すると、
**掴んだ手順の急所**が消える」。

### リスク

- **後退リスクは低い**。通常操作では両 `findIndex` が押した行を必ず返す。
- 親を先に解決する分だけ探索が 2 段になるが、シナリオの規模 (手順数十・急所数個) では
  計測に出ない。既存の `movePointTo` と同じ計算量である。

---

## 施策 3: `removeStep` と削除確認ダイアログを安定キー化する

### 変更箇所

- ファイル: `resources/js/components/features/manual/ScenarioEditor.svelte`
  - L182 `confirmingStepIndex` の宣言
  - L228-231 `removeStep` の本体
  - L588 キーボードショートカット `$effect` 内のガード条件
  - L1124 削除ボタンの `onclick`
  - L1323-1332 `ConfirmDialog` の `open` / `onConfirm` / `onCancel`

### 波及変更

- TypeScript 型定義 / API Resource / DTO / Inertia Props: **なし**
- `ConfirmDialog` の props 契約 (`ConfirmDialogProps`): **変更なし**。
  `open: boolean` へ渡す式が `confirmingStepIndex !== null` から
  `confirmingStepKey !== null` に変わるだけで、`boolean` のまま
- テストファイル: 既存の「手順の削除は確認ダイアログを経由し、配下の急所ごと消える」(L213) と
  「手順削除 (確認ダイアログ) → Undo で配下急所ごと復活」(L958) は
  `data-testid` (`step-0-remove` / `scenario-step-remove-dialog`) 経由なので**変更不要**。
  施策 4 で新規ケースを足す
- markup: 削除ボタン 1 箇所 + ダイアログ 1 箇所

### 現行コード

```svelte
<script lang="ts">
    let confirmingStepIndex = $state<number | null>(null);

    function removeStep(index: number): void {
        runSettled(() => commitStructural(() => steps.splice(index, 1)));
        confirmingStepIndex = null; // 確認ダイアログを閉じるのは即時 (履歴とは独立)
    }

    // キーボードショートカット $effect 内 (L588)
    if (saving || confirmingStepIndex !== null || confirmingReload) return;
</script>

<!-- 削除ボタン (L1119-1128) -->
<Button
    variant="danger-ghost"
    size="sm"
    iconOnly
    ariaLabel={`手順 ${stepIndex + 1} を削除`}
    onclick={() => (confirmingStepIndex = stepIndex)}
    testId="step-{stepIndex}-remove"
>

<!-- ダイアログ (L1323-1332) -->
<ConfirmDialog
    open={confirmingStepIndex !== null}
    title="手順を削除しますか?"
    message="この手順を削除すると、配下の急所と登録済みのテイク (撮影動画) も一緒に削除されます。この操作は「シナリオを更新」で保存すると元に戻せません。"
    confirmLabel="削除する"
    confirmVariant="danger"
    onConfirm={() => confirmingStepIndex !== null && removeStep(confirmingStepIndex)}
    onCancel={() => (confirmingStepIndex = null)}
    testId="scenario-step-remove-dialog"
/>
```

### 変更後コード

```svelte
<script lang="ts">
    /**
     * 削除確認ダイアログが対象にしている手順の**安定キー** (null = 閉じている)。
     * 数値 index を持つと、ダイアログを開いている間に compositionend で遅延中の並べ替えが
     * 実行されたとき、確定した時点で index が別の手順を指す。
     */
    let confirmingStepKey = $state<string | null>(null);

    /**
     * 手順の削除。
     * **対象は数値 index ではなく安定キー (clientKey) で持ち回る** (理由は addPoint と同じ)。
     * 実行時に対象が見つからなければ**何もしない** (もう消えている = 意図はすでに満たされている)。
     * ダイアログを閉じるのは解決の成否によらず即時に行う (履歴とは独立、という現行の扱いを維持)。
     */
    function removeStep(stepKey: string): void {
        runSettled(() => {
            const at = steps.findIndex((step) => step.clientKey === stepKey); // 実行時点で解決し直す
            if (at < 0) return;
            commitStructural(() => steps.splice(at, 1));
        });
        confirmingStepKey = null; // 確認ダイアログを閉じるのは即時 (履歴とは独立)
    }

    // キーボードショートカット $effect 内
    if (saving || confirmingStepKey !== null || confirmingReload) return;
</script>

<!-- 削除ボタン -->
<Button
    variant="danger-ghost"
    size="sm"
    iconOnly
    ariaLabel={`手順 ${stepIndex + 1} を削除`}
    onclick={() => (confirmingStepKey = step.clientKey)}
    testId="step-{stepIndex}-remove"
>

<!-- ダイアログ -->
<ConfirmDialog
    open={confirmingStepKey !== null}
    title="手順を削除しますか?"
    message="この手順を削除すると、配下の急所と登録済みのテイク (撮影動画) も一緒に削除されます。この操作は「シナリオを更新」で保存すると元に戻せません。"
    confirmLabel="削除する"
    confirmVariant="danger"
    onConfirm={() => confirmingStepKey !== null && removeStep(confirmingStepKey)}
    onCancel={() => (confirmingStepKey = null)}
    testId="scenario-step-remove-dialog"
/>
```

**設計上の注意**:

- **ダイアログの表示内容は対象に依存しない**。`title` / `message` は固定文言で、手順番号も
  scene も描画していない (現行コードで確認済み)。したがって key 化しても表示側に index 依存は
  残らず、`steps` から対象を引き直す derived を作る必要が無い
  (conceptual-review R1 Warning への回答)。
- **対象が解決できなくてもダイアログは閉じる**。`confirmingStepKey = null` は `runSettled` の
  **外**にあるため、遅延されても即時に実行される (現行と同じ)。利用者から見える結果は
  「消したかった行はもう無く、ダイアログも閉じている」で不整合が無い。
  閉じる処理を `compositionend` の drain 側へ移す案は、遅延実行の仕組みへ新しい結合を作るので
  採らない (本タスクの制約: 遅延実行の仕組みを作り替えない)。
- **`onConfirm` の `confirmingStepKey !== null &&` は残す**。TypeScript の narrowing のために
  必要 (`string | null` を `string` 引数へ渡せない)。現行と同じ形。
- **`confirmingStepIndex` という名前は残さない**。AGENTS.md 思考原則 3
  「後方互換の並走を残さない」に従い、同じ変更で旧名を消す。
- `splice(at, 1)` の `at` は `commitStructural` の外で解決済み。`flushPendingEdit()` は
  配列構造を変えないので無効化されない (施策 1 と同じ根拠)。

### PHPStan 適合チェック

- [x] 該当なし (PHP 変更無し)。TypeScript は `pnpm typecheck`:
  - `confirmingStepKey: string | null` の narrowing が `onConfirm` で成立している
  - `removeStep` の引数が `string`。旧呼び出し (`number`) が残ればコンパイルエラー
  - `open` へ渡す式が `boolean` を保つ

### テスト計画

施策 4 に集約。対応ケース: 「変換中に並べ替え → 手順削除 を続けて要求すると、
**確定した手順**が消える」「ダイアログを開いている間に遅延中の並べ替えが確定しても、
**開いたときの手順**が消える」「対象が既に消えていたらダイアログは閉じ、何も起きない」。

### リスク

- **キーボードショートカットのガード条件の替え忘れ**: L588 の `confirmingStepIndex !== null` を
  替え忘れると未定義変数でコンパイルエラーになる (旧名を消すため silent break にならない)。
- **`step.clientKey` が markup で参照できること**は `{#each steps as step, stepIndex (step.clientKey)}`
  (L1079) から自明。
- **後退リスク**: ダイアログの開閉タイミング・文言・testId はいずれも不変なので、
  既存の削除テスト 2 件はそのまま緑であるべき。緑でなければ本設計の想定違いなので調査する。

---

## 施策 4: 回帰テストの追加

### 変更箇所

- ファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts`
  (既存 1533 行。`describe("ドラッグ&ドロップ並べ替え (T185)")` の末尾に隣接して
  新しい `describe` を足す。既存ケースは 1 件も削除・書き換えしない)

### 波及変更

- 既存テストの変更: **なし**。すべて `data-testid` 経由で操作しており、
  本設計は testId を 1 つも変えない
- 新規ヘルパ: 既存の `makeDndDocument()` (2 手順 × 2 急所) と `dragHandle()` /
  `stubRowRects()` を再利用する。**新しいモックや新しい描画ヘルパを作らない**

### テスト設計の方針

T185 のテスト「IME 変換中に手順の並べ替えと急所の並べ替えを続けて確定しても、掴んだ手順の
急所が動く」(L1457-1478) が手本。**先行する構造操作で index がずれた後に、遅延実行された
削除/追加が正しい対象へ適用される**ことを直接検証する。

前提の作り方は既存と同じ:
`fireEvent.compositionStart(...)` で composing に入れ → 遅延させたい操作を順に発火 →
`fireEvent.compositionEnd(...)` で drain → 結果を検証する。

`makeDndDocument()` は手順 2 件 (A / B) × 急所 2 件なので、
「A を末尾へ移動 → B を対象に操作」という形で index を確実にずらせる
(移動後は B が index 0、A が index 1 になる)。

### 新規テストケース (6 件)

```ts
describe("IME 変換中の構造操作は安定キーで対象を解決する", () => {
    // stubRowRects() / dragHandle() / makeDndDocument() は T185 の describe から共有する
    // (実装時は共通ヘルパを module scope へ持ち上げるか、describe を統合する)

    it("変換中に「並べ替え → 手順削除」を続けて確定すると、確定した手順が消える", async () => {
        // A を末尾へドラッグ (遅延) → B の削除を確認ダイアログで確定 (遅延)
        // → compositionEnd → 残るのは A のみ (数値 index なら A が消える)
    });

    it("変換中に「並べ替え → 急所追加」を続けて確定すると、押した手順に急所が付く", async () => {
        // A を末尾へドラッグ (遅延) → B の「急所を追加」(遅延)
        // → compositionEnd → B の急所が 3 件、A は 2 件のまま
    });

    it("変換中に「並べ替え → 急所削除」を続けて確定すると、掴んだ手順の急所が消える", async () => {
        // A を末尾へドラッグ (遅延) → A の急所 1 の削除 (遅延)
        // → compositionEnd → A の急所は「急所A-2」のみ、B の急所は 2 件のまま
    });

    it("遅延中に対象手順が消えていたら、急所追加は何も起こさず後続の遅延操作も走る", async () => {
        // A の削除を確定 (遅延) → A の「急所を追加」(遅延) → B の「急所を追加」(遅延)
        // → compositionEnd → 例外を投げずに完走し、B の急所が 3 件になる
        // (現行実装は steps[1] が undefined で TypeError → 3 つ目の操作が失われる)
    });

    it("削除ダイアログを開いている間に遅延中の並べ替えが確定しても、開いたときの手順が消える", async () => {
        // 変換中に A を末尾へドラッグ (遅延) → B の削除ボタンでダイアログを開く
        // → compositionEnd (並べ替えだけ実行され B が index 0 になる)
        // → ダイアログの「削除する」を押す → 消えるのは B
    });

    it("対象が既に消えている削除は、ダイアログを閉じたうえで何も起こさない (履歴も汚さない)", async () => {
        // 変換中に A の削除を確定 (遅延) → もう一度 A の削除を確定 (遅延)
        // → compositionEnd → A は 1 度だけ消え、Undo 1 回で A が戻る (空エントリが積まれない)
    });
});
```

各ケースの検証手段 (既存ヘルパと同じ流儀):

- 手順の並び: `stepScenes()` (既存。`step-{i}-scene` の value 配列)
- 急所の並び / 件数: `screen.getByTestId("point-{i}-{j}-scene")` の value と
  `screen.queryByTestId(...)` の不在
- 履歴: `undoBtn()` の `disabled` 状態と、`click` 後の DOM 復元
- 例外で drain が止まっていないこと: 3 つ目の操作の結果が DOM に出ていること
  (`fireEvent.compositionEnd` が reject しないことも同時に担保される)

### PHPStan 適合チェック

- [x] 該当なし。TypeScript は `pnpm typecheck` (テストも `tsconfig.json` の `include` 対象)
- [x] **素の型アサーション (`as`) を新規に増やさない**。DOM 検証は
  `toHaveValue` / `toBeInTheDocument` / `not.toBeInTheDocument` で書く
  (T185 impl-review Round 1 で指摘された「DOM assertion の型アサーション」を再発させない)

### テスト計画 (上位)

- [x] バグ修正なので**再現テストを先に書く** (施策 4 → 施策 1-3 の順で実装し、
  先に赤を確認してから直す。AGENTS.md 思考原則 5「テストファースト」)
- [x] 既存テスト `tests/js/components/features/manual/ScenarioEditor.test.ts` は
  既存 79 ケースすべてが**無変更で緑**であること
- [x] 新規テスト 6 件 (上記)
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 — **該当なし** (JS テスト)
- 検証コマンド: `pnpm test` / `pnpm typecheck` / `pnpm lint` / `pnpm build`。
  PHP 側は無変更の確認として `composer test` / `composer phpstan` も通す

### リスク

- **テストが実装を写しただけになる危険**。これを潰すのが施策 5 (負のコントロール) である。
- `compositionStart` / `compositionEnd` の発火要素: 既存テストは `section` に委譲した
  ハンドラを使うため、`screen.getByTestId("step-0-scene")` に対して発火してもバブリングで届く
  (T185 のテストが実証済み)。同じ形にする。

---

## 施策 5: 負のコントロールの実測と記録

### 変更箇所

- ファイル: `devnotes/20260816-1754-ime-deferred-structural-ops-stable-key/negative-control.md` (新規)

### 手順

1. 施策 1-3 を実装し、施策 4 のテストが**緑**であることを確認する
2. 安定キー解決を**外す** (3 経路を数値 index を捕捉する現行実装へ一時的に戻す。
   markup 側の引数も戻す)
3. `pnpm test tests/js/components/features/manual/ScenarioEditor.test.ts` を実行し、
   **どのケースがどう落ちるか** (失敗メッセージ・期待値と実測値・TypeError の有無) を記録する
4. 安定キー解決を戻し、再度**緑**であることを確認する
5. 3 の出力 (抜粋) と、どのケースが安定キー解決の欠如を検出したかの対応表を
   `negative-control.md` に残す

### 記録に必ず含めるもの

- 実行コマンドと実行日時
- 落ちたケース名の一覧 (**新規 6 件のうち何件が落ちたか**。落ちないケースがあれば、
  そのケースは安定キー解決を検出していない = テストとして弱いので設計を見直す)
- `addPoint` の範囲外アクセスが `TypeError` として観測されたことの証跡
  (「静かな取り違え」と「操作の消失」の 2 種類の壊れ方を実測で区別する)
- 既存 79 ケースが負のコントロール中も緑のままであること
  (= 新規ケースだけが欠陥を検出しており、既存テストは元々この欠陥に沈黙していた事実)

### リスク

- **負のコントロールの戻し忘れ**。手順 4 の再確認を必ず行い、
  最終的なコミット差分に一時的な巻き戻しが残っていないことを `git diff` で確認する
  (この確認は実装エージェントの責務)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更は `ScenarioEditor.svelte` 1 ファイル + そのテスト 1 ファイルに閉じており、他タスクと共有する層 (DTO / route / DS token / 共通 lib) に一切触れない。一方で **T182〜T186 と同じファイル群を触る後続タスクが並行しうる**ため、独立ブランチで完結させて衝突面を最小化する。施策 1-5 は同一ファイル内の相互依存 (共通の不変条件・共通のテスト) が強く、分割すると中間状態で不変条件が半分しか成立しない期間ができるので、1 タスクとして一括で入れる。 |
| 競合リスク | `ScenarioEditor.svelte` を触る他タスクがあれば行単位の衝突が起きる。現時点で main にマージ済みの T182-T186 以降、同ファイルへの未マージ変更は把握していない。実装前に main の最新を取り込むこと。 |

## 全体のリスクと後退可能性

| リスク | 影響 | 緩和 |
|--------|------|------|
| 通常操作 (非 IME) の挙動が変わる | 高 | 解決に成功した場合の変異は現行と同一。既存 79 ケースが無変更で緑であることを完了条件にする |
| undo/redo 履歴の整合が崩れる | 高 | すべての変異を `commitStructural` へ合流 (T185 の設計を踏襲)。解決失敗時は `commitStructural` を呼ばないので空エントリが積まれない。施策 4 の 6 件目がこれを直接検証する |
| dirty 判定が壊れる | 中 | `serializeSteps` / `snapshot` / `payloadSteps` を変更しない。`clientKey` は元から `serializeSteps` に含まれ `payloadSteps` に含まれない (この非対称を維持) |
| PUT payload に `clientKey` が混入する | 高 (サーバ保護キー) | `payloadSteps()` に手を触れない。既存テスト「PUT payload に clientKey を含めない」(L915) が守る |
| テストが実装の写しになる | 中 | 施策 5 の負のコントロールで検出力を実測する |
| 解決失敗時の no-op が利用者に「無反応」と映る | 低 | 発生条件は「変換中に、同じ対象を消す操作と別の操作を続けて要求した」ときのみ。その時点で画面上その行は既に消えており、観測される最終状態は利用者の意図と一致する。告知を足すと成功時に告知しない現行 UI と粒度が不揃いになるため足さない |

## 完了条件

- [ ] 施策 1-3 の実装 (`ScenarioEditor.svelte` から数値 index を捕捉する遅延 closure が 0 件になる)
- [ ] 施策 4 の新規テスト 6 件が緑
- [ ] 既存テスト (JS 全レーン / PHP 全レーン) が緑
- [ ] `pnpm typecheck` / `pnpm lint` / `pnpm build` が緑
- [ ] 施策 5 の `negative-control.md` が実測ログ付きで存在し、新規 6 件すべてが
      安定キー解決の欠如を検出したことが示されている
- [ ] `confirmingStepIndex` という識別子がコードベースに残っていない (旧実装を並走させない)


---

## 関連する現行コード

### `resources/js/components/features/manual/ScenarioEditor.svelte` (抜粋)

#### 状態宣言と構造操作 (L129-320)

```svelte
  129      // 作業コピーは scenario prop から「マウント時に一度だけ」seed する (意図的)。
  130      // prop 追随で編集中の内容を握り潰さないため。サーバ最新への置換は
  131      // applySaved (保存成功) / reloadScenario (409 からの明示同意リロード) が reseed で行う。
  132      // svelte-ignore state_referenced_locally
  133      let version = $state(scenario.scenario_version);
  134      // clientKey 採番は 1 回だけ (2 回呼ぶと steps と snapshot で異なるキーが振られ初期 dirty になる)。
  135      // svelte-ignore state_referenced_locally
  136      const initialSteps = toDraftSteps(scenario.steps);
  137      // svelte-ignore state_referenced_locally
  138      let steps = $state<DraftStep[]>(initialSteps);
  139      /** 保存済みスナップショット (正規形の JSON 文字列。$state proxy と参照を共有しない) */
  140      // svelte-ignore state_referenced_locally
  141      let snapshot = $state(serializeSteps(initialSteps));
  142      let saving = $state(false);
  143      // 直近の保存成功をその場に残す (toast の 4s 自動消去に依存しない永続確認)。
  144      // true にするのは applySaved() のみ。reseed()・save 開始・失敗・dirty 転換で false。
  145      let justSaved = $state(false);
  146      let errors = $state<Record<string, string[]>>({});
  147  
  148      // --- undo/redo 履歴 (保存前のローカル編集のみ対象。サーバ状態 version/snapshot は不変) ---
  149      let undoStack = $state<string[]>([]);
  150      let redoStack = $state<string[]>([]);
  151      /** 編集フィールド focus 時の「変更前」状態 (未確定の pending 編集の基準)。canUndo が参照するため $state */
  152      let editBaseline = $state<string | null>(null);
  153      // IME/保留は event handler 内でのみ同期参照するため非 reactive local で足りる
  154      let composing = false;
  155      let flushDeferred = false;
  156      /** composing 中に要求された構造操作/undo/redo を compositionend 後に FIFO 実行する */
  157      let pendingActions: Array<() => void> = [];
  158  
  159      /**
  160       * 保存失敗フィードバックの判別可能 union。
  161       * - conflict: 409 (scenario_conflict 契約。理由はサーバ供給 message)
  162       * - forbidden: 403 (セッション途中の権限剥奪等。将来の再ログイン導線はこの分岐に足す)
  163       * - generic: 通信断・5xx・shape 不一致などその他の失敗
  164       */
  165      type SaveFailure =
  166          | { kind: "conflict"; body: ScenarioConflictBody }
  167          | { kind: "forbidden" }
  168          | { kind: "generic"; message: string };
  169  
  170      /** アラート描画用の表示モデル (kind → 見た目の導出を switch 1 箇所に集約) */
  171      interface FailureView {
  172          type: "warning" | "danger";
  173          title?: string;
  174          message: string;
  175          showReloadCta: boolean;
  176          testId: string;
  177      }
  178  
  179      let saveFailure = $state<SaveFailure | null>(null);
  180      /** 失敗アラートの focus 対象 wrapper (tabindex=-1) */
  181      let failureEl = $state<HTMLDivElement | null>(null);
  182      let confirmingStepIndex = $state<number | null>(null);
  183      let confirmingReload = $state(false);
  184      /** 明示同意済みの最新取得中フラグ (dirty 離脱確認を二重に出さない) */
  185      let reloading = false;
  186  
  187      const dirty = $derived(serializeSteps(steps) !== snapshot);
  188  
  189      const canUndo = $derived(
  190          undoStack.length > 0 ||
  191              (editBaseline !== null && editBaseline !== serializeSteps(steps)),
  192      );
  193      const canRedo = $derived(redoStack.length > 0);
  194  
  195      // 編集で dirty に転じたら成功確認を消す (level-triggered)。dirty は derived で決定的なため
  196      // applySaved 直後は dirty=false のままで justSaved=true が保たれる。
  197      $effect(() => {
  198          if (dirty) justSaved = false;
  199      });
  200  
  201      /** 新規行の空値 (scene のみ必須のため空で作る)。clientKey は安定 key 用に採番する */
  202      function emptyRow(shotType: "hiki" | "yori"): Omit<DraftPoint, "id"> {
  203          return {
  204              clientKey: nextClientKey(),
  205              scene: "",
  206              shot_type: shotType,
  207              shooting_point: null,
  208              narration: "",
  209              subtitle_primary: null,
  210              subtitle_secondary: "",
  211              material_type: null,
  212              static_display_seconds: null,
  213          };
  214      }
  215  
  216      function addStep(): void {
  217          runSettled(() =>
  218              commitStructural(() => steps.push({ ...emptyRow("hiki"), id: null, points: [] })),
  219          );
  220      }
  221  
  222      function addPoint(stepIndex: number): void {
  223          runSettled(() =>
  224              commitStructural(() => steps[stepIndex].points.push({ ...emptyRow("yori"), id: null })),
  225          );
  226      }
  227  
  228      function removeStep(index: number): void {
  229          runSettled(() => commitStructural(() => steps.splice(index, 1)));
  230          confirmingStepIndex = null; // 確認ダイアログを閉じるのは即時 (履歴とは独立)
  231      }
  232  
  233      function removePoint(stepIndex: number, pointIndex: number): void {
  234          runSettled(() => commitStructural(() => steps[stepIndex].points.splice(pointIndex, 1)));
  235      }
  236  
  237      // --- 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) ---
  238  
  239      /** 並べ替え結果のスクリーンリーダ告知 (視覚的には出さない) */
  240      let reorderStatus = $state("");
  241      function announce(message: string): void {
  242          reorderStatus = message;
  243      }
  244  
  245      /**
  246       * 並べ替えは「任意位置への移動」1 本に集約する。
  247       * ▲▼ ボタン・ハンドルのキーボード操作・D&D のすべてがここへ合流するので、
  248       * undo/redo 履歴・dirty 判定・IME ゲート (runSettled) との整合が 1 箇所で保たれる。
  249       * 保存 payload は配列順がそのまま順序 (sort_order はサーバ採番) なので、
  250       * ここで順序表現を作る必要はない。
  251       */
  252      function moveStepTo(from: number, to: number): void {
  253          const target = steps[from];
  254          if (target === undefined || from === to || to < 0 || to >= steps.length) return;
  255          // 掴んだ行を**安定キーで覚える**。runSettled は IME 変換中なら実行を compositionend まで
  256          // 遅らせるので、その間に先行する構造操作が実行されると数値 index は別の行を指す。
  257          const key = target.clientKey;
  258          // 告知は runSettled の**中**に置く。実行時の再検査で no-op になることもあるため、
  259          // 外に置くと「移動していないのに移動しましたと読み上げる」ことになる (design-review R2)。
  260          runSettled(() => {
  261              const at = steps.findIndex((step) => step.clientKey === key); // 実行時点で解決し直す
  262              if (at < 0 || at === to || to >= steps.length) return;
  263              commitStructural(() => {
  264                  steps = moveItem(steps, at, to);
  265              });
  266              announce(`手順 ${at + 1} を ${to + 1} 番目に移動しました`);
  267          });
  268      }
  269  
  270      /**
  271       * 急所の移動。
  272       * **対象は数値 index ではなく安定キー (clientKey) で持ち回る**。`runSettled` は IME 変換中に
  273       * 実行を compositionend まで遅らせるため、実行時点では手順の並びが変わっていることがあり、
  274       * 数値 index を持ち回ると「掴んだのとは別の手順の急所」を並べ替えてしまう
  275       * (impl-review R1 Critical)。呼び出し時点と実行時点の両方で検査する。
  276       */
  277      function movePointTo(stepIndex: number, from: number, to: number): void {
  278          const step = steps[stepIndex];
  279          if (step === undefined) return;
  280          const point = step.points[from];
  281          if (point === undefined || from === to || to < 0 || to >= step.points.length) return;
  282          const stepKey = step.clientKey;
  283          const pointKey = point.clientKey;
  284          runSettled(() => {
  285              const stepAt = steps.findIndex((row) => row.clientKey === stepKey);
  286              if (stepAt < 0) return;
  287              const current = steps[stepAt];
  288              const at = current.points.findIndex((row) => row.clientKey === pointKey);
  289              if (at < 0 || at === to || to >= current.points.length) return;
  290              commitStructural(() => {
  291                  current.points = moveItem(current.points, at, to);
  292              });
  293              announce(`急所 ${stepAt + 1}-${at + 1} を ${to + 1} 番目に移動しました`);
  294          });
  295      }
  296  
  297      /** ▲▼ (既存 UI。挙動は現行と同じ = 1 段移動 + 端は無変更) */
  298      function moveStep(index: number, delta: -1 | 1): void {
  299          const next = index + delta;
  300          if (next < 0 || next >= steps.length) {
  301              // disabled にはしない (禁止事項 8)。押されたら「なぜ動かないか」を告知する
  302              announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
  303              return;
  304          }
  305          moveStepTo(index, next);
  306      }
  307  
  308      function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
  309          const step = steps[stepIndex];
  310          if (step === undefined) return;
  311          const next = index + delta;
  312          if (next < 0 || next >= step.points.length) {
  313              announce(delta < 0 ? "これ以上、上へは移動できません" : "これ以上、下へは移動できません");
  314              return;
  315          }
  316          movePointTo(stepIndex, index, next);
  317      }
  318  
  319      /** ドラッグ表示状態 (手順リスト / 急所リストで別々に持つ) */
  320      let stepDrag = $state<PointerDragState>({ activeIndex: null, insertionIndex: null });
```

#### 履歴コア・IME ゲート・composition ハンドラ (L443-600)

```svelte
  443      // --- 履歴コア (保存前ローカル編集のみ対象。undo/redo は steps を再代入し安定 clientKey で差分描画) ---
  444  
  445      /** 保存/リロード時に履歴を断つ (保存前ローカル編集のみ対象。R1 決定) */
  446      function resetHistory(): void {
  447          undoStack = [];
  448          redoStack = [];
  449          editBaseline = null;
  450          flushDeferred = false;
  451          pendingActions = [];
  452      }
  453  
  454      /** editBaseline を(変化があれば)確定して 1 エントリに積む。IME-aware・冪等 */
  455      function flushPendingEdit(): void {
  456          if (composing) {
  457              flushDeferred = true; // 変換確定後に compositionend で flush
  458              return;
  459          }
  460          if (editBaseline === null) return;
  461          const before = editBaseline;
  462          editBaseline = null; // 冪等化 (直後の focusout で再 push しない)
  463          if (pushHistory(undoStack, before, serializeSteps(steps))) {
  464              redoStack = []; // 新規編集で redo クリア
  465          }
  466      }
  467  
  468      /** 構造操作/undo/redo の IME ゲート。composing 中は compositionend まで保留 */
  469      function runSettled(action: () => void): void {
  470          if (composing) {
  471              pendingActions.push(action); // FIFO: 発行順に compositionend で実行 (R4 policy)
  472              return;
  473          }
  474          action();
  475      }
  476  
  477      /** 構造操作の共通コミット: pending 編集確定 → 変更前を控え → 変異 → 変化があれば push */
  478      function commitStructural(mutate: () => void): void {
  479          flushPendingEdit();
  480          const before = serializeSteps(steps);
  481          mutate();
  482          if (pushHistory(undoStack, before, serializeSteps(steps))) {
  483              redoStack = [];
  484          }
  485      }
  486  
  487      /**
  488       * 履歴文字列を検証(util)→ rowOf 正規化で新規 DraftStep[] を作り steps に反映。
  489       * 壊れていれば false(steps を変えない fail-safe)。素の型アサーションを残さない。
  490       */
  491      function restoreFrom(serialized: string): boolean {
  492          const parsed = parseHistorySnapshot(serialized); // util: unknown→type predicate→検証済み
  493          if (parsed === null) return false;
  494          steps = parsed.map((step) => ({
  495              ...rowOf(step),
  496              id: step.id,
  497              clientKey: step.clientKey, // 安定 key を round-trip
  498              points: step.points.map((point) => ({
  499                  ...rowOf(point),
  500                  id: point.id,
  501                  clientKey: point.clientKey,
  502              })),
  503          }));
  504          return true;
  505      }
  506  
  507      function reportHistoryCorruption(): void {
  508          resetHistory();
  509          if (import.meta.env.DEV) {
  510              console.warn("[ScenarioEditor] 編集履歴の復元に失敗しました (履歴を破棄)");
  511          }
  512          addToast("warning", "編集履歴を復元できませんでした");
  513      }
  514  
  515      function undo(): void {
  516          runSettled(doUndo);
  517      }
  518      function redo(): void {
  519          runSettled(doRedo);
  520      }
  521  
  522      function doUndo(): void {
  523          flushPendingEdit(); // 進行中のテキスト編集を先に 1 エントリ確定
  524          if (undoStack.length === 0) return;
  525          const current = serializeSteps(steps); // 復元前 = redo へ退避する状態
  526          if (!restoreFrom(undoStack[undoStack.length - 1])) {
  527              reportHistoryCorruption(); // fail-safe: steps は変えない
  528              return;
  529          }
  530          undoStack.pop();
  531          redoStack.push(current);
  532          boundHistory(redoStack);
  533          editBaseline = null;
  534      }
  535  
  536      function doRedo(): void {
  537          flushPendingEdit(); // pending 編集があれば「新規編集」= redo クリア (この後 length 0 で no-op)
  538          if (redoStack.length === 0) return;
  539          const current = serializeSteps(steps);
  540          if (!restoreFrom(redoStack[redoStack.length - 1])) {
  541              reportHistoryCorruption();
  542              return;
  543          }
  544          redoStack.pop();
  545          undoStack.push(current);
  546          boundHistory(undoStack);
  547          editBaseline = null;
  548      }
  549  
  550      // --- focus / composition ハンドラ (section に委譲。バブリングする focusin/focusout を使う) ---
  551  
  552      /** input/textarea/select/contenteditable か */
  553      function isEditableField(el: EventTarget | null): boolean {
  554          if (!(el instanceof HTMLElement)) return false;
  555          const tag = el.tagName;
  556          return tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT" || el.isContentEditable;
  557      }
  558  
  559      function onEditorFocusIn(event: FocusEvent): void {
  560          if (isEditableField(event.target) && editBaseline === null) {
  561              editBaseline = serializeSteps(steps); // このフィールド編集セッションの基準
  562          }
  563      }
  564      function onEditorFocusOut(): void {
  565          flushPendingEdit(); // composing 中なら flushDeferred に退避される
  566      }
  567      // 粒度=フィールド単位 (1 フィールドの編集 = 1 履歴エントリ)。値を変えないフォーカス巡回は
  568      // pushHistory(before===current) が no-op のため履歴を汚さない。
  569      function onCompositionStart(): void {
  570          composing = true;
  571      }
  572      function onCompositionEnd(): void {
  573          composing = false;
  574          if (flushDeferred) {
  575              flushDeferred = false;
  576              flushPendingEdit(); // テキスト編集を 1 エントリ確定 (中間文字列は積まれない)
  577          }
  578          const queued = pendingActions;
  579          pendingActions = [];
  580          for (const action of queued) action(); // 構造操作/undo/redo を発行順に実行
  581      }
  582  
  583      // キーボードショートカット (Ctrl/Cmd+Z = undo, +Shift = redo)。編集フィールド内は native に委譲
  584      $effect(() => {
  585          const onKeydown = (event: KeyboardEvent): void => {
  586              if (event.isComposing) return; // IME 変換中は無視
  587              if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== "z") return;
  588              if (saving || confirmingStepIndex !== null || confirmingReload) return;
  589              // 編集フィールドに focus がある間は native の文字単位 undo に委ねる (R1 決定)
  590              if (isEditableField(document.activeElement)) return;
  591              event.preventDefault();
  592              if (event.shiftKey) redo();
  593              else undo();
  594          };
  595          window.addEventListener("keydown", onKeydown);
  596          return () => window.removeEventListener("keydown", onKeydown);
  597      });
  598  
  599      /**
  600       * union 網羅の型固定 (kind 追加時は引数の never 不一致でコンパイルエラーになり
```

#### markup: 手順リスト・削除ボタン (L1074-1135)

```svelte
 1074          <ol
 1075              class="mt-4 flex flex-col gap-4 {stepDrag.activeIndex !== null ? 'select-none' : ''}"
 1076              data-testid="scenario-steps"
 1077              bind:this={stepListEl}
 1078          >
 1079              {#each steps as step, stepIndex (step.clientKey)}
 1080                  <li class="relative" data-reorder-index={stepIndex}>
 1081                      {#if stepDrag.insertionIndex === stepIndex}
 1082                          <!-- 落とし先の目印。影・scale は使わない (DESIGN.md §Elevation) -->
 1083                          <div class="absolute inset-x-0 -top-2 h-0.5 bg-primary" aria-hidden="true"></div>
 1084                      {/if}
 1085                      <div class={stepDrag.activeIndex === stepIndex ? "opacity-50" : ""}>
 1086                      <Card padding="md">
 1087                          <div class="flex items-start justify-between gap-2">
 1088                              <div class="flex items-center gap-2">
 1089                                  <DragHandle
 1090                                      ariaLabel={`手順 ${stepIndex + 1} の並び順を変更 (ドラッグ、または上下キー)`}
 1091                                      onpointerdown={(event) => onStepHandleDown(stepIndex, event)}
 1092                                      onkeydown={(event) =>
 1093                                          onHandleKeydown(event, (delta) => moveStep(stepIndex, delta))}
 1094                                      testId="step-{stepIndex}-drag-handle"
 1095                                  />
 1096                                  <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
 1097                              </div>
 1098                              <div class="flex items-center gap-1">
 1099                                  <Button
 1100                                      variant="ghost"
 1101                                      size="sm"
 1102                                      iconOnly
 1103                                      ariaLabel={`手順 ${stepIndex + 1} を上へ移動`}
 1104                                      onclick={() => moveStep(stepIndex, -1)}
 1105                                      testId="step-{stepIndex}-move-up"
 1106                                  >
 1107                                      <ChevronUp class="size-4" aria-hidden="true" />
 1108                                  </Button>
 1109                                  <Button
 1110                                      variant="ghost"
 1111                                      size="sm"
 1112                                      iconOnly
 1113                                      ariaLabel={`手順 ${stepIndex + 1} を下へ移動`}
 1114                                      onclick={() => moveStep(stepIndex, 1)}
 1115                                      testId="step-{stepIndex}-move-down"
 1116                                  >
 1117                                      <ChevronDown class="size-4" aria-hidden="true" />
 1118                                  </Button>
 1119                                  <Button
 1120                                      variant="danger-ghost"
 1121                                      size="sm"
 1122                                      iconOnly
 1123                                      ariaLabel={`手順 ${stepIndex + 1} を削除`}
 1124                                      onclick={() => (confirmingStepIndex = stepIndex)}
 1125                                      testId="step-{stepIndex}-remove"
 1126                                  >
 1127                                      <Trash2 class="size-4" aria-hidden="true" />
 1128                                  </Button>
 1129                              </div>
 1130                          </div>
 1131                          <div class="mt-3">
 1132                              {@render rowFields(step, `steps.${stepIndex}`, `step-${stepIndex}`)}
 1133                          </div>
 1134                          {@render videoCell(step.id, `step-${stepIndex}`)}
 1135  
```

#### markup: 急所の削除ボタンと「急所を追加」 (L1194-1252)

```svelte
 1194                                                  <Button
 1195                                                      variant="danger-ghost"
 1196                                                      size="sm"
 1197                                                      iconOnly
 1198                                                      ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を削除`}
 1199                                                      onclick={() => removePoint(stepIndex, pointIndex)}
 1200                                                      testId="point-{stepIndex}-{pointIndex}-remove"
 1201                                                  >
 1202                                                      <Trash2 class="size-4" aria-hidden="true" />
 1203                                                  </Button>
 1204                                              </div>
 1205                                          </div>
 1206                                          <div class="mt-2">
 1207                                              {@render rowFields(
 1208                                                  point,
 1209                                                  `steps.${stepIndex}.points.${pointIndex}`,
 1210                                                  `point-${stepIndex}-${pointIndex}`,
 1211                                              )}
 1212                                          </div>
 1213                                          {@render videoCell(
 1214                                              point.id,
 1215                                              `point-${stepIndex}-${pointIndex}`,
 1216                                          )}
 1217                                          </div>
 1218                                      </li>
 1219                                  {/each}
 1220                                  {#if pointDragStep === stepIndex && pointDrag.insertionIndex === step.points.length}
 1221                                      <li class="h-0.5 bg-primary" aria-hidden="true"></li>
 1222                                  {/if}
 1223                              </ol>
 1224                          {/if}
 1225  
 1226                          <div class="mt-4">
 1227                              <Button
 1228                                  variant="ghost"
 1229                                  size="sm"
 1230                                  onclick={() => addPoint(stepIndex)}
 1231                                  testId="step-{stepIndex}-add-point"
 1232                              >
 1233                                  <Plus class="size-4" aria-hidden="true" />
 1234                                  急所を追加
 1235                              </Button>
 1236                          </div>
 1237                      </Card>
 1238                      </div>
 1239                  </li>
 1240              {/each}
 1241              {#if stepDrag.insertionIndex === steps.length}
 1242                  <li class="h-0.5 bg-primary" aria-hidden="true"></li>
 1243              {/if}
 1244          </ol>
 1245  
 1246          <div class="mt-4">
 1247              <Button variant="neutral" onclick={addStep} testId="scenario-add-step">
 1248                  <Plus class="size-4" aria-hidden="true" />
 1249                  手順を追加
 1250              </Button>
 1251          </div>
 1252      {/if}
```

#### markup: 削除確認ダイアログ (L1320-1342)

```svelte
 1320      </div>
 1321  </section>
 1322  
 1323  <ConfirmDialog
 1324      open={confirmingStepIndex !== null}
 1325      title="手順を削除しますか?"
 1326      message="この手順を削除すると、配下の急所と登録済みのテイク (撮影動画) も一緒に削除されます。この操作は「シナリオを更新」で保存すると元に戻せません。"
 1327      confirmLabel="削除する"
 1328      confirmVariant="danger"
 1329      onConfirm={() => confirmingStepIndex !== null && removeStep(confirmingStepIndex)}
 1330      onCancel={() => (confirmingStepIndex = null)}
 1331      testId="scenario-step-remove-dialog"
 1332  />
 1333  
 1334  <ConfirmDialog
 1335      bind:open={confirmingReload}
 1336      title="サーバの最新を取得しますか?"
 1337      message="現在編集中の内容は破棄され、サーバに保存されている最新のシナリオに置き換わります。"
 1338      confirmLabel="破棄して最新を取得"
 1339      confirmVariant="danger"
 1340      onConfirm={reloadScenario}
 1341      testId="scenario-reload-dialog"
 1342  />
```

### `resources/js/types/manual.ts` (作業コピー型。L148-190)

```ts
  148   * PHP: App\DataTransferObjects\Manual\ScenarioPointData と対。
  149   * サーバ shape の id は常に number (確定 id)。未保存行 (id: null) は
  150   * 編集中の作業コピー専用型 DraftPoint / DraftStep で表現し、型を分離する。
  151   */
  152  export interface ScenarioPoint {
  153      id: number;
  154      scene: string;
  155      shot_type: "hiki" | "yori";
  156      shooting_point: string | null;
  157      narration: string;
  158      subtitle_primary: string | null;
  159      subtitle_secondary: string;
  160      material_type: "video" | "still" | null;
  161      static_display_seconds: number | null;
  162  }
  163  
  164  /** PHP: ScenarioStepData と対 (step 行 + 配下の points) */
  165  export interface ScenarioStep extends ScenarioPoint {
  166      points: ScenarioPoint[];
  167  }
  168  
  169  /** PHP: ScenarioDocumentData と対 (edit props / PUT 成功応答の共通 shape) */
  170  export interface ScenarioDocument {
  171      scenario_version: number;
  172      steps: ScenarioStep[];
  173  }
  174  
  175  /**
  176   * 編集中の作業コピー (未保存行は id: null)。
  177   * clientKey は each の安定 key 用のクライアント専用識別子。
  178   * serializeSteps() には含めるが PUT payload (payloadSteps) には含めない (サーバ非公開)。
  179   */
  180  export type DraftPoint = Omit<ScenarioPoint, "id"> & { id: number | null; clientKey: string };
  181  export type DraftStep = Omit<ScenarioStep, "id" | "points"> & {
  182      id: number | null;
  183      clientKey: string;
  184      points: DraftPoint[];
  185  };
  186  
  187  /** PHP: App\Enums\Manual\JobStatus と対 (値集合を一致させる) */
  188  export type AnalysisJobStatus = "queued" | "running" | "succeeded" | "failed";
  189  
  190  /** PHP: App\Enums\Manual\AnalysisStep と対 */
```

### `tests/js/components/features/manual/ScenarioEditor.test.ts` (抜粋)

#### テスト基盤 (L110-170)

```ts
  110  }
  111  
  112  // 動画列 (takeSummaries) は既定で空 = 保存済み行でも「テイク 0 件」表示になる
  113  const baseProps = { projectId: 1, manualId: 5, takeSummaries: [] };
  114  
  115  /** fetch Response の最小スタブ */
  116  function jsonResponse(status: number, body: unknown): Response {
  117      return {
  118          ok: status >= 200 && status < 300,
  119          status,
  120          json: () => Promise.resolve(body),
  121      } as unknown as Response;
  122  }
  123  
  124  /** JSON として読めない応答 (破損 body) */
  125  function brokenResponse(status: number): Response {
  126      return {
  127          ok: status >= 200 && status < 300,
  128          status,
  129          json: () => Promise.reject(new Error("broken")),
  130      } as unknown as Response;
  131  }
  132  
  133  const fetchMock = vi.fn<(input: RequestInfo | URL, init?: RequestInit) => Promise<Response>>();
  134  
  135  beforeEach(() => {
  136      fetchMock.mockReset();
  137      routerReloadMock.mockReset();
  138      routerOnMock.mockClear();
  139      vi.stubGlobal("fetch", fetchMock);
  140      clearToasts();
  141      // jsdom は scrollIntoView 未実装。失敗フィードバックの知覚処理 (showFailure) が
  142      // 全失敗経路で呼ぶため、毎テスト新しい spy を注入する (呼び出し順/引数検証にも使う)
  143      Element.prototype.scrollIntoView = vi.fn();
  144      // parseHistorySnapshot mock を毎テスト既定 (real 委譲) へ復帰させ、fail-safe テストの
  145      // mockReturnValueOnce が他テストへ波及しないようにする
  146      if (holder.real) holder.mock.mockImplementation(holder.real);
  147  });
  148  
  149  afterEach(() => {
  150      cleanup();
  151      vi.unstubAllGlobals();
  152  });
  153  
  154  /** 直近の PUT リクエスト body を取り出す */
  155  function lastPutPayload(): { expected_version: number; steps: Array<Record<string, unknown>> } {
  156      const calls = fetchMock.mock.calls.filter(([, init]) => init?.method === "PUT");
  157      const last = calls[calls.length - 1];
  158      if (!last?.[1]?.body) throw new Error("PUT リクエストがありません");
  159      return JSON.parse(String(last[1].body)) as {
  160          expected_version: number;
  161          steps: Array<Record<string, unknown>>;
  162      };
  163  }
  164  
  165  /** セルに値を入力する */
  166  async function typeInto(testId: string, value: string): Promise<void> {
  167      await fireEvent.input(screen.getByTestId(testId), { target: { value } });
  168  }
  169  
  170  describe("ScenarioEditor", () => {
```

#### T185 が追加した D&D テストの基盤とヘルパ (L1214-1300)

```ts
 1214  /*
 1215   * ドラッグ&ドロップ並べ替え (T185)。層 3 = 配線:
 1216   * 落としたら既存の保存経路 (payloadSteps の配列順) / 履歴 / dirty 判定が期待どおり動くか。
 1217   * 意味論 (どこに落ちたら何番目か) は tests/js/lib/dnd/list-reorder.test.ts が持つ。
 1218   */
 1219  describe("ドラッグ&ドロップ並べ替え (T185)", () => {
 1220      let rectSpy: ReturnType<typeof vi.spyOn> | null = null;
 1221  
 1222      /** 行の実測を data-reorder-index から固定値へ差し替える (top = index * 100, height = 100) */
 1223      function stubRowRects(): void {
 1224          rectSpy = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(
 1225              function (this: HTMLElement): DOMRect {
 1226                  const raw = this.dataset.reorderIndex;
 1227                  const index = raw === undefined ? -1 : Number(raw);
 1228                  const top = index < 0 ? 0 : index * 100;
 1229                  const height = index < 0 ? 0 : 100;
 1230                  // 素の型アサーションを使わずに実体を作る (new DOMRect が top/bottom を導出する)
 1231                  return new DOMRect(0, top, 0, height);
 1232              },
 1233          );
 1234      }
 1235  
 1236      function pointerEvent(type: string, clientY: number, pointerId = 1): PointerEvent {
 1237          return new PointerEvent(type, {
 1238              bubbles: true,
 1239              cancelable: true,
 1240              pointerId,
 1241              clientY,
 1242              button: 0,
 1243              pointerType: "touch",
 1244          });
 1245      }
 1246  
 1247      async function grab(testId: string, clientY: number, pointerId = 1): Promise<void> {
 1248          await fireEvent(screen.getByTestId(testId), pointerEvent("pointerdown", clientY, pointerId));
 1249      }
 1250  
 1251      async function dragTo(clientY: number, pointerId = 1): Promise<void> {
 1252          await fireEvent(window, pointerEvent("pointermove", clientY, pointerId));
 1253      }
 1254  
 1255      async function drop(clientY: number, pointerId = 1): Promise<void> {
 1256          await fireEvent(window, pointerEvent("pointerup", clientY, pointerId));
 1257      }
 1258  
 1259      /** 掴む → 動かす → 落とす */
 1260      async function dragHandle(testId: string, startY: number, endY: number): Promise<void> {
 1261          await grab(testId, startY);
 1262          await dragTo(endY);
 1263          await drop(endY);
 1264      }
 1265  
 1266      /** 2 手順 × 2 急所 (急所の同一スコープ性を検証できる形) */
 1267      function makeDndDocument(): ScenarioDocument {
 1268          const row = (id: number, scene: string) => ({
 1269              id,
 1270              scene,
 1271              shot_type: "yori" as const,
 1272              shooting_point: null,
 1273              narration: "",
 1274              subtitle_primary: null,
 1275              subtitle_secondary: "",
 1276              material_type: null,
 1277              static_display_seconds: null,
 1278          });
 1279          return {
 1280              scenario_version: 3,
 1281              steps: [
 1282                  {
 1283                      ...row(11, "手順シーンA"),
 1284                      shot_type: "hiki",
 1285                      points: [row(21, "急所A-1"), row(22, "急所A-2")],
 1286                  },
 1287                  {
 1288                      ...row(12, "手順シーンB"),
 1289                      shot_type: "hiki",
 1290                      points: [row(23, "急所B-1"), row(24, "急所B-2")],
 1291                  },
 1292              ],
 1293          };
 1294      }
 1295  
 1296      function renderDnd(): void {
 1297          render(ScenarioEditor, { props: { ...baseProps, scenario: makeDndDocument() } });
 1298      }
 1299  
 1300      /** 現在の手順の scene 値 (表示順) */
```

#### T185 の IME 回帰テスト 2 件 (手本。L1440-1478)

```ts
 1440      it("IME 変換中に確定した D&D は compositionend まで順序も告知も変わらない", async () => {
 1441          renderDnd();
 1442  
 1443          await fireEvent.compositionStart(screen.getByTestId("step-0-scene"));
 1444          await dragHandle("step-0-drag-handle", 50, 160);
 1445  
 1446          expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
 1447          expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent("");
 1448  
 1449          await fireEvent.compositionEnd(screen.getByTestId("step-0-scene"));
 1450  
 1451          expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
 1452          expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent(
 1453              "手順 1 を 2 番目に移動しました",
 1454          );
 1455      });
 1456  
 1457      it("IME 変換中に手順の並べ替えと急所の並べ替えを続けて確定しても、掴んだ手順の急所が動く", async () => {
 1458          renderDnd();
 1459  
 1460          await fireEvent.compositionStart(screen.getByTestId("step-0-scene"));
 1461          // (1) 手順 1 (手順シーンA) を 2 番目へ
 1462          await dragHandle("step-0-drag-handle", 50, 160);
 1463          // (2) その手順シーンA の急所 1 を 2 番目へ (この時点では手順シーンA はまだ index 0)
 1464          await dragHandle("point-0-0-drag-handle", 50, 160);
 1465  
 1466          // どちらも compositionend まで保留される
 1467          expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
 1468  
 1469          await fireEvent.compositionEnd(screen.getByTestId("step-0-scene"));
 1470  
 1471          // (1) が先に効いて並びが変わっても、(2) は**掴んだ手順シーンA の急所**に適用される。
 1472          // 数値 index を持ち回っていると手順シーンB の急所が入れ替わってしまう。
 1473          expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
 1474          expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所B-1");
 1475          expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所B-2");
 1476          expect(screen.getByTestId("point-1-0-scene")).toHaveValue("急所A-2");
 1477          expect(screen.getByTestId("point-1-1-scene")).toHaveValue("急所A-1");
 1478      });
```

#### 既存の削除・追加テスト (L205-244)

```ts
  205      it("急所を追加できる (行内の急所を追加ボタン)", async () => {
  206          render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
  207  
  208          await fireEvent.click(screen.getByTestId("step-1-add-point"));
  209  
  210          expect(screen.getByTestId("point-1-0-scene")).toHaveValue("");
  211      });
  212  
  213      it("手順の削除は確認ダイアログを経由し、配下の急所ごと消える", async () => {
  214          render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
  215  
  216          await fireEvent.click(screen.getByTestId("step-0-remove"));
  217          // ダイアログにテイクも消える旨の説明がある
  218          await waitFor(() => {
  219              expect(screen.getByText(/登録済みのテイク/)).toBeInTheDocument();
  220          });
  221          await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
  222  
  223          // 手順A が消え、手順B が繰り上がる
  224          expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンB");
  225          expect(screen.queryByTestId("point-0-0-scene")).not.toBeInTheDocument();
  226      });
  227  
  228      it("急所の削除はダイアログなしで行える", async () => {
  229          render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
  230  
  231          await fireEvent.click(screen.getByTestId("point-0-0-remove"));
  232  
  233          expect(screen.queryByTestId("point-0-0-scene")).not.toBeInTheDocument();
  234      });
  235  
  236      it("▲▼ で同一スコープ内の並べ替えができる", async () => {
  237          render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
  238  
  239          await fireEvent.click(screen.getByTestId("step-1-move-up"));
  240  
  241          expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンB");
  242          expect(screen.getByTestId("step-1-scene")).toHaveValue("手順シーンA");
  243      });
  244  
```

