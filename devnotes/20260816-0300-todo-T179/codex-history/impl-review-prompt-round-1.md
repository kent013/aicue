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
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。


## あなたの役割

Laravel + Svelte アプリの改善実装をレビューするコードレビュアー。

本タスクはドキュメント (テンプレートからの逸脱の登録簿) の統一形式への移行と、
その形式を機械で見る検査 (テスト層のみ) の新設である。app/ resources/ routes/ database/ config/ は
1 行も触っていない。

## レビュー観点

1. **設計との一致性** — 詳細設計書 (末尾に「実装時に設計から変えた点」を追記済み) と実装が一致するか。
   設計から外れた箇所が設計側の追記で説明されているか
2. **検出器としての正確性** — 解析器と判定器が、規約が禁じている形を実際に検出できるか。
   **黙って通してしまう抜け道**が無いか (囲みの扱い / 表の解析 / 日付 / 件数の 3 点一致 / 対象パスの重複)
3. **fail-closed** — 解析不能・読み取り不能を緑にする経路が無いか
4. **テスト網羅性** — 負例が規則ごとに 1 本以上あるか。正のコントロールと負のコントロールが揃っているか
5. **PHPStan 適合性** (tests/ は解析対象外だが型は明示しているか) / **不要な複雑さ**
6. **保証範囲の記述** — 「保証しないもの」の宣言が誇張になっていないか (実装より強い保証を書いていないか)
7. **日本語の質** — 造語を作っていないか。初見の人が辞書どおりの意味で読んで解釈できるか

## 出力形式

- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で書く


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

## 変更の範囲 (スコープ宣言)

触るのは **`docs/` / `devnotes/` / `AGENTS.md` / `tests/`** の 4 つだけである。
`app/` `routes/` `database/` `config/` `resources/` `.claude/settings.json` は 1 行も触らない。
判断ログは設計・調査の中間成果物なので `devnotes/` に置く (AGENTS.md の運用どおり)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 登録簿の規約節を統一形式へ書き換える | `docs/template-divergence.md` | 高 |
| 2 | 解消済み D9 の削除 | `docs/template-divergence.md` | 高 |
| 3 | 残る 17 件へ 9 行の登録メタ表を新設し、見出しから印を外す | `docs/template-divergence.md` | 高 |
| 4 | 形式検査の新設 (純粋ロジック層 + 薄い検査層 + 負例の単体テスト) | `tests/Support/TemplateDivergence/*` (新規 6), `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` (新規), `tests/Unit/Architecture/DivergenceLedgerRulesTest.php` (新規) = **新規 8 ファイル** | 高 |
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
| 根拠 | `T<n>` (3 桁以上のゼロ埋め。`docs/TODO.md` / `docs/TODO-closed.md` の表に実在) または `devnotes/<dir>/` (ディレクトリが実在) |
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
- **削除した番号の再利用**は検出できない (使用済み番号の履歴を持たないため。
  再利用しないことは人が守る規約である)

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

**対象パス欄の書式**: バッククォート囲みのパスを ` / ` (半角スペース + スラッシュ + 半角スペース) で
つなぐ形だけを許す (`` `a/b.php` / `c/d.php` ``)。バッククォートの外に空白以外の文字があれば違反にする
(説明を添えられると抽出が曖昧になり、取りこぼす側に倒れるため)。
実在判定は **`is_file()`** で行う (ディレクトリを通さない)。

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
| D4 | `app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php` / `routes/web.php` | `恒久` | `T001` |
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

### 変更箇所 (新規 8 ファイル = Support 6 + テスト 2)

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

