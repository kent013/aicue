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
