# 詳細設計レビュー Round 2

Round 1 の指摘に対する対応マトリクスと、修正後の詳細設計書全文を送ります。

**反論している項目が 2 件あります** ([Critical] LIKE の `ESCAPE` 句 / [Warning] `Assert::string`)。
根拠を読んだうえで、なお修正が必要なら理由とともに指摘してください。
反論に同意できるなら、その旨を明記して次の判定へ進んでください。

判定を再度お願いします (各施策 APPROVE / REQUEST_CHANGES、全体 APPROVED / CHANGES_REQUESTED)。

---

# 対応マトリクス: design-review Round 1

Codex 判定: **CHANGES_REQUESTED** (Critical 4 / Warning 8 / Suggestion 2)。

## [Critical] 施策1: `addcslashes()` だけでは LIKE エスケープ契約として不十分。`ESCAPE '\'` を明示せよ (whereRaw 等)
- 判断: **反論する** (ただしテストで実挙動を固定する形で一部を受ける)
- 根拠:
  1. **PostgreSQL の `LIKE` は `ESCAPE` 句を省略したときの既定 escape 文字が `\` である**
     (SQL 標準ではなく PostgreSQL の仕様として明文化されている)。よって
     `ESCAPE '\'` を書いても書かなくても**同じ意味**であり、付けても挙動は 1 mm も変わらない。
  2. Codex が懸念する「文字列リテラルの解釈 (`standard_conforming_strings`)」は
     **本件に無関係**である。検索語は PDO の**バインド変数**として渡り、SQL 文字列リテラルとして
     解釈される経路を通らない。
  3. **既存の title 検索が同じ規則で動いており、pgsql 実接続の Feature テストで固定済み**である
     (`ProjectShowManualsTest`「q フィルタは title 部分一致 (LIKE メタ文字はリテラル扱い)」の
     `?q=100%25` が `洗浄 100% 完全版` を 1 件返す)。仮説ではなく実測が既にある。
  4. Codex の修正案 (`whereRaw('"title" like ? escape ...')`) は**明確に悪化**する:
     列名の引用を自前で書くことになり、`cuts` 側は 4 列 + 入れ子 group を raw で組む必要が出る。
     テナント境界に関係のない領域でクエリビルダの安全機構を捨てることになる。
  5. 本タスクのブリーフも「エスケープは既存実装 (addcslashes) と統一する」を要件としている。
