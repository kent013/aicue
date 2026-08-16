**全体判定: CHANGES_REQUESTED**

設計の方向性は概ね妥当ですが、実装前に直すべき点がいくつかあります。特に `coverCut()` の relation 設計、権限同値テスト、Svelte の props/reactivity まわりはそのままだと実装時に破綻する可能性があります。

**施策別判定**

| 施策 | 判定 |
|---|---|
| 1. `VideoManual::coverCut()` | REQUEST_CHANGES |
| 2. T148 目録登録 | APPROVE |
| 3. cover DTO と summary 合成 | REQUEST_CHANGES |
| 4. 一覧クエリ eager load / 権限評価 | REQUEST_CHANGES |
| 5. TypeScript 型 | APPROVE |
| 6. `ManualCoverThumbnail.svelte` | REQUEST_CHANGES |
| 7. 一覧カード差し込み | REQUEST_CHANGES |
| 8. Feature テスト | REQUEST_CHANGES |
| 9. Vitest | REQUEST_CHANGES |
| 10. docs 追記 | APPROVE |

**指摘**

[Critical] 施策 1: `ofMany(['sort_order' => 'min', 'id' => 'min'])` が「最小 sort_order の集合内で最小 id」を本当に返す前提が強いです。Laravel の one-of-many は複合集約の join 条件が複雑で、`whereHas()` 付き relation では SQL 実体を確認しないと誤選択や DB 方言差を見落とします。  
修正案: 設計に「生成 SQL を Feature テストで固定」だけでなく、`coverCut()` の実装候補として `latestOfMany` 系ではなく明示的な `hasOne()->ofMany(...)` が通ることを、実 DB テスト #1/#2 で fail-first する条件に格上げしてください。可能なら設計に「実装時に `toSql()` ではなく実レコード選択で確認する」と明記してください。

[Warning] 施策 3: `CaptureManualSummaryData::fromManual(VideoManual $manual, bool $canViewCover)` の変更は呼び出し元が本当に 1 箇所とは限りません。DTO はテストや別 props 生成でも直接呼ばれている可能性があります。  
修正案: 実装前の探索項目に `CaptureManualSummaryData::fromManual(` の全呼び出し確認を追加し、変更対象に漏れがあれば施策 4 以外も含めてください。互換用のデフォルト引数は避ける方針でよいですが、呼び出し元の網羅確認は必要です。

[Critical] 施策 4 / 8: `Gate::allows('capture', $project)` と `Gate::allows('preview', $take)` の同値テストは、`TakePolicy::preview` が relation を辿るため、テスト fixture が `cut.videoManual.project` をどうロードするかで結果が変わり得ます。実装では props 側が project 単位判定、endpoint 側が take 単位判定なので、この drift 検出は重要ですが、雑に書くと false positive/false negative になります。  
修正案: #9 の同値テストでは、`Take` を DB から取得し直した「relation 未ロード状態」と、必要 relation を eager load した状態の両方で `preview` が期待通りになることを確認してください。少なくとも endpoint 実リクエストの #6/#8 を主契約にし、Gate 単体同値テストは補助に位置づけるべきです。

[Warning] 施策 6: Svelte component の `onerror={() => (failedSrc = src)}` は、分割代入した `src` props が更新されたときの再評価挙動に依存しています。Svelte 5 runes では props の扱いを誤ると、親から `src` が変わっても期待通り再挑戦しない可能性があります。  
修正案: Vitest の「src が別の値に変わると再び img」が必須です。加えて実装では既存コンポーネントの props 更新パターンに合わせ、必要なら `$effect` で `src` 変更時に `failedSrc` を維持/解除する条件を明示してください。

[Warning] 施策 6 / 7: `data-testid={testId}` を img と placeholder の両方に付ける設計だと、親テストで「img か placeholder か」を誤判定しやすいです。  
修正案: `data-testid` は外枠に固定し、画像判定は `getByRole('img', { hidden: true })` ではなく `querySelector('img')` 等で見るか、`data-state="image|placeholder"` を付ける方がテストが安定します。

[Warning] 施策 7: カードレイアウトは `justify-between` の左右 flex で、左側に `ManualCoverThumbnail` が追加されますが、左 flex に `min-w-0` があっても右の Badge と長いタイトルの競合で狭幅時に詰まる可能性があります。  
修正案: mobile 幅の Vitest/DOM だけでは検出しづらいので、最低限 CSS 設計として外側を `items-start` または `grid grid-cols-[auto_1fr_auto]` 相当にする案を検討してください。バッジが潰れないこと、タイトルが truncate することを設計に明記するとよいです。

[Critical] 施策 8: #6「cover 非 null なら URL が 302」は `TakeObjectStorage` mock だけでは足りません。endpoint は ready と `thumbnail_path` を再確認するので、props の `take_id` が正しくても storage mock の URL 生成だけが通ってしまう形に寄ると契約が弱くなります。  
修正案: #6 は Inertia props から得た `cut_id/take_id` で実際に thumbnail route を叩き、`assertRedirect('https://...')` と `Cache-Control: no-store, private` まで確認してください。

[Warning] 施策 8: 「完全性」#7 が #3/#4/#5/#8 と対、とありますが、`capture 権限あり ∧ 候補あり ∧ ready 成立` の正例が明示されていません。  
修正案: 正例は #1 とは別に、候補が複数ある場合も含めて「条件が全成立なら cover 非 null」を明示してください。#1 は選択規則、#7 は契約として分ける方が失敗原因が読みやすいです。

[Suggestion] 施策 3: `CaptureManualCoverData::toArray()` のキー順は `cut_id`, `take_id` でよいです。Feature テスト #13 で URL 不保持まで見るのは良い契約です。

[Suggestion] 施策 10: docs の「保証しないもの」は良いです。特に「次のカットへ探しに行かない」を明文化している点は、将来の仕様変更時の判断材料になります。

**まとめ**

実装方針は North Star、DTO/Inertia props、T148、既存 thumbnail endpoint 再利用の方向に沿っています。ただし、`ofMany + whereHas` の実 SQL 契約、`TakePolicy::preview` との drift 検出、Svelte props 更新、狭幅レイアウトの詰まりは詳細設計としてまだ弱いです。上記を設計に反映すれば、実装に進める水準になります。