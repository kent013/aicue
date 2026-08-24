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
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。想定外のパターンも判断材料になる。数値を見て即座に閾値を弄るな。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。方向性が間違っているなら設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC

【本件の性質】
- 変更は docs + tests のみ。app/ routes/ database/ config/ resources/ は 1 行も触らない
- 対象は「テンプレートからの意図的逸脱の登録簿」(docs/template-divergence.md) の統一形式への移行と、
  その形式を機械で見る検査の新設である。統一形式は家系の機能台帳 lctl が 2026-08-15 に確定した正典で、
  本設計はその実装側である (概念設計は同じセッションで APPROVED 済み)

【レビュー観点】
1. コードの正確性（解析ロジックのエッジケース、fail-open になる箇所、null 安全性）
2. 既存コードとの整合性（本リポジトリの先例 RouteBindingCustomBinderDocSyncTest の作法 =
   純関数の抽出層 + 正のコントロール + 負のコントロール + 双方向照合）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策にテスト、負例で検出力を実測する）
5. 副作用・後退リスク（移行の取りこぼし、将来の CI 赤）
6. セキュリティ観点（この登録簿はセキュリティ不変条件の逸脱理由を保持しているので、
   記録が失われると防御が「追従漏れ」と誤読されて戻される)
7. 保証範囲の誇張が無いか（検出できないものを検出できると書いていないか）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: template-divergence-machine-layer

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項（AGENTS.md より。本作業に効くもの）

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き / 6. prompt 文字列のコード直書き — 本作業は該当なし
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)。`RefreshDatabase` はグローバル適用、個別 `DatabaseTransactions` 禁止
- `declare(strict_types=1)` + 日本語コメント (git 追跡下の PHP 全数が対象。`StrictTypesDeclarationGateTest`)
- `echo` / `goto` / `global` / 開始タグ付きの出力記法は書かない (`ForbiddenStatementTokenInvariantTest`)
- コードフォーマット: `composer fix` (Pint)
- PHP 8.4 + Laravel 12

## 概念設計リファレンス

`devnotes/20260815-2059-template-divergence-machine-layer/conceptual-design.md` (Codex Round 5 APPROVED)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 登録簿の規約節を統一形式へ書き換える | `docs/template-divergence.md` | 高 |
| 2 | 解消済み D9 の削除 | `docs/template-divergence.md` | 高 |
| 3 | 残る 17 件へ 9 行の登録メタ表を新設し、見出しから印を外す | `docs/template-divergence.md` | 高 |
| 4 | 形式検査の新設 (純粋ロジック層 + 薄い検査層 + 負例の単体テスト) | `tests/Support/TemplateDivergence/*` (新規 5), `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` (新規), `tests/Unit/Architecture/DivergenceLedgerRulesTest.php` (新規) | 高 |
| 5 | 未登録の逸脱 7 候補の判定と登録 | `docs/template-divergence.md`, `devnotes/{実装 dir}/unregistered-divergence-triage.md` (新規), 検査側の固定件数 | 中 |
| 6 | 運用契約の参照先を登録簿の規約節へ寄せる | `docs/app-integration-guide.md`, `AGENTS.md` | 低 |

コミットは **(a) 施策 1〜4 + 6** と **(b) 施策 5** の 2 つに分ける。(b) では検査側の固定件数も
同じコミットで更新する (件数は 3 点一致なので片方だけ動かせない)。

---

## 施策 1: 登録簿の規約節を統一形式へ書き換える

### 変更箇所

- ファイル: `docs/template-divergence.md` (L1〜L35。表題・記録の原則・エントリ形式の記入例)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 4 の検査が本節の書式を前提にする (同一コミット)

### 現行 (L1〜L35 の要約)

```markdown
# テンプレート差分レジストリ
（判定原則 2 行）
## 記録の原則
- 判定軸は「同じ不変条件を同じタイミング/抽象度で保証するか」
- 各エントリには (a) なぜ logic-driven か (b) どの機構で保証し続けるか を必ず書く
## エントリ形式
```(囲みコード区画に `## D1 ✅ <逸脱の要約>` の記入例)```
---
```

### 変更後 (構成)

```markdown
# テンプレート差分レジストリ

テンプレート(laravel-claude-template)の構造から**意図的に逸脱**した箇所の正本記録。
逸脱が正当なのは **logic-driven(ドメイン要件起因)のときだけ**。互換・UX・作業量を理由にした
逸脱は記録せず是正する(`docs/app-integration-guide.md` §0)。

**書式の正本は本節である**。家系の統一形式 (機能台帳 lctl の feature
`template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。

登録エントリ: 17 件

## 記録の原則

- 判定軸は「ライブラリ/実装が同じか」でなく「**同じ不変条件を同じタイミング/抽象度で保証するか**」
- **登録は逸脱を作る変更そのものに含める**。後でまとめて書かない。まだ実在しない逸脱
  (これから作る予定) は登録しない — 予定の管理は TODO の役目である
- **解消した逸脱は登録から消す**。全パスが戻ったならエントリごと、一部が戻ったなら
  そのパスを対象パス欄から削る。状態の語で「解消済み」を表さない。
  台帳の中に履歴の節を作らない (走査の対象外になる領域は回避口になるため)
- 番号 (`D<n>`) は**再利用しない**。削除しても後続を詰めない (欠番は正常)。
  他リポジトリから参照するときは `aicue:D<n>` と書く

## 登録メタ表 (9 行ちょうど・この順序)