- 対応内容 (一部受け):
  - **保証範囲を誇張しない記述**を `ManualKeywordSearch` の docblock と設計書へ足す —
    「この escape 規則が成立するのは `LIKE` の既定 escape 文字が `\` である DBMS
    (PostgreSQL / MySQL) に限る。**sqlite では `\` は既定 escape ではないので成立しない**。
    これは本改善が新たに持ち込む制約ではなく、既存 title 検索と同じ前提である」。
  - エスケープのテストを **`%` だけでなく `_` と `\` まで**広げ、**カット本文列でも**固定する
    (施策 3 のテスト計画を拡充)。

## [Critical] 施策3: grouping の意図 (`... and (title like ? or exists (...))`) を SQL の形として固定せよ。境界テストを完了条件に明示せよ
- 判断: **対応する** (ただし `toSql()` 文字列断言は採らない)
- 根拠: 完了条件への昇格は正しい。一方 `toSql()` の文字列一致は
  Laravel のバージョン差 (括弧の付き方・別名) で壊れる脆いテストで、**守りたい性質を直接見ていない**。
  守りたいのは「呼び出し側が積んだ条件が無効化されないこと」であり、それは DB 実行で
  直接観測できる。より強い負のコントロールを behavioral で書ける。
- 対応内容:
  - 施策 1 / 3 / 4 に **「完了条件」節**を新設し、テナント境界テストの通過を明記した。
  - `ManualKeywordSearchBoundaryTest` に**純粋な負のコントロール**を追加した:
    「`apply()` は呼び出し側が積んだ条件を無効化しない」 —
    一致語を持たない manual だけに絞ったクエリ (`whereKey($nonMatchingId)`) に対して
    別 manual に一致する語で `apply()` し、**0 件**であること。
    入れ子 group を外すと必ず赤くなり、SQL 文字列には依存しない。

## [Critical] 施策5: index の事前確認が人手手順で実装者依存
- 判断: **対応する** (さらに「実装時に確認」から「コード読解で確定済み」へ格上げする)
- 根拠: Codex の指摘どおり「実装者が確認する」は設計としては弱い。
  しかも本件は**確認を待たずに確定できる**: (1) `create_cuts_table` migration が索引を宣言していない、
  (2) `cuts` の索引を足す migration は他に 1 本も無い (`grep index( database/migrations` で確認済み)、
  (3) Laravel の `Grammar::compileForeign()` は FK 制約しか出さない (vendor 実読)、
  (4) PostgreSQL は FK 列に索引を自動生成しない。→ **索引は存在しない**と断定できる。
- 対応内容: 施策 5 を「実装時に確認して落とすかもしれない施策」から
  **「根拠を示した確定施策」**へ書き換えた。証拠の連鎖を設計へ明記し、
  実装の完了条件として `Schema::getIndexes('cuts')` の出力を devnotes へ 1 度貼ることを残した
  (断定が外れていた場合に静かに重複索引を作らないための保険)。

## [Critical] 追加: 別 organization の本文一致が混ざらないテストを施策 1/3/4 の完了条件へ昇格せよ
- 判断: **対応する**
- 根拠: 正しい。境界テストを「別ファイルに置いた付随物」にすると、施策単位のレビューで
  「その施策は緑」と言えてしまう。OR 漏れは施策 1 の実装 1 行で起き、施策 3/4 の両面に出る。
- 対応内容: 施策 1 / 3 / 4 の**完了条件**に `ManualKeywordSearchBoundaryTest` 全件通過を入れた。

## [Warning] 施策1: 契約 interface を受け型にする判断は Larastan の `@mixin` 解決に依存する
- 判断: **対応する** (証拠を足し、検証ゲートと具体的な代替案を明記する)
- 根拠: 依存しているのは事実。ただし**本リポジトリに実証がある**:
  `CaptureManualController` は `use Illuminate\Contracts\Database\Eloquent\Builder;` を import し、
  そのクロージャ引数に対して `->where('title','like',…)` / `->whereHas('adoptedTake', …)` /
  `->where('category_id', …)` を呼んでおり、`composer phpstan` level 10 が現に緑である。
  `orWhereHas` も同じ `@mixin` 経由で解決されるため、新しい依存を持ち込まない。
  また `Relation implements BuilderContract` は vendor 実読で確認済みなので、
  PC 側の `HasMany` をそのまま渡せる。
- 対応内容: 施策 1 に「型の根拠」節を足し、(a) vendor 実読の事実、(b) 同一リポジトリでの実証、
  (c) **level 10 が通らなかった場合の具体的な代替案**(呼び出し側で
  `->where(function (Builder $scoped) ...)` と括り、`apply()` は
  `Illuminate\Database\Eloquent\Builder<VideoManual>` だけを受ける形) を書いた。
  実装の**最初の 1 コミットで `composer phpstan` を回す**ことを完了条件に入れた。

## [Warning] 施策1: `BODY_COLUMNS` の列名 typo を PHPStan は検出できない
- 判断: **対応する** (ただし検出責務は既にテストにある。設計へ明記する)
- 根拠: PHPStan が列名を検証しないのは事実。ただし**typo は静かに落ちない** —
  PostgreSQL は存在しない列を参照した時点で `42703 undefined_column` を投げるため、
  検索を通る**すべての**テストが赤くなる。加えて設計のテスト計画は
  **4 列それぞれに 1 本ずつ**「その列だけに語を持つ manual が hit する」テストを置いており、
  これが列単位の取りこぼし検出そのものである。
- 対応内容: 施策 1 の docblock に「列名変更時の検出責務は 4 列個別テスト + pgsql の
  undefined_column が負う」と明記した。

## [Warning] 施策3: 範囲外ページ丸めと検索条件の相互作用の検証が弱い
- 判断: **対応する**
- 根拠: 正しい。丸め経路は `(clone $baseQuery)` を 2 回叩くため、キーワード条件が
  片方にしか乗っていないと `meta` が食い違う。現行構造では乗るはずだが、**テストが無い**。
- 対応内容: 施策 3 のテスト計画に
  「`?q=<11 件に一致する語>&page=999` で `meta.current_page=2` / `last_page=2` / `total=11` になり、
  `data` が 1 件返ること」を足した。

## [Warning] 施策4: PWA は `.get()` 全件のままで、検索述語が重くなる分の負荷評価が甘い
- 判断: **対応する**
- 根拠: 正しい。検索は行数を減らす方向だが、**無検索時の全件返却は残る**うえ、
  検索時も EXISTS の評価は母集団全体に掛かる。
- 対応内容: 施策 4 の「リスク」に想定上限 (1 project の ready/published は 200 本を上限として
  見積もる) と、超えた場合の後続対応 (概念設計の Conditional「撮影 PWA 一覧のページング」) を
  明記した。あわせて実装時に `EXPLAIN` を採る対象へ PWA 一覧も含めた。

## [Warning] 施策4: `Assert::string($search)` より `if ($search !== null)` で捕捉する方が読みやすい
- 判断: **見送る**
- 根拠: 現行 `CaptureManualController::index()` は `->when(...)` の連鎖 1 本でクエリを組み立てており、
  category / mine / canViewCover も同じ流儀である。ここだけ `if` 文へ抜くと**同一メソッド内に
  2 つの流儀が並ぶ**。`Assert::string($search)` は現行コードに既にある行で、
  差分を増やさない。可読性の好みの差であり、Codex 自身も「実装上は残してよい」と書いている。
- 対応内容: 変更なし。理由を設計の施策 4 へ 1 行残す。

## [Warning] 施策5: 性能説明に過信がある。`%LIKE%` に索引は効かない。EXPLAIN で確認せよ
- 判断: **対応する** (「効かない」は設計に既記。EXPLAIN を完了条件へ足す)
- 根拠: 設計は既に「`%語%` の LIKE 自体には B-tree 索引は効かない。本索引が支えるのは
  相関 nested-loop 計画のときの cuts 取得である」と書いている。過信ではないが、
  **実測を採る手順が無い**のは弱い。EXPLAIN は読み取りのみで dev DB を壊さない (禁止事項 3 に触れない)。
- 対応内容: 施策 5 に「完了条件」を足し、PC 一覧・PWA 一覧の検索クエリについて
  `EXPLAIN (ANALYZE, BUFFERS)` を **dev DB で読み取りのみ**実行し、
  選ばれた計画 (nested-loop / semi-join / seq scan) と実測時間を
  `devnotes/{dir}/explain-notes.md` へ貼ることを必須にした。
  seq scan が選ばれた場合は「想定規模では許容」の理由も併記させる。

## [Warning] 追加: pgsql の `like` が大小文字を区別することを UI/仕様側にも残せ
- 判断: **一部対応する**
- 根拠: 事実として残すべきという指摘は正しい。ただし **placeholder には書かない** —
  「タイトル・本文で検索 (英字の大小を区別します)」は主目的を薄め、
  日本語が主な現場利用者の大半に無関係な情報を毎回見せることになる。
- 対応内容: 保証範囲として `ManualKeywordSearch` の docblock と設計書の
  「実装しないと決めたもの」へ**明示的に**書いた (既記の ILIKE 行を、
  「現状は大小を区別する」という**現在の挙動の記述**に書き換えた)。

## [Warning] 追加: `source_documents` を対象外にする理由を North Star との距離込みで明確にせよ
- 判断: **対応する**
- 根拠: 正しい。「SOP 起点」が使命なので、SOP を検索しない理由は書いておくべきである。
- 対応内容: 設計へ境界を明記した —
  「本機能は**一覧に並ぶもの (= 生成済み動画マニュアル) の検索**であって、
  **原本 (SOP) の検索ではない**。SOP 原本は manual を作るための入力であり一覧の行ではない。
  『この SOP から作った manual はどれか』という需要は検索ではなく**関連の表示**で解く問題で、
  検索窓に混ぜると『出てきた行が SOP なのか manual なのか』が利用者に判別できなくなる」。

## [Suggestion] 施策2: `ManualKeywordSearch` を正規化と述語ビルダに分割する案
- 判断: **見送る**
- 根拠: 「検索語とは何か (正規化)」と「検索語が何に当たるか (述語)」は**1 つの概念の 2 面**である。
  分けると「検索の定義」が 2 ファイルに散り、片方だけ変えたときに食い違う —
  本改善が解消しようとしている問題そのものを再生産する。Codex も「現スコープでは必須ではない」と付記。
- 対応内容: 変更なし。

## [Suggestion] 施策6: 「本文」が scene/narration/subtitle を指すことは利用者にやや曖昧
- 判断: **見送る (追認)**
- 根拠: Codex 自身が「placeholder なので許容範囲」と判断している。
  代替語 (「原稿」) は scene を含まないため**より不正確**になる。
- 対応内容: 変更なし。


---

## 修正後の詳細設計書 (全文)

# 詳細設計: manual-search-scope (一覧検索の対象範囲の拡張)

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
5. LLM 呼び出しの Prism 直呼び(窓口 `PromptDefense` → 実行単位 `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

**本設計に効く追加の不変条件** (AGENTS.md セキュリティ不変条件):

- **3. cross-org 不可**: 組織を跨ぐ read をしない(relation / org-scoped 解決経由のみ)
- **6. PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
- **10. 層 2(テナント境界 = 404)は層 3(認可 = 403)より前**

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript + **PostgreSQL** (`phpunit.xml` L52 で `pgsql` 固定)

## 概念設計リファレンス

`devnotes/20260817-0909-manual-search-scope/conceptual-design.md` (Codex conceptual-review Round 1 で **APPROVED**)

要点:

- 検索対象を `video_manuals.title` + `cuts` の**本文 4 列** (`scene` / `narration` / `subtitle_primary` / `subtitle_secondary`) に広げる。`shooting_point` は**採らない**。
- PC 一覧と撮影 PWA 一覧で**対象を書き分けない**。述語と正規化を 1 箇所に置く。
- **作成者名検索は作らない** (blind index は完全一致しかできず、部分一致を期待する検索窓に混ぜると説明できない挙動になる。PII 暗号化を弱める案は禁止)。
- 検索語の正規化 (trim + 先頭 200 **文字**) を PC / PWA で一本化する (現在 PWA には上限も trim も無い)。
- `cuts.video_manual_id` の索引を足す (PostgreSQL は FK 列に索引を自動生成しない)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 検索述語と正規化の単一の正本を作る | `app/Services/Manual/ManualKeywordSearch.php` (新規) | 高 |
| 2 | `ManualListQuery` を正規化の正本から切り離す | `app/DataTransferObjects/Manual/ManualListQuery.php` | 高 |
| 3 | PC 一覧へカット本文検索を入れる | `app/Http/Controllers/Projects/ProjectController.php` | 高 |
| 4 | 撮影 PWA 一覧へカット本文検索と正規化を入れる | `app/Http/Controllers/Capture/CaptureManualController.php` | 高 |
| 5 | `cuts.video_manual_id` へ索引を足す | `database/migrations/*_add_video_manual_id_index_to_cuts_table.php` (新規) | 高 |
| 6 | 検索欄の文言を共有定数化して両面へ出す | `resources/js/lib/manual/search.ts` (新規) / `resources/js/pages/Projects/Show.svelte` / `resources/js/pages/Capture/Index.svelte` | 中 |
| 7 | 台帳 T053 の記述を訂正する | `docs/TODO-closed.md` | 中 |

---

## 施策 1: 検索述語と正規化の単一の正本を作る

### 変更箇所

- ファイル: `app/Services/Manual/ManualKeywordSearch.php` (**新規**)

置き場所の根拠: `App\Services\Manual\ManualRowAbilities` が既に「一覧のためのクエリ/権限を畳む
静的ヘルパ」として同じ名前空間にある。本クラスも同じ役割 (一覧のためのクエリ条件) なので揃える。
`App\Support\Manual\` は `ScenarioLimits` / `LlmJson` など Eloquent に触らない純粋ヘルパの置き場なので採らない。

### 波及変更

- TypeScript型定義: **なし** (props の shape を変えない)
- API Resource/DTO: `ManualListQuery` から `MAX_KEYWORD_LENGTH` と `mb_substr` を移す (施策 2)
- テストファイル: 施策 3 / 4 のテスト計画に記載

### 現行コード

該当クラスは存在しない。検索述語は 2 箇所に**別々に**書かれている
(`ProjectController::manualRows()` L185-188 / `CaptureManualController::index()` L75-78)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * 動画マニュアル一覧のキーワード検索 (PC 一覧 / 撮影 PWA 一覧の**共通の正本**)。
 *
 * ここが 1 箇所であることに意味がある: 対象列・LIKE メタ文字のエスケープ規則・
 * 検索語の正規化を面ごとに書くと必ず食い違う (実際 T053 以降、PC 側だけに 200 文字上限があり
 * 撮影 PWA 側には無いという食い違いが生まれていた)。
 *
 * **検索対象** = `video_manuals.title` + 配下 `cuts` の**本文 4 列**。
 * doc/05 §5.2 の「原稿」は narration / subtitle を指すが、本クラスは `scene` を足して
 * 「カット本文」を対象にする。`scene` は `UpdateScenarioRequest` で唯一 `required` の
 * 本文列であり (narration / subtitle_secondary は `present` = 空文字可、
 * subtitle_primary は `nullable`)、外すと**手書きシナリオが本文検索に一切かからない**ため。
 *
 * `cuts.shooting_point` は**対象外**である。撮影者への構図指示 (doc/05 の「撮影ガイド」) で
 * あって作業内容ではなく、「手元を寄りで」のような定型句が多数のマニュアルに散らばるため、
 * 含めると精度だけが落ちる。
 *
 * **対象外だと明言するもの**: 大小文字を区別しない検索、語の分割・同義語・ランキング、
 * SOP 原本 (`source_documents`) の全文検索、作成者名の検索。
 *
 * **保証範囲を誇張しない (LIKE メタ文字のエスケープ)**:
 * `addcslashes($keyword, '%_\\')` が成立するのは **`LIKE` の既定 escape 文字が `\` である
 * DBMS** (PostgreSQL / MySQL) に限る。**sqlite では `\` は既定の escape 文字ではない**ため
 * この規則は成立しない。これは本クラスが新しく持ち込む制約ではなく、
 * 従来の title 検索と**同じ前提**である (本アプリの接続は pgsql)。
 * 検索語は PDO のバインド変数として渡るため、SQL 文字列リテラルの解釈
 * (`standard_conforming_strings`) は関与しない。
 *
 * **大小文字**: pgsql の `like` は**大小文字を区別する**。`abc` で `ABC` は hit しない。
 * これは従来の title 検索と同じ挙動であり、本改善では変えない (面によって挙動を変えないため)。
 *
 * **列名 typo の検出責務**: BODY_COLUMNS の列名を PHPStan は検証しない。
 * 検出は 2 段で負う — (1) 存在しない列は pgsql が `42703 undefined_column` を投げるため
 * 検索を通る**すべての**テストが赤くなる、(2) 4 列それぞれについて
 * 「その列にしか語を持たない manual が hit する」テストが列単位の取りこぼしを見る。
 */
final class ManualKeywordSearch
{
    /**
     * 検索語の最大長 (文字数。バイト数ではない)。
     *
     * **負荷制御のための上限**である。これを超える語を打つと**先頭 200 文字だけで検索される**
     * (打った語と違う条件で検索されることになる)。
     * かつて「title の validation が max:200 だから 201 文字目以降は一致に寄与しない」という
     * 根拠が書かれていたが、`cuts.narration` / `cuts.subtitle_secondary` は max:2000 なので
     * **その根拠はもう成立しない**。切り詰めが絞り込みを緩める方向にしか倒れないことは事実だが、
     * それを理由に「無害」とは書かない。
     */
    public const int MAX_LENGTH = 200;

    /**
     * 検索対象にする `cuts` の本文列。**この配列がカット本文の定義の正本**である。
     *
     * @var list<string>
     */
    private const array BODY_COLUMNS = [
        'scene',
        'narration',
        'subtitle_primary',
        'subtitle_secondary',
    ];

    /**
     * 生の検索語を正規化する。前後の空白を除き、空なら null、長ければ先頭 MAX_LENGTH **文字**。
     *
     * `mb_substr` を使うのは日本語を**文字数**で切るためである (`substr` はバイト数で切り、
     * UTF-8 の途中で割ると壊れた文字が LIKE に渡る)。
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, self::MAX_LENGTH);
    }

    /**
     * キーワード条件を**1 つの入れ子 group として**積む。
     *
     * **入れ子 group は必須である**。`orWhereHas` を素で積むと OR が外へ漏れ、
     * 呼び出し側が積んだ母集団条件 (`project_id` の relation 制約 / `status` の
     * ready・published 制限 / `created_by` の自作フィルタ) を**すべて無効化する**。
     * これは cross-project の manual が一覧に混ざる = テナント境界の破壊であり、
     * 本機能で最も危険な失敗様式である (`ManualKeywordSearchBoundaryTest` が固定)。
     *
     * `cuts` への条件は `orWhereHas` = 相関 EXISTS 副問い合わせであり、
     * **同一 SQL 内で完結する** (行ごとの追加クエリ = N+1 を生まない)。
     * join にしないのは、1 manual の複数カットが一致したときに行が重複し
     * paginate の総件数が壊れるためである。
     *
     * 実行計画は相関 nested-loop と hash semi-join の**どちらもありうる**。
     * PostgreSQL は WHERE 句の記述順で駆動表や索引を選ばないので、
     * 条件の並び順で計画を誘導しようとしない (施策 5 の索引が nested-loop 側を支える)。
     *
     * @param  Builder<\App\Models\VideoManual>  $query  VideoManual を返すクエリ (Relation でも可)
     */
    public static function apply(Builder $query, string $keyword): void
    {
        // LIKE メタ文字 (%/_/\) はリテラル検索として扱う (現行 title 検索と同じ規則)
        $like = '%'.addcslashes($keyword, '%_\\').'%';

        $query->where(function (Builder $scoped) use ($like): void {
            $scoped
                ->where('title', 'like', $like)
                ->orWhereHas('cuts', function (Builder $cuts) use ($like): void {
                    $cuts->where(function (Builder $body) use ($like): void {
                        // 入れ子 group の先頭の boolean は grammar が落とすため全件 orWhere でよい
                        foreach (self::BODY_COLUMNS as $column) {
                            $body->orWhere($column, 'like', $like);
                        }
                    });
                });
        });
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`?string` / `void`)
- [x] null 安全 (`normalize` は `?string` を受けて `?string` を返す。`apply` は非 null の `string` のみ受ける = 呼び出し側が null 判定を済ませる)
- [x] DTO を返している (配列返却なし。本クラスは値を返さない述語ビルダ)
- [x] `private const array BODY_COLUMNS` に `@var list<string>` を付ける (PHP 8.3+ の型付きクラス定数 + PHPStan の list 推論)

### 受け型の根拠 (Codex Round 1 [Warning] への回答)

受け型は **`Illuminate\Contracts\Database\Eloquent\Builder`** (契約 interface) にする。

根拠は 3 つ:

1. **vendor 実読の事実**: この interface は
   `@mixin \Illuminate\Database\Eloquent\Builder` を持つ**意図的に空の** interface で
   (`vendor/laravel/framework/src/Illuminate/Contracts/Database/Eloquent/Builder.php` の
   "This interface is intentionally empty and exists to improve IDE support")、
   **`Illuminate\Database\Eloquent\Builder`** (`class Builder implements BuilderContract`) と
   **`Illuminate\Database\Eloquent\Relations\Relation`** (`abstract class Relation implements BuilderContract`)
   の**両方が implements している**。
   よって PC 側の `$project->manuals()->with([...])` (= `HasMany`) も
   PWA 側の `when()` クロージャ引数 (= `Eloquent\Builder`) も**そのまま渡せる**。
2. **本リポジトリでの実証**: `CaptureManualController` は既にこの契約 interface を import し、
   そのクロージャ引数に対して `->where('category_id', …)` / `->where('title','like',…)` /
   `->whereHas('adoptedTake', …)` / `->whereHas('takes')` を呼んでいる。
   `composer phpstan` level 10 は**現に緑**である。`orWhereHas` は同じ `@mixin` 経由で
   解決されるため、**新しい依存を持ち込まない**。
3. Larastan の `@mixin` 解決に依存しているのは事実なので、**検証ゲートを置く** (下記完了条件)。

**level 10 が通らなかった場合の代替案 (事前に決めておく)**:
`apply()` の受け型を `Illuminate\Database\Eloquent\Builder<\App\Models\VideoManual>` に変え、
公開 API を `apply(Builder $query, string $keyword)` から
**呼び出し側で group を開かせる形**へ寄せる:

```php
// 呼び出し側 (PC / PWA 共通)
$query->where(function (Builder $scoped) use ($keyword): void {
    ManualKeywordSearch::applyInsideGroup($scoped, $keyword);
});
```

`Builder::where(Closure)` のクロージャは**必ず `Eloquent\Builder` を受け取る**
(`$this->model->newQueryWithoutRelationships()` が渡される) ので、契約 interface に頼らずに済む。
**この代替案は第 2 案である** — group を開く責務が呼び出し側 2 箇所に移り、
「片方だけ括り忘れる」余地が生まれるため、通るなら第 1 案を採る。

### 完了条件 (施策 1)

- [ ] 実装の**最初のコミットで `composer phpstan` を回し**、契約 interface 受けが level 10 を
      通ることを確認する。通らなければ上記の代替案へ切り替え、切り替えた事実を
      `devnotes/{dir}/` の実装メモへ残す
- [ ] `tests/Unit/Manual/ManualKeywordSearchTest.php` が緑
- [ ] `tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php` が**全件**緑
      (テナント境界は施策 1 の実装 1 行で壊れるため、施策 1 の完了条件に含める)

### テスト計画

- [ ] 新規 `tests/Unit/Manual/ManualKeywordSearchTest.php` — `normalize()` の純粋関数契約
  - `null` → `null`
  - `'   '` (空白のみ) → `null`
  - `'  ネジ  '` → `'ネジ'` (trim)
  - `str_repeat('あ', 201)` → `str_repeat('あ', 200)` (**文字数**で切る。長さを `mb_strlen` で 200 と検査し、バイト長 600 も併記して「bytes ではない」ことを固定する)
  - `str_repeat('あ', 200)` → そのまま (境界で切らない)

### リスク

- なし (新規クラスで既存経路を変えない。既存経路の差し替えは施策 2〜4)。

---

## 施策 2: `ManualListQuery` を正規化の正本から切り離す

### 変更箇所

- ファイル: `app/DataTransferObjects/Manual/ManualListQuery.php` (L24-27 docblock / L35-36 定数 / L83-86 解析)

### 波及変更

- TypeScript型定義: **なし** (`toProps()` の shape は不変)
- API Resource/DTO: 本ファイル自体
- テストファイル: 既存 `tests/Feature/Projects/ProjectShowManualsTest.php`「q は先頭 200 文字で絞り込む」は**挙動が同値なのでそのまま通る** (削除・上書きしない)

### 現行コード

```php
    /** 検索語の最大長 (StoreVideoManualRequest の title max:200 と一致させる) */
    public const int MAX_KEYWORD_LENGTH = 200;
...
        $keyword = $request->query('q');
        $keyword = is_string($keyword) && trim($keyword) !== ''
            ? mb_substr(trim($keyword), 0, self::MAX_KEYWORD_LENGTH)
            : null;
```

docblock L24-27:

```
 * - `keyword`: 前後の空白を除いた検索語。**先頭 MAX_KEYWORD_LENGTH 文字だけを使う (truncate)**。
 *   破棄 (= 絞り込み無し) にしないのは「全件が出る」驚きの方向へ倒れるためで、
 *   切り詰めは「より広く当たる」方向にしか倒れない。title の validation が max:200 なので、
 *   201 文字目以降が一致に寄与することは無い
```

### 変更後コード

```php
    // MAX_KEYWORD_LENGTH は ManualKeywordSearch::MAX_LENGTH へ移した。
    // 「検索語とは何か」の定義を 1 箇所に持たせるため (撮影 PWA も同じ定義を使う)。
...
        $rawKeyword = $request->query('q');
        // 正規化 (trim + 先頭 MAX_LENGTH 文字) の正本は ManualKeywordSearch。
        // 撮影 PWA 一覧と**同じ関数**を通す (面ごとに検索語の定義が違う状態を作らない)
        $keyword = ManualKeywordSearch::normalize(is_string($rawKeyword) ? $rawKeyword : null);
```

docblock L24-27 の差し替え:

```
 * - `keyword`: 検索語。正規化 (前後の空白を除く / 先頭 ManualKeywordSearch::MAX_LENGTH 文字)
 *   の正本は ManualKeywordSearch::normalize であり、撮影 PWA 一覧も同じ関数を通る。
 *   空白のみ・空文字は null (= 絞り込み無し)。**上限は負荷制御のためであり、
 *   超えた分は検索に寄与しない** (打った語と違う条件で検索されることになる)。
 *   かつて書かれていた「title の max:200 なので 201 文字目以降は寄与しない」という根拠は
 *   カット本文 (narration / subtitle_secondary は max:2000) を対象に含めた時点で成立しない
```

`use App\Services\Manual\ManualKeywordSearch;` を追加する。

**後方互換の並走を残さない**: `ManualListQuery::MAX_KEYWORD_LENGTH` は
**別名として残さず削除する** (参照は本ファイル内の 2 箇所のみで、`grep` で外部参照が
無いことを確認済み)。

### PHPStan適合チェック

- [x] `$request->query('q')` は `mixed` を返すため `is_string()` で絞ってから渡す (現行と同じ絞り方)
- [x] `normalize()` の戻りは `?string` で `ManualListQuery::$keyword` の宣言型と一致
- [x] 定数削除による未定義参照が無いこと (`grep -rn MAX_KEYWORD_LENGTH` が 0 件になること)

### テスト計画

- [ ] 既存 `tests/Feature/Projects/ProjectShowManualsTest.php`「q は先頭 200 文字で絞り込む」が**変更なしで通る**こと (挙動同値のリグレッション確認)
- [ ] 施策 1 の Unit テストが正規化契約を固定する

### リスク

- DTO が Service を参照する向きになる。`ManualKeywordSearch::normalize` は**副作用の無い静的純粋関数**であり DB にもコンテナにも触れないため、DTO のテスト容易性は落ちない。逆に正規化を DTO 側に残すと撮影 PWA が「一覧 DTO」を目的外に依存することになり、そちらの方が歪む。

---

## 施策 3: PC 一覧へカット本文検索を入れる

### 変更箇所

- ファイル: `app/Http/Controllers/Projects/ProjectController.php` (`manualRows()` L185-188)

### 波及変更

- TypeScript型定義: **なし** (`manuals` / `manualFilters` の shape は不変)
- API Resource/DTO: **なし** (`ManualListItemData` は不変)
- テストファイル: `tests/Feature/Projects/ProjectShowManualsTest.php` に追記 / `tests/Feature/Projects/ManualListQueryCountTest.php` に追記 / 新規 `tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php`

### 現行コード

```php
        if ($listQuery->keyword !== null) {
            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う
            $baseQuery->where('title', 'like', '%'.addcslashes($listQuery->keyword, '%_\\').'%');
        }
```

### 変更後コード

```php
        if ($listQuery->keyword !== null) {
            // title + カット本文 (scene / narration / subtitle_*) の部分一致。
            // 述語の正本は ManualKeywordSearch (撮影 PWA 一覧と同じ関数を通る)。
            // **入れ子 group で括られる**ため、上で積んだ mine / category / progress と
            // relation の project_id 制約は OR に押し出されない
            ManualKeywordSearch::apply($baseQuery, $listQuery->keyword);
        }
```

`use App\Services\Manual\ManualKeywordSearch;` を追加する。

**適用順は現行のまま最後**にする (入れ子 group なので順序は結果に影響しないが、
差分を最小に保つ)。`(clone $baseQuery)` の 2 箇所 (paginate と範囲外ページの丸め) は
**キーワード条件が積まれた後の `$baseQuery` を clone している**ので、丸め後のページでも
同じ絞り込みが効く (現行と同じ構造)。

### PHPStan適合チェック

- [x] `$listQuery->keyword` は `?string`。`!== null` の分岐内なので `string` に絞れている
- [x] `$baseQuery` (`HasMany<VideoManual, Project>` に `with()` を積んだもの) は `Illuminate\Contracts\Database\Eloquent\Builder` を満たす (`Relation implements BuilderContract`)
- [x] 戻り値の型 (`manualRows()` の array shape) は不変

### テスト計画

`tests/Feature/Projects/ProjectShowManualsTest.php` へ追記 (既存テストは削除・上書きしない):

- [ ] `q は narration に部分一致する (title に無くても hit する)` — title に語を含まない manual に `narration` だけ一致するカットを付け、1 件返ること
- [ ] `q は scene / subtitle_primary / subtitle_secondary のいずれに一致しても hit する` — 4 列それぞれ 1 本ずつの manual を作り、各列の固有語で 1 件ずつ返ること (**列の取りこぼしを 1 列単位で検出する**)
- [ ] `q は shooting_point には一致しない (対象外列)` — `shooting_point` にだけ語を持つ manual が **0 件**になること (**hit しない側**)
- [ ] `q はカット本文にも title にも一致しない manual を除外する` (**hit しない側**)
- [ ] `本文が複数カットに一致しても manual は 1 行だけ返る` — 同一 manual の 3 カットすべてに同じ語を入れ、`manuals.data` が 1 件・`meta.total` が 1 であること (**join 化して行が重複していないことの証拠**)
- [ ] `q はカット本文でも LIKE メタ文字をリテラル扱いする (%/_/\ の 3 文字)` —
      **3 文字すべてを見る** (Codex Round 1 の指摘を受けて `%` だけから拡張):
  - `narration` に `洗浄 100% 完全版` を持つ manual と `洗浄 1005 完全版` を持つ manual を作り、
    `?q=100%25` (= `100%`) が**前者だけ**を返すこと (`%` がワイルドカード化していない)
  - `narration` に `A_B` を持つ manual と `AXB` を持つ manual を作り、
    `?q=A_B` が**前者だけ**を返すこと (`_` が任意 1 文字になっていない)
  - `narration` に `C\D` を持つ manual を作り、`?q=C%5CD` (= `C\D`) が 1 件返ること
    (エスケープ文字自身がリテラルとして通ること)
- [ ] `mine=1 と q は AND で効く` — 他人が作った本文一致 manual が出ないこと
- [ ] `progress フィルタと q は AND で効く` — 状態が外れる本文一致 manual が出ないこと
- [ ] `category フィルタと q は AND で効く`
- [ ] `q は先頭 200 文字で切られる (カット本文でも)` — `narration` に `str_repeat('あ',200).'ZZZ'` を持つ manual と別 manual を用意し、`?q=` に 203 文字を渡して前者だけが返ること

- [ ] `検索条件付きでも範囲外ページは丸められ meta が食い違わない` (Codex Round 1 [Warning] 対応) —
      本文に同じ語を持つ manual を 11 本作り、`?q=語&page=999` で
      `meta.current_page=2` / `meta.last_page=2` / `meta.total=11` / `data` 1 件になること。
      **丸めは `(clone $baseQuery)` を 2 回叩く**ため、キーワード条件が片方にしか乗っていないと
      `total` が食い違って赤くなる

`tests/Feature/Projects/ManualListQueryCountTest.php` へ追記:

- [ ] `検索ありでも一覧のクエリ数は行数に比例しない` — 既存の計測ヘルパと同じ形で、`?q=<全行に一致する語>` を付けた 1 行ページと 10 行ページのクエリ数が同数であること (**EXISTS が行ごとの追加クエリになっていないことの固定**)

### 完了条件 (施策 3)

- [ ] 上記の追記テストが全件緑
- [ ] `tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php` の **PC 面の全ケース**が緑
      (別 project / 別 organization / `mine` の 3 条件が OR に押し出されないこと)
- [ ] 既存 `ProjectShowManualsTest` / `ManualListQueryCountTest` が**無変更で**緑

### リスク

- **OR の漏れ**でテナント境界が壊れる → 施策 1 の入れ子 group + 下記境界テストで固定する。
- `%語%` の LIKE で `cuts` の逐次走査が増える → 施策 5 の索引と、想定規模 (project あたり cuts 10^3〜10^4) で許容。実測が想定を超えたら概念設計の Conditional (pg_trgm) を起こす。
- 検索の当たりが広がることで「以前は 1 件だったのに 5 件出る」変化が起きる → placeholder 文言 (施策 6) で対象が広いことを示す。

---

## 施策 4: 撮影 PWA 一覧へカット本文検索と正規化を入れる

### 変更箇所

- ファイル: `app/Http/Controllers/Capture/CaptureManualController.php` (`index()` L61 / L75-78 / L105)

### 波及変更

- TypeScript型定義: **なし** (`filters: { category, q, mine }` の shape は不変。`q` の**値**が正規化後になる)
- API Resource/DTO: **なし** (`CaptureManualSummaryData` は不変)
- テストファイル: `tests/Feature/Capture/CaptureManualBrowsingTest.php` に追記 / `tests/Feature/Capture/CaptureManualListQueryCountTest.php` に追記 / 新規 `tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php`

### 現行コード

```php
        $search = $request->filled('q') ? $request->string('q')->value() : null;
...
            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う (PC 一覧 manualRows と統一)
            ->when($search !== null, function (Builder $query) use ($search): void {
                Assert::string($search);
                $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%');
            })
```

**現行の欠陥** (ブリーフに無かったが実読で判明):

- `trim` していない → `?q=%20` が「空白 1 文字」の検索として成立し 0 件になる
- 長さ上限が無い → PC 側 (200 文字) と食い違い、長文でも LIKE に渡る
- `filled('q')` は `'0'` を truthy 判定する Laravel の仕様に依存しており、
  `?q=0` は通るが `?q=` は通らない。正規化関数へ寄せると判定が 1 本になる

### 変更後コード

```php
        $categoryId = $request->filled('category') ? (int) $request->string('category')->value() : null;
        // 検索語の正規化 (trim + 先頭 200 文字) の正本は ManualKeywordSearch。
        // PC 一覧 (ManualListQuery 経由) と**同じ関数**を通す
        $rawSearch = $request->query('q');
        $search = ManualKeywordSearch::normalize(is_string($rawSearch) ? $rawSearch : null);
        $mine = $request->boolean('mine'); // "1"/"true" を bool 正規化
...
            // title + カット本文 (scene / narration / subtitle_*) の部分一致。
            // 述語の正本は ManualKeywordSearch (PC 一覧と同じ関数を通る)。
            // **入れ子 group で括られる**ため、ready/published の母集団制限と
            // category / mine の絞り込みは OR に押し出されない
            ->when($search !== null, function (Builder $query) use ($search): void {
                Assert::string($search);
                ManualKeywordSearch::apply($query, $search);
            })
```

`use App\Services\Manual\ManualKeywordSearch;` を追加する。

`filters` prop (L105) は**変更しない**。`'q' => $search` が正規化後の値になるため、
**利用者の検索欄には切り詰め・trim 後の語が戻る** (PC の `manualFilters.q` と同じ流儀)。

`Assert::string($search)` は現行どおり残す (`use ($search)` の時点で PHPStan は
`?string` としか見ないため。クロージャ外の `!== null` は narrowing されない)。

> Codex Round 1 [Warning] は `if ($search !== null) { ... }` へ抜く方が読みやすいと指摘したが
> **見送る**。`index()` は category / mine / canViewCover も含めて `->when()` の連鎖 1 本で
> クエリを組み立てており、ここだけ `if` 文へ抜くと同一メソッド内に 2 つの流儀が並ぶ。
> `Assert::string($search)` は現行コードに既にある行で、差分を増やさない。

### PHPStan適合チェック

- [x] `$request->query('q')` は `mixed` → `is_string()` で絞る (現行の `$request->string()` は
      配列が来ると例外を投げうるため、`query()` + `is_string()` の方が安全側)
- [x] `Assert::string($search)` でクロージャ内の `?string` → `string` を確定 (現行踏襲)
- [x] `apply()` の第 1 引数は `Illuminate\Contracts\Database\Eloquent\Builder` で、
      クロージャ引数の型 (同 interface。現行の import をそのまま使う) と一致する
- [x] `filters.q` の型は `string|null` のまま (TS の `filters: { q: string | null }` と一致)

### テスト計画

`tests/Feature/Capture/CaptureManualBrowsingTest.php` へ追記:

- [ ] `q は narration に部分一致する (撮影 PWA でも本文で当たる)`
- [ ] `q は scene / subtitle_primary / subtitle_secondary のいずれでも hit する`
- [ ] `q は shooting_point には一致しない` (**hit しない側**)
- [ ] `q は draft / analyzing の manual を拾わない (ready/published の母集団が保たれる)` —
      本文に一致語を持つ `draft` の manual を用意し、**0 件**であること (**最重要**)
- [ ] `mine=1 と q は AND で効く` — 他人が作った本文一致 manual が出ないこと
- [ ] `category と q は AND で効く`
- [ ] `q は前後の空白を trim する` — `?q=%20ネジ%20` で hit し、`filters.q` が `'ネジ'` であること (**新規契約**)
- [ ] `q が空白のみなら絞り込まない` — `?q=%20%20` で全件返り `filters.q` が `null` であること (**新規契約**)
- [ ] `q は先頭 200 文字 (文字数) で切られ filters.q も切り詰め後を返す` — 203 文字を渡して `filters.q` の `mb_strlen` が 200 であること (**新規契約**)

`tests/Feature/Capture/CaptureManualListQueryCountTest.php` へ追記:

- [ ] `検索ありでも撮影一覧のクエリ数は行数に比例しない` — 既存の `measureCaptureIndexQueries` を `?q=` 付きで呼ぶ変種を足し、1 行 / 10 行で同数であること

### 完了条件 (施策 4)

- [ ] 上記の追記テストが全件緑
- [ ] `tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php` の **撮影 PWA 面の全ケース**が緑
      (別 project / 別 organization / `ready·published` の母集団制限 / `mine` の 4 条件が
      OR に押し出されないこと)
- [ ] 既存 `CaptureManualBrowsingTest` / `CaptureManualListQueryCountTest` /
      `tests/Browser/AuthenticatedPageBfcacheTest.php` が**無変更で**緑

### リスク

- `filters.q` が正規化後の値になるため、極端に長い語を打った利用者の入力欄が 200 文字へ縮む。**PC 一覧が既にそう振る舞っており**、面を揃える方向なので受容する。
- `?q=0` の扱いが `filled()` 判定から `normalize()` 判定に変わる。`'0'` は `trim('0') !== ''` なので**引き続き検索語として成立する** (挙動不変)。
- **PWA 一覧は paginate せず `.get()` で全件返す** (本改善の原因ではない既存仕様)。
  検索は行数を減らす方向だが、**EXISTS の評価は母集団全体に掛かる**うえ、
  無検索時の全件返却は残る。
  - **想定上限**: 1 project の ready/published を **200 本**、manual あたり cut を 50 まで
    (= EXISTS が見る cuts は最大 10^4 行) と見積もる。この範囲では
    `%LIKE% + EXISTS + withCount` の一括評価で数十 ms を想定する。
  - **超えたときの対応**: 概念設計の Conditional「撮影 PWA 一覧のページング」
    (引き金: 1 project の ready/published が 200 本を超える) を起こす。
    **本改善で先回りしてページングを作らない** (思考原則 2)。
  - 実装時の `EXPLAIN` 採取対象に**撮影 PWA 一覧の検索クエリも含める** (施策 5 の完了条件)。

---

## 施策 5: `cuts.video_manual_id` へ索引を足す

### 変更箇所

- ファイル: `database/migrations/2026_08_17_000000_add_video_manual_id_index_to_cuts_table.php` (**新規**)

### 前提: 索引が存在しないことは**コード読解で確定済み** (Codex Round 1 [Critical] 対応)

Round 1 の設計は「実装時に確認して、あれば施策を落とす」と書いていたが、それは実装者依存で弱い。
**確認を待たずに確定できる**ので、証拠の連鎖を示して確定施策に格上げする:

1. `database/migrations/2026_07_10_000300_create_cuts_table.php` は
   `$table->foreignId('video_manual_id')->constrained()->cascadeOnDelete();` だけで、
   `index()` を 1 つも宣言していない (全文を実読)。
2. `cuts` に索引を足す migration は**他に 1 本も無い**
   (`grep -n "index(" database/migrations/*.php` の全出力に `cuts` 由来の行が無い)。
   後付け migration `2026_07_10_000500_add_foreign_keys_to_cuts_table.php` も
   `foreign()` を 2 本張るだけで索引を作らない。
3. Laravel の `Grammar::compileForeign()` は
   `alter table … add constraint … foreign key …` しか出さず、**索引は作らない** (vendor 実読)。
4. **PostgreSQL は FK 列に索引を自動生成しない** (MySQL/InnoDB とは異なる)。

→ `cuts.video_manual_id` に索引は**存在しない**。migration は無条件に作る。

保険として、断定が外れていた場合に静かに重複索引を作らないよう、
実装の最初に `Schema::getIndexes('cuts')` の出力を 1 度だけ採り、
`devnotes/{dir}/index-precheck.md` へ貼る (完了条件)。

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: なし
- テストファイル: 新規 `tests/Feature/Database/CutsIndexTest.php`

### 変更後コード

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * cuts.video_manual_id へ索引を足す。
     *
     * **PostgreSQL は FK 列に索引を自動生成しない** (MySQL/InnoDB とは異なる)。
     * 元の create migration は foreignId()->constrained() だけで索引を宣言していないため、
     * cuts を video_manual_id で引く経路がすべて逐次走査になっていた。
     *
     * 効く経路は本改善のカット本文検索 (相関 EXISTS) だけではない:
     * 撮影 PWA 一覧の withCount(['cuts', ...]) は**行ごとに** cuts への相関副問い合わせを
     * 出しており、索引が無いと cuts 全走査 × 表示行数になる。
     * シナリオ編集・レンダリングの cuts 取得、manual 削除時の cascade も同様。
     *
     * `%語%` の LIKE 自体には B-tree 索引は効かない (前方一致でないため)。
     * 本索引が支えるのは**相関 nested-loop 計画のときの cuts 取得**である。
     * pg_trgm + GIN は導入しない (拡張の導入は運用権限と運用負担を増やす。
     * 引き金は devnotes の概念設計に Conditional として記録した)。
     */
    public function up(): void
    {
        Schema::table('cuts', function (Blueprint $table): void {
            $table->index('video_manual_id');
        });
    }

    public function down(): void
    {
        Schema::table('cuts', function (Blueprint $table): void {
            $table->dropIndex(['video_manual_id']);
        });
    }
};
```

索引名は Laravel 既定の `cuts_video_manual_id_index`。

### PHPStan適合チェック

- [x] Blueprint クロージャに `: void` を付ける (既存 migration の流儀)
- [x] 匿名クラス migration の書式は既存踏襲

### テスト計画

- [ ] 新規 `tests/Feature/Database/CutsIndexTest.php` —
      `Schema::getIndexes('cuts')` に `video_manual_id` を**先頭列**に持つ索引が 1 本以上あること。
      (既存 `tests/Feature/Database/IdempotencyStateMigrationTest.php` と同じ流儀。
      索引が黙って消えたら赤くなる)

### 完了条件 (施策 5) — 実測を採る (Codex Round 1 [Warning] 対応)

「索引を足したから速い」で終えない。**索引は `%語%` の LIKE 自体には効かない**ので、
何が効いて何が効いていないかを実測で分けて記録する。

- [ ] `Schema::getIndexes('cuts')` の migration **前**の出力を `devnotes/{dir}/index-precheck.md` へ貼る
      (「索引が無い」という前提の実証)
- [ ] PC 一覧の検索クエリ / 撮影 PWA 一覧の検索クエリの 2 本について、
      **dev DB で `EXPLAIN (ANALYZE, BUFFERS)` を読み取りのみ実行**し
      (SELECT の実行計画取得であり dev DB への破壊操作ではない = 禁止事項 3 に触れない)、
      結果を `devnotes/{dir}/explain-notes.md` へ貼る。記録するのは 3 つ:
  - 選ばれた計画 (相関 nested-loop / hash semi-join / seq scan のどれか)
  - `cuts` へのアクセス方法 (`Index Scan` / `Bitmap Heap Scan` / `Seq Scan`)
  - 実測時間
- [ ] `Seq Scan` が選ばれた場合は**それを異常としない**。想定規模 (project あたり cuts 10^3〜10^4)
      では正しい計画でありうるため、「行数 N のとき実測 M ms で許容」という**理由を併記**する。
      許容できない実測が出たときだけ概念設計の Conditional (pg_trgm) を起こす

### リスク

- 書き込み (cuts の INSERT/UPDATE/DELETE) が索引更新の分だけ僅かに遅くなる。cuts の書き込みは
  シナリオ保存時のバッチであり、読み取り側の利得が上回る。
- **索引が本改善の検索を直接速くするとは限らない**。`%語%` の LIKE には B-tree 索引が効かないため、
  hash semi-join が選ばれた場合の `cuts` へのアクセスは逐次走査になる。
  本索引が確実に効くのは (a) 相関 nested-loop が選ばれたときの cuts 取得、
  (b) 撮影 PWA 一覧の `withCount(['cuts', ...])` の相関副問い合わせ、
  (c) manual 削除時の cascade、の 3 つである。**(a) を保証としては書かない**。

---

## 施策 6: 検索欄の文言を共有定数化して両面へ出す

### 変更箇所

- `resources/js/lib/manual/search.ts` (**新規**)
- `resources/js/pages/Capture/Index.svelte` (L101 `placeholder="タイトルで検索"`)
- `resources/js/pages/Projects/Show.svelte` (L460-465 の `Input` に placeholder が無い)

### 波及変更

- TypeScript型定義: 新規定数のみ (props / 型の変更なし)
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/CaptureIndex.test.ts` / `tests/js/pages/ProjectsShow.test.ts`

