# 対応マトリクス: design-review Round 1

## [Critical] 施策 1: `ofMany(['sort_order' => 'min', 'id' => 'min'])` が意図した選択をする前提が強い
- 判断: **対応する (実測して設計に根拠を添付 + テストを fail-first 条件へ格上げ)**
- 根拠: 「たぶん動く」で設計を通すべきではないという指摘は正しい。
  ただし設計段階でも**実行せずに確かめる手はある**ので、憶測のまま先送りしない。
- 対応内容:
  1. `devnotes/.../verify-ofmany-sql.php` (一時検証スクリプト。app/ 無変更・匿名派生モデル・
     **DB へクエリを 1 件も投げない**) で生成 SQL を実測し、
     `devnotes/.../ofmany-sql-evidence.md` に記録した。結果:
     - 内側 `min(sort_order) group by video_manual_id` (候補条件 `exists(takes … thumbnail_path is not null)` 付き)
       → 中間 `min(id)` (同 `sort_order` の中で) → 外側は **`id_aggregate = cuts.id` の主キー一致 join** で
       1 行に確定。**辞書順の選択が SQL の構造として実現している**ことを確認した。
     - `addEagerConstraints([...])` を与えると `video_manual_id in (1, 2, 3)` になり、
       **eager load が 1 クエリで済む**ことも確認した。
  2. それでも「SQL 文字列の構造を見ただけ」であり実データの選択結果は別物なので、
     施策 8 #1 / #2 を **fail-first の必須条件**へ格上げし、
     **`toSql()` ではなく実レコードでの選択結果**で確認すると明記した。
     さらに #2 のフィクスチャを「**最小 id のカットが最小 sort_order ではない**」配置にして、
     単一列 `['id' => 'min']` の実装では**必ず落ちる**判別力を持たせる。
  3. 検証記録に「保証しないもの」(実データ・方言差・検証手順の副作用) を明記した。

## [Warning] 施策 3: `fromManual()` の呼び出し元が 1 箇所とは限らない
- 判断: **対応する (実際に全数確認した)**
- 根拠: 引数追加は破壊的変更なので、呼び出し元の網羅確認は設計の責務。
- 対応内容: リポジトリ全体を検索し、`CaptureManualSummaryData` を参照するのは
  **`app/Http/Controllers/Capture/CaptureManualController.php` の 1 ファイル (import 1 + 呼び出し 1) だけ**で
  あることを確認した (テストからの直接呼び出しも無い)。結果を施策 3 の「波及変更」へ明記した。
  互換用のデフォルト引数は付けない (後方互換の並走を残さない = 思考原則 3)。

## [Critical] 施策 4 / 8: `preview` と `capture` の同値テストが relation ロード状態に左右される
- 判断: **対応する**
- 根拠: `TakePolicy::preview` は `$take->cut?->videoManual?->project` を辿るため、
  fixture の relation ロード状態で結果が変わりうるという指摘は正しい。
- 対応内容:
  - **主契約を endpoint 実リクエスト (#6 / #8) に置き直した**。Gate 単体の同値テスト (#9) は
    「補助 (drift 検出)」と明記した。
  - #9 は **relation 未ロードの再取得インスタンス**と **eager load 済みインスタンス**の
    両方で同じ結果になることを確認する形にした。

## [Warning] 施策 6: Svelte 5 runes の props 更新で `failedSrc` の再挑戦が効かない可能性
- 判断: **対応する (テストを必須化 + 実装注記を追加)**
- 根拠: `$props()` の分割代入は rune モードで再代入時も追随する (既存
  `TakeThumbnail.svelte` が `$derived(size === "sm" …)` を props に対して行っている前例がある) が、
  **設計で断言せずテストで固定する**のが正しい。
- 対応内容: Vitest「`src` を差し替えると再び `<img>` が出る」を**必須項目**として明記し、
  実装注記に「`$effect` で `failedSrc` を消す形は採らない (失敗した URL そのものを覚えることで
  `src` 変更時に自動的に再挑戦になる)。効かなければ `$effect` へ切り替える」と分岐条件を書いた。

## [Warning] 施策 6 / 7: `data-testid` を img と placeholder の両方に付けると判定が曖昧
- 判断: **対応する**
- 対応内容: `data-testid` は両分岐に付けたまま (枠の位置は同じ) で、
  **`data-state="image" | "placeholder"` を追加**し、テストは `data-state` で判定する形にした。

## [Warning] 施策 7: 狭幅でバッジとタイトルが競合して詰まる
- 判断: **対応する**
- 根拠: 左 flex に `min-w-0` を付けても、3 要素 (サムネイル / 本文 / バッジ) の伸縮が
  1 段の `justify-between` では読み切れない。
- 対応内容: カード内を **`grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3`** に変更し、
  「サムネイルは固定幅・本文だけが縮んで truncate・バッジは潰れない」を構造で表す設計に直した。

## [Critical] 施策 8 #6: mock だけでは 302 契約が弱い
- 判断: **対応する**
- 対応内容: #6 を「props から得た `cut_id` / `take_id` で**実際に thumbnail route を GET** し、
  `assertRedirect(署名 URL)` と **`Cache-Control: no-store, private`** まで確認する」に具体化した
  (`TakeObjectStorage` の mock は署名 URL を決定的にするためだけに使う)。

## [Warning] 施策 8 #7: 完全性の**正例**が明示されていない
- 判断: **対応する**
- 対応内容: #7 を「3 条件がすべて成立するとき cover が非 null」の**正例専用**テストに分離し
  (候補が複数あるケースを含む)、選択規則 (#1) とは別ケースにした。

## [Suggestion] 施策 3 / 10
- 判断: 指摘なし (肯定的評価)。変更しない。