| 行 | 値域 |
|---|---|
| 対象パス | リポジトリ相対のファイルパスをバッククォート囲みで 1 件以上。glob・絶対パス・`..` は不可。実在すること。**全登録の和集合で重複しないこと** |
| 業務要件起因の説明 | なぜドメイン要件のせいで外れたか (1〜2 文) |
| 揃え続ける不変条件と保証機構 | 何を揃え続け、どの機構が保証するか |
| 再判定の条件 | 何が変わったら見直すか (**恒久の登録にも必須**) |
| 決めた日 | `YYYY-MM-DD`。逸脱を最初に決めた日 (再判断で書き換えない)。未来日は不可 |
| 決めた人 | `オーナー` / `開発者` |
| 根拠 | `T<n>` (TODO 台帳に実在) または `devnotes/<dir>/` (実在) |
| 状態 | `恒久` / `監視中` |
| 見直し期限 | `監視中` は `YYYY-MM-DD` (基準日から 400 日以内)。`恒久` は `—` |

- **`恒久` も `監視中` も「今ある逸脱」を表す**。解消を意味する語は値域に無い
- `監視中` にするのは、期限付きで能動的に見直す根拠 (期限・予定時期・追跡中の事象) が
  あるときだけである。解消の条件が書けることは `監視中` の根拠にならない
  (`恒久` の登録も再判定の条件を必ず持つので、条件の有無は区別にならない)

## 見直し期限が切れたときの直し方 (4 通り)

1. 逸脱を解消して登録を消す
2. `恒久` へ変えて理由を足す
3. 期限を延ばして再判断の根拠を足す
4. 対象を分けて個別に判断する

**検査を緩めることは選択肢に入れない**。期限切れで CI が赤くなるのは仕様である。

## この登録簿が保証しないもの

- 実ファイルがテンプレートから逸脱したのに登録が無いこと (登録漏れそのもの) は検出できない。
  実体との突合は台帳リポジトリの巡回が行う (家系の裁定 AG-159)
- 内容としてテンプレート準拠へ戻したのにファイルが残っている登録も検出できない
- 登録の中身が正しいことは機械では見ない (空でないことだけを見る)

## エントリ形式

```(囲みコード区画: 見出し + 9 行メタ表 + 比較表 + 3 節の記入例)```

---
```

### PHPStan適合チェック

- 本施策は Markdown のみ。該当なし

### テスト計画

- 施策 4 の `TemplateDivergenceLedgerFormatTest` が本節の「登録エントリ: N 件」行を読む。
  行が無い / 2 行ある / 数が合わない場合に赤になることを負例で固定する
- 記入例は囲みコード区画の中に置き、**見出しとして数えられないこと**を負例テストで固定する

### リスク

- 規約節を厚く書くと形骸化する。遷移 4 通りと保証しないものは**ここ 1 か所だけ**に置き、
  各エントリへ同じ文を複写しない (正典 s5 の決定と同じ理由)

---

## 施策 2: 解消済み D9 の削除

### 変更箇所

- ファイル: `docs/template-divergence.md` (L264〜L308 = `## D9 ✅→解消 …` のエントリ全体)

### 波及変更

- `AGENTS.md` は D14 / D16 を参照するが **D9 への参照は無い** (実測)。`README.md` もファイル名のみ
- 削除する情報の退避先は既存で足りる — 経緯は `devnotes/20260712-0927-bugfix-billing-free-access/`、
  実装は `RequireActiveSubscriptionMiddlewareTest` が固定している。**新しい退避文書を作らない**

### 変更内容

エントリを丸ごと削除する。番号は詰めない (D9 は欠番になる)。

### テスト計画

- 施策 4 の件数 3 点一致が「17 件」を強制するので、削除漏れ・削除しすぎの両方で赤になる
- 負例テスト: 状態欄に `解消` / `解消済み` を書いた fixture が違反になること

### リスク

- 「記録は削除せず経緯として残す」と本文が明言しているものを消す。正典 i3 の決定
  (解消を状態で表すと掃除漏れの検出を回避できる) が根拠であり、経緯は devnotes と TODO に残る。
  **削除の理由をコミットメッセージに明記する**

---

## 施策 3: 残る 17 件へ 9 行の登録メタ表を新設する

### 変更箇所

- ファイル: `docs/template-divergence.md` (D1〜D18 のうち D9 を除く 17 件)

各エントリで行うことは 3 つ。

1. 見出しから印 (`✅`) を外し、`## D<n> <要約>` の行全体一致にする
2. 見出しの直後に 9 行の登録メタ表を置く (既存の 3 列比較表はメタ表の**次**に残す)
3. 既存の 3 節 (なぜ正当な差分か / 揃えている不変条件 / 関連) は**そのまま残す**

### 対象パスの起こし方 (実装時の手順)

1. 各エントリの「関連 → 実装」行に挙がっているパスを取る
2. **glob と波括弧展開を実パスへ展開する** (`database/migrations/2026_07_10_*` /
   `app/Models/{SourceDocument,Cut,Take}.php` は正典 i5 が禁じる形なので、実ファイル名へ展開する)
3. 全 17 件の和集合を取り、**重複するパスは 1 件だけに載せる**。どちらへ載せるかは
   「そのパスがどちらの逸脱の実体か」で決め、載せなかった側は本文の「関連」に残す
   (関連は対象パス欄ではないので重複しても検査に掛からない)
4. 実在しないパスは載せない (検査が赤になる)

**現時点で判明している重複は 1 件**: `bootstrap/app.php` が D4 (middleware alias 登録) と
D14 (priority list 上の位置) の両方に出る。**D14 に載せる** — D14 の逸脱の実体は
「web グループの末尾かつ priority list の鎖の最後」という位置そのものであり、
D4 の実体は middleware クラスと route group への付与だからである。
残りの重複は実装時に和集合を取って確定する (見落としがあっても検査が赤で教える)。

### 各エントリの移行方針 (実装時に 1 件ずつ実読で確定する)