### 現行コード

```svelte
<!-- resources/js/pages/Capture/Index.svelte L98-103 -->
<Input
    type="search"
    bind:value={search}
    placeholder="タイトルで検索"
    testId="capture-search"
/>
```

```svelte
<!-- resources/js/pages/Projects/Show.svelte L456-466 -->
<div class="flex min-w-40 grow flex-col gap-1">
    <label class="text-caption text-text-secondary" for="manual-filter-q">
        キーワード
    </label>
    <Input
        id="manual-filter-q"
        type="search"
        bind:value={filterQ}
        testId="manual-filter-q"
    />
</div>
```

### 変更後コード

```ts
// resources/js/lib/manual/search.ts (新規)

/**
 * 動画マニュアル一覧の検索欄に出す説明文言 (PC 一覧 / 撮影 PWA 一覧で共通)。
 *
 * サーバ側の検索対象は ManualKeywordSearch が正本で、タイトルに加えて
 * カット本文 (シーン / ナレーション / 字幕) に部分一致する。
 * **文言を 2 画面に別々に書かない**: 片方だけ直すと「タイトルで検索」のまま嘘が残る
 * (実際、対象を広げる前の撮影 PWA は「タイトルで検索」と書いていた)。
 */
export const MANUAL_SEARCH_PLACEHOLDER = "タイトル・本文で検索";
```

