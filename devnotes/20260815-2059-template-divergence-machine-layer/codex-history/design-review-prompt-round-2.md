Round 1 の指摘への対応を報告する。Critical 4 件・Warning 8 件すべてに対応した。再レビューして各施策の判定と全体判定を出してほしい。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

## [Critical] 見出し regex が現行の `## D1 ✅ …` を通す (施策 4)
- 判断: 対応する
- 根拠: 指摘のとおり。`\S.*` が `✅ Tier B …` に一致するので、行全体一致だけでは印を落とせない。
  設計時点の見落としであり、これを直さないと移行の取りこぼしが緑で通る
- 対応内容: 見出し検査を 2 段にした。(1) `/^## D([1-9]\d*) (\S.*)$/u` の行全体一致、
  (2) 要約に `\p{So}` (その他の記号。`✅` が入る) と `→`、`解消` / `済み` の 2 語を含まないこと。
  **`\p{S}` 全体は禁じない** — 見出しに `+` を使う登録 (D8) が実在し正当だからである。
  `保留` も禁じない (D7 が正当に使っている)

## [Critical] 解析時違反の伝播が仕様に無い (施策 4)
- 判断: 対応する
- 根拠: 握り潰すと未閉じの囲みコード区画が緑で通る = fail-open
- 対応内容: `violations()` は先頭で `ParsedLedger::$parseViolations` を取り込み、
  解析不能なら**そこで打ち切って返す**と明記。負例テストに「以降の規則を評価せずに返すこと」を追加

## [Critical] 根拠の実在判定が緩い (施策 4)
- 判断: 対応する
- 根拠: `str_contains` では `T1` が `T10` に一致する。`devnotes/<dir>/` の判定にもなっていない
- 対応内容: `T\d{3,}` は TODO 台帳の**表のセル**として `/^\|\s*T…\s*\|/mu` で境界付き照合し、
  `devnotes/<dir>/` は `is_dir()` で別判定する。`LedgerContext` に `directoryExists` を足した。
  負例に「`T1` が `T10` に一致しない」を追加

## [Critical] 判断ログと AGENTS.md がスコープ宣言と矛盾する (施策 5 / 施策 6)
- 判断: 対応する (スコープ宣言の側を正す)
- 根拠: 「docs + tests のみ」は私が Codex へ渡した要約が不正確だった。判断ログは設計の中間成果物なので
  `devnotes/` が正しい置き場所であり (AGENTS.md の運用)、`AGENTS.md` の 1 行追記も
  本作業に必要な参照の付け替えである。実装層 (app/ routes/ database/ config/ resources/) は触らない
- 対応内容: 「変更の範囲 (スコープ宣言)」節を新設し、触るのは
  `docs/` / `devnotes/` / `AGENTS.md` / `tests/` の 4 つだけと明記した

## [Warning] 対象パスセルの抽出仕様が未確定 (施策 3)
- 判断: 対応する
- 対応内容: バッククォート囲みのパスを ` / ` でつないだ形**だけ**を許し、
  バッククォートの外に空白以外の文字があれば違反にすると明記。負例テストも追加

## [Warning] `file_exists()` はディレクトリも通す (施策 3)
- 判断: 対応する
- 対応内容: 対象パスは `is_file()` で判定する。負例に「ディレクトリを書くと落ちる」を追加

## [Warning] 新規ファイル数の記述が食い違う (施策 4)
- 判断: 対応する
- 対応内容: 「新規 8 ファイル = Support 6 + テスト 2」に揃えた

## [Warning] 日付検査が `2026-02-30` を通しうる (施策 4)
- 判断: 対応する
- 対応内容: `createFromFormat('!Y-m-d', $value)` の結果を `format('Y-m-d')` して
  元の文字列と一致するときだけ実在する日付と認めると明記。負例にも入れてある

## [Warning] プレースホルダ語彙の `—` が `恒久` の正値と衝突する (施策 4)
- 判断: 対応する
- 対応内容: プレースホルダ検査の適用先を TD10 (根拠) と TD11 (自由記述 3 欄) だけに限定し、
  見直し期限欄には TD6 / TD7 だけを掛けると明記

## [Warning] 削除した番号の再利用を検出できない (施策 1)
- 判断: 対応する (明記のみ。使用済み番号の台帳は作らない)
- 根拠: 履歴を持たない以上検出できないのは事実である。一方で「使用済み番号の正本」を新設すると
  同じ関心事に 2 つ目の台帳ができる (今必要なものだけ作る)
- 対応内容: 登録簿の「保証しないもの」に「削除した番号の再利用は検出できない」を明記

## [Warning] `T<n>` の表記ゆれ (施策 5)
- 判断: 対応する
- 対応内容: 3 桁以上のゼロ埋め (`T001` / `T099` / `T164`) に固定し、検査は `T\d{3,}` で照合すると明記

## [Suggestion] 規約節で強制対象と非対象を分ける (施策 1)
- 判断: 対応済み
- 対応内容: 登録簿に「この登録簿が保証しないもの」節を置く設計に既になっている

## 修正後の詳細設計 (全文)

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

1. **囲みコード区画を先に落とす**。行頭 ` ``` ` でトグルし、閉じずに終端したら
   `P1: 囲みコード区画が閉じていない (解析不能)` を返して**それ以上解析しない** (fail-closed)
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
6. セル値は `|` で分割して trim する。値の妥当性は `DivergenceLedgerRules` が見る

### 判定の仕様 (`DivergenceLedgerRules`)

| 記号 | 規則 | 出典 |
|---|---|---|
| TD1 | 見出しは正準形・id は一意 | i4 |
| TD2 | メタ表は 9 行ちょうど・ラベルは規定の順序 | i2 |
| TD3 | 対象パスは 1 件以上・リポジトリ相対・glob/絶対/`..` 不可・**`is_file()` で実在**。セルはバッククォート囲みのパスを ` / ` でつないだ形だけを許し、バッククォートの外に空白以外の文字があれば違反 | i5 |
| TD4 | 全登録の対象パスの和集合で重複しない | i5 |
| TD5 | 状態は `恒久` / `監視中` の 2 語 | i3 |
| TD6 | `監視中` は見直し期限必須・実在する日付・**基準日から 400 日以内**・**期限切れは不合格** | i8 |
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
- **日付は往復で検証する**。`CarbonImmutable::createFromFormat('!Y-m-d', $value)` の結果を
  `format('Y-m-d')` して元の文字列と一致するときだけ実在する日付と認める
  (`Carbon::parse()` は `2026-02-30` を 3/2 へ正規化して通してしまう)
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
- [ ] 負例 TD10c: `T1` が `T10` の登録に一致して通らないこと (境界付き照合の正のコントロール)
- [ ] 負例 TD10d: 根拠に実在しない `devnotes/9999-nope/` を書くと落ちること
- [ ] 負例 TD3d: 対象パスにディレクトリを書くと落ちること (`is_file` であることの確認)
- [ ] 負例 TD3e: 対象パスのセルにバッククォート外の説明文を添えると落ちること
- [ ] 負例 P1: 囲みコード区画が閉じていない fixture が「解析不能」で落ち、**以降の規則を評価せずに返すこと**
- [ ] 負例 P2: 登録エントリ領域が見つからない (見出しが 1 件も無い) fixture が落ちること
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