| id | 対象パスの起点 | 状態の見込み | 根拠の候補 |
|---|---|---|---|
| D1 | `app/Models/Cut.php` / `app/Models/Take.php` / 該当 migration | 卒業の実績がある (SourceDocument が卒業済み) が**期限を導ける材料が無い** → `恒久` + 再判定の条件へ転記 | `T001` |
| D2 | cuts の migration 2 本 | `恒久` | `T001` |
| D3 | `app/Services/Manual/CategoryService.php` / `app/Models/Category.php` | `恒久` | `T001` |
| D4 | `app/Http/Middleware/EnsureProjectBelongsToRouteOrganization.php` / `routes/web.php` | `恒久` | `T001` |
| D5 | `app/Services/Manual/ScenarioService.php` ほか 2 | `恒久` | `T002` |
| D6 | `app/Services/Capture/TakeObjectStorage.php` | `恒久` | `T004` |
| D7 | `app/Services/Manual/RenderJobService.php` | 「保留」= 見直す意思があるが**期限を導ける材料が無い** → `恒久` + 再判定の条件へ転記 (材料が出たら `監視中` へ) | `T005` |
| D8 | `app/Enums/AdminConsoleRole.php` ほか | `恒久`。決めた人は**オーナー** (裁定 AG-079) | `T006` |
| D10 | `scripts/global-test-lock.sh` ほか | `恒久`。決めた人は**オーナー** (テンプレ昇格承認) | `T099` |
| D11 | `tests/js/architecture/svelte-no-undef-gate.test.ts` / `eslint.config.js` | 本文が「収束条件」を持つ。**時期を導けるかを実読で判断**し、導けなければ `恒久` + 再判定の条件 | `T102` |
| D12 | `app/Support/Seo/SeoManager.php` ほか | `恒久` | 実装時に確定 (`devnotes/20260805-0101-architecture-gate-followup/` を根拠にしてよい) |
| D13 | `app/Services/Auth/SocialAccountService.php` ほか | `恒久` | 実装時に確定 |
| D14 | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `bootstrap/app.php` ほか | `恒久` | `T164` |
| D15 | `tests/Architecture/StrictTypesDeclarationGateTest.php` ほか | `恒久`。決めた人は**オーナー** (裁定 AG-010) | `T167` |
| D16 | `app/Support/Llm/PromptDefense.php` ほか | `恒久` | `T169` |
| D17 | `app/Contracts/Recovery/StuckWorkStream.php` ほか | `恒久`。決めた人は**オーナー** (裁定 AG-083) | `T171` |
| D18 | `.claude/settings.json` / `scripts/*-hook.sh` | `恒久` | `T172` |

- **この表は見込みであって確定値ではない**。実装時に概念設計の「移行で新しく決める値の出どころ」の
  規則で 1 件ずつ確認し、確認できないものは判断ログへ書いて差し戻す
- **決めた日**は各エントリが挙げる設計 `devnotes/<dir>/` を実読し、
  **その文書の中で当該逸脱が決まっていること**を確認できたらそのディレクトリの日付を採る
  (全 14 ディレクトリの実在は確認済み)。確認できないものは差し戻す

### テスト計画

- 施策 4 の検査が全 17 件を機械で見る (メタ表の 9 行・値域・実在・重複・期限)
- 移行の取りこぼし (印の付いた見出しが 1 件残る / メタ表が 8 行しかない) は
  すべて検査が赤にする。**移行が終わったことの判定を人の目視に依存させない**

### リスク

- 17 件 × 9 行の手作業なので取りこぼしが出る。→ 検査を**先に**書いて赤を見てから移行する
  (テストファースト。AGENTS.md 思考原則 5)
- 対象パスの和集合の重複は、書いてみるまで全部は分からない。→ 検査が列挙して教える形にする
  (違反メッセージにどのパスがどのエントリと衝突したかを出す)

---

## 施策 4: 形式検査の新設

### 変更箇所 (新規 7 ファイル)

| ファイル | 役割 |
|---|---|
| `tests/Support/TemplateDivergence/EntryMetadata.php` | メタ表 9 行の値を持つ readonly class |
| `tests/Support/TemplateDivergence/ParsedEntry.php` | 見出し + メタ表 + 出現行 |
| `tests/Support/TemplateDivergence/ParsedLedger.php` | 登録一覧 + 明示件数 + 解析時点の違反 |
| `tests/Support/TemplateDivergence/LedgerContext.php` | 基準日 / 固定件数 / パス実在判定 / 根拠実在判定 |
| `tests/Support/TemplateDivergence/DivergenceLedgerParser.php` | Markdown → `ParsedLedger` (純関数) |
| `tests/Support/TemplateDivergence/DivergenceLedgerRules.php` | `ParsedLedger` + `LedgerContext` → 違反一覧 (純関数) |
| `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` | 薄い検査層 (実ファイルを読み、文脈を組み立て、違反が空であることを見る) |
| `tests/Unit/Architecture/DivergenceLedgerRulesTest.php` | 正例 1 + 負例 (規則ごとに 1 本以上) |

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- `app/` は 1 行も触らない (検査はテスト層に閉じる)

### 解析の仕様 (`DivergenceLedgerParser`)

1. **囲みコード区画を先に落とす**。行頭 ` ``` ` でトグルし、閉じずに終端したら
   `P1: 囲みコード区画が閉じていない (解析不能)` を返して**それ以上解析しない** (fail-closed)
2. **明示行**: 区画外で `^登録エントリ: (\d+) 件$` に一致する行。**ちょうど 1 本**でなければ違反
3. **登録エントリ領域**: 区画外の最初の `^## D` 行から文書末尾まで
4. **見出し**: 領域内の `^## ` 行は `^## D([1-9]\d*) (\S.*)$` に**行全体一致**しなければ違反
   (`✅` や `→解消` が付いていれば必ず落ちる)。id の重複も違反