1. **囲みコード区画を先に落とす**。台帳で使ってよい囲みは
   **行頭のバッククォート 3 個ちょうど** (` ``` `) だけとし、これでトグルする。
   閉じずに終端したら `P1: 囲みコード区画が閉じていない (解析不能)` を返して
   **それ以上解析しない** (fail-closed)。
   **バッククォート 4 個以上で始まる行と `~~~` で始まる行は違反にする**
   (Markdown の囲み文法を全部は実装しない。実装しないものを黙って読み飛ばすと、
   4 個の囲みで登録を隠せる回避口になるため、書式そのものを 1 種類に絞って明示的に拒否する)
2. **明示行**: 区画外で `^登録エントリ: (\d+) 件$` に一致する行。**ちょうど 1 本**でなければ違反
3. **登録エントリ領域**: 区画外の最初の `^## D` 行から文書末尾まで
4. **見出し**: 領域内の `^## ` 行は次の 2 つを**両方**満たさなければ違反。id の重複も違反
   - `/^## D([1-9]\d*) (\S.*)$/u` に**行全体一致**する
   - 要約に**印と解消を表す語を含まない** — Unicode の「その他の記号」(`\p{So}`。`✅` はここに入る) と
     矢印 `→`、および `解消` / `済み` の 2 語を禁じる。
     **`\p{S}` 全体は禁じない** (見出しに `+` を使っている登録が実在し、正当な書き方だからである)。
     `保留` のような語は禁じない (D7 が正当に使っている)
   - この 2 段にする理由: 行全体一致だけでは `## D1 ✅ Tier B …` が
     「要約が `✅ Tier B …` である正準形」として**通ってしまう** (Codex Round 1 の Critical 指摘。
     設計時点で実際に見落としていた)
5. **メタ表**: 見出しの次の非空行から始まり、`| 行 | 内容 |` のヘッダ・`|---|---|` の区切り・
   **9 本のデータ行**で構成される。ラベル列が規定の 9 語と**順序ごと一致**しなければ違反
   (過不足・順序違いは「台帳を解釈できない」= 不合格)
6. セル値は `|` で分割して trim する。**セルの中に `|` を書いてはならない** (エスケープした
   `\|` も含めて禁止) — 分割後の要素数がちょうど 4 個 (先頭の空・ラベル・値・末尾の空) で
   なければ違反にする。エスケープを解釈する解析器を持たないことを規約側にも書く
   (自由記述で表の区切りを使いたくなったら、その内容は本文の節へ書く)

### 判定の仕様 (`DivergenceLedgerRules`)

| 記号 | 規則 | 出典 |
|---|---|---|
| TD1 | 見出しは正準形・id は一意 | i4 |
| TD2 | メタ表は 9 行ちょうど・ラベルは規定の順序 | i2 |
| TD3 | 対象パスは 1 件以上・リポジトリ相対・glob/絶対/`..` 不可・**`is_file()` で実在**。セルはバッククォート囲みのパスを ` / ` でつないだ形だけを許し、バッククォートの外に空白以外の文字があれば違反 | i5 |
| TD4 | 全登録の対象パスの和集合で重複しない | i5 |
| TD5 | 状態は `恒久` / `監視中` の 2 語 | i3 |
| TD6 | `監視中` は見直し期限必須・実在する日付・**基準日以降** (基準日当日は合格・前日は期限切れで不合格)・**基準日から 400 日以内** (401 日後は不合格)。**期限は未来を向く欄であり、決めた日 (過去を向く) と時間の向きが逆である** | i8 |
| TD7 | `恒久` の見直し期限は `—` ちょうど | i8 |
| TD8 | 決めた日は実在する日付で**未来日不可** | i7 |
| TD9 | 決めた人は `オーナー` / `開発者` の 2 語 | i10 |
| TD10 | 根拠は `T\d{3,}` (TODO 台帳の表のセルとして実在) か `devnotes/<dir>/` (`is_dir` で実在)。プレースホルダ不可 | i7 |
| TD11 | 業務要件起因の説明 / 揃え続ける不変条件と保証機構 / 再判定の条件が空でない・プレースホルダでない | i9 |
| TD12 | 明示件数・解析件数・固定件数の 3 点一致 | i6 |

**解析時の違反は必ず伝播させる**。`DivergenceLedgerRules::violations()` は先頭で
`ParsedLedger::$parseViolations` を取り込み、**解析不能 (囲みコード区画が閉じていない /
登録エントリ領域が見つからない) のときはそこで打ち切って返す** (以降の規則は評価できないため、
評価しないことを違反として返す = fail-closed)。握り潰す経路を作らない。

- **プレースホルダの語彙**: `...` / `…` / `TBD` / `未定` / `-` / `—` / 空文字。
  過剰検出寄りに倒す (正典 i15)。**適用先は TD10 (根拠) と TD11 (自由記述 3 欄) だけ**である —
  見直し期限の `—` は `恒久` の正値なので、期限欄にはプレースホルダ検査を掛けない (TD6/TD7 だけを掛ける)
- **根拠の実在判定は境界付きで行う**。`T\d{3,}` は `docs/TODO.md` / `docs/TODO-closed.md` の
  **表のセルとして** (`/^\|\s*T0*\d+\s*\|/m` に `preg_quote` した値を埋めた形で) 照合する。
  素の `str_contains` は `T1` が `T10` に一致して通るので使わない。
  `devnotes/<dir>/` は **`is_dir()`** で別に判定する (ファイルの実在判定と混ぜない)
- **日付は往復で検証し、解析失敗を型で締める**。`createFromFormat()` は失敗時に `false` を返しうるので、
  戻り値を型で確かめてから往復比較する (`Carbon::parse()` は `2026-02-30` を 3/2 へ正規化して通す)。
  例外に倒さず**違反一覧へ入れる**:

  ```php
  /** `YYYY-MM-DD` として実在する日付だけを受け取る (失敗は null を返し、呼び出し側が違反にする)。 */
  private static function parseDate(string $value): ?CarbonImmutable
  {
      $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

      if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
          return null;
      }

      return $date;
  }
  ```
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
     * @param  Closure(string): bool  $pathExists  リポジトリ相対の**ファイル**の実在判定 (is_file)
     * @param  Closure(string): bool  $directoryExists  リポジトリ相対の**ディレクトリ**の実在判定 (is_dir)
     * @param  Closure(string): bool  $rationaleExists  根拠 (T 番号) が TODO 台帳の表に実在するか
     */
    public function __construct(
        public CarbonImmutable $baseDate,
        public int $pinnedEntryCount,
        public Closure $pathExists,
        public Closure $directoryExists,
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
        pathExists: fn (string $path): bool => is_file(base_path($path)),
        directoryExists: fn (string $path): bool => is_dir(base_path($path)),
        // T 番号は TODO 台帳の表のセルとして境界付きで照合する (T1 が T10 に一致しないように)
        rationaleExists: fn (string $ref): bool => preg_match(
            '/^\|\s*'.preg_quote($ref, '/').'\s*\|/mu',
            templateDivergenceTodoSources(),
        ) === 1,
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
- [ ] 負例 TD6b: 見直し期限が基準日から **401 日後**だと落ちること
- [ ] 負例 TD6c: 見直し期限が**基準日の前日** (期限切れ) だと落ちること
- [ ] 負例 TD6d: 見直し期限が空文字 / `not-a-date` / `2026-02-30` だと
      **例外ではなく違反一覧に入って**落ちること
- [ ] 正例 TD6e (境界): 見直し期限が**基準日当日** / **基準日の翌日** / **基準日から 400 日後**の
      3 つはいずれも合格すること (**期限は未来日が正常値である**。決めた日と時間の向きが逆なので
      同じ負例を共用しない)
- [ ] 負例 TD7: `恒久` に日付の見直し期限が書いてあると落ちること
- [ ] 負例 TD8: 決めた日が**基準日の翌日** (未来日) / `2026-02-30` / 空文字 / `not-a-date` だと
      **例外ではなく違反一覧に入って**落ちること
- [ ] 正例 TD8b (境界): 決めた日が**基準日当日**は合格すること
- [ ] 負例 TD9: 決めた人が `チーム` だと落ちること
- [ ] 負例 TD10a: 根拠が実在しない `T9999` だと落ちること
- [ ] 負例 TD10b: 根拠が `TBD` だと落ちること
- [ ] 負例 TD11: 再判定の条件が空 / `...` だと落ちること
- [ ] 負例 TD12a: 明示件数と解析件数が食い違うと落ちること
- [ ] 負例 TD12b: 固定件数と食い違うと落ちること (増えても減っても)
- [ ] 負例 TD10c: `T1` が `T10` の登録に一致して通らないこと (境界付き照合の正のコントロール)
- [ ] 負例 TD10d: 根拠に実在しない `devnotes/9999-nope/` を書くと落ちること
- [ ] 負例 TD3d: 対象パスにディレクトリを書くと落ちること (`is_file` であることの確認)
- [ ] 負例 TD3e: 対象パスのセルにバッククォート外の説明文を添えると落ちること
- [ ] 負例 P1: 囲みコード区画が閉じていない fixture が「解析不能」で落ち、**以降の規則を評価せずに返すこと**
- [ ] 負例 P2: 登録エントリ領域が見つからない (見出しが 1 件も無い) fixture が落ちること
- [ ] 負例 P3: バッククォート 4 個の囲み / `~~~` の囲みが**明示的に拒否**されること
      (黙って読み飛ばさないこと)
- [ ] 負例 P4: メタ表のセルに `|` や `\|` を書くと違反になること
- [ ] 負のコントロール: **囲みコード区画の中の記入例 (`## D1 <要約>`) を登録として数えないこと**
- [ ] 負のコントロール: 登録エントリ領域より**前**の `## 記録の原則` 等の節は違反にならないこと
- [ ] 実挙動: 現物の `docs/template-divergence.md` が違反 0 件であること (Architecture レーン)
- [ ] 期限判定は固定日を渡して検証する (実行日で揺れないこと)
- [ ] TD6e の 3 ケースをデータセットでまとめる場合も、**各ケースが独立して実行・報告される**こと
      (1 本にまとめて最初の失敗で止まる形にしない)
- [ ] 日付の比較の前に `parseDate()` の `null` を必ず分岐すること (実装レビューの確認点)

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

**根拠の表記**: TODO 台帳の実表記に合わせて **3 桁以上のゼロ埋め** (`T001` / `T099` / `T164`) を使う。
検査は `T\d{3,}` を TODO 台帳の表のセルとして照合する。

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
  **書式の中身は写さない** (2 か所に書くと必ず食い違う)。
  `AGENTS.md` は本作業のスコープ宣言に含まれる編集対象である (app/ 等の実装層は触らない)

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

---

## 実装時に設計から変えた点 (T179 実装セッションで追記)

設計は 2026-08-15 20:59 時点の現物を前提にしていた。実装開始時に読み直したところ差があったので、
**設計側を先に直してから実装した**。変えたのは次の 5 点である。

1. **件数が 17 件ではない**。設計後に別の変更が `D19` (経路キャッシュ起動での後付け) と
   `D20` (bug-hunt 目録の生成方式) を足していたため、移行対象は 20 件、`D9` を消して 19 件、
   施策 5 で 4 件を足して**最終 23 件**になった。検査側の固定件数も 23 である。
2. **施策 5 の候補 1 は既に登録済みだった**。設計の候補表の 1 行目
   (経路キャッシュ起動での後付け) は現 HEAD で `D19` として登録されていたので、
   判定の対象は 6 件になった。決着は登録 4 件 / 逸脱ではない 1 件 / 差し戻し 1 件で、
   判断ログは `devnotes/20260816-0300-todo-T179/unregistered-divergence-triage.md`。
3. **新規ファイルは 8 本ではなく 9 本**。根拠の T 番号を TODO 台帳の表のセルとして
   境界付きで照合する処理を `tests/Support/TemplateDivergence/TodoLedgerReference.php` へ
   切り出した。検査層のクロージャの中に置くと**負例 TD10c (`T1` が `T10` に一致しないこと) を
   単体テストで固定できない**ため、判定を純粋ロジック層側へ出す設計の方針に合わせた。
4. **`D14` の見出しを言い換えた**。旧見出しは「実行済み route の記録を … (退避 → 正規化 →
   route 名解決の 3 段を置かない)」で、検査が禁じる矢印と `済み` を含んでいた。
   検査を緩めるのではなく見出しの側を「実行した route の記録を … (退避と正規化と route 名解決の
   3 段を置かない)」へ直した (誤検出は 1 行で直せる、という過剰検出寄りの原則どおり)。
   番号での参照 (`AGENTS.md` の D14) は壊れない。
5. **移行は一度きりの変換器で行った** (`devnotes/20260816-0300-todo-T179/migrate-ledger.py`)。
   19 件 × 9 行を手で書き写すと取りこぼすため、値だけを表に持って機械で差し込んだ。
   変換器は再実行を想定していない (移行後の台帳を入力にすると見出しに一致しない)。


## 未登録の逸脱候補の判断ログ

# 未登録の逸脱候補の判定 (T179 施策 5)

過去の巡回 (機能台帳 lctl の受信箱に届いている差分巡回の所見) が「記録されるべきなのに未登録」と
挙げた候補を、現 HEAD で 1 件ずつ確認して決着させた記録である。

判定の規則は概念設計のとおり。**既定は「登録する」か「差し戻す」**で、「逸脱ではない」と
結論してよいのは (a) 台帳リポジトリから届いた判定 / (b) 確定した正典がテンプレートの管理範囲を
定めている / (c) 指摘元が分類を認めている、のいずれかを根拠にできるときだけである
(本アプリの中に文書を書いて自己証明する形は認めない)。

判定に使った巡回の所見は受信箱の次の 3 件である。

- 差分巡回 2026-08-09 (観測点 c8ed20e): 候補 1・2
- 差分巡回 2026-08-10 (観測点 4e19995): 候補 3・4
- 差分巡回 2026-08-12 (観測点 29141ac): 候補 5・6・7

## 判定の一覧

| # | 候補 | 結論 | 登録先 |
|---|---|---|---|
| 1 | 経路キャッシュ起動での後付けを「走らせない」側で維持したこと | **登録済み** (本作業の前に完了) | D19 |
| 2 | 走査基盤に構文解析ライブラリでなく字句 (トークン) 走査を採り続けていること | **差し戻す** | — |
| 3 | bug-hunt の自己検証の実行配線を CI ステップでなくテストの配線へ置いたこと | **登録する** | D21 |
| 4 | worktree へ供給する秘密ファイルを作成時モード 0600 で置くこと | **逸脱ではない** | — |
| 5 | 凍結方式の退会 | **登録する** | D22 |
| 6 | 課金記録の保持期間 7 年の仕組み | **登録する** | D23 |
| 7 | SSO の差し替え点の切り出し | **登録する** | D24 |

## 1. 経路キャッシュ起動での後付け (登録済み)

現 HEAD には既に `## D19` として登録がある (本作業の直前に別の変更が入れた)。
対象パスは `app/Support/Http/RouteMiddlewareBinder.php` と
`app/Support/Http/RouteThrottleBinder.php` で、本作業では書式の移行だけを行った。

**設計時点 (2026-08-15 20:59) の見積もりでは未登録だった**。実装開始時に現物を読み直したことで
判明したもので、設計の「実装開始時に現物を読み直して件数を数え直す」に従った結果である。

## 2. 字句走査を採り続けていること (差し戻す)

- 現 HEAD で生きているか: 生きている (`tests/Support/PhpTokenScan.php` /
  `tests/Support/PhpReferenceScanner.php` が実在し、複数の目録型テストが両者に乗っている)
- 既存登録に覆われているか: 覆われていない (どの登録の対象パス欄にも無い)
- **差し戻す理由**: 登録メタ表の「決めた日」を証拠から転記できない。
  この形は 1 つの変更で決まったものではなく「採り続けている」ものであり、
  走査の共通化を入れた変更 (T138 の外部到達点の目録) の設計文書を実読しても、
  **構文解析ライブラリと字句走査のどちらを採るかを論じた記述が無い**。
  対応する作業項目の行にも当該の判断は書かれていない。
  規約が禁じる「台帳へ登録した日で代用する」形を避けるため、日付を発明せず差し戻す。
- 差し戻し先: 監督セッション経由で台帳リポジトリへ。必要な材料は
  「この形をいつ・誰が・どの文書で決めたか」と「正典が構文解析ライブラリを求めていること」の 2 つである。

## 3. bug-hunt の自己検証の実行配線 (登録する → D21)

- 現 HEAD で生きているか: 生きている (`tests/Architecture/BughuntSelfTestExecutionTest.php`)
- 巡回の所見が「他 3 リポジトリは CI ステップ版」と**比較対象を明示している**ため、
  テンプレートと形が違うことの根拠になる (自己証明ではない)
- 決めた日: T142 の完了日 2026-08-10。作業項目の行に
  「self-test をどこも自動実行していなかったので composer test の配線に載せ」と
  **当該の判断がそのまま書かれている**ので、設計文書が無くても転記できる
- 決めた人: 開発者 (設計フローの中で決めた。オーナーの指示も家系の裁定も根拠に無い)

## 4. worktree の秘密ファイルの作成時モード (逸脱ではない)

- 現 HEAD で生きているか: 生きている (`scripts/setup-worktree.sh` +
  `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php`)
- **逸脱ではないと結論できる根拠**: 受信箱の `worktree-task-isolation` の項が
  「**AG-153 で還流承認された側**」と明記しており、本アプリのこの形が家系の標準形 t1 として
  取り込まれている。つまりテンプレートから外れた形ではなく、**テンプレートへ配られた側**である。
  T170 はその標準形 t1 への追従として範囲を広げた作業だった。
  根拠は (a) 台帳リポジトリから届いた判定であり、自己証明ではない。

## 5. 凍結方式の退会 (登録する → D22)

- 現 HEAD で生きているか: 生きている
  (`app/Http/Middleware/EnsureAccountNotPendingDeletion.php` /
  `app/Http/Controllers/Settings/AccountDeletionRequestController.php`)
- 決めた日: 2026-08-09。設計 `devnotes/20260809-0908-account-deletion-grace/` の冒頭が
  **オーナー決定 (猶予 30 日 / 保持 7 年 / 凍結方式) を明記**しており、その文書の中で
  当該の逸脱が決まっていることを確認できる
- 決めた人: オーナー (同上)
- 根拠: 設計ディレクトリを使う。作業項目の番号は同じ `T142` が 2 行あって一意に指せないため

## 6. 課金記録の保持期間 7 年 (登録する → D23)

- 現 HEAD で生きているか: 生きている (`app/Enums/Billing/BillingRetentionTarget.php` +
  `app/Services/Billing/Retention/` の掃除器群 + 検査 2 本)
- 決めた日・決めた人・根拠: 候補 5 と同じオーナー決定の文書 (PR-C 側)
- 候補 5 と別の登録にするのは、**対象パスが交わらず、解消の条件も別**だからである
  (退会の表現と、課金記録の寿命は別の判断)

## 7. SSO の差し替え点の切り出し (登録する → D24)

- 現 HEAD で生きているか: 生きている (`app/Services/Auth/SocialiteDriverResolver.php`)
- 決めた日: 2026-08-11。設計 `devnotes/20260811-1736-bughunt-sso-egress/` が
  「driver 解決点の切り出し + 既存 fake 配線への相乗り」を採用案として決めている
- 決めた人: 開発者
- 根拠: T153 (作業項目の行に「Socialite の driver 解決点を自前クラスへ切り出して fake を
  差し替えた」と書かれている)
- **再判定の条件に残した含み**: 家系の外部到達点の標準形 (v1) は SSO を 1 クラスへ名指し固定する
  形を既に持っており、正典がこの解決点の形まで定めていると読める余地がある。
  そう確定したときは本登録を消す (再判定の条件に書いた)。過剰検出の側に倒して登録しておき、
  不要と分かれば 1 行消すほうが、検出漏れより回復しやすい。

## 監督セッションへの報告事項

- **差し戻し 1 件** (候補 2 = 字句走査)。台帳への書き込みは行っていない。
- 候補 1 は本作業の開始時点で既に登録済みだった (設計時点の見積もりとの差)。
- 候補 4 は還流承認済みのため登録しない。台帳側の候補一覧から外してよいかの確認を求める。


## 実装差分 1: 検査層と規約の参照先 (tests/ / AGENTS.md / docs/app-integration-guide.md)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 9f85b8f..9495da3 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -442,6 +442,10 @@ ## テンプレートとの関係
 (バージョンは `config/template.php` の `template_version`)。
 テンプレート構造からの**意図的な逸脱**は `docs/template-divergence.md` に
 logic-driven な理由と「保証し続ける不変条件」を記録してから行う。
+**書式の正本は同ファイルの規約節**で、形式は
+`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する
+(登録メタ表の 9 行・状態の値域・対象パスの実在と重複・件数の 3 点一致)。
+書式の中身は本書に写さない (2 か所に書くと必ず食い違う)。
 
 ## ドメイン固有規約
 
diff --git a/docs/app-integration-guide.md b/docs/app-integration-guide.md
index 19722ea..3e088a4 100644
--- a/docs/app-integration-guide.md
+++ b/docs/app-integration-guide.md
@@ -11,8 +11,9 @@ ## 0. 大原則
    場合、要件を不変条件の上に再設計する(例外を作らない)。
 2. **変えてよい層と変えてはいけない層を区別する**(§1 の表)。
 3. **テンプレートからの構造的逸脱は `docs/template-divergence.md` に記録してからやる**。
-   aigenba/spirux 間の divergence registry と同じ規律: 逸脱が正当なのは logic-driven
-   (ドメイン要件起因)のときだけ。互換・UX・作業量を理由にした逸脱は不可。
+   逸脱が正当なのは logic-driven(ドメイン要件起因)のときだけ。互換・UX・作業量を
+   理由にした逸脱は不可。**書式の正本は `docs/template-divergence.md` の規約節**で、
+   形式は `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 4. **フレームワークのレンジ内でやる**。自前機構を発明する前に Laravel / 同梱モジュールの
    公式の作法で実現できないか確認する。
 
@@ -535,7 +536,7 @@ ## 8. 設計ドキュメントの書き方(このテンプレ上の流儀)
 1. 概念設計 → レビュー → 詳細設計 → レビュー(app-design スキルのフロー)
 2. 設計には必ず「**テンプレートのどの構造に何をマップしたか**」の節を設ける
    (§2〜§6 の判定結果を表で明記。判定に迷った項目は理由ごと残す)
-3. テンプレ構造から逸脱する場合は `docs/template-divergence.md` に
-   aigenba/spirux divergence registry と同じ形式(なぜ logic-driven か・どの不変条件を
-   どの機構で保証し続けるか)で記録する
+3. テンプレ構造から逸脱する場合は `docs/template-divergence.md` に記録する。
+   **書式の正本は同ファイルの規約節**(登録メタ表 9 行・状態の値域・件数の明示行)で、
+   登録は逸脱を作る変更そのものに含める
 4. 中間成果物は `devnotes/YYYYMMDD-HHMM-<topic>/` に置く
diff --git a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
new file mode 100644
index 0000000..d7d9698
--- /dev/null
+++ b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
@@ -0,0 +1,80 @@
+<?php
+
+declare(strict_types=1);
+
+use Carbon\CarbonImmutable;
+use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
+use Tests\Support\TemplateDivergence\DivergenceLedgerRules;
+use Tests\Support\TemplateDivergence\LedgerContext;
+use Tests\Support\TemplateDivergence\TodoLedgerReference;
+use Webmozart\Assert\Assert;
+
+/*
+ * 逸脱の登録簿 (`docs/template-divergence.md`) が家系の統一形式を満たすことを検査する。
+ *
+ * 判定の実体は `DivergenceLedgerRules` (純関数) にあり、本テストは
+ * **実ファイルを読んで文脈を組み立て、違反が空であることを見るだけ**の薄い層である。
+ * 負例 (検出器が実際に検出できること) は
+ * `tests/Unit/Architecture/DivergenceLedgerRulesTest.php` が固定する。
+ *
+ * **この検査が保証しないもの** (誇張しない):
+ *  - 実ファイルがテンプレートから逸脱したのに登録が無いこと (登録漏れそのもの)。
+ *    実体との突合は台帳リポジトリの巡回が行う (家系の裁定 AG-159)
+ *  - 内容をテンプレート準拠へ戻したのに残っている登録 (対象パスは実在し続けるため)
+ *  - 登録の中身が正しいこと (空でないこと・値域に収まっていることだけを見る)
+ *  - 削除した番号の再利用 (使用済み番号の履歴を持たないため)
+ *
+ * 実行不能 (台帳が読めない / 囲みが閉じない / 登録エントリ領域が無い) は
+ * skip でも緑でもなく**不合格**にする。
+ */
+
+/**
+ * 登録件数の固定値。
+ *
+ * **明示件数との同期検査であって、例外を許す一覧ではない**。個別の D 番号を名指しして
+ * 規則を免除する仕組みは持たない。登録を足した / 消したら同じ変更でこの値も直す。
+ */
+const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 23;
+
+/** 逸脱の登録簿の本文 (読めないことは不合格)。 */
+function templateDivergenceMarkdown(): string
+{
+    $markdown = file_get_contents(base_path('docs/template-divergence.md'));
+    Assert::string($markdown, 'docs/template-divergence.md を読めない');
+
+    return $markdown;
+}
+
+/** 根拠の照合先 (TODO 台帳の Open と Closed の両方)。 */
+function templateDivergenceTodoSources(): string
+{
+    $open = file_get_contents(base_path('docs/TODO.md'));
+    $closed = file_get_contents(base_path('docs/TODO-closed.md'));
+    Assert::string($open, 'docs/TODO.md を読めない');
+    Assert::string($closed, 'docs/TODO-closed.md を読めない');
+
+    return $open."\n".$closed;
+}
+
+test('TD0: 逸脱の登録簿を読めること (実行不能は不合格)', function (): void {
+    expect(trim(templateDivergenceMarkdown()))->not->toBe('');
+    expect(trim(templateDivergenceTodoSources()))->not->toBe('');
+});
+
+test('TD1〜TD12: 逸脱の登録簿が統一形式を満たすこと', function (): void {
+    $todoSources = templateDivergenceTodoSources();
+
+    $violations = DivergenceLedgerRules::violations(
+        DivergenceLedgerParser::parse(templateDivergenceMarkdown()),
+        new LedgerContext(
+            baseDate: CarbonImmutable::today(),
+            pinnedEntryCount: TEMPLATE_DIVERGENCE_ENTRY_COUNT,
+            pathExists: fn (string $path): bool => is_file(base_path($path)),
+            directoryExists: fn (string $path): bool => is_dir(base_path($path)),
+            // T 番号は TODO 台帳の表のセルとして境界付きで照合する (T1 が T10 に一致しないように)
+            rationaleExists: fn (string $reference): bool => TodoLedgerReference::existsIn($reference, $todoSources),
+        ),
+    );
+
+    expect($violations)->toBe([], "逸脱の登録簿の形式違反:\n".implode("\n", $violations));
+});
diff --git a/tests/Support/TemplateDivergence/DivergenceLedgerParser.php b/tests/Support/TemplateDivergence/DivergenceLedgerParser.php
new file mode 100644
index 0000000..cee9140
--- /dev/null
+++ b/tests/Support/TemplateDivergence/DivergenceLedgerParser.php
@@ -0,0 +1,363 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * 逸脱の登録簿 (`docs/template-divergence.md`) の Markdown を解析する (純関数)。
+ *
+ * 解析器は**取り出すだけ**で、値の妥当性は `DivergenceLedgerRules` が見る。
+ * 読み解けなかったこと (囲みが閉じない / 登録エントリ領域が無い) は
+ * 違反として返し、空集合へ落として緑にする経路は持たない (fail-closed)。
+ *
+ * Markdown の囲み文法を全部は実装しない。台帳で使ってよい囲みは**行頭のバッククォート
+ * 3 個ちょうど**だけで、バッククォート 4 個以上と `~~~` は**明示的に違反**にする
+ * (黙って読み飛ばすと、その囲みで登録を隠せる回避口になる)。
+ */
+final class DivergenceLedgerParser
+{
+    /**
+     * 登録メタ表のラベル (規定の順序)。過不足・順序違いは「台帳を解釈できない」= 不合格。
+     *
+     * @var list<string>
+     */
+    public const META_LABELS = [
+        '対象パス',
+        '業務要件起因の説明',
+        '揃え続ける不変条件と保証機構',
+        '再判定の条件',
+        '決めた日',
+        '決めた人',
+        '根拠',
+        '状態',
+        '見直し期限',
+    ];
+
+    /** 登録の見出しの正準形 (行全体一致)。 */
+    private const ENTRY_HEADING = '/^## D([1-9]\d*) (\S.*)$/u';
+
+    /** 件数の明示行。 */
+    private const DECLARED_COUNT = '/^登録エントリ: (\d+) 件$/u';
+
+    public static function parse(string $markdown): ParsedLedger
+    {
+        $scan = self::outsideFenceLines($markdown);
+        $violations = $scan['violations'];
+
+        if ($scan['unclosed']) {
+            $violations[] = 'P1: 囲みコード区画が閉じていない (解析不能)。囲みは行頭のバッククォート 3 個ちょうどで開閉する';
+
+            return new ParsedLedger([], null, $violations, true);
+        }
+
+        $lines = $scan['lines'];
+
+        $declared = null;
+        $declaredHits = 0;
+        foreach ($lines as $line) {
+            if (preg_match(self::DECLARED_COUNT, $line[1], $matches) === 1) {
+                $declaredHits++;
+                $declared = (int) $matches[1];
+            }
+        }
+        if ($declaredHits !== 1) {
+            $violations[] = sprintf(
+                'TD12: 件数の明示行「登録エントリ: N 件」は囲みの外にちょうど 1 本必要 (実測 %d 本)',
+                $declaredHits,
+            );
+            $declared = null;
+        }
+
+        $regionStart = null;
+        foreach ($lines as $index => $line) {
+            if (str_starts_with($line[1], '## D')) {
+                $regionStart = $index;
+                break;
+            }
+        }
+
+        if ($regionStart === null) {
+            $violations[] = 'P2: 登録エントリ領域 (最初の `## D<n>` 見出し) が見つからない (解析不能)';
+
+            return new ParsedLedger([], $declared, $violations, true);
+        }
+
+        /** @var list<int> $headingIndexes */
+        $headingIndexes = [];
+        $total = count($lines);
+        for ($index = $regionStart; $index < $total; $index++) {
+            if (str_starts_with($lines[$index][1], '## ')) {
+                $headingIndexes[] = $index;
+            }
+        }
+
+        /** @var list<ParsedEntry> $entries */
+        $entries = [];
+        /** @var array<int, int> $seenIds id => 初出の行番号 */
+        $seenIds = [];
+
+        foreach ($headingIndexes as $position => $headingIndex) {
+            [$lineNumber, $headingText] = $lines[$headingIndex];
+
+            if (preg_match(self::ENTRY_HEADING, $headingText, $matches) !== 1) {
+                $violations[] = sprintf(
+                    'TD1: %d 行目の見出しが正準形 `## D<n> <要約>` ではない: %s',
+                    $lineNumber,
+                    $headingText,
+                );
+
+                continue;
+            }
+
+            $id = (int) $matches[1];
+            $summary = $matches[2];
+
+            foreach (self::forbiddenSummaryReasons($summary) as $reason) {
+                $violations[] = sprintf('TD1: D%d (%d 行目) の要約は%s', $id, $lineNumber, $reason);
+            }
+
+            if (isset($seenIds[$id])) {
+                $violations[] = sprintf(
+                    'TD1: D%d が重複している (%d 行目と %d 行目)。番号はリポジトリ内で一意',
+                    $id,
+                    $seenIds[$id],
+                    $lineNumber,
+                );
+            } else {
+                $seenIds[$id] = $lineNumber;
+            }
+
+            $bodyEnd = $headingIndexes[$position + 1] ?? $total;
+            $body = array_slice($lines, $headingIndex + 1, $bodyEnd - $headingIndex - 1);
+
+            $metadata = self::parseMetadata($body, sprintf('D%d (%d 行目)', $id, $lineNumber), $violations);
+
+            $entries[] = new ParsedEntry($id, $summary, $lineNumber, $metadata);
+        }
+
+        return new ParsedLedger($entries, $declared, $violations, false);
+    }
+
+    /**
+     * 囲みコード区画の外にある行だけを行番号付きで返す。
+     *
+     * @return array{lines: list<array{0: int, 1: string}>, violations: list<string>, unclosed: bool}
+     */
+    private static function outsideFenceLines(string $markdown): array
+    {
+        $split = preg_split("/\r\n|\n|\r/", $markdown);
+        Assert::isArray($split, '登録簿を行へ分割できない');
+
+        /** @var list<array{0: int, 1: string}> $lines */
+        $lines = [];
+        /** @var list<string> $violations */
+        $violations = [];
+        $inFence = false;
+
+        foreach ($split as $index => $text) {
+            Assert::string($text);
+            $number = $index + 1;
+
+            if ($inFence) {
+                if (preg_match('/^`{3}(?!`)/', $text) === 1) {
+                    $inFence = false;
+                }
+
+                continue;
+            }
+
+            if (preg_match('/^`{4,}/', $text) === 1) {
+                $violations[] = sprintf('P3: %d 行目: バッククォート 4 個以上の囲みは台帳では使えない', $number);
+
+                continue;
+            }
+
+            if (str_starts_with($text, '~~~')) {
+                $violations[] = sprintf('P3: %d 行目: `~~~` の囲みは台帳では使えない', $number);
+
+                continue;
+            }
+
+            if (str_starts_with($text, '```')) {
+                $inFence = true;
+
+                continue;
+            }
+
+            $lines[] = [$number, $text];
+        }
+
+        return ['lines' => $lines, 'violations' => $violations, 'unclosed' => $inFence];
+    }
+
+    /**
+     * 要約に印・解消を表す語が含まれていないかを見る。
+     *
+     * `\p{S}` 全体は禁じない (見出しに `+` を使っている登録が実在し、正当な書き方であるため)。
+     *
+     * @return list<string> 違反理由 (空 = 合格)
+     */
+    private static function forbiddenSummaryReasons(string $summary): array
+    {
+        /** @var list<string> $reasons */
+        $reasons = [];
+
+        if (preg_match('/\p{So}/u', $summary) === 1) {
+            $reasons[] = '印 (その他の記号) を含む。見出しは印を持たない正準形で書く';
+        }
+        if (str_contains($summary, '→')) {
+            $reasons[] = '矢印 `→` を含む。状態の遷移を見出しで表さない';
+        }
+        foreach (['解消', '済み'] as $word) {
+            if (str_contains($summary, $word)) {
+                $reasons[] = sprintf('解消を表す語「%s」を含む。解消した逸脱は登録ごと消す', $word);
+            }
+        }
+
+        return $reasons;
+    }
+
+    /**
+     * 見出しの直後にある登録メタ表を解析する。
+     *
+     * @param  list<array{0: int, 1: string}>  $body  見出しの次の行から次の見出しの手前まで
+     * @param  list<string>  $violations
+     */
+    private static function parseMetadata(array $body, string $label, array &$violations): ?EntryMetadata
+    {
+        $position = 0;
+        $count = count($body);
+        while ($position < $count && trim($body[$position][1]) === '') {
+            $position++;
+        }
+
+        if ($position >= $count) {
+            $violations[] = sprintf('TD2: %s に登録メタ表が無い', $label);
+
+            return null;
+        }
+
+        $header = self::splitRow($body[$position][1]);
+        if ($header === null || $header[0] !== '行' || $header[1] !== '内容') {
+            $violations[] = sprintf('TD2: %s の登録メタ表は `| 行 | 内容 |` のヘッダで始める', $label);
+
+            return null;
+        }
+        $position++;
+
+        $separator = $position < $count ? self::splitRow($body[$position][1]) : null;
+        if ($separator === null
+            || preg_match('/^:?-{3,}:?$/', $separator[0]) !== 1
+            || preg_match('/^:?-{3,}:?$/', $separator[1]) !== 1) {
+            $violations[] = sprintf('TD2: %s の登録メタ表にヘッダ区切り行 `|---|---|` が無い', $label);
+
+            return null;
+        }
+        $position++;
+
+        /** @var list<string> $values */
+        $values = [];
+        foreach (self::META_LABELS as $expected) {
+            $row = $position < $count ? self::splitRow($body[$position][1]) : null;
+            if ($row === null) {
+                $violations[] = sprintf(
+                    'TD2: %s の登録メタ表が 9 行に足りない (「%s」の行が読めない。セルに `|` を書いていないか)',
+                    $label,
+                    $expected,
+                );
+
+                return null;
+            }
+            if ($row[0] !== $expected) {
+                $violations[] = sprintf(
+                    'TD2: %s の登録メタ表 %d 行目のラベルが「%s」ではなく「%s」。9 行の順序は規定である',
+                    $label,
+                    count($values) + 1,
+                    $expected,
+                    $row[0],
+                );
+
+                return null;
+            }
+            $values[] = $row[1];
+            $position++;
+        }
+
+        if ($position < $count && self::splitRow($body[$position][1]) !== null) {
+            $violations[] = sprintf('TD2: %s の登録メタ表が 9 行を超えている', $label);
+
+            return null;
+        }
+
+        return new EntryMetadata(
+            targetPaths: self::extractTargetPaths($values[0]),
+            rawTargetPathCell: $values[0],
+            domainReason: $values[1],
+            invariantAndGuard: $values[2],
+            reevaluationCondition: $values[3],
+            decidedOn: $values[4],
+            decidedBy: $values[5],
+            rationale: $values[6],
+            state: $values[7],
+            reviewDeadline: $values[8],
+        );
+    }
+
+    /**
+     * 表の 1 行を 2 セルへ分割する。
+     *
+     * セルの中に `|` を書くこと (エスケープした `\|` を含む) は許さないので、
+     * 分割後の要素数がちょうど 4 個 (先頭の空・ラベル・値・末尾の空) でなければ null を返す。
+     *
+     * @return array{0: string, 1: string}|null
+     */
+    private static function splitRow(string $line): ?array
+    {
+        $trimmed = trim($line);
+        if (! str_starts_with($trimmed, '|')) {
+            return null;
+        }
+
+        $parts = explode('|', $trimmed);
+        if (count($parts) !== 4) {
+            return null;
+        }
+        if (trim($parts[0]) !== '' || trim($parts[3]) !== '') {
+            return null;
+        }
+
+        return [trim($parts[1]), trim($parts[2])];
+    }
+
+    /**
+     * 対象パス欄からパスを取り出す。
+     *
+     * 許すのはバッククォート囲みのパスを ` / ` でつないだ形だけで、
+     * バッククォートの外に空白以外の文字があれば 1 件も取り出さない
+     * (書式違反は `DivergenceLedgerRules` が生セルを見て報告する)。
+     *
+     * @return list<string>
+     */
+    private static function extractTargetPaths(string $cell): array
+    {
+        if (preg_match('/^`[^`]+`(?: \/ `[^`]+`)*$/u', $cell) !== 1) {
+            return [];
+        }
+
+        $found = preg_match_all('/`([^`]+)`/u', $cell, $matches);
+        if ($found === false || $found === 0) {
+            return [];
+        }
+
+        /** @var list<string> $paths */
+        $paths = [];
+        foreach ($matches[1] as $path) {
+            $paths[] = $path;
+        }
+
+        return $paths;
+    }
+}
diff --git a/tests/Support/TemplateDivergence/DivergenceLedgerRules.php b/tests/Support/TemplateDivergence/DivergenceLedgerRules.php
new file mode 100644
index 0000000..025664f
--- /dev/null
+++ b/tests/Support/TemplateDivergence/DivergenceLedgerRules.php
@@ -0,0 +1,352 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use Carbon\CarbonImmutable;
+use Carbon\Exceptions\InvalidFormatException;
+
+/**
+ * 逸脱の登録簿の形式違反を列挙する (純関数)。
+ *
+ * **保証しない範囲** (誇張しない):
+ *  - 実ファイルがテンプレートから逸脱したのに登録が無いことは検出しない
+ *    (実体との突合は台帳リポジトリの巡回が持つ。家系の裁定 AG-159)
+ *  - 内容がテンプレート準拠へ戻った登録の残置も検出しない (対象パスは実在し続けるため)
+ *  - 登録の中身が正しいことは見ない (空でないこと・値域に収まっていることだけを見る)
+ *  - 登録エントリ領域より前の節と、エントリの中の `###` 見出し・地の文は見ない
+ *  - 削除した番号の再利用は検出しない (使用済み番号の履歴を持たないため)
+ *
+ * 固定件数 (`LedgerContext::$pinnedEntryCount`) は**明示件数との同期検査**であって、
+ * 例外を許す一覧ではない。個別の D 番号を名指しする許可一覧は持たない。
+ */
+final class DivergenceLedgerRules
+{
+    /** 状態の値域。どちらも「今ある逸脱」を表し、解消を意味する語は持たない。 */
+    public const STATES = ['恒久', '監視中'];
+
+    /** 決めた人の値域。 */
+    public const DECIDERS = ['オーナー', '開発者'];
+
+    /** 見直し期限の上限 (基準日からの日数)。青天井の期限で検査を無力化させない。 */
+    public const MAX_REVIEW_WINDOW_DAYS = 400;
+
+    /** `恒久` の見直し期限に置く不在の記号。 */
+    public const PERMANENT_DEADLINE = '—';
+
+    /**
+     * プレースホルダの語彙 (過剰検出寄りに倒す)。
+     *
+     * 適用先は根拠と自由記述 3 欄だけである。見直し期限の `—` は `恒久` の正値なので、
+     * 期限欄にはプレースホルダ検査を掛けない。
+     *
+     * @var list<string>
+     */
+    private const PLACEHOLDERS = ['', '...', '…', 'TBD', '未定', '-', '—', '?', '？'];
+
+    /**
+     * @return list<string> 違反一覧 (空 = 合格)
+     */
+    public static function violations(ParsedLedger $ledger, LedgerContext $context): array
+    {
+        $violations = $ledger->parseViolations;
+
+        // 解析不能なら以降の規則は評価できない。評価しないことを違反として返す (fail-closed)。
+        if ($ledger->unparsable) {
+            return $violations;
+        }
+
+        /** @var array<string, list<string>> $pathOwners */
+        $pathOwners = [];
+
+        foreach ($ledger->entries as $entry) {
+            $metadata = $entry->metadata;
+            if ($metadata === null) {
+                continue;
+            }
+
+            foreach (self::targetPathViolations($entry, $metadata, $context) as $violation) {
+                $violations[] = $violation;
+            }
+            foreach ($metadata->targetPaths as $path) {
+                $pathOwners[$path][] = $entry->label();
+            }
+
+            foreach (self::freeTextViolations($entry, $metadata) as $violation) {
+                $violations[] = $violation;
+            }
+            foreach (self::decisionViolations($entry, $metadata, $context) as $violation) {
+                $violations[] = $violation;
+            }
+            foreach (self::stateViolations($entry, $metadata, $context) as $violation) {
+                $violations[] = $violation;
+            }
+        }
+
+        foreach ($pathOwners as $path => $owners) {
+            if (count($owners) > 1) {
+                $violations[] = sprintf(
+                    'TD4: 対象パス `%s` を %s が重複して挙げている。和集合で重複させない (片方を消しても赤にならなくなる)',
+                    $path,
+                    implode(' / ', $owners),
+                );
+            }
+        }
+
+        $parsedCount = count($ledger->entries);
+        if ($ledger->declaredCount !== null && $ledger->declaredCount !== $parsedCount) {
+            $violations[] = sprintf(
+                'TD12: 明示件数 %d 件と解析した見出しの件数 %d 件が食い違う',
+                $ledger->declaredCount,
+                $parsedCount,
+            );
+        }
+        if ($parsedCount !== $context->pinnedEntryCount) {
+            $violations[] = sprintf(
+                'TD12: 解析した見出しの件数 %d 件と検査側の固定件数 %d 件が食い違う (登録を足した / 消したら同じ変更で固定件数も直す)',
+                $parsedCount,
+                $context->pinnedEntryCount,
+            );
+        }
+
+        return $violations;
+    }
+
+    /**
+     * TD3: 対象パスの書式・値域・実在。
+     *
+     * @return list<string>
+     */
+    private static function targetPathViolations(ParsedEntry $entry, EntryMetadata $metadata, LedgerContext $context): array
+    {
+        /** @var list<string> $violations */
+        $violations = [];
+
+        if ($metadata->targetPaths === []) {
+            $violations[] = sprintf(
+                'TD3: %s の対象パスが読めない。バッククォート囲みのパスを ` / ` でつないだ形だけを許す (実測: %s)',
+                $entry->label(),
+                $metadata->rawTargetPathCell === '' ? '(空)' : $metadata->rawTargetPathCell,
+            );
+
+            return $violations;
+        }
+
+        foreach ($metadata->targetPaths as $path) {
+            if (str_starts_with($path, '/')) {
+                $violations[] = sprintf('TD3: %s の対象パス `%s` が絶対パスである', $entry->label(), $path);
+
+                continue;
+            }
+            if (preg_match('/[*?\[\]{}]/', $path) === 1) {
+                $violations[] = sprintf('TD3: %s の対象パス `%s` が glob である。実ファイル名へ展開する', $entry->label(), $path);
+
+                continue;
+            }
+            if (in_array('..', explode('/', $path), true)) {
+                $violations[] = sprintf('TD3: %s の対象パス `%s` が上位への相対指定を含む', $entry->label(), $path);
+
+                continue;
+            }
+            if (! ($context->pathExists)($path)) {
+                $violations[] = sprintf(
+                    'TD3: %s の対象パス `%s` がファイルとして実在しない (ディレクトリは対象パスに書けない)',
+                    $entry->label(),
+                    $path,
+                );
+            }
+        }
+
+        return $violations;
+    }
+
+    /**
+     * TD11: 自由記述 3 欄が空でもプレースホルダでもないこと。
+     *
+     * @return list<string>
+     */
+    private static function freeTextViolations(ParsedEntry $entry, EntryMetadata $metadata): array
+    {
+        /** @var list<string> $violations */
+        $violations = [];
+
+        $columns = [
+            '業務要件起因の説明' => $metadata->domainReason,
+            '揃え続ける不変条件と保証機構' => $metadata->invariantAndGuard,
+            '再判定の条件' => $metadata->reevaluationCondition,
+        ];
+
+        foreach ($columns as $label => $value) {
+            if (self::isPlaceholder($value)) {
+                $violations[] = sprintf(
+                    'TD11: %s の「%s」が空かプレースホルダである (恒久の登録にも再判定の条件は必須)',
+                    $entry->label(),
+                    $label,
+                );
+            }
+        }
+
+        return $violations;
+    }
+
+    /**
+     * TD8 / TD9 / TD10: 決めた日・決めた人・根拠。
+     *
+     * @return list<string>
+     */
+    private static function decisionViolations(ParsedEntry $entry, EntryMetadata $metadata, LedgerContext $context): array
+    {
+        /** @var list<string> $violations */
+        $violations = [];
+
+        $decidedOn = self::parseDate($metadata->decidedOn);
+        if ($decidedOn === null) {
+            $violations[] = sprintf(
+                'TD8: %s の決めた日「%s」が `YYYY-MM-DD` の実在する日付ではない',
+                $entry->label(),
+                $metadata->decidedOn,
+            );
+        } elseif ($decidedOn->greaterThan($context->baseDate)) {
+            $violations[] = sprintf(
+                'TD8: %s の決めた日 %s が未来日である (基準日 %s)',
+                $entry->label(),
+                $metadata->decidedOn,
+                $context->baseDate->format('Y-m-d'),
+            );
+        }
+
+        if (! in_array($metadata->decidedBy, self::DECIDERS, true)) {
+            $violations[] = sprintf(
+                'TD9: %s の決めた人「%s」は値域 (%s) にない',
+                $entry->label(),
+                $metadata->decidedBy,
+                implode(' / ', self::DECIDERS),
+            );
+        }
+
+        $rationale = $metadata->rationale;
+        if (self::isPlaceholder($rationale)) {
+            $violations[] = sprintf('TD10: %s の根拠が空かプレースホルダである', $entry->label());
+        } elseif (preg_match('/^T\d{3,}$/', $rationale) === 1) {
+            if (! ($context->rationaleExists)($rationale)) {
+                $violations[] = sprintf(
+                    'TD10: %s の根拠 %s が TODO 台帳 (docs/TODO.md / docs/TODO-closed.md) の表に実在しない',
+                    $entry->label(),
+                    $rationale,
+                );
+            }
+        } elseif (preg_match('#^devnotes/[^/]+/$#', $rationale) === 1) {
+            if (! ($context->directoryExists)(rtrim($rationale, '/'))) {
+                $violations[] = sprintf('TD10: %s の根拠 %s がディレクトリとして実在しない', $entry->label(), $rationale);
+            }
+        } else {
+            $violations[] = sprintf(
+                'TD10: %s の根拠「%s」は `T<n>` (3 桁以上) か `devnotes/<dir>/` で書く',
+                $entry->label(),
+                $rationale,
+            );
+        }
+
+        return $violations;
+    }
+
+    /**
+     * TD5 / TD6 / TD7: 状態と見直し期限。
+     *
+     * @return list<string>
+     */
+    private static function stateViolations(ParsedEntry $entry, EntryMetadata $metadata, LedgerContext $context): array
+    {
+        /** @var list<string> $violations */
+        $violations = [];
+
+        if (! in_array($metadata->state, self::STATES, true)) {
+            $violations[] = sprintf(
+                'TD5: %s の状態「%s」は値域 (%s) にない。解消は状態ではなく登録の削除で表す',
+                $entry->label(),
+                $metadata->state,
+                implode(' / ', self::STATES),
+            );
+
+            return $violations;
+        }
+
+        if ($metadata->state === '恒久') {
+            if ($metadata->reviewDeadline !== self::PERMANENT_DEADLINE) {
+                $violations[] = sprintf(
+                    'TD7: %s は恒久なので見直し期限は「%s」ちょうどにする (実測「%s」)',
+                    $entry->label(),
+                    self::PERMANENT_DEADLINE,
+                    $metadata->reviewDeadline,
+                );
+            }
+
+            return $violations;
+        }
+
+        $deadline = self::parseDate($metadata->reviewDeadline);
+        if ($deadline === null) {
+            $violations[] = sprintf(
+                'TD6: %s は監視中なので見直し期限を `YYYY-MM-DD` の実在する日付で書く (実測「%s」)',
+                $entry->label(),
+                $metadata->reviewDeadline,
+            );
+
+            return $violations;
+        }
+
+        if ($deadline->lessThan($context->baseDate)) {
+            $violations[] = sprintf(
+                'TD6: %s の見直し期限 %s が切れている (基準日 %s)。直し方は登録簿の規約節の 4 通りから選ぶ (検査は緩めない)',
+                $entry->label(),
+                $metadata->reviewDeadline,
+                $context->baseDate->format('Y-m-d'),
+            );
+        }
+
+        if ($deadline->greaterThan($context->baseDate->addDays(self::MAX_REVIEW_WINDOW_DAYS))) {
+            $violations[] = sprintf(
+                'TD6: %s の見直し期限 %s が基準日 %s から %d 日を超えている',
+                $entry->label(),
+                $metadata->reviewDeadline,
+                $context->baseDate->format('Y-m-d'),
+                self::MAX_REVIEW_WINDOW_DAYS,
+            );
+        }
+
+        return $violations;
+    }
+
+    /** プレースホルダか (前後の空白は解析器が落としている)。 */
+    private static function isPlaceholder(string $value): bool
+    {
+        return in_array($value, self::PLACEHOLDERS, true);
+    }
+
+    /**
+     * `YYYY-MM-DD` として実在する日付だけを受け取る (失敗は null を返し、呼び出し側が違反にする)。
+     *
+     * `Carbon::parse()` は `2026-02-30` を 3/2 へ正規化して通してしまうため、
+     * 書式を固定して往復比較する。例外に倒さないのは、日付の書き間違いを
+     * 「検査の実行時エラー」ではなく「違反一覧の 1 行」として報せるためである。
+     */
+    private static function parseDate(string $value): ?CarbonImmutable
+    {
+        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
+            return null;
+        }
+
+        try {
+            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
+        } catch (InvalidFormatException) {
+            return null;
+        }
+
+        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
+            return null;
+        }
+
+        return $date;
+    }
+}
diff --git a/tests/Support/TemplateDivergence/EntryMetadata.php b/tests/Support/TemplateDivergence/EntryMetadata.php
new file mode 100644
index 0000000..0a7e3f5
--- /dev/null
+++ b/tests/Support/TemplateDivergence/EntryMetadata.php
@@ -0,0 +1,32 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+/**
+ * 逸脱の登録 1 件が持つ登録メタ表 9 行の値。
+ *
+ * 値は**生文字列のまま**持ち、妥当性 (値域・日付・実在) は `DivergenceLedgerRules` が見る。
+ * ここで正規化すると「解析器が直したので合格した」という抜け道ができるため、
+ * 解析器は取り出すだけにする。
+ */
+final readonly class EntryMetadata
+{
+    /**
+     * @param  list<string>  $targetPaths  対象パス欄から取り出したパス (バッククォート囲みの中身)
+     * @param  string  $rawTargetPathCell  対象パス欄の生の値 (書式違反の報告に使う)
+     */
+    public function __construct(
+        public array $targetPaths,
+        public string $rawTargetPathCell,
+        public string $domainReason,
+        public string $invariantAndGuard,
+        public string $reevaluationCondition,
+        public string $decidedOn,
+        public string $decidedBy,
+        public string $rationale,
+        public string $state,
+        public string $reviewDeadline,
+    ) {}
+}
diff --git a/tests/Support/TemplateDivergence/LedgerContext.php b/tests/Support/TemplateDivergence/LedgerContext.php
new file mode 100644
index 0000000..0de643a
--- /dev/null
+++ b/tests/Support/TemplateDivergence/LedgerContext.php
@@ -0,0 +1,32 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use Carbon\CarbonImmutable;
+use Closure;
+
+/**
+ * 形式検査の文脈 (基準日と実在判定の注入点)。
+ *
+ * 基準日を引数で受け取るのは、見直し期限と決めた日の判定を純関数に保ち、
+ * 単体テストが実行日で揺れないようにするためである。
+ */
+final readonly class LedgerContext
+{
+    /**
+     * @param  CarbonImmutable  $baseDate  期限判定の基準日 (検査層は今日、単体テストは固定日を渡す)
+     * @param  int  $pinnedEntryCount  検査側に固定した登録件数 (明示件数との同期検査であって免除一覧ではない)
+     * @param  Closure(string): bool  $pathExists  リポジトリ相対の**ファイル**の実在判定 (is_file)
+     * @param  Closure(string): bool  $directoryExists  リポジトリ相対の**ディレクトリ**の実在判定 (is_dir)
+     * @param  Closure(string): bool  $rationaleExists  根拠 (T 番号) が TODO 台帳の表に実在するか
+     */
+    public function __construct(
+        public CarbonImmutable $baseDate,
+        public int $pinnedEntryCount,
+        public Closure $pathExists,
+        public Closure $directoryExists,
+        public Closure $rationaleExists,
+    ) {}
+}
diff --git a/tests/Support/TemplateDivergence/ParsedEntry.php b/tests/Support/TemplateDivergence/ParsedEntry.php
new file mode 100644
index 0000000..6b3272f
--- /dev/null
+++ b/tests/Support/TemplateDivergence/ParsedEntry.php
@@ -0,0 +1,27 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+/**
+ * 解析した逸脱の登録 1 件。
+ *
+ * `$metadata` が null なのは登録メタ表を解析できなかった場合で、そのときは
+ * `ParsedLedger::$parseViolations` に理由が入っている (握り潰さない)。
+ */
+final readonly class ParsedEntry
+{
+    public function __construct(
+        public int $id,
+        public string $summary,
+        public int $line,
+        public ?EntryMetadata $metadata,
+    ) {}
+
+    /** 違反メッセージの見出し (どの登録の話かを 1 目で分かるようにする)。 */
+    public function label(): string
+    {
+        return sprintf('D%d (%d 行目)', $this->id, $this->line);
+    }
+}
diff --git a/tests/Support/TemplateDivergence/ParsedLedger.php b/tests/Support/TemplateDivergence/ParsedLedger.php
new file mode 100644
index 0000000..aef8622
--- /dev/null
+++ b/tests/Support/TemplateDivergence/ParsedLedger.php
@@ -0,0 +1,27 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+/**
+ * 逸脱の登録簿を解析した結果。
+ *
+ * `$unparsable` が true のときは登録簿を読み解けていないので、
+ * `DivergenceLedgerRules` は解析時の違反だけを返して**そこで打ち切る** (fail-closed)。
+ * 解析できなかったことを空集合へ落として緑にする経路は作らない。
+ */
+final readonly class ParsedLedger
+{
+    /**
+     * @param  list<ParsedEntry>  $entries  解析できた登録 (見出しが正準形のものだけ)
+     * @param  int|null  $declaredCount  「登録エントリ: N 件」の明示行の値 (行がちょうど 1 本でなければ null)
+     * @param  list<string>  $parseViolations  解析時点で分かった違反
+     */
+    public function __construct(
+        public array $entries,
+        public ?int $declaredCount,
+        public array $parseViolations,
+        public bool $unparsable,
+    ) {}
+}
diff --git a/tests/Support/TemplateDivergence/TodoLedgerReference.php b/tests/Support/TemplateDivergence/TodoLedgerReference.php
new file mode 100644
index 0000000..c80dc91
--- /dev/null
+++ b/tests/Support/TemplateDivergence/TodoLedgerReference.php
@@ -0,0 +1,22 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+/**
+ * 根拠に書かれた作業項目の番号 (`T<n>`) が TODO 台帳に実在するかを、境界付きで照合する。
+ *
+ * 素の部分文字列判定を使わないのが要点である。`T1` は `T10` にも `T100` にも一致してしまい、
+ * 「実在する」と誤って通す。TODO 台帳は表なので、**表のセルとして**現れることを照合する。
+ */
+final class TodoLedgerReference
+{
+    /** `$reference` が `$todoMarkdown` の表の ID セルとして実在するか。 */
+    public static function existsIn(string $reference, string $todoMarkdown): bool
+    {
+        $pattern = '/^\|\s*'.preg_quote($reference, '/').'\s*\|/mu';
+
+        return preg_match($pattern, $todoMarkdown) === 1;
+    }
+}
diff --git a/tests/Unit/Architecture/DivergenceLedgerRulesTest.php b/tests/Unit/Architecture/DivergenceLedgerRulesTest.php
new file mode 100644
index 0000000..8c66837
--- /dev/null
+++ b/tests/Unit/Architecture/DivergenceLedgerRulesTest.php
@@ -0,0 +1,495 @@
+<?php
+
+declare(strict_types=1);
+
+use Carbon\CarbonImmutable;
+use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
+use Tests\Support\TemplateDivergence\DivergenceLedgerRules;
+use Tests\Support\TemplateDivergence\LedgerContext;
+use Tests\Support\TemplateDivergence\TodoLedgerReference;
+
+/*
+ * 逸脱の登録簿の形式検査 (`DivergenceLedgerParser` + `DivergenceLedgerRules`) の
+ * 正例と負例を固定する。
+ *
+ * ★負例が本テストの存在理由である。検査が「何も検出できないまま緑」になっていても
+ *   実物の台帳が合格していれば Architecture レーンは緑になるので、
+ *   検出器そのものの実効性はここでしか固定できない。
+ *
+ * ★期限の判定は**固定した基準日**を渡して検証する (実行日でテストが揺れない)。
+ *
+ * ★検体は文字列で組み立てる。実ファイルの実在判定は文脈 (`LedgerContext`) の
+ *   クロージャに閉じてあるので、DB もファイルシステムも触らない。
+ */
+
+/** 検体の基準日。期限の境界はすべてこの日を起点に書く。 */
+function divergenceBaseDate(): CarbonImmutable
+{
+    return CarbonImmutable::parse('2026-08-16')->startOfDay();
+}
+
+/**
+ * 検体で実在扱いにするファイル。
+ *
+ * @return list<string>
+ */
+function divergenceExistingFiles(): array
+{
+    return ['docs/template-divergence.md', 'AGENTS.md', 'README.md'];
+}
+
+/** 検体用の文脈 (実在判定は固定の一覧で答える)。 */
+function divergenceContext(int $pinnedEntryCount = 1): LedgerContext
+{
+    return new LedgerContext(
+        baseDate: divergenceBaseDate(),
+        pinnedEntryCount: $pinnedEntryCount,
+        pathExists: fn (string $path): bool => in_array($path, divergenceExistingFiles(), true),
+        directoryExists: fn (string $path): bool => $path === 'devnotes/20260816-0300-todo-T179',
+        rationaleExists: fn (string $reference): bool => TodoLedgerReference::existsIn(
+            $reference,
+            "| ID | タイトル |\n|---|---|\n| T010 | 何かの作業 |\n| T179 | 逸脱の登録簿の形式検査 |\n",
+        ),
+    );
+}
+
+/**
+ * 登録メタ表の既定値 (すべて合格する値)。
+ *
+ * @return array<string, string>
+ */
+function divergenceDefaultMeta(): array
+{
+    return [
+        '対象パス' => '`docs/template-divergence.md`',
+        '業務要件起因の説明' => '業務の都合でテンプレートの形から外した理由',
+        '揃え続ける不変条件と保証機構' => '不変条件 X を gate Y が守り続ける',
+        '再判定の条件' => '前提 Z が変わったら見直す',
+        '決めた日' => '2026-08-01',
+        '決めた人' => '開発者',
+        '根拠' => 'T010',
+        '状態' => '恒久',
+        '見直し期限' => '—',
+    ];
+}
+
+/**
+ * 登録メタ表の行を規定の順序で組み立てる。
+ *
+ * @param  array<string, string>  $overrides
+ * @return list<array{0: string, 1: string}>
+ */
+function divergenceMetaRows(array $overrides = []): array
+{
+    $defaults = divergenceDefaultMeta();
+
+    /** @var list<array{0: string, 1: string}> $rows */
+    $rows = [];
+    foreach (DivergenceLedgerParser::META_LABELS as $label) {
+        $rows[] = [$label, $overrides[$label] ?? $defaults[$label]];
+    }
+
+    return $rows;
+}
+
+/**
+ * 登録 1 件の Markdown を組み立てる。
+ *
+ * @param  list<array{0: string, 1: string}>  $rows
+ */
+function divergenceEntry(string $heading, array $rows): string
+{
+    $markdown = $heading."\n\n| 行 | 内容 |\n|---|---|\n";
+    foreach ($rows as $row) {
+        $markdown .= sprintf("| %s | %s |\n", $row[0], $row[1]);
+    }
+
+    return $markdown."\n| 観点 | テンプレート | 本アプリ |\n|---|---|---|\n| 例 | 例 | 例 |\n\n### なぜ正当な差分か\n\n説明。\n";
+}
+
+/**
+ * 登録簿 1 冊の Markdown を組み立てる (規約節つき)。
+ *
+ * @param  list<string>  $entries
+ */
+function divergenceLedgerMarkdown(array $entries, ?int $declaredCount = null): string
+{
+    $declared = $declaredCount ?? count($entries);
+
+    $markdown = "# テンプレート差分レジストリ\n\n登録エントリ: ".$declared." 件\n\n";
+    $markdown .= "## 記録の原則\n\n- 解消した逸脱は登録から消す (この節は登録エントリ領域の外にある)\n\n";
+    $markdown .= "## エントリ形式\n\n```\n## D1 <逸脱の要約>\n\n| 行 | 内容 |\n|---|---|\n```\n\n";
+
+    return $markdown.implode("\n", $entries);
+}
+
+/**
+ * 検体を解析して違反一覧を返す。
+ *
+ * @return list<string>
+ */
+function divergenceViolations(string $markdown, int $pinnedEntryCount = 1): array
+{
+    return DivergenceLedgerRules::violations(
+        DivergenceLedgerParser::parse($markdown),
+        divergenceContext($pinnedEntryCount),
+    );
+}
+
+/** 違反一覧に指定の記号で始まる違反が含まれるか。 */
+function divergenceHasViolation(string $marker, string $markdown, int $pinnedEntryCount = 1): bool
+{
+    foreach (divergenceViolations($markdown, $pinnedEntryCount) as $violation) {
+        if (str_starts_with($violation, $marker)) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+test('正例: 統一形式を満たす検体は違反 0 件になる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ]);
+
+    expect(divergenceViolations($markdown))->toBe([]);
+});
+
+test('負のコントロール: 囲みコード区画の中の記入例は登録として数えない', function (): void {
+    // 規約節の記入例 (`## D1 <逸脱の要約>`) は囲みの中にある。数えていれば件数が 2 になり赤くなる。
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ]);
+
+    expect(DivergenceLedgerParser::parse($markdown)->entries)->toHaveCount(1);
+});
+
+test('負のコントロール: 登録エントリ領域より前の節は違反にならない', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ]);
+
+    // `## 記録の原則` / `## エントリ形式` は領域の外なので正準形でなくてよい
+    expect(divergenceViolations($markdown))->toBe([]);
+});
+
+test('TD1a: 見出しに印が付いていると落ちる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 ✅ 逸脱の要約', divergenceMetaRows()),
+    ]);
+
+    expect(divergenceHasViolation('TD1', $markdown))->toBeTrue();
+});
+
+test('TD1a: 見出しに解消を表す語や矢印があると落ちる', function (string $heading): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry($heading, divergenceMetaRows()),
+    ]);
+
+    expect(divergenceHasViolation('TD1', $markdown))->toBeTrue();
+})->with([
+    '矢印' => ['## D1 課金ゲートの反転 → 解消'],
+    '解消' => ['## D1 課金ゲートの反転 (解消)'],
+    '済み' => ['## D1 課金ゲートの反転 (対応済み)'],
+]);
+
+test('TD1: 要約に `+` を使う正当な見出しは落ちない', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 招待一本化 + 遷移コマンドロール', divergenceMetaRows()),
+    ]);
+
+    expect(divergenceViolations($markdown))->toBe([]);
+});
+
+test('TD1b: 見出しの階層を 1 段下げると登録として数えられず件数が合わない', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+        divergenceEntry('### D2 逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md`'])),
+    ], declaredCount: 2);
+
+    expect(divergenceHasViolation('TD12', $markdown, 2))->toBeTrue();
+});
+
+test('TD1c: id が重複すると落ちる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+        divergenceEntry('## D1 別の逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md`'])),
+    ]);
+
+    expect(divergenceHasViolation('TD1', $markdown, 2))->toBeTrue();
+});
+
+test('TD2a: 登録メタ表が 9 行でないと落ちる', function (int $drop, bool $extra): void {
+    $rows = divergenceMetaRows();
+    if ($extra) {
+        $rows[] = ['備考', '10 行目'];
+    } else {
+        array_splice($rows, $drop, 1);
+    }
+
+    $markdown = divergenceLedgerMarkdown([divergenceEntry('## D1 逸脱の要約', $rows)]);
+
+    expect(divergenceHasViolation('TD2', $markdown))->toBeTrue();
+})->with([
+    '8 行 (末尾を落とす)' => [8, false],
+    '8 行 (途中を落とす)' => [3, false],
+    '10 行' => [0, true],
+]);
+
+test('TD2b: ラベルの順序を入れ替えると落ちる', function (): void {
+    $rows = divergenceMetaRows();
+    [$rows[7], $rows[8]] = [$rows[8], $rows[7]];
+
+    $markdown = divergenceLedgerMarkdown([divergenceEntry('## D1 逸脱の要約', $rows)]);
+
+    expect(divergenceHasViolation('TD2', $markdown))->toBeTrue();
+});
+
+test('TD3a: 対象パスが 0 件だと落ちる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => ''])),
+    ]);
+
+    expect(divergenceHasViolation('TD3', $markdown))->toBeTrue();
+});
+
+test('TD3b/TD3c/TD3d: 対象パスの値域と実在を見る', function (string $cell): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => $cell])),
+    ]);
+
+    expect(divergenceHasViolation('TD3', $markdown))->toBeTrue();
+})->with([
+    'glob' => ['`app/Models/*.php`'],
+    '波括弧展開' => ['`app/Models/{Cut,Take}.php`'],
+    '絶対パス' => ['`/workspace/AGENTS.md`'],
+    '上位への相対指定' => ['`../AGENTS.md`'],
+    '実在しない' => ['`app/Nope.php`'],
+    'ディレクトリ' => ['`devnotes/20260816-0300-todo-T179`'],
+]);
+
+test('TD3e: 対象パスのセルにバッククォート外の説明文を添えると落ちる', function (string $cell): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => $cell])),
+    ]);
+
+    expect(divergenceHasViolation('TD3', $markdown))->toBeTrue();
+})->with([
+    '説明を添える' => ['`AGENTS.md` (規約の正本)'],
+    '読点でつなぐ' => ['`AGENTS.md`、`README.md`'],
+    'バッククォート無し' => ['AGENTS.md'],
+]);
+
+test('TD3: 複数の対象パスを ` / ` でつなぐ形は合格する', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md` / `README.md`'])),
+    ]);
+
+    expect(divergenceViolations($markdown))->toBe([]);
+});
+
+test('TD4: 2 つの登録が同じ対象パスを挙げると落ちる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md`'])),
+        divergenceEntry('## D2 別の逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md` / `README.md`'])),
+    ]);
+
+    expect(divergenceHasViolation('TD4', $markdown, 2))->toBeTrue();
+});
+
+test('TD5: 状態が値域の外だと落ちる', function (string $state): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['状態' => $state])),
+    ]);
+
+    expect(divergenceHasViolation('TD5', $markdown))->toBeTrue();
+})->with([
+    '解消済み' => ['解消済み'],
+    '解消' => ['解消'],
+    '未実装' => ['未実装'],
+    '空' => [''],
+]);
+
+test('TD6: 監視中の見直し期限が不正だと落ちる', function (string $deadline): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows([
+            '状態' => '監視中',
+            '見直し期限' => $deadline,
+        ])),
+    ]);
+
+    expect(divergenceHasViolation('TD6', $markdown))->toBeTrue();
+})->with([
+    '期限が無い' => ['—'],
+    '空' => [''],
+    '日付でない' => ['not-a-date'],
+    '実在しない日付' => ['2026-02-30'],
+    '基準日の前日 (期限切れ)' => ['2026-08-15'],
+    '基準日から 401 日後' => ['2027-09-21'],
+]);
+
+test('TD6e: 監視中の見直し期限の境界は合格する', function (string $deadline): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows([
+            '状態' => '監視中',
+            '見直し期限' => $deadline,
+        ])),
+    ]);
+
+    expect(divergenceViolations($markdown))->toBe([]);
+})->with([
+    '基準日当日' => ['2026-08-16'],
+    '基準日の翌日' => ['2026-08-17'],
+    '基準日から 400 日後' => ['2027-09-20'],
+]);
+
+test('TD7: 恒久に日付の見直し期限が書いてあると落ちる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows([
+            '状態' => '恒久',
+            '見直し期限' => '2026-12-31',
+        ])),
+    ]);
+
+    expect(divergenceHasViolation('TD7', $markdown))->toBeTrue();
+});
+
+test('TD8: 決めた日が未来日・不正な日付だと落ちる', function (string $decidedOn): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['決めた日' => $decidedOn])),
+    ]);
+
+    expect(divergenceHasViolation('TD8', $markdown))->toBeTrue();
+})->with([
+    '基準日の翌日 (未来日)' => ['2026-08-17'],
+    '実在しない日付' => ['2026-02-30'],
+    '空' => [''],
+    '日付でない' => ['not-a-date'],
+    '桁が足りない' => ['2026-8-1'],
+]);
+
+test('TD8b: 決めた日が基準日当日は合格する', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['決めた日' => '2026-08-16'])),
+    ]);
+
+    expect(divergenceViolations($markdown))->toBe([]);
+});
+
+test('TD9: 決めた人が値域の外だと落ちる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['決めた人' => 'チーム'])),
+    ]);
+
+    expect(divergenceHasViolation('TD9', $markdown))->toBeTrue();
+});
+
+test('TD10: 根拠が実在しない・書式外・プレースホルダだと落ちる', function (string $rationale): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['根拠' => $rationale])),
+    ]);
+
+    expect(divergenceHasViolation('TD10', $markdown))->toBeTrue();
+})->with([
+    '実在しない T 番号' => ['T999'],
+    'プレースホルダ' => ['TBD'],
+    '空' => [''],
+    '実在しない devnotes' => ['devnotes/9999-nope/'],
+    '書式外 (末尾のスラッシュ無し)' => ['devnotes/20260816-0300-todo-T179'],
+    '書式外 (自由記述)' => ['前任者の口頭指示'],
+]);
+
+test('TD10: 実在する devnotes ディレクトリは根拠として合格する', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['根拠' => 'devnotes/20260816-0300-todo-T179/'])),
+    ]);
+
+    expect(divergenceViolations($markdown))->toBe([]);
+});
+
+test('TD10c: T 番号の照合は表のセル境界で行う (T1 が T10 に一致しない)', function (): void {
+    $todo = "| ID | タイトル |\n|---|---|\n| T010 | 何かの作業 |\n";
+
+    expect(TodoLedgerReference::existsIn('T010', $todo))->toBeTrue()
+        ->and(TodoLedgerReference::existsIn('T01', $todo))->toBeFalse()
+        ->and(TodoLedgerReference::existsIn('T1', $todo))->toBeFalse();
+});
+
+test('TD11: 自由記述 3 欄が空かプレースホルダだと落ちる', function (string $label, string $value): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows([$label => $value])),
+    ]);
+
+    expect(divergenceHasViolation('TD11', $markdown))->toBeTrue();
+})->with([
+    '説明が空' => ['業務要件起因の説明', ''],
+    '不変条件が伏せ字' => ['揃え続ける不変条件と保証機構', '...'],
+    '再判定の条件が未定' => ['再判定の条件', '未定'],
+    '再判定の条件が不在の記号' => ['再判定の条件', '—'],
+]);
+
+test('TD12: 明示件数・解析件数・固定件数の 3 点一致を要求する', function (int $declared, int $pinned): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ], declaredCount: $declared);
+
+    expect(divergenceHasViolation('TD12', $markdown, $pinned))->toBeTrue();
+})->with([
+    '明示件数が多い' => [2, 1],
+    '明示件数が少ない' => [0, 1],
+    '固定件数が多い' => [1, 2],
+    '固定件数が少ない' => [1, 0],
+]);
+
+test('TD12: 件数の明示行が無い・2 本ある場合も落ちる', function (string $markdown): void {
+    expect(divergenceHasViolation('TD12', $markdown))->toBeTrue();
+})->with([
+    '明示行が無い' => [
+        "# 台帳\n\n".divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ],
+    '明示行が 2 本' => [
+        "# 台帳\n\n登録エントリ: 1 件\n\n登録エントリ: 1 件\n\n".divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ],
+]);
+
+test('P1: 囲みコード区画が閉じていないと解析不能で落ち、以降の規則を評価しない', function (): void {
+    $markdown = "# 台帳\n\n登録エントリ: 1 件\n\n```\n## D1 <逸脱の要約>\n";
+
+    $violations = divergenceViolations($markdown);
+
+    // 件数 (TD12) も対象パス (TD3) も評価されないことまで固定する (fail-closed)
+    expect($violations)->toHaveCount(1)
+        ->and($violations[0])->toStartWith('P1');
+});
+
+test('P2: 登録エントリ領域が見つからないと解析不能で落ちる', function (): void {
+    $markdown = "# 台帳\n\n登録エントリ: 0 件\n\n## 記録の原則\n\n- 何か\n";
+
+    $violations = divergenceViolations($markdown, 0);
+
+    expect($violations)->toHaveCount(1)
+        ->and($violations[0])->toStartWith('P2');
+});
+
+test('P3: 台帳が扱わない囲みの書き方は明示的に拒否する', function (string $fence): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ])."\n".$fence."\n本文\n".$fence."\n";
+
+    expect(divergenceHasViolation('P3', $markdown))->toBeTrue();
+})->with([
+    'バッククォート 4 個' => ['````'],
+    'チルダ 3 個' => ['~~~'],
+]);
+
+test('P4: 登録メタ表のセルに `|` を書くと落ちる', function (string $value): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['再判定の条件' => $value])),
+    ]);
+
+    expect(divergenceHasViolation('TD2', $markdown))->toBeTrue();
+})->with([
+    '素の縦棒' => ['A | B が変わったら見直す'],
+    'エスケープした縦棒' => ['A \\| B が変わったら見直す'],
+]);