```svelte
<!-- Capture/Index.svelte -->
<Input
    type="search"
    bind:value={search}
    placeholder={MANUAL_SEARCH_PLACEHOLDER}
    testId="capture-search"
/>
```

```svelte
<!-- Projects/Show.svelte -->
<Input
    id="manual-filter-q"
    type="search"
    bind:value={filterQ}
    placeholder={MANUAL_SEARCH_PLACEHOLDER}
    testId="manual-filter-q"
/>
```

いずれも `import { MANUAL_SEARCH_PLACEHOLDER } from "@/lib/manual/search";` を足す。

### 設計上の判断

- **「本文」と書き「原稿」と書かない**: 対象には `scene` (シーン = 何を撮るか) が含まれ、
  これは狭義の原稿 (ナレーション/字幕) ではないため。「本文」なら実際の対象を過不足なく指す。
- **ラベル「キーワード」は変えない** (PC)。placeholder が対象を説明するので二重に書かない。
- **DESIGN.md / Atomic Design 準拠**: 変更は既存 `atoms/Input` への props 追加 1 つだけで、
  新規 component も新規 token も作らない。`placeholder` は `Input` の
  `Props extends Omit<HTMLInputAttributes, ...>` により**既に受けられる** (rest props で `<input>` へ渡る)。