5. **メタ表**: 見出しの次の非空行から始まり、`| 行 | 内容 |` のヘッダ・`|---|---|` の区切り・
   **9 本のデータ行**で構成される。ラベル列が規定の 9 語と**順序ごと一致**しなければ違反
   (過不足・順序違いは「台帳を解釈できない」= 不合格)
6. セル値は `|` で分割して trim する。値の妥当性は `DivergenceLedgerRules` が見る

### 判定の仕様 (`DivergenceLedgerRules`)

| 記号 | 規則 | 出典 |
|---|---|---|
| TD1 | 見出しは正準形・id は一意 | i4 |
| TD2 | メタ表は 9 行ちょうど・ラベルは規定の順序 | i2 |
| TD3 | 対象パスは 1 件以上・リポジトリ相対・glob/絶対/`..` 不可・実在 | i5 |
| TD4 | 全登録の対象パスの和集合で重複しない | i5 |
| TD5 | 状態は `恒久` / `監視中` の 2 語 | i3 |
| TD6 | `監視中` は見直し期限必須・実在する日付・**基準日から 400 日以内**・**期限切れは不合格** | i8 |
| TD7 | `恒久` の見直し期限は `—` ちょうど | i8 |
| TD8 | 決めた日は実在する日付で**未来日不可** | i7 |
| TD9 | 決めた人は `オーナー` / `開発者` の 2 語 | i10 |
| TD10 | 根拠は `T<n>` (TODO 台帳に実在) か `devnotes/<dir>/` (実在)。プレースホルダ不可 | i7 |
| TD11 | 業務要件起因の説明 / 揃え続ける不変条件と保証機構 / 再判定の条件が空でない・プレースホルダでない | i9 |
| TD12 | 明示件数・解析件数・固定件数の 3 点一致 | i6 |

- **プレースホルダの語彙**: `...` / `…` / `TBD` / `未定` / `-` / `—` / 空文字。
  過剰検出寄りに倒す (正典 i15)
- **許可一覧を持たない**。個別の D 番号を名指しして規則を免除する仕組みは作らない

### 変更後コード (骨子)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use Closure;
use Carbon\CarbonImmutable;

/**
 * 形式検査の文脈 (基準日と実在判定の注入点)。
 *
 * 基準日を引数で受け取るのは、期限判定を純関数に保ち単体テストが実行日で揺れないようにするため。
 */
final readonly class LedgerContext
{
    /**
     * @param  Closure(string): bool  $pathExists  リポジトリ相対パスの実在判定
     * @param  Closure(string): bool  $rationaleExists  根拠 (T 番号) の実在判定
     */
    public function __construct(
        public CarbonImmutable $baseDate,
        public int $pinnedEntryCount,
        public Closure $pathExists,
        public Closure $rationaleExists,
    ) {}
}
```

```php
/** 登録メタ表 9 行の値 (生文字列のまま持ち、妥当性は Rules が見る)。 */
final readonly class EntryMetadata
{
    /** @param list<string> $targetPaths */
    public function __construct(
        public array $targetPaths,
        public string $rawTargetPathCell,
        public string $domainReason,
        public string $invariantAndGuard,
        public string $reevaluationCondition,
        public string $decidedOn,
        public string $decidedBy,
        public string $rationale,
        public string $state,
        public string $reviewDeadline,
    ) {}
}
```

```php
/**
 * 逸脱の登録簿の形式違反を列挙する (純関数)。
 *
 * **保証しない範囲** (誇張しない):
 *  - 実ファイルがテンプレートから逸脱したのに登録が無いことは検出しない
 *    (実体との突合は台帳リポジトリの巡回が持つ。家系の裁定 AG-159 / 正典 i13)
 *  - 内容がテンプレート準拠へ戻った登録の残置も検出しない (対象パスは実在し続けるため)
 *  - 登録の中身が正しいことは見ない (空でないことだけを見る)
 *  - 登録エントリ領域より前の節と、エントリの中の `###` 見出しは見ない
 *
 * 固定件数 (`LedgerContext::$pinnedEntryCount`) は**明示件数との同期検査**であって、
 * 例外を許す一覧ではない。個別の D 番号を名指しする許可一覧は持たない。
 */
final class DivergenceLedgerRules
{
    /** @return list<string> 違反一覧 (空 = 合格) */
    public static function violations(ParsedLedger $ledger, LedgerContext $context): array { /* … */ }
}
```

```php
// tests/Architecture/TemplateDivergenceLedgerFormatTest.php (薄い検査層)

/** 登録件数の固定値。明示件数との同期検査であって例外の許可一覧ではない。 */
const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 17;

test('TD0: 登録簿を読んで解析できること (実行不能は不合格)', function (): void {
    $markdown = file_get_contents(base_path('docs/template-divergence.md'));
    Assert::string($markdown, 'docs/template-divergence.md を読めない');
    expect(trim($markdown))->not->toBe('');
});

test('TD1〜TD12: 登録簿が統一形式を満たすこと', function (): void {
    $ledger = DivergenceLedgerParser::parse(templateDivergenceMarkdown());
    $violations = DivergenceLedgerRules::violations($ledger, new LedgerContext(
        baseDate: CarbonImmutable::today(),
        pinnedEntryCount: TEMPLATE_DIVERGENCE_ENTRY_COUNT,
        pathExists: fn (string $path): bool => file_exists(base_path($path)),
        rationaleExists: fn (string $ref): bool => str_contains(templateDivergenceTodoSources(), $ref),
    ));

    expect($violations)->toBe([], "逸脱の登録簿の形式違反:\n".implode("\n", $violations));
});
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`list<string>` / `ParsedLedger`)
- [x] null 安全 — `file_get_contents` の戻り値は `Webmozart\Assert\Assert::string()` で締める
- [x] 解析失敗を空配列へ落とさない (違反として返すか例外に倒す)
- [x] Generics — `Closure(string): bool` を phpdoc で明示
- [x] DTO を返している (配列の裸返しをしない。`ParsedEntry` / `EntryMetadata` は readonly class)