```

## 実装差分 2: 登録簿そのものの移行 (docs/template-divergence.md)

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 99f47a0..0e3c77e 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -4,17 +4,79 @@ # テンプレート差分レジストリ
 逸脱が正当なのは **logic-driven(ドメイン要件起因)のときだけ**。互換・UX・作業量を理由にした
 逸脱は記録せず是正する(`docs/app-integration-guide.md` §0)。
 
+**書式の正本は本節である**。家系の統一形式 (機能台帳 lctl の feature
+`template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
+`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
+
+登録エントリ: 23 件
+
 ## 記録の原則
 
 - 判定軸は「ライブラリ/実装が同じか」でなく「**同じ不変条件を同じタイミング/抽象度で保証するか**」。
   不変条件が揃っていれば構文差は許容
-- 各エントリには (a) なぜ logic-driven か (b) テンプレートの不変条件をどの機構で保証し続けるか
-  を必ず書く
+- **登録は逸脱を作る変更そのものに含める**。後でまとめて書かない。まだ実在しない逸脱
+  (これから作る予定) は登録しない — 予定の管理は `docs/TODO.md` の役目である
+- **解消した逸脱は登録から消す**。全パスが戻ったならエントリごと、一部が戻ったなら
+  そのパスを対象パス欄から削る。状態の語で「解消済み」を表さない。
+  台帳の中に履歴の節を作らない (走査の対象外になる領域は回避口になるため)
+- 番号 (`D<n>`) は**再利用しない**。削除しても後続を詰めない (欠番は正常)。
+  他リポジトリから参照するときは `aicue:D<n>` と書く
+
+## 登録メタ表 (9 行ちょうど・この順序)
+
+| 行 | 値域 |
+|---|---|
+| 対象パス | リポジトリ相対のファイルパスをバッククォート囲みで 1 件以上。区切りは半角スペースとスラッシュと半角スペース。glob・絶対パス・上位への相対指定は不可。ファイルとして実在すること。**全登録の和集合で重複しないこと** |
+| 業務要件起因の説明 | なぜドメイン要件のせいでテンプレートの形から外れたか (1〜2 文) |
+| 揃え続ける不変条件と保証機構 | 何を揃え続け、どの機構が保証するか |
+| 再判定の条件 | 何が変わったら見直すか (**恒久の登録にも必須**) |
+| 決めた日 | `YYYY-MM-DD`。逸脱を最初に決めた日 (再判断で書き換えない)。未来日は不可 |
+| 決めた人 | `オーナー` / `開発者` |
+| 根拠 | `T<n>` (3 桁以上のゼロ埋め。`docs/TODO.md` / `docs/TODO-closed.md` の表に実在) または `devnotes/<dir>/` (ディレクトリが実在) |
+| 状態 | `恒久` / `監視中` |
+| 見直し期限 | `監視中` は `YYYY-MM-DD` (基準日から 400 日以内)。`恒久` は全角ダッシュ 1 文字 |
+
+- **`恒久` も `監視中` も「今ある逸脱」を表す**。解消を意味する語は値域に無い
+- `監視中` にするのは、期限付きで能動的に見直す根拠 (期限・予定時期・追跡中の事象) が
+  あるときだけである。解消の条件が書けることは `監視中` の根拠にならない
+  (`恒久` の登録も再判定の条件を必ず持つので、条件の有無は区別にならない)
+- セルの中に縦棒を書かない (エスケープしても解釈しない)。表の区切りを使いたくなる内容は
+  エントリ本文の節へ書く
+
+## 見直し期限が切れたときの直し方 (4 通り)
+
+1. 逸脱を解消して登録を消す
+2. `恒久` へ変えて理由を足す
+3. 期限を延ばして再判断の根拠を足す
+4. 対象を分けて個別に判断する
+
+**検査を緩めることは選択肢に入れない**。期限切れで CI が赤くなるのは仕様である。
+
+## この登録簿が保証しないもの
+
+- 実ファイルがテンプレートから逸脱したのに登録が無いこと (登録漏れそのもの) は検出できない。
+  実体との突合は台帳リポジトリの巡回が行う (家系の裁定 AG-159)
+- 内容としてテンプレート準拠へ戻したのにファイルが残っている登録も検出できない
+- 登録の中身が正しいことは機械では見ない (空でないこと・値域に収まっていることだけを見る)
+- **削除した番号の再利用**は検出できない (使用済み番号の履歴を持たないため。
+  再利用しないことは人が守る規約である)
 
 ## エントリ形式
 
 ```