- **置き場所**: `resources/js/lib/manual/` は既に `format-duration.ts` / `scenario-history.ts` を
  持つ「マニュアル領域の純粋ヘルパ」置き場。component 階層 (atoms→…→pages) の外なので
  `atomic-import-graph.test.ts` の単方向 import 規則に触れない (pages からの lib 参照は
  `lib/capture/take-endpoints.ts` 等で既に多数の前例がある)。

### PHPStan適合チェック

- 該当なし (TypeScript)。`pnpm typecheck` / `pnpm lint` で確認する。

### テスト計画

- [ ] `tests/js/pages/CaptureIndex.test.ts` へ追記 —
      `screen.getByTestId("capture-search")` の `placeholder` 属性が
      **`MANUAL_SEARCH_PLACEHOLDER` を import した値と一致**すること
- [ ] `tests/js/pages/ProjectsShow.test.ts` へ追記 —
      `screen.getByTestId("manual-filter-q")` の `placeholder` 属性が同じ定数と一致すること
- [ ] 上記 2 本は**定数を import して比較する** (文字列リテラルを写さない)。
      これにより「片方の画面だけ文言を直した」が赤くなる
- [ ] 既存の `tests/js/pages/ProjectsShow.test.ts`「q 入力中に並べ替えを操作しても trim 済み q が
      クエリに維持される」が**変更なしで通る**こと (placeholder 追加は入力挙動を変えない)