### テスト計画

**先に検査を書き、赤を見てから移行する** (テストファースト)。

- [ ] 正例: 統一形式を満たす最小の fixture で違反が 0 件になること
- [ ] 負例 TD1a: 見出しに `✅` が付いていると落ちること
- [ ] 負例 TD1b: 見出しの階層を 1 段下げる (`### D1 …`) と登録として数えられず件数が合わずに落ちること
- [ ] 負例 TD1c: id が重複すると落ちること
- [ ] 負例 TD2a: メタ表が 8 行 / 10 行だと落ちること
- [ ] 負例 TD2b: ラベルの順序を入れ替えると落ちること
- [ ] 負例 TD3a: 対象パスが 0 件だと落ちること
- [ ] 負例 TD3b: glob (`app/Models/*.php`) / 絶対パス / `..` が落ちること
- [ ] 負例 TD3c: 実在しないパスが落ちること
- [ ] 負例 TD4: 2 つの登録が同じパスを挙げると落ちること
- [ ] 負例 TD5: 状態が `解消済み` だと落ちること
- [ ] 負例 TD6a: `監視中` に見直し期限が無いと落ちること
- [ ] 負例 TD6b: 見直し期限が基準日から 401 日先だと落ちること
- [ ] 負例 TD6c: 見直し期限が基準日より前 (期限切れ) だと落ちること
- [ ] 負例 TD7: `恒久` に日付の見直し期限が書いてあると落ちること
- [ ] 負例 TD8: 決めた日が未来日 / 実在しない日付 (`2026-02-30`) だと落ちること
- [ ] 負例 TD9: 決めた人が `チーム` だと落ちること
- [ ] 負例 TD10a: 根拠が実在しない `T9999` だと落ちること
- [ ] 負例 TD10b: 根拠が `TBD` だと落ちること
- [ ] 負例 TD11: 再判定の条件が空 / `...` だと落ちること
- [ ] 負例 TD12a: 明示件数と解析件数が食い違うと落ちること
- [ ] 負例 TD12b: 固定件数と食い違うと落ちること (増えても減っても)
- [ ] 負例 P1: 囲みコード区画が閉じていない fixture が「解析不能」で落ちること
- [ ] 負のコントロール: **囲みコード区画の中の記入例 (`## D1 <要約>`) を登録として数えないこと**
- [ ] 負のコントロール: 登録エントリ領域より**前**の `## 記録の原則` 等の節は違反にならないこと
- [ ] 実挙動: 現物の `docs/template-divergence.md` が違反 0 件であること (Architecture レーン)
- [ ] 期限判定は固定日を渡して検証する (実行日で揺れないこと)

### リスク

- 検査が厳しすぎて正当な書き方を弾く。→ 負例と**正例**の両方を単体テストで固定し、
  正例が緑であることで書ける形を明示する
- 期限切れが将来 CI を赤にする。→ 仕様。直し方は登録簿の規約節の 4 通り。検査は緩めない

---

## 施策 5: 未登録の逸脱 7 候補の判定と登録

### 変更箇所

- `docs/template-divergence.md` (登録する分。番号は D19 から)
- `devnotes/{実装 dir}/unregistered-divergence-triage.md` (判断ログ。新規)
- `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` の固定件数 (登録した分だけ増やす)

### 候補 (過去の巡回が「記録されるべきなのに未登録」と挙げたもの)

| # | 候補 | 巡回日 | 現 HEAD の手掛かり |
|---|---|---|---|
| 1 | 経路キャッシュ起動での後付けについて、正典 (a) でなく (b) 焼き込み変種を採ったこと | 2026-08-09 | `app/Http/Routing/RouteThrottleBinder.php` / `RouteMiddlewareBinder.php` / `PostBootRouteMutationInventoryTest` / `docs/app-integration-guide.md` §7c |
| 2 | 走査基盤に構文解析ライブラリでなく token 走査を採り続けていること | 2026-08-09 | `tests/Support/PhpTokenScan.php` / `tests/Support/PhpReferenceScanner.php` |
| 3 | bug-hunt harness の self-test の実行配線を CI ステップでなく Pest の Architecture suite に置いたこと | 2026-08-10 | `tests/Architecture/BughuntSelfTestExecutionTest.php` |
| 4 | `scripts/setup-worktree.sh` が秘密ファイルを作成時 0600 で供給すること | 2026-08-10 | `scripts/setup-worktree.sh` / `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php` (T170 で範囲が変わっている) |
| 5 | 凍結方式の退会 | 2026-08-12 | `app/Http/Middleware/...` / `tests/Architecture/AccountDeletionFreezeRouteGateTest.php` (T142) |
| 6 | 保持期間 7 年の仕組み | 2026-08-12 | `config/legal.php` / `tests/Architecture/BillingRetentionConfigSingleSourceTest.php` (T143〜T145) |
| 7 | SSO の差し替え点の切り出し | 2026-08-12 | `app/Services/Auth/SocialiteDriverResolver.php` / `ExternalFakeWiringInvariantTest` (T153) |

### 判定の手順 (1 件ずつ)

1. 現 HEAD で当該の形が**まだ生きているか**を実読で確認する (消えていれば登録しない)
2. **既存登録の対象パス欄**と突き合わせ、既に覆われていないかを見る
   (説明文に出るだけでは「登録済み」と数えない)
3. 次の 3 分類へ決着させる。**既定は「登録する」か「差し戻す」**である
   - **登録する** — テンプレートが持つ構造・作法を置き換えた、または正典と別の形を採ったと言える
   - **逸脱ではない** — (a) 台帳リポジトリから届いた判定 / (b) 確定した正典がテンプレートの
     管理範囲を定めている / (c) 指摘元 (巡回) が分類を認めている、のいずれかを根拠にできるときだけ。
     **自分で文書を書いて自己証明する形は認めない**
   - **判定材料が足りない** — テンプレートの管理範囲か分からないもの。差し戻す