-## D1 ✅ <逸脱の要約>
+## D1 <逸脱の要約>
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Example.php` |
+| 業務要件起因の説明 | ... |
+| 揃え続ける不変条件と保証機構 | ... |
+| 再判定の条件 | ... |
+| 決めた日 | 2026-01-01 |
+| 決めた人 | 開発者 |
+| 根拠 | T001 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -29,12 +91,23 @@ ### 揃えている不変条件(これは保証し続ける)
 
 ### 関連
 - 実装: ...
-- テンプレート側の根拠: ...
 ```
 
 ---
 
-## D1 ✅ Tier B スキーマの先取り (Cut / Take を振る舞い無しで先行作成)
+## D1 Tier B スキーマの先取り (Cut / Take を振る舞い無しで先行作成)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Models/Cut.php` / `app/Models/Take.php` |
+| 業務要件起因の説明 | 中核集約のスキーマをフェーズ 1 で確定させ、後続フェーズが列追加なしに振る舞いだけを足せるようにするため、route と UI を伴わないモデルを先に置いた |
+| 揃え続ける不変条件と保証機構 | route を張った時点で `NestedRouteIdorDefenseTest` の登録と relation 経由解決を同時に行う。保護キーの不含は `MassAssignmentSafetyTest` が走査する |
+| 再判定の条件 | Cut / Take に route と UI が付いたとき (SourceDocument と同じく本登録から外す) |
+| 決めた日 | 2026-07-10 |
+| 決めた人 | 開発者 |
+| 根拠 | T001 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -67,7 +140,19 @@ ### 関連
   `resources/js/components/features/manual/SourceDocumentUpload.svelte`
 - 設計: `devnotes/20260710-2137-aicue-domain-foundation/detailed-design.md` 施策2/4/5
 