- [ ] 既存の `tests/Browser/AuthenticatedPageBfcacheTest.php` が `@capture-search` に
      `type` / `value` を使っている。placeholder 追加は値に影響しないため**変更不要**

### リスク

- Browser テスト (bfcache) が `@capture-search` の**値**を見ているだけなので影響なし。
- 文言が長くなるため狭幅で省略されうる。`Input` は `w-full` 系の DS class を使っており
  溢れずに `…` になる。撮影 PWA の主戦場 (iOS Safari の狭幅) で読めることを実装時に確認する。

---

## 施策 7: 台帳 T053 の記述を訂正する

### 変更箇所

- ファイル: `docs/TODO-closed.md` (L71 の T053 行)

### 現行コード

```
| T053 | 動画一覧の並べ替え・自作フィルタ・作成者/更新日メタ表示・原稿検索。一覧に並替(PC)/自作filter/作成者・更新日メタ追加・原稿検索 | backend | 2026-07-15 03:06 |
```

### 変更後コード

同じ行の末尾に訂正注記を足す (行を消さない・日付を変えない):

```
| T053 | 動画一覧の並べ替え・自作フィルタ・作成者/更新日メタ表示・原稿検索。一覧に並替(PC)/自作filter/作成者・更新日メタ追加・原稿検索 **【訂正 (本 TODO 実装時に実測)】「原稿検索」は実装されていなかった** — 着地したのは `title` の LIKE 1 条件だけで、`cuts` (narration / subtitle / scene) を対象にした検索は app/ に 1 件も無かった。原稿 (カット本文) 検索は本 TODO で初めて実装された | backend | 2026-07-15 03:06 |
```

