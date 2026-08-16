全体判定: **CHANGES_REQUESTED**

主因は M8 のダウンロード導線が「非 Inertia の通常リンク」として成立する設計保証を持っていない点です。ここが崩れると、一覧からの DL という主要目的が動きません。

## M1: 一覧クエリの値オブジェクト化

判定: **APPROVE**

[Suggestion] `page` は巨大値を受けると巨大 offset になり得ます。既存挙動から大きく悪化しているわけではありませんが、将来 `min(max(...), 上限)` を検討してよいです。

## M2: 最新 succeeded render relation 追加

判定: **APPROVE**

[Suggestion] `latestSucceededRender` と `CurrentRenderArtifact::currentSucceeded()` の差分、つまり `output_path = null` を relation が返す点は設計上妥当です。Parity test で明示されているので問題ありません。

## M3: 行の操作可否 Service

判定: **APPROVE**

[Suggestion] 代表行方式は現在の policy には合っています。`ManualRowAbilityPremiseTest` は「status / creator / category が違っても同じ」を見るだけでなく、できれば **別 creator / 別 status / 別 category の各 manual に対して個別に `can()` した結果と representative 結果が一致する**形にすると、将来 policy が行属性依存になった時により確実に赤くなります。

## M4: 行 props DTO 化

判定: **REQUEST_CHANGES**

[Warning] `ManualListItemData` の promoted property `public ?array $category`, `public ?array $creator` は PHPStan level 10 で iterable value type 不足として扱われる可能性が高いです。constructor の `@param array{id:int,name:string}|null` だけで promoted property 側まで十分に効くかが不安定です。

修正案: 小さな DTO に分けるか、配列を property に保持せず `categoryId/categoryName` のような scalar nullable に分解して `toArray()` で shape を組み立てる。配列 property を維持するなら `@phpstan-type` と property 用 PHPDoc が効く形にしてください。

[Warning] `downloadable` コメントが「実体あり」と書いていますが、実際の判定は `output_path !== null` であり、ストレージ上の存在確認ではありません。既存 `CurrentRenderArtifact` と合わせるなら正しいですが、表現は過剰です。

修正案: 「現行 succeeded render の `output_path` がある」に表現を寄せる。実体存在確認まで保証する設計にしない。

## M5: 削除後の着地維持

判定: **APPROVE**

[Suggestion] allowlist 外 query を Location に載せないテストは良いです。加えて `page=abc` / `page=0` が redirect に載らないことも M1 の契約として一緒に pin するとより明確です。

## M6: TS 型追加

判定: **APPROVE**

問題ありません。PHP DTO の `toArray()` と対であるコメントも妥当です。

## M7: 再生時間ヘルパ

判定: **APPROVE**

問題ありません。`null` を `0:00` にしない判断も妥当です。

## M8: 一覧行 component 化 + DL / 削除導線

判定: **REQUEST_CHANGES**

[Critical] DL 導線で `Button href=...` を使いながら「素の `<a>` / Inertia なし」とコメントしていますが、`Button` atom が本当に通常 anchor を出す保証が設計内にありません。もし Inertia Link 経由なら、ファイル download / 302 away / attachment 応答が壊れる可能性があります。

修正案: 既存 `Button` の `href` 実装を確認し、通常 `<a>` であることを設計に明記してください。Inertia Link なら、通常 anchor を出す専用 prop か atom を追加し、`ManualListRow.test.ts` で `href` だけでなく Inertia router を使わないことを確認する方針にしてください。

[Warning] 狭い画面で、タイトル・メタ情報・時間・Badge・DL・削除が横一列に詰まり、行が破綻するリスクがあります。`shrink-0` 側が増えるため既存より悪化します。

修正案: モバイルでは `li` を `flex-col` に落とし、操作群を次行に逃がす設計にしてください。例: `flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between`。テキスト側には `min-w-0` に加えて必要なら truncate/wrap の方針を明記してください。

[Warning] `manualQueryString()` の `new URLSearchParams(Object.entries(query).map(...))` は TS の推論次第で tuple と見なされず typecheck が落ちる可能性があります。

修正案: `const params = new URLSearchParams(); for (const [key, value] of Object.entries(query)) params.set(key, String(value));` の形にするのが安全です。

## M9: テスト計画

判定: **REQUEST_CHANGES**

[Warning] M8 の DL が通常リンクであることを固定するテストが不足しています。今回の主要機能なので、ここは必要です。

修正案: `ManualListRow.test.ts` に「DL 導線は通常 anchor として `href` を持つ」「クリックで Inertia router を呼ばない」契約を追加してください。`Button` atom の責務次第では atom 側テストでも構いません。

[Suggestion] `ManualListQueryCountTest` は exact query count 比較だと環境差・認証周辺の副作用で脆くなりがちです。fixture 生成後に flush する方針は良いので、失敗時に何のクエリが増えたか分かるようログ出力補助を入れると保守しやすいです。