-## D2 ✅ 循環 FK の 3 段階マイグレーション (cuts の parent_cut_id / adopted_take_id を後付け)
+## D2 循環 FK の 3 段階マイグレーション (cuts の parent_cut_id / adopted_take_id を後付け)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `database/migrations/2026_07_10_000300_create_cuts_table.php` / `database/migrations/2026_07_10_000500_add_foreign_keys_to_cuts_table.php` |
+| 業務要件起因の説明 | 循環 FK と自己参照 FK は単一の CREATE では構築できず DB 実装に依存して不安定になるため、cuts → takes → FK 後付けの 3 段に分けた |
+| 揃え続ける不変条件と保証機構 | 親削除時の参照整合 (nullOnDelete) と down() の逆順 drop。`RefreshDatabase` が全 Feature テストで up を暗黙検証する |
+| 再判定の条件 | cuts と takes の循環参照が解消されたとき / 採用する DB が単一 CREATE での循環 FK を扱えるようになったとき |
+| 決めた日 | 2026-07-10 |
+| 決めた人 | 開発者 |
+| 根拠 | T001 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -85,7 +170,19 @@ ### 揃えている不変条件(これは保証し続ける)
 ### 関連
 - 実装: `database/migrations/2026_07_10_000300_create_cuts_table.php` / `..._000500_add_foreign_keys_to_cuts_table.php`
 
-## D3 ✅ Category `sort_order` の Service 専有 (fillable 外・Store/Update で受けない)
+## D3 Category `sort_order` の Service 専有 (fillable 外・Store/Update で受けない)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Services/Manual/CategoryService.php` / `app/Models/Category.php` |
+| 業務要件起因の説明 | 並べ替えは「送信 id 集合 = project の Category 集合」という集合契約で成立するため、任意の並び順を payload から受けると契約を迂回して順序が破綻する |
+| 揃え続ける不変条件と保証機構 | create / update / reorder / delete は Project 行ロック下で直列化され、sort_order は project 内で一意な並びを保つ。`CategoryReorderTest` が固定する |
+| 再判定の条件 | 並べ替えを行単位の操作として外部へ開く要件が出たとき |
+| 決めた日 | 2026-07-10 |
+| 決めた人 | 開発者 |
+| 根拠 | T001 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -105,7 +202,19 @@ ### 関連
 - 実装: `app/Services/Manual/CategoryService.php`, `app/Models/Category.php`
 - 設計: `devnotes/20260710-2137-aicue-domain-foundation/detailed-design.md` 施策7
 
-## D4 ✅ web `{project}` route の org スコープ guard を middleware 層に追加 (project.in-current-org)
+## D4 web `{project}` route の org スコープ guard を middleware 層に追加 (project.in-current-org)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php` / `routes/web.php` |
+| 業務要件起因の説明 | FormRequest の DB ルールは controller の inline guard より前に走り、他組織の project に対する 422 と 404 の差がカテゴリ名や所属関係を辞書探索できる存在オラクルになる |
+| 揃え続ける不変条件と保証機構 | 他組織の project は FormRequest を含むあらゆるアプリコードより前に 404。`ProjectRouteCurrentOrgGuardTest` が deny-by-default で強制する |
+| 再判定の条件 | web と API v1 で project の解決モデルが 1 つに揃ったとき (binder 化を再検討できる) |
+| 決めた日 | 2026-07-10 |
+| 決めた人 | 開発者 |
+| 根拠 | T001 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -134,7 +243,19 @@ ### 関連
 - 実装: `app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php`, `routes/web.php`, `bootstrap/app.php`
 - テンプレート側の根拠: `docs/app-integration-guide.md` §2 (URL 整合 guard 行を 2 層構成に更新済み)
 
-## D5 ✅ Cut のシナリオ編集は per-row CRUD でなく document 単位保存 (PUT .../scenario)
+## D5 Cut のシナリオ編集は per-row CRUD でなく document 単位保存 (PUT .../scenario)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Services/Manual/ScenarioService.php` / `app/Http/Requests/Projects/UpdateScenarioRequest.php` / `app/Http/Controllers/Projects/ManualScenarioController.php` |
+| 業務要件起因の説明 | シナリオ編集は親子カスケードと並べ替えを伴うため、行単位の CRUD では原子性が壊れ、編集途中の中間状態がサーバへ漏れる |
+| 揃え続ける不変条件と保証機構 | 保護キー不信 / 認可より前の 404 / relation 経由の作成を document 保存でも同じ機構で維持する。`ScenarioUpdateTest` と `NestedRouteIdorDefenseTest` が固定する |
+| 再判定の条件 | シナリオを行単位で編集する要件が出たとき / 楽観ロックを別の同時編集制御へ置き換えるとき |
+| 決めた日 | 2026-07-11 |
+| 決めた人 | 開発者 |
+| 根拠 | T002 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -161,7 +282,19 @@ ### 関連
 - 実装: `app/Services/Manual/ScenarioService.php`, `app/Http/Requests/Projects/UpdateScenarioRequest.php`, `app/Http/Controllers/Projects/ManualScenarioController.php`
 - 設計: `devnotes/20260711-0007-scenario-editing/detailed-design.md`
 