### 波及変更

- なし (台帳の散文のみ)

### テスト計画

- [ ] `docs/TODO-closed.md` の書式を検査するテストがあれば通ること (実装時に `composer test` の
      Architecture レーンで確認する)

### リスク

- なし。ただし**本 TODO の実装が完了するまでこの訂正を入れない** (先に入れると
  「実装された」と書いた行と実態がまた食い違う)。

---

## 新規テストファイル: テナント境界 (最重要)

### `tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php` (新規)

**このテストが本設計の安全性の中核**である。`ManualKeywordSearch::apply()` の入れ子 group を
外すと (= `orWhereHas` を素で積むと) 全件が赤くなるように書く。

- [ ] `別 project の manual は本文一致でも PC 一覧に混ざらない` —
      同一 organization の別 project に一致語を持つ manual を作り、
      `GET /projects/{project}?q=語` が自 project の分だけ返すこと
- [ ] `別 project の manual は本文一致でも撮影 PWA 一覧に混ざらない` —
      `GET /app/projects/{project}/manuals?q=語` で同上
- [ ] `別 organization の manual は本文一致でも混ざらない` —
      別 org の project に一致語を持つ manual を作り、どちらの面にも出ないこと
      (**cross-org 不可** = セキュリティ不変条件 3)
- [ ] `撮影 PWA の ready/published 制限は本文一致でも外れない` —
      `draft` の manual の `narration` に一致語を入れ、PWA 一覧に出ないこと