4. 結論・根拠・現 HEAD で確認した対象パスを判断ログに書く

**候補 5・6・7 は特に慎重に見る**。「テンプレートに無い機能を足した」だけなら
`docs/app-integration-guide.md` §1 の「追加前提」層であって逸脱ではない。
テンプレートの相当物を**置き換えた**と言えるかどうかが分かれ目で、
テンプレートのソースが手元に無いため判断できないことが起こりうる。その場合は差し戻す。

### テスト計画

- 登録した分だけ固定件数を更新する。更新漏れは 3 点一致が赤にする
- 新しい登録の対象パスが既存と重複していれば TD4 が赤にする
- **判断ログの存在自体は検査しない** (devnotes は検査対象外)。実装レビューで見る

### リスク

- 7 件すべてを登録すると台帳が急に厚くなる。→ 1 件ずつ根拠を確認し、
  根拠が揃わないものは差し戻す。件数を目標にしない
- 差し戻し分が忘れられる。→ 判断ログを根拠に監督セッションへ報告する (台帳への書き込みはしない)

---

## 施策 6: 運用契約の参照先を登録簿の規約節へ寄せる

### 変更箇所

- `docs/app-integration-guide.md` §0 の 3 項 (L13〜L15) と §8 の 3 項 (L523〜L526)
- `AGENTS.md` §テンプレートとの関係 (L410〜L415)

### 変更内容

- 「aigenba/spirux divergence registry と同じ形式」という記述を、
  **`docs/template-divergence.md` の規約節が書式の正本である**という参照へ差し替える
  (兄弟比較の台帳は家系の裁定 2026-08-06 で廃止済みであり、参照先として古い)
- `AGENTS.md` には「形式は `TemplateDivergenceLedgerFormatTest` が機械で強制する」の 1 行を足す。
  **書式の中身は写さない** (2 か所に書くと必ず食い違う)

### テスト計画

- 文書のみ。機械検査は足さない (同じ関心事に 2 本目の検査を作らない)
- `pnpm test` の `verification-commands-doc-sync` など既存の文書同期テストに影響しないことを確認する
  (どちらも本作業が触る節を見ていない)

### リスク

- なし (参照の付け替えのみ)

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | `docs/template-divergence.md` を全面的に書き換えるため、同じファイルへ追記する他タスクと必ず衝突する。本ティアの最後に単独で実施し、その時点の全エントリを一度に移す |
| 競合リスク | 他の設計者が新しい逸脱を登録すると件数と番号が動く。**実装開始時に現物を読み直して件数を数え直す** (本設計の 17 件は 2026-08-15 20:59 時点の値)。`tests/Architecture/` への新規ファイル追加は名前が衝突しなければ安全 |


## 関連する現行コード

### tests/Architecture/RouteBindingCustomBinderDocSyncTest.php (作法の写経元)