-## D6 ✅ presigned PUT の署名対象は ChecksumSHA256 のみ (Content-Type/Length は HeadObject 照合が担う)
+## D6 presigned PUT の署名対象は ChecksumSHA256 のみ (Content-Type/Length は HeadObject 照合が担う)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Services/Capture/TakeObjectStorage.php` |
+| 業務要件起因の説明 | AWS SDK の presign は Content-Type と Content-Length を署名対象から外すため、置ける内容を 1 通りに固定する保証はハッシュの署名だけで成立させる必要がある |
+| 揃え続ける不変条件と保証機構 | presigned URL で登録済みオブジェクトを別内容に差し替えられない。`TakeObjectStorageTest` が署名対象を、`TakeRegistrationTest` が登録時の三点照合を固定する |
+| 再判定の条件 | SDK が署名対象ヘッダの扱いを変えたとき / 登録時の三点照合を外すとき |
+| 決めた日 | 2026-07-11 |
+| 決めた人 | 開発者 |
+| 根拠 | T004 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | 設計書 (T004 detailed-design) | 実装 |
 |---|---|---|
@@ -184,7 +317,19 @@ ### 関連
 - 実装: `app/Services/Capture/TakeObjectStorage.php`
 - 設計: `devnotes/20260711-0345-capture-pwa/detailed-design.md` 施策3
 
-## D7 ✅ org 同時 preview 上限の「直列化実証テスト」は subprocess 方式を保留 (逐次境界テストで代替)
+## D7 org 同時 preview 上限の「直列化実証テスト」は subprocess 方式を保留 (逐次境界テストで代替)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Services/Manual/RenderJobService.php` / `tests/Feature/Manual/RenderPreviewConcurrencyTest.php` |
+| 業務要件起因の説明 | `RefreshDatabase` が検体を未コミットのトランザクション内に置くため、別プロセスからは検体が見えず、直列化の実証には非トランザクションの専用レーンが要る |
+| 揃え続ける不変条件と保証機構 | 組織ごとの同時 preview 上限の検査とジョブ作成は Organization 行ロック下で行う。逐次境界は `RenderPreviewConcurrencyTest` が固定する |
+| 再判定の条件 | 非トランザクションのテストレーンを導入したとき (別プロセスでの実証へ移す) |
+| 決めた日 | 2026-07-11 |
+| 決めた人 | 開発者 |
+| 根拠 | T005 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | 設計 (T005 詳細設計 施策 4/15) | 本アプリ |
 |---|---|---|
@@ -218,7 +363,19 @@ ### 関連
 
 ---
 
-## D8 ✅ 管理メニューのユーザー管理 = 招待一本化 + 遷移コマンドロール + Settings からの UI 移設
+## D8 管理メニューのユーザー管理 = 招待一本化 + 遷移コマンドロール + Settings からの UI 移設
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Enums/AdminConsoleRole.php` / `app/Enums/MemberRoleState.php` / `app/Services/Organization/OrganizationMembershipService.php` / `app/Http/Controllers/Admin/UserManagementController.php` |
+| 業務要件起因の説明 | 管理メニューの役割は組織ロールと Default Project の割当の合成で表す必要があり、保存された役割にすると非正規状態を見つけて直せなくなる |
+| 揃え続ける不変条件と保証機構 | 招待 token は hash-only 保存 / 権限判定は laratrust_team_id を明示 / Owner 昇格は transferOwnership のみ。`ConsoleRoleTransitionTest` と `ProjectMemberPivotWritePathTest` が固定する |
+| 再判定の条件 | 役割を保存概念へ戻す要件が出たとき / 家系の裁定が役割の語彙を変えたとき |
+| 決めた日 | 2026-07-11 |
+| 決めた人 | オーナー |
+| 根拠 | T006 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -261,52 +418,19 @@ ### 関連
 - 設計: `devnotes/20260711-1009-admin-console/` (概念設計 D1/D2/D6・詳細設計 施策 1〜7) /
   `devnotes/20260807-2032-invitation-in-app-acceptance/` (役割付き招待の撤去 = 裁定 AG-079)
 
-## D9 ✅→解消 BillingAccess の entitlement 判定への書き換え (free tier は課金ゲートを通す)
-
-> **【解消 / 2026-08-03 (T075 = 決済 parity P4)】** 本乖離は**ゲート反転で解消した**。
-> 「free tier (= `plan_code` null) は課金ゲートを通す」という扱いをやめ、無料枠は
-> `organizations.free_plan_code = 'personal'` の**明示申告** (`ActiveFreePlan`) で表現するようになった。
-> `plan_code` は entitlement 判定に一切使わない (quota の解決キーのみ)。
-> 既存組織は grandfathering backfill が `free_plan_code` を書くため締め出しは発生しない。
-> 設計: `devnotes/20260717-0035-aigenba-billing-parity/` §P4。**記録は削除せず経緯として残す**。
-
-| 観点 | テンプレート | 本アプリ |
-|---|---|---|
-| BillingAccess::hasActiveAccess | `subscription('default')` が active/trialing のときのみ許可 (未契約 = fail-closed) | plan_code null (未契約 = 支払い不要 free tier) は許可 / plan_code 非 null (有償プラン契約状態) のみ active/trialing を要求 |
-| 遮断時の UX | billing へ redirect (理由提示なし) / JSON 402 「有効なサブスクリプションがありません」 | billing へ redirect + 理由 flash / JSON 402 (両経路とも「サブスクリプションのお支払いが確認できないため…」で統一) |
-| ダッシュボード callout | `has_active_subscription` (subscription 有無) | `billing_state` (`OnboardingBillingState` の 5 値) による状態別 callout。未契約はプラン選択 CTA / 支払い不健全は支払い方法確認 CTA (真偽値に潰さない。T150) |
-
-### なぜ正当な差分か (logic-driven)
-
-AI-CUE は「Free プランで今すぐ試せます」を掲げる freemium 設計 (pricing / home)。テンプレート
-既定の「active subscription 必須」では、未契約の新規組織が business route (/projects, /app) に
-一切到達できず、North Star フロー (SOP→シナリオ→撮影→動画) が入口で詰む
-(bug-hunt F-07: devnotes/20260712-075854-bug-hunt)。有償価値は別レイヤで gate 済み
-(チケット残高 = analyze/render、Quota = max_projects / max_storage_bytes) のため、
-本ゲートの責務は「有償プラン契約中の支払い健全性の担保」のみで足りる。
-なお BillingAccess docblock 自身が「アプリは本クラスの書き換えで gate 方針を変更する」と
-宣言する公式拡張ポイントのため、これは構造逸脱ではなくサンクション済み拡張の記録。
-
-### 揃えている不変条件 (これは保証し続ける)
-
-> 「課金による利用可否の判定は BillingAccess 経由のみ / 有償契約の支払い不健全
-> (past_due / canceled / incomplete / 行不在) は fail-closed で遮断 / billing・checkout は
-> 構造的 allowlist で遮断中も到達可能 / plan_code は Stripe Price を持つ有償プラン契約時のみ
-> webhook が set する状態キー (null = 未契約 = free tier)」
-
-- 挙動固定: `RequireActiveSubscriptionMiddlewareTest` (F-07 再現 3 本 + 有償契約マトリクス +
-  free プランが Stripe Price を持たない前提の固定 + BillingAccess 単体マトリクス)
-- 遮断 UX: 同テストが flash / 402 message の文言を両経路で固定。
-  ダッシュボード callout は `DashboardTest` + `Dashboard.test.ts` が固定
-
-### 関連
-
-- 実装: `app/Services/Billing/BillingAccess.php` /
-  `app/Http/Middleware/RequireActiveSubscription.php` /
-  `app/DataTransferObjects/Dashboard/BillingSummaryData.php`
-- 設計: `devnotes/20260712-0927-bugfix-billing-free-access/` (概念設計 + 詳細設計 施策 1〜5)
+## D10 テストレーンのグローバルロック (worktree-local flock を残さず削除)
 
-## D10 ✅ テストレーンのグローバルロック (worktree-local flock を残さず削除)
+| 行 | 内容 |
+|---|---|
+| 対象パス | `scripts/global-test-lock.sh` / `scripts/with-global-test-lock.sh` / `scripts/verify-global-test-lock.sh` / `scripts/run-test.sh` / `scripts/run-browser-test.sh` / `scripts/run-vitest.sh` |
+| 業務要件起因の説明 | 実装を必ず worktree で行うため同一マシンで複数のテストレーンが同時に走るのが常態で、奪い合う資源 (PostgreSQL / CPU / ブラウザ掃除) の作用域がマシン全体である |
+| 揃え続ける不変条件と保証機構 | ブロッキング取得 / 待機中の heartbeat / 再入ガード / ロック fd の非継承の 4 要件。`scripts/verify-global-test-lock.sh` と `GlobalTestLockInventoryTest` が固定する |
+| 再判定の条件 | テストレーンの並走が起きなくなったとき / 家系がロックの標準形を別の形で確定したとき |
+| 決めた日 | 2026-08-04 |
+| 決めた人 | オーナー |
+| 根拠 | T099 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート(正典 = spirux 形) | 本アプリ |
 |---|---|---|
@@ -375,7 +499,19 @@ ### 関連
 
 ---
 
-## D11 ✅ svelte-no-undef-gate を config 静的検査型で別実装 (同一不変条件・別実装)
+## D11 svelte-no-undef-gate を config 静的検査型で別実装 (同一不変条件・別実装)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/js/architecture/svelte-no-undef-gate.test.ts` / `eslint.config.js` |
+| 業務要件起因の説明 | 同じ不変条件を守る実装がテンプレート側にあるが手元で読めないため、実装を待たずに設定の静的検査で先に固定した |
+| 揃え続ける不変条件と保証機構 | resources/js 配下の全 svelte で no-undef が error / globals が実行時グローバルと完全一致 / lint 対象の全ファイルで inline の抑制が効かない |
+| 再判定の条件 | laravel-claude-template の実装を読める状態になったとき (突き合わせて寄せられるなら本登録を消す) |
+| 決めた日 | 2026-08-05 |
+| 決めた人 | 開発者 |
+| 根拠 | T102 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -432,7 +568,19 @@ ### 関連
 
 ---
 
-## D12 ✅ ページタイトル / description はサーバ単一 SoT (helper 経由必須の JS 契約は不採用)
+## D12 ページタイトル / description はサーバ単一 SoT (helper 経由必須の JS 契約は不採用)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Support/Seo/SeoManager.php` / `app/Support/Seo/SeoRenderer.php` / `resources/js/lib/document-title.ts` / `config/seo.php` |
+| 業務要件起因の説明 | ページ題名の一次情報は controller と config が持ち、ページ側に helper を挟む層が無い。同じ契約を移植すると一次情報が 2 か所に割れ、フルロードと SPA 遷移で題名が食い違う |
+| 揃え続ける不変条件と保証機構 | 題名の正本はサーバの `SeoManager::resolveDocumentTitle` ただ 1 つで、フルロードと SPA 遷移で一致する。`DocumentTitleCoverageTest` と `svelte-head-no-title.test.ts` が固定する |
+| 再判定の条件 | ページ側が題名の一次情報を持つ要件が出たとき / description に SPA 遷移後の追従が要るようになったとき |
+| 決めた日 | 2026-08-05 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260805-0101-architecture-gate-followup/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -486,7 +634,19 @@ ### 関連
 
 ---
 
-## D13 ✅ SSO 登録ユーザーの password を保存しない (phantom password の撤去。前方修正のみ)
+## D13 SSO 登録ユーザーの password を保存しない (phantom password の撤去。前方修正のみ)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Services/Auth/SocialAccountService.php` |
+| 業務要件起因の説明 | SSO とパスキーを第一級のログイン手段として扱うため、「ログイン手段が 0 になる操作を止める」不変条件が `hasPassword()` の真実性に依存する |
+| 揃え続ける不変条件と保証機構 | `User::hasPassword()` は password 経路の可否を fail-closed で判定する。`SocialAuthTest` / `RecentAuthTest` / `LoginMethodInventoryTest` が固定する |
+| 再判定の条件 | 既存ユーザーの遡及是正を判別できる材料 (password 変更の監査証跡) が全ユーザーぶん揃ったとき |
+| 決めた日 | 2026-08-05 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260805-1244-auth-method-and-passkey/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -544,7 +704,19 @@ ### 関連
 
 ---
 
-## D14 ✅ 実行済み route の記録をアプリ側の観測器で採る (退避 → 正規化 → route 名解決の 3 段を置かない)
+## D14 実行した route の記録をアプリ側の観測器で採る (退避と正規化と route 名解決の 3 段を置かない)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `bootstrap/app.php` / `config/bughunt.php` / `.claude/skills/app-bug-hunt/coverage/build_executed.py` / `.claude/skills/app-bug-hunt/coverage/correlate.py` |
+| 業務要件起因の説明 | 記録が採れていないことと本当に叩けていないことを取り違えると操作到達の一覧そのものが嘘になるため、遮断 middleware の内側で 1 要求 1 行を機械記録する |
+| 揃え続ける不変条件と保証機構 | 主入力が揃わない走行は成功にしない。`BughuntExecutedRouteOrderingTest` が記録器の位置を、集約と照合の 2 つの Python ツールが終了コード 3 を担う |
+| 再判定の条件 | 家系の正典が退避 → 正規化 → route 名解決の 3 段へ揃える裁定を出したとき / web グループ外の面を分母に載せるとき |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | 開発者 |
+| 根拠 | T164 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -607,7 +779,19 @@ ### 関連
 
 ---
 
-## D15 ✅ strict_types gate の走査域を追跡下 PHP 全数にし、未宣言一覧を持たない
+## D15 strict_types gate の走査域を追跡下 PHP 全数にし、未宣言一覧を持たない
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Architecture/StrictTypesDeclarationGateTest.php` / `tests/Support/StrictTypesDeclarationScanner.php` / `tests/Support/StrictTypesRuntimeProbe.php` |
+| 業務要件起因の説明 | 容量 (bytes) やチケット枚数のように数値と文字列の取り違えがそのまま業務の誤りになる領域を持つため、走査域のどこか 1 枚だけが緩い状態を残さない |
+| 揃え続ける不変条件と保証機構 | 宣言を欠く PHP ファイルが新しく増えない。走査域はテンプレートが保証する app/ を包含し、判定器は実測照合器と突き合わせて fail-open を 0 件に固定する |
+| 再判定の条件 | どうしても宣言できないファイルが現れたとき (なし崩しに許可一覧を足さず設計レビューを通す) |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | オーナー |
+| 根拠 | T167 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -669,7 +853,19 @@ ### 関連
 
 ---
 
-## D16 ✅ prompt の trusted 変数の入口を作らない (窓口の引数は untrusted だけ)
+## D16 prompt の trusted 変数の入口を作らない (窓口の引数は untrusted だけ)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Support/Llm/PromptDefense.php` / `app/Support/Llm/GuardedPrompt.php` / `config/llm-defense.php` |
+| 業務要件起因の説明 | prompt の変数はすべて作業手順書 (SOP) 由来の untrusted で、固定値や列挙型の値を prompt へ渡す面が 1 つも無いため、trusted の入口を作る対象が存在しない |
+| 揃え続ける不変条件と保証機構 | prompt へ入る実行時の文字列はすべて窓口で無害化とタグ境界化を受ける。`PromptDefenseWindowGateTest` の変数集合の突き合わせが双方向で固定する |
+| 再判定の条件 | trusted 変数を足すとき (窓口の入口・値をリテラルに限る字句検査・目録の 3 つを同じ変更で足す) |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | 開発者 |
+| 根拠 | T169 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -714,7 +910,19 @@ ### 関連
 
 ---
 
