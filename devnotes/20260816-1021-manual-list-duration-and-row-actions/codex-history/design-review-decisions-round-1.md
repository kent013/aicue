# 対応マトリクス: design-review Round 1

## [Critical] M8: `Button href=…` が通常 anchor を出す保証が設計内に無い
- 判断: **反論する (事実で解消) + テストは追加する**
- 根拠: `resources/js/components/atoms/Button.svelte` の分岐は 3 枝で、
  **`href !== undefined && inertia`** のときだけ `@inertiajs/svelte` の `Link` を描画し、
  `inertia` を渡さない (既定 `inertia = false`) 場合は**素の `<a>`** を描画する:

  ```svelte
  {#if href !== undefined && inertia}
      <Link {href} …>{@render content()}</Link>
  {:else if href !== undefined}
      <a {href} {target} rel={computedRel} …>{@render content()}</a>
  {:else}
      <button …>{@render content()}</button>
  {/if}
  ```

  本設計の DL は `inertia` を渡していないため通常 anchor になる。既存の
  `RenderPanel.svelte` の「完成動画をダウンロード」も同じ書き方 (`href` のみ・`inertia` なし) で
  本番稼働している = 同一機構の先例がある。
- 対応内容: (1) 詳細設計の M8 に上記の分岐を**引用して明記**する。
  (2) 指摘どおりテストを足す — `ManualListRow.test.ts` に
  「DL 導線の要素は `A` タグで `href` を持つ」「クリックしても Inertia router
  (`visit` / `get` / `delete`) が呼ばれない」を追加する (atom の実装が変わったら赤くなる)。

## [Warning] M4: promoted `public ?array $category` の iterable value type
- 判断: **対応する**
- 根拠: PHPStan level 10 で「配列の値型が無い」と言われる余地を設計段階で残さない。
  Codex の第 1 案 (小さな DTO に分ける) を採る。scalar 分解 (categoryId / categoryName) は
  「対で null になる」不変条件が型から消えるため採らない。
- 対応内容: `App\DataTransferObjects\Manual\ManualListRefData` (id / name の対) を新設し、
  `ManualListItemData` は `?ManualListRefData $category` / `?ManualListRefData $creator` を持つ。
  DTO には**配列プロパティが 1 つも無くなる** (配列は `toArray()` の戻り shape だけ)。

## [Warning] M4: 「実体あり」という表現が過剰
- 判断: **対応する**
- 根拠: 判定しているのは `output_path !== null` であって、オブジェクトストレージ上の
  実在確認ではない。保証しないものを保証すると読める文言は残さない。
- 対応内容: コメント / TS の docstring を「現行世代の succeeded render に `output_path` がある
  (ストレージ実体の存在確認ではない)」へ書き換える。

## [Warning] M8: 狭い画面で行が破綻する
- 判断: **対応する**
- 対応内容: `<li>` を `flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between` にし、
  操作群 (再生時間 / バッジ / DL / 削除) をモバイルでは次行へ逃がす。タイトルは `truncate`、
  メタ行は既存どおり。`min-w-0` は維持する。

## [Warning] M8: `new URLSearchParams(Object.entries(...).map(...))` の型推論
- 判断: **対応する**
- 対応内容: `const params = new URLSearchParams();` + `for (const [key, value] of Object.entries(query)) params.set(key, String(value));` の形へ変更する。

## [Warning] M9: DL が通常リンクであることを固定するテストが無い
- 判断: **対応する** (Critical への対応 (2) と同一)

## [Suggestion] M3: 前提テストをより確実に赤くする
- 判断: **対応する**
- 対応内容: `ManualRowAbilityPremiseTest` を「各 manual を個別に `can()` した結果が、
  `ManualRowAbilities::forPage()` の結果と全行一致する」形にする
  (representative の結果と行ごとの実評価を突き合わせる)。

## [Suggestion] M1: `page` の巨大値
- 判断: **一部対応 (上限定数は置かない)**
- 根拠: M4 の丸め (`current_page > last_page` なら最終ページで引き直す) が既に着地を保証する。
  任意の上限定数を足すと「なぜその値か」を説明できない魔法の数が増える。
  `(int)` は PHP_INT_MAX で頭打ちになり、OFFSET は bigint 範囲に収まる。
- 対応内容: 上限定数は置かず、`?page=99999999` が最終ページに着地することを Feature テストで固定する。

## [Suggestion] M5: `page=abc` / `page=0` が redirect に載らないことも pin
- 判断: **対応する**
- 対応内容: `ManualRowActionsTest` のケースに追加する (`toQueryParams()` は page<=1 を載せない)。

## [Suggestion] M9: query count テストの失敗時可読性
- 判断: **対応する**
- 対応内容: 失敗時に増えたクエリ (`DB::getQueryLog()` の `query` 列) をアサーションメッセージへ
  出す方針をテスト計画に明記する。

## [Suggestion] M2: relation と CurrentRenderArtifact の差分 / M6 / M7
- 判断: 指摘なし (肯定)。変更しない。