```php
<?php

declare(strict_types=1);

use App\Http\Routing\RouteBindingTypes;
use Webmozart\Assert\Assert;

/*
 * `RouteBindingTypes::CUSTOM_BINDER` と docs/architecture.md の列挙を **双方向** で同期する。
 *
 * なぜ必要か: T106 が `{passkey}` を CUSTOM_BINDER へ足したとき、docs/architecture.md の
 * 「単一 SoT の全 binding param 型 inventory」を説明する節が 1 件のままドリフトした
 * (監査サイクル 2 の docs-freshness §2-2)。`{passkey}` は「他人の passkey を 404 に倒す」=
 * セキュリティ不変条件 2 の実装点であり、inventory から落ちている影響は小さくない。
 *
 * 解析対象は CUSTOM_BINDER:BEGIN 〜 CUSTOM_BINDER:END の HTML コメントで囲まれた範囲のみ。
 * 文書全体を grep すると、別の文脈で `{passkey}` に言及しただけで green になり形骸化する
 * (ci-workflow-inventory.test.ts が「YAML を parse してから歩く」のと同じ考え方)。
 *
 * 本テストは DB を触らない (ファイル読み取りのみ)。
 */

/** 同期マーカー。doc 側を書き換えるときは両方を維持すること。 */
const CUSTOM_BINDER_DOC_BEGIN = '<!-- CUSTOM_BINDER:BEGIN -->';

/** 同期マーカー (終端)。 */
const CUSTOM_BINDER_DOC_END = '<!-- CUSTOM_BINDER:END -->';

/**
 * マーカー間の本文を取り出す (純関数)。
 *
 * マーカーがちょうど 1 組でなければ `null` を返す (呼び出し側が違反として報告する)。
 */
function customBinderDocSection(string $markdown): ?string
{
    if (substr_count($markdown, CUSTOM_BINDER_DOC_BEGIN) !== 1) {
        return null;
    }
    if (substr_count($markdown, CUSTOM_BINDER_DOC_END) !== 1) {
        return null;
    }

    $start = strpos($markdown, CUSTOM_BINDER_DOC_BEGIN);
    $end = strpos($markdown, CUSTOM_BINDER_DOC_END);
    Assert::integer($start);
    Assert::integer($end);
    if ($end <= $start) {
        return null;
    }

    return substr($markdown, $start + strlen(CUSTOM_BINDER_DOC_BEGIN), $end - $start - strlen(CUSTOM_BINDER_DOC_BEGIN));
}

/**
 * マーカー間から `` `{param}` `` トークンを抽出する (純関数)。
 *
 * バッククォート囲みの `{name}` だけを拾う。散文中の `{...}` (例: `{organization:slug}` の
 * 説明や JSON 例) を巻き込まないようにするため、param 名は `[a-zA-Z][a-zA-Z0-9_]*` に限定する。
 *
 * @return list<string> 出現順・重複除去済み
 */
function customBinderDocParams(string $section): array
{
    $matches = [];
    $count = preg_match_all('/`\{([a-zA-Z][a-zA-Z0-9_]*)\}`/', $section, $matches);
    Assert::integer($count, 'CUSTOM_BINDER doc の param 抽出に失敗した');

    /** @var list<string> $names */
    $names = $matches[1];

    return array_values(array_unique($names));
}

/**
 * 定数と doc の乖離を列挙する (純関数)。
 *
 * @param  list<string>  $constantKeys  `RouteBindingTypes::CUSTOM_BINDER` の key
 * @param  list<string>  $docParams  doc のマーカー範囲から抽出した param
 * @return list<string> 違反一覧 (空 = 合格)
 */
function customBinderDocSyncViolations(array $constantKeys, array $docParams): array
{
    $violations = [];

    // forward: 定数にある key が doc に載っているか (追加時の追記漏れ = T106 のドリフト)
    foreach ($constantKeys as $key) {
        if (! in_array($key, $docParams, true)) {
            $violations[] = "CB2: CUSTOM_BINDER の `{{$key}}` が docs/architecture.md の同期マーカー範囲に無い (追加時は 1 行追記すること)";
        }
    }

    // reverse: doc にあるが定数に無い = stale (削除時の消し忘れ)
    foreach ($docParams as $param) {
        if (! in_array($param, $constantKeys, true)) {
            $violations[] = "CB3: docs/architecture.md の `{{$param}}` が CUSTOM_BINDER に無い (削除時は doc の行も消すこと)";
        }
    }

    return $violations;
}

/** docs/architecture.md を読む。 */
function customBinderArchitectureMarkdown(): string
{
    $markdown = file_get_contents(base_path('docs/architecture.md'));
    Assert::string($markdown, 'docs/architecture.md を読めない');

    return $markdown;
}

test('CB1: docs/architecture.md に CUSTOM_BINDER 同期マーカーがちょうど 1 組あること', function (): void {
    $markdown = customBinderArchitectureMarkdown();

    expect(substr_count($markdown, CUSTOM_BINDER_DOC_BEGIN))->toBe(1);
    expect(substr_count($markdown, CUSTOM_BINDER_DOC_END))->toBe(1);
    expect(customBinderDocSection($markdown))->not->toBeNull();
});

test('CB2/CB3: CUSTOM_BINDER と docs/architecture.md の列挙が双方向で一致すること', function (): void {
    $section = customBinderDocSection(customBinderArchitectureMarkdown());
    Assert::string($section, 'CUSTOM_BINDER 同期マーカーがちょうど 1 組でない (CB1 を先に直すこと)');

    $violations = customBinderDocSyncViolations(
        array_keys(RouteBindingTypes::CUSTOM_BINDER),
        customBinderDocParams($section),
    );

    expect($violations)->toBe([], "CUSTOM_BINDER と doc の乖離:\n".implode("\n", $violations));
});

test('CB4: 空振り防止 — doc から 1 件以上抽出でき、件数が定数と一致すること', function (): void {
    $section = customBinderDocSection(customBinderArchitectureMarkdown());
    Assert::string($section, 'CUSTOM_BINDER 同期マーカーがちょうど 1 組でない (CB1 を先に直すこと)');

    $docParams = customBinderDocParams($section);

    expect(count($docParams))->toBeGreaterThanOrEqual(1);
    expect(count($docParams))->toBe(count(RouteBindingTypes::CUSTOM_BINDER));
});

test('CB5 正のコントロール: 定数にだけある key を forward 違反として検出すること', function (): void {
    $violations = customBinderDocSyncViolations(['organization', 'passkey'], ['organization']);

    expect($violations)->toContain(
        'CB2: CUSTOM_BINDER の `{passkey}` が docs/architecture.md の同期マーカー範囲に無い (追加時は 1 行追記すること)',
    );
});

test('CB6 正のコントロール: doc にだけある param を reverse 違反として検出すること', function (): void {
    $violations = customBinderDocSyncViolations(['organization'], ['organization', 'ghost']);

    expect($violations)->toContain(
        'CB3: docs/architecture.md の `{ghost}` が CUSTOM_BINDER に無い (削除時は doc の行も消すこと)',
    );
});

test('CB7 負のコントロール: マーカー外の `{ghost}` は照合対象にならないこと', function (): void {
    $markdown = <<<'MD'
        散文の中で `{ghost}` に言及している (マーカー外なので照合対象にならない)。

        <!-- CUSTOM_BINDER:BEGIN -->
        - `{organization}` — MembershipScopedOrganizationBinder
        <!-- CUSTOM_BINDER:END -->

        こちらも範囲外の `{phantom}`。
        MD;

    $section = customBinderDocSection($markdown);
    Assert::string($section);

    expect(customBinderDocParams($section))->toBe(['organization']);
    expect(customBinderDocSyncViolations(['organization'], customBinderDocParams($section)))->toBe([]);
});

test('CB1 負のコントロール: マーカーが無い / 二重にあると section を取り出せないこと', function (): void {
    expect(customBinderDocSection('マーカーの無い文書'))->toBeNull();
    expect(customBinderDocSection(
        CUSTOM_BINDER_DOC_BEGIN."\na\n".CUSTOM_BINDER_DOC_END."\n".CUSTOM_BINDER_DOC_BEGIN."\nb\n".CUSTOM_BINDER_DOC_END,
    ))->toBeNull();
    // 終端が先に来る (順序が壊れた) ケースも取り出せない
    expect(customBinderDocSection(CUSTOM_BINDER_DOC_END."\na\n".CUSTOM_BINDER_DOC_BEGIN))->toBeNull();
});
```

