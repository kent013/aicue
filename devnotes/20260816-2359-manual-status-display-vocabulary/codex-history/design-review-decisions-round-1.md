# 対応マトリクス: design-review Round 1

全体判定 CHANGES_REQUESTED。Critical 1 件 / Warning 9 件 / Suggestion 5 件。

## [Critical] 旧 `?status=` が pagination の query string に残る経路が未処理

- 判断: **一部反論 + 対応する**
- 根拠 (反論部分): `ProjectController::manualRows()` は paginator の `links` / `url()` を
  **props へ 1 つも出していない**。返しているのは自前で組んだ `data` と
  `meta` (`current_page` / `last_page` / `per_page` / `total`) の 2 キーだけであり、
  ページ送り UI (`Projects/Show.svelte` の `changeManualPage`) は
  **クライアント側で `manualQuery(pageNumber)` を組み直して `router.get`** する。
  したがって `withQueryString()` が拾った旧キーがリンクとして外に出る経路は現状**存在しない**
  (旧キーに限らず `?foo=bar` のような未知キーも同じで、これは本変更以前からの性質である)。
- 根拠 (対応部分): とはいえ「allowlist を通った値だけを外へ出す」という本 VO の設計意図に対して
  `withQueryString()` (生クエリをそのまま拾う) は**意図の緩い側**である。1 行で締められるので締める。
- 対応内容:
  - 施策 C に `->withQueryString()` → `->appends($listQuery->toQueryParams())` の置換を追加した。
    `AbstractPaginator::addQuery()` は `pageName` (`page`) を除外するため、
    `toQueryParams()` が持つ `page` と paginator 自身のページ番号は衝突しない。
  - Feature テストに「`manuals` props のキーは `data` / `meta` だけで `links` を持たない」
    「`manualFilters` に `status` キーが存在しない (`missing`)」の 2 本を追加した。

## [Warning] B: Svelte 側 state の型が `string` で緩い

- 判断: **対応する**
- 根拠: 妥当。`ManualFilters.progress` を union にする以上、state 側も union で受けないと
  「型で防ぐ」効果が select の値で切れる。
- 対応内容: `let filterProgress = $state<ManualProgress | "">(manualFilters.progress ?? "")` へ変更し、
  `manualQuery()` の分岐も `!== ""` のまま union を保つ形に書き直した (施策 F)。

## [Warning] E: `VIDEO_MANUAL_STATUS_LABELS` の用途説明が狭すぎる (5 値の正当な用途を否定してしまう)

- 判断: **対応する**
- 根拠: 指摘のとおり。同ファイルの `CAPTURE_NAVIGABLE_BY_STATUS` / `SCENARIO_ESTABLISHED_BY_STATUS` /
  `SCENARIO_ANALYZABLE_BY_STATUS` は 5 値を使う正当な判定であり、
  「5 値は詳細画面とダッシュボードだけ」と書くとこれらと矛盾する。
- 対応内容: docblock を**ラベル / トーン (表示語彙) の使用面**の話に限定し、
  「一覧の行バッジと絞り込みでは使わない」と狭めた。5 値の型そのものの用途は制限しない。

## [Warning] F: 旧 testId の参照ゼロが設計書の主張に依存している

- 判断: **対応する**
- 根拠: 妥当。設計書の「実読で確認済み」は再現手順が無い。
- 対応内容: 実測した grep の結果 (対象と件数) を施策 F に記載し、
  テスト計画へ「実装時に `rg 'manual-status-|manual-filter-status'` が
  **実装対象ファイル以外で 0 件**であることを確認する」を明記した。
  (実測: 参照は `ManualListRow.svelte` / `Projects/Show.svelte` と
  Vitest 2 ファイル (`ProjectsShow.test.ts` / `ManualListRow.test.ts`) のみ。
  Browser lane・Feature テスト・bug-hunt 目録に参照は無い。
  `Manuals/Show.svelte` の `manual-status` (id 無し) は詳細画面の 5 値バッジで**別物**なので変えない)

## [Warning] G: Capture 側の `category` 入力正規化が PC 側 allowlist と不一致

- 判断: **見送る (据え置きと明記する)**
- 根拠: `(int) 'abc' = 0` は「該当なし」へ倒れる (fail-closed 方向) 挙動で、
  権限やテナント境界を跨がない。PC 側は破棄して「全件」へ倒れるので方向が逆だが、
  **どちらも安全側**であり、本タスクの対象は**表示語彙**である。
  ここで Capture 側に VO を新設するのは別タスク相当のスコープ拡大 (思考原則 2)。
- 対応内容: 施策 G に「本設計では触らない (既存仕様として据え置き)」と理由付きで明記した。

## [Warning] G: `captureProgressOf()` の `cuts_total=0 && cuts_with_takes>0` の扱いが仕様として固定される

- 判断: **対応する**
- 根拠: 関数化すると判定順序が仕様になる、という指摘は正しい。
- 対応内容: 関数に「takes は cut に属するため `cuts_total=0` かつ `cuts_with_takes>0` は
  構造上生じないが、生じても**撮影の分母が無い**ので『未撮影』へ倒す」というコメントを付け、
  Vitest の境界テストに同ケースを追加した。

## [Warning] I: 純粋 enum テストを Feature に置くのは配置が不適切

- 判断: **対応する**
- 根拠: 妥当。本リポジトリには `tests/Unit/Manual/` が既にあり (`CutSequencerTest` 等の
  DB を使わない純粋テストの置き場)、そこが正しい所在である。
- 対応内容: `tests/Feature/Manual/ManualProgressMappingTest.php` →
  **`tests/Unit/Manual/ManualProgressMappingTest.php`** へ移した。
  Inertia payload / 絞り込み挙動は Feature (`ProjectShowManualsTest`) に残す。

## [Warning] I: `has('manuals.data', 5)` は脆く、対象の同定が曖昧

- 判断: **対応する**
- 根拠: 妥当。件数だけの assertion は fixture の増減で意味が変わる。
- 対応内容: fixture の 5 本に status ごとの固有 title を付け、
  `in_progress` は**3 件の title を集合として**固定する形へ書き換えた。

## [Warning] 追加: 検証コマンドが設計の完了条件に書かれていない

- 判断: **対応する**
- 根拠: 妥当。PHP / TS / Svelte / payload を同時に変えるため、完了条件の明示は必要。
- 対応内容: 「完了条件 (検証コマンド)」節を新設し、AGENTS.md の検証コマンド一覧のうち
  本変更で必ず走らせるものを列挙した。

## [Suggestion] A / C / D / H / J への肯定的評価

- 判断: 対応不要 (設計維持)。`statuses()` を導出のままにする判断も維持する。