-## D17 ✅ 滞留回収の共通基盤を、閾値の置き場所と `recover()` の引数で正典から外す
+## D17 滞留回収の共通基盤を、閾値の置き場所と `recover()` の引数で正典から外す
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Contracts/Recovery/StuckWorkStream.php` / `app/Services/Recovery/StuckWorkRecoverySweeper.php` / `app/Services/Recovery/StuckWorkStreamRegistry.php` / `app/Console/Commands/Operations/RecoverStuckWorkCommand.php` |
+| 業務要件起因の説明 | 「ジョブの制限時間 < 再試行間隔 < 予約の有効期限 ≤ 滞留の閾値」の序列を既存の検査 2 本が固定しており、閾値を回収側の設定へ移すと序列の情報源が 2 つに割れる |
+| 揃え続ける不変条件と保証機構 | 回収は必ず行を取り直し、候補列挙と同じ述語を行ロック下で再評価してから作用する。`StuckWorkRecoveryInventoryTest` が系列の集合一致を deny-by-default で強制する |
+| 再判定の条件 | 家系の正典が閾値の置き場所を変えたとき / 遡及の下限や自走をやめる上限が要る事象が実際に起きたとき |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | オーナー |
+| 根拠 | T171 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 家系の裁定 AG-083 標準形 v1 (追従元 laravel-claude-template:T076) の共通基盤へ寄せ替えるにあたり、
 **3 点だけ**正典と形を変えた。骨格 (系列の契約 / 走査と作用の分離 / 既定は実行しない入口 /
@@ -764,7 +972,19 @@ ### 関連
 
 ---
 
-## D18 ✅ hook の起動子を「起動先の検証 + 終了コードの写像器」にする
+## D18 hook の起動子を「起動先の検証 + 終了コードの写像器」にする
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `.claude/settings.json` / `scripts/bughunt-worktree-hook.sh` / `scripts/code-review-graph-update-hook.sh` |
+| 業務要件起因の説明 | hook の故障がセッションの操作を止めてはならず、起動先の検証は起動された後では手遅れなので起動子にしか置けない |
+| 揃え続ける不変条件と保証機構 | 配線は常設で起動子は絶対パス、排他はスクリプト内にあり、配線は台帳テストで完全一致 pin される。`ClaudeHooksWiringTest` が固定する |
+| 再判定の条件 | Claude Code が hook の終了コードの扱いを変えたとき / 家系が起動子の形を確定したとき |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | 開発者 |
+| 根拠 | T172 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 常設 hook 配線 (家系の feature `claude-hooks-wiring`) を取り込むにあたり、**起動子の形だけ**
 テンプレートと変えた。配線されている hook の本数・対象・スクリプトの置き場所は正典どおりである。
@@ -812,7 +1032,19 @@ ### 関連
 
 ---
 
-## D19 ✅ 経路キャッシュ起動での middleware 後付けは「走らせない」側の契約を維持する (専用の実行点クラスへは移行しない)
+## D19 経路キャッシュ起動での middleware 後付けは「走らせない」側の契約を維持する (専用の実行点クラスへは移行しない)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Support/Http/RouteMiddlewareBinder.php` / `app/Support/Http/RouteThrottleBinder.php` |
+| 業務要件起因の説明 | 本リポジトリにデプロイ定義の実体が無く、存在しない基盤のための preflight を先回りして作らない規約があるため、正典の専用実行点へ移行する利益が今は無い |
+| 揃え続ける不変条件と保証機構 | 後付けした保護は実効の経路に必ず載る / 後付けの入口は 2 つの binder に限られる / 経路名が消えたら起動を止める。`PostBootRouteMutationInventoryTest` と `RouteCacheBakedProtectionTest` が固定する |
+| 再判定の条件 | デプロイ定義が入ったとき / route:cache を実行する記述が入ったとき / 家系の機能台帳の裁定が変わったとき |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260815-2100-route-cache-middleware-attach/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 家系の正典 (機能台帳 `route-cache-safe-middleware-attach` の v1) は、経路の一覧が組み上がった後に
 走らせたい処理を**専用の実行点クラス 1 つ**へ集約し、経路キャッシュ起動でも後付けを効かせる形である。
@@ -889,7 +1121,19 @@ ### 関連
 
 ---
 
-## D20 ✅ bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 列・中間 JSON 無しで実装する
+## D20 bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 列・中間 JSON 無しで実装する
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` / `.claude/skills/app-bug-hunt/inventory/annotations.toml` |
+| 業務要件起因の説明 | 機能カタログの id 列が所見記録の語彙の正本であり、Python ツールを標準ライブラリだけで書く規約から注釈は TOML になる |
+| 揃え続ける不変条件と保証機構 | 目録は実装と注釈から再生成でき、ずれていたら CI が落ちる。`BugHuntInventoryCheckInvariantTest` と生成器の自己テストが 4 段の判定を固定する |
+| 再判定の条件 | 家系の正典が id 列を持つ形へ変わったとき / Python に依存を足す裁定が出たとき / 中間 JSON を読む道具が家系に現れたとき |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | 開発者 |
+| 根拠 | T176 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 家系の正典 (機能台帳 `bughunt-inventory-generation` の t1) は、bug-hunt の分母 (画面一覧 /
 操作一覧 / 機能カタログ) を実装から生成し、人が書く注釈ファイルと段階的なドリフト検査で守る形である。
@@ -957,3 +1201,171 @@ ### 関連
   `tests/Architecture/BughuntInventoryToolSelfTest.php` /
   `tests/Feature/Bughunt/InventoryScanCommandTest.php`
 - 設計: `devnotes/20260815-2100-bughunt-inventory-generator/`
+
+---
+
+## D21 bug-hunt の自己検証を CI の専用ステップでなく composer test の配線に載せる
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Architecture/BughuntSelfTestExecutionTest.php` |
+| 業務要件起因の説明 | bug-hunt の自己検証はどこからも自動実行されておらず二段防御の片側が眠っていた。CI の責務を同期検査に限る裁定があるため、専用ステップではなくテストの配線へ載せた |
+| 揃え続ける不変条件と保証機構 | 自己検証が毎回のテスト実行で実走し、実資源に触れない。隔離境界はテスト側が握り、専用マーカーのある空き地しか受け付けない |
+| 再判定の条件 | CI に bug-hunt 専用のステップを設ける判断が出たとき / 自己検証の実行時間がテスト実行の妨げになったとき |
+| 決めた日 | 2026-08-10 |
+| 決めた人 | 開発者 |
+| 根拠 | T142 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | 家系の他リポジトリ / テンプレート | 本アプリ |
+|---|---|---|
+| 自己検証の実行点 | CI の専用ステップ | `composer test` が走らせる Architecture テスト (`BughuntSelfTestExecutionTest`) |
+| 隔離境界の持ち主 | 自己検証スクリプト自身 | テスト側が「捨ててよい空き地」を作って渡す (専用マーカー必須・借り物は消さない) |
+
+### なぜ正当な差分か (logic-driven)
+
+bug-hunt の自己検証は guard と資源導出と環境変数の隔離という**実行時の挙動**を見る側で、
+静的構造を見る Architecture テストと二段防御をなす。ところが導入時はどこからも自動実行されておらず、
+片側が眠ったまま緑になっていた。本アプリの CI は同期検査に責務を限る裁定を採っており
+(依存脆弱性の gate と同じ考え方)、専用ステップを増やすと「CI でしか走らない検査」が生まれて
+手元の `composer test` と CI で守られる範囲が食い違う。実行点を 1 つにするほうが、
+実行され続けることを保証しやすい。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「自己検証は毎回のテスト実行で実走し、実資源には触れない」
+
+- 隔離境界はテスト側が握る。自己検証は外から渡された空き地を使い、未指定のときだけ自分で作る
+- 外から渡せるのは専用マーカーを置いた空き地だけで、リポジトリのルートを渡す事故を構造的に防ぐ
+- 借り物の空き地は削除しない (作った側が片付ける)
+
+### 関連
+
+- 実装: `tests/Architecture/BughuntSelfTestExecutionTest.php` / `scripts/bug-hunt-shard.sh`
+- 設計: `devnotes/20260810-0251-bughunt-harness-hardening/`
+
+---
+
+## D22 退会は利用者の行を消さず凍結で表す (猶予 30 日)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Http/Middleware/EnsureAccountNotPendingDeletion.php` / `app/Http/Controllers/Settings/AccountDeletionRequestController.php` |
+| 業務要件起因の説明 | 退会後も課金記録の保持義務が残るため利用者の行を消せない。猶予中の取消をそのまま元の状態へ戻せる形にする必要もある |
+| 揃え続ける不変条件と保証機構 | 凍結は deny-by-default で auth と verified の group 全体に掛かり、開けるのは救済経路だけである。退会の予約と取消は監査記録に残る |
+| 再判定の条件 | 猶予期間や保持義務の前提が変わったとき / 家系が退会の標準形を確定したとき |
+| 決めた日 | 2026-08-09 |
+| 決めた人 | オーナー |
+| 根拠 | devnotes/20260809-0908-account-deletion-grace/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 退会の表現 | 相当する仕組みを持たない | 利用者の行の生死を変えない**凍結** (論理削除も使わない) + 30 日の猶予 |
+| 猶予中の到達性 | — | `auth` + `verified` の group 全体に凍結 middleware を付け、取消などの救済経路だけを許可一覧で開ける |
+
+### なぜ正当な差分か (logic-driven)
+
+退会しても課金記録には保持義務が残る (D23) ため、利用者の行を消す形にすると
+「消えた利用者に紐づく課金記録」という参照の切れた状態を作ってしまう。
+凍結なら猶予中の取消がそのまま元の状態に戻り、保持義務のある記録も参照を保ったまま残る。
+オーナー決定 (猶予 30 日 / 保持 7 年 / 凍結方式) に沿った実装である。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「凍結は deny-by-default で、開けるのは救済経路だけである」
+
+- 凍結 middleware は route ごとの付け忘れが起きないよう group 全体に付ける
+- 取消 (救済) には再認証を課さない。詰みを作らないための例外であり、目録に理由付きで載る
+- 退会の予約と取消は監査記録に残る
+
+### 関連
+
+- 実装: `app/Http/Middleware/EnsureAccountNotPendingDeletion.php` /
+  `app/Http/Controllers/Settings/AccountDeletionRequestController.php`
+- 設計: `devnotes/20260809-0908-account-deletion-grace/` (PR-B)
+
+---
+
+## D23 課金記録は退会後も 7 年保持し、対象と年数を 1 か所で持つ
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Enums/Billing/BillingRetentionTarget.php` / `app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php` |
+| 業務要件起因の説明 | 課金記録の保持義務は退会より寿命が長く、利用者データと同じ掃除の対象にできない。年数を各所に書くと必ず食い違う |
+| 揃え続ける不変条件と保証機構 | 保持年数の正本は 1 か所で、掃除の対象は宣言された表だけである。`BillingRetentionConfigSingleSourceTest` と `BillingRetentionTargetInventoryTest` が固定する |
+| 再判定の条件 | 保持義務の年数が変わったとき / 家系が保持期間の標準形を確定したとき |
+| 決めた日 | 2026-08-09 |
+| 決めた人 | オーナー |
+| 根拠 | devnotes/20260809-0908-account-deletion-grace/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 課金記録の寿命 | 相当する仕組みを持たない | 保持年数の正本を列挙型 1 か所に置き、表ごとの掃除を登録した掃除器だけが行う |
+| 利用者データとの関係 | — | 退会で消えるものと 7 年残るものを分け、残す側は掃除の対象から除外する理由を宣言する |
+
+### なぜ正当な差分か (logic-driven)
+
+課金記録の保持義務は退会よりも寿命が長く、利用者データと同じ掃除の対象にできない。
+年数を各所に書くと必ず食い違うため、年数と対象表の対応を 1 か所に集約し、
+掃除の実処理はその宣言からしか作れないようにした。オーナー決定 (保持 7 年) に沿った実装である。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「保持年数の正本は 1 か所で、掃除の対象は宣言された表だけである」
+
+- `BillingRetentionConfigSingleSourceTest` が単一出典を固定する
+- `BillingRetentionTargetInventoryTest` が対象表と掃除器の対応を deny-by-default で強制する
+- 表を足したときの分類は `RetentionTableClassificationTest` が実スキーマと突き合わせる
+
+### 関連
+
+- 実装: `app/Enums/Billing/BillingRetentionTarget.php` /
+  `app/Services/Billing/Retention/` 配下の掃除器
+- 設計: `devnotes/20260809-0908-account-deletion-grace/` (PR-C)
+
+---
+
+## D24 SSO の driver 解決点を自前クラス 1 つへ切り出す
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Services/Auth/SocialiteDriverResolver.php` |
+| 業務要件起因の説明 | bug-hunt の走行が実際の外部 ID 基盤へ出ていくのを塞ぐ必要があるが、Socialite の Factory を丸ごと差し替えると本番経路の解決まで置き換わる |
+| 揃え続ける不変条件と保証機構 | SSO の driver 解決は 1 クラスに集約され、他クラスからの直呼びは登録も免除もできない。`ExternalSeamInventoryTest` と `ExternalFakeWiringInvariantTest` が固定する |
+| 再判定の条件 | 家系の外部到達点の標準形が解決点の形を定めたとき / Socialite が差し替え点を公式に提供したとき |
+| 決めた日 | 2026-08-11 |
+| 決めた人 | 開発者 |
+| 根拠 | T153 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| SSO の driver 取得 | 呼び出し側が Socialite の facade から直接取る | `SocialiteDriverResolver` 1 クラスに集約し、他クラスからの直呼びを目録が拒否する |
+| 非本番での差し替え | — | 解決点クラスを container で差し替える (Socialite の Factory そのものは差し替えない) |
+
+### なぜ正当な差分か (logic-driven)
+
+bug-hunt の走行が実際の外部 ID 基盤へ遷移すると、探索が本アプリの外へ出て戻れなくなる。
+これを塞ぐには driver の解決点が要るが、Socialite の Factory を丸ごと差し替えると
+本番経路の解決まで置き換わり、差し替えの影響範囲が読めなくなる。
+薄い解決点を 1 つ置くほうが、差し替えの範囲を非本番だけに閉じられる。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「SSO の driver 解決は 1 クラスに集約され、他クラスからの直呼びは登録も免除もできない」
+
+- `ExternalSeamInventoryTest` が解決点を名指しで固定する (他クラスの直呼びは目録に載せられない)
+- 非本番の差し替えは `ExternalFakeWiringInvariantTest` が配線ごと固定する
+- 差し替えを許す環境は testing と bug-hunt だけで、local は含めない
+
+### 関連
+
+- 実装: `app/Services/Auth/SocialiteDriverResolver.php` /
+  `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php`
+- 設計: `devnotes/20260811-1736-bughunt-sso-egress/`

```

## 補足

- 移行は一度きりの変換器 `devnotes/20260816-0300-todo-T179/migrate-ledger.py` で行った
  (値の表を持って機械で差し込む形。差分には含めたが本文はここに貼っていない)

## テスト結果

- `composer test`: 5253 tests / 5251 passed / 0 failed / 2 skipped (skip は本変更と無関係の既存分)
- `composer phpstan`: level 10 / 945 files / No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test`: 137 files / 1533 tests passed
- `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`: passed
- 新設した検査は移行前の登録簿に対して実際に赤くなることを確認済み
  (印つき見出し 20 件 / メタ表なし 20 件 / 件数の明示行なし / 件数の不一致)