### docs/template-divergence.md の冒頭 (現行の規約節と記入例)

```markdown
# テンプレート差分レジストリ

テンプレート(laravel-claude-template)の構造から**意図的に逸脱**した箇所の正本記録。
逸脱が正当なのは **logic-driven(ドメイン要件起因)のときだけ**。互換・UX・作業量を理由にした
逸脱は記録せず是正する(`docs/app-integration-guide.md` §0)。

## 記録の原則

- 判定軸は「ライブラリ/実装が同じか」でなく「**同じ不変条件を同じタイミング/抽象度で保証するか**」。
  不変条件が揃っていれば構文差は許容
- 各エントリには (a) なぜ logic-driven か (b) テンプレートの不変条件をどの機構で保証し続けるか
  を必ず書く

## エントリ形式

```
## D1 ✅ <逸脱の要約>

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| ... | ... | ... |

### なぜ正当な差分か(logic-driven)
...

### 揃えている不変条件(これは保証し続ける)
> 「...」
どの機構でカバーするか。drift を防ぐテスト。

### 関連
- 実装: ...
- テンプレート側の根拠: ...
```

---

## D1 ✅ Tier B スキーマの先取り (Cut / Take を振る舞い無しで先行作成)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
```

### docs/template-divergence.md の代表エントリ (現行 D14)

```markdown
## D14 ✅ 実行済み route の記録をアプリ側の観測器で採る (退避 → 正規化 → route 名解決の 3 段を置かない)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 「どの操作を叩けたか」の採取 | ブラウザの通信履歴を退避 → 正規化器 → artisan コマンドで route 名解決、の 3 段 | **serve のプロセス内で middleware が 1 要求 1 行を追記**する (`BughuntExecutedRouteMiddleware`) |
| 採取の起動 | 走行中の LLM (探索エージェント) が退避コマンドを呼ぶ | 起動時に `provision` が env で仕込み、以後は無条件 |
| 遮断された要求の扱い | 通信履歴なので 302/403 も「叩いた」側に残り、後段で除外しきれない | 遮断 middleware より**内側**に置いてあるため、そもそも記録に現れない |
| 主入力が欠けたとき | 照合器が「全 in_scope を未実行 candidate」として出力し 0 で終わる | **終了コード 3 で落ちる** (worklist を出さない) |

### なぜ正当な差分か(logic-driven)

操作到達カバレッジの出力は「次に何を叩くべきか」という作業指示であり、
**記録が採れていないこと**と**本当に叩けていないこと**を取り違えると、
一覧そのものが嘘になる (全機構が未実行に見える)。3 段方式はこの取り違えを
2 か所で作っていた:

1. **採取の起動が LLM に依存する**。退避コマンドを呼び忘れた走行は、
   記録が空のまま「全部未実行」として成功終了する。
2. **通信履歴は遮断された要求も含む**。認証・課金ゲート・step-up 再認証で
   跳ねた 302 は「叩いた」ように見えるが、controller には到達していない。
   route 名の再解決は URL からの逆引きなので、この差を後段では復元できない。

アプリ側の観測器は、web グループの**末尾** (priority list の鎖の最後) に置くことで
「ここに到達した = 遮断 middleware をすべて通過した」という機械的事実を得る。
route 名は `$request->route()->getName()` でその場で確定するので逆引きも要らない。
起動は `scripts/bug-hunt-shard.sh provision` が env で仕込むため LLM の手順に依存しない。

### 揃えている不変条件(これは保証し続ける)

> 「**主入力が揃わない走行は成功にしない**」

- `scripts/bug-hunt-shard.sh provision` は疎通確認の要求が実際に記録されたことを
  同期点として確認し、記録されなければ**走行前に**落ちる (`assert_executed_capture_wired`)
- `coverage/build_executed.py` は失敗マーカー / ファイル欠落 / 壊れた行 / 別 run の混入 /
  **名前付き route の観測行が 0** のいずれでも**終了コード 3** で落ち、`executed.json` を
  書き出さない (`route_name: null` の行しか無い shard もここで落ちる)
- `coverage/correlate.py` は `--executed` 未指定 / 形が契約外 / run_id 不一致 /
  shard 宣言と実測の食い違い / 観測行 0 のいずれでも**終了コード 3** で落ち、
  未実行 worklist を出力しない
- 記録器が遮断 middleware より内側に居ることは
  `tests/Architecture/BughuntExecutedRouteOrderingTest.php` が deny-by-default で固定する
  (短絡しうる middleware の分類は `tests/Support/Routing/MiddlewareShortCircuitInventory.php`)
- 記録器が既定 no-op であること (env 既定 false + production 除外) と ok/blocked の写像は
  `tests/Feature/Bughunt/ExecutedRouteCaptureTest.php` が実 HTTP 要求で固定する

### 保証しないもの (誇張しない)

- **web グループ外は観測しない** (`api/*` / Filament `/admin` / MCP)。分母に載っていれば
  未実行側へ倒れる (過小申告の方向)
- **部分欠測は検出しない**。分かるのは「名前付き route の行が 1 件も無い」「別 run が混ざった」
  「失敗マーカーが残せた」まで
- **偽造耐性は無い**。記録ファイルは worktree 内にあり、書き換えを検出する仕組みは持たない

### 関連

- 実装: `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `config/bughunt.php` /
  `bootstrap/app.php` / `scripts/bug-hunt-shard.sh` /
  `.claude/skills/app-bug-hunt/coverage/build_executed.py` /
  `.claude/skills/app-bug-hunt/coverage/correlate.py`
- 設計: `devnotes/20260815-1113-bughunt-route-capture-failclosed/`

---

## D15 ✅ strict_types gate の走査域を追跡下 PHP 全数にし、未宣言一覧を持たない
```