- [ ] `mine=1 の created_by 制限は本文一致でも外れない` —
      他人が作った本文一致 manual が `?mine=1&q=語` で出ないこと (PC / PWA の両面)
- [ ] **`apply()` は呼び出し側が積んだ条件を無効化しない (純粋な負のコントロール)** —
      (Codex Round 1 [Critical] 対応)
      HTTP を経由せず `ManualKeywordSearch::apply()` を直接使う:
      一致語を**持たない** manual A と、一致語を narration に**持つ** manual B を作り、
      `VideoManual::query()->whereKey($A->id)` に対して B に一致する語で `apply()` した結果が
      **0 件**であること。
      入れ子 group を外すと `whereKey` が OR に押し出されて B が返り、必ず赤くなる。
      **`toSql()` の文字列一致は採らない** — Laravel の版差 (括弧の付き方・別名) で壊れる脆いテストで、
      守りたい性質 (呼び出し側の条件が無効化されないこと) を**直接は見ていない**ため。
      本テストは DB 実行で同じ性質を、より強く、実装詳細に依存せずに見る。

**fail-first の確認**: 上記 6 本は「入れ子 group を外したら必ず赤くなる」ことを
実装時に**一度手で確認する** (`apply()` の `$query->where(function ...)` を外して
テストが赤くなることを見てから元に戻す)。確認したことをコミットメッセージに残す。

---

## 実装しないと決めたもの (設計判断の記録)

| 項目 | 判断 | 根拠 |
|---|---|---|
| 作成者名の部分一致検索 | **作らない** | `users.name` は CipherSweet + blind index (値全体のハッシュ) で `whereBlind` は case-insensitive の**完全一致**のみ。同じ検索窓に部分一致 (title/本文) と完全一致 (作成者名) を混ぜると説明できない挙動になる。既存の「自分の作成分のみ」フィルタ + 一覧の作成者名表示で実用上足りている |
| 作成者名の**完全一致**検索 (案 a) | **却下** | 上記のとおり「田中」で `田中 太郎` が出ない = 検索が壊れて見える |
| 平文の検索用 name 列の併設 (案 c-1) | **却下** | PII の暗号化を弱める。AGENTS.md セキュリティ不変条件 6 に反する (禁止) |
| n-gram blind index (粒度を下げる) (案 c-2) | **却下** | blind index の粒度低下は頻度解析で平文推定を許す。暗号化を弱める方向 (禁止) |
| 作成者を select で選ぶフィルタ (案 c-3) | **Conditional** | 暗号化を一切弱めずに実現できる唯一の正攻法だが、今は `mine` の 2 値で足りる (思考原則 2)。**引き金**: 1 project の manual 作成者が 3 人を超え、かつ `mine` では絞れないという要望が出たとき |
| `cuts.shooting_point` を検索対象に含める | **却下** | 撮影者への構図指示であり作業内容ではない。定型句が散らばり精度だけ落ちる |
| SOP 原本 (`source_documents`) の全文検索 | **スコープ外** | 下記「一覧検索と原本検索の境界」を参照 |
| ILIKE 化 (大小文字を区別しない検索) | **スコープ外** | **現在の挙動は「大小文字を区別する」** (pgsql の `like`)。`abc` で `ABC` は hit しない。現行 title 検索と同じ挙動を保つため今回は変えない。変えるなら title と本文を同時に変える別タスク (面によって挙動が違う状態を作らない)。**placeholder には書かない** — 「英字の大小を区別します」は日本語が主の現場利用者の大半に無関係な情報を毎回見せることになり、検索欄の主目的を薄める。保証範囲は `ManualKeywordSearch` の docblock と本設計書に残す |
| pg_trgm + GIN 索引 | **Conditional** | 想定規模 (project あたり cuts 10^3〜10^4) では不要。**引き金**: `cuts` が 10^6 行を超える or 一覧描画の p95 が 1 秒を超える |
| 撮影 PWA 一覧のページング | **Conditional** | 本改善の原因ではない既存仕様。**引き金**: 1 project の ready/published が 200 本を超える |
| 検索語のハイライト・どの列に当たったかの提示 | **スコープ外** | 「あったら便利」(思考原則 2) |

### 一覧検索と原本検索の境界 (Codex Round 1 [Warning] 対応)

使命は「**SOP を起点に**」だが、それは SOP を**検索対象にする**ことを意味しない。

- **本機能は一覧に並ぶもの (= 生成済み動画マニュアル) の検索**である。
  一覧の 1 行は manual であり、SOP 原本 (`source_documents`) は**行にならない**。
- SOP 原本は manual を**作るための入力**であって、撮影者・編集者が一覧で探す対象ではない。
  撮影 PWA の利用者は「撮るべきシナリオ」を探しており、原本の PDF を探してはいない。
- 検索窓に原本を混ぜると「**出てきた行が原本なのか manual なのか**」を利用者が判別できなくなる。
  同じ窓に別種のものを入れるのは「別物の概念を『似ているから』で統合しない」(思考原則 4) に反する。
- 「この SOP から作った manual はどれか」という需要は実在しうるが、それは**検索ではなく
  関連の表示**で解く問題である (manual 詳細から原本へ、原本から manual へのリンク)。
  必要になったらそちらとして起こす。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更は 5 ファイル + 新規 3 ファイル + migration 1 本と小さいが、**migration を 1 本含む**ため他タスクと同じ worktree で並走させると migration の順序が絡む。また `ManualListQuery` / `ProjectController` / `CaptureManualController` という一覧の中心を同時に触るため、他の一覧系タスクと衝突しやすい。単独で入れて `composer test` 全体を通す方が安全 |
| 競合リスク | 一覧 (`ProjectController::manualRows` / `CaptureManualController::index`) を触る他タスクと衝突する。`ManualListQuery` の `MAX_KEYWORD_LENGTH` 削除は外部参照が無いことを確認済みだが、実装直前に `grep -rn MAX_KEYWORD_LENGTH` を再実行して確認する |

## 検証コマンド (全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

