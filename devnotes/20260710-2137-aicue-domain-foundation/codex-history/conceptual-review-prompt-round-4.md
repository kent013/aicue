## Round 4: Round 3 指摘への対応と再レビュー依頼

Round 3（CHANGES_REQUESTED、reorder 契約の Warning 2 点）に対し、以下の通り改訂しました。**残 Warning を解消できているか、APPROVED 可否を再判定してください。**

### 対応マトリクス（要約）

- [Warning] Store/Update が sort_order を受けて reorder 契約を迂回 → Category FormRequest 入力を **name のみ**に限定（sort_order 除外）。作成時は Service が `max(sort_order)+1` を末尾採番、以後の変更は **reorder Service のみ**。
- [Warning] transaction だけでは並行 reorder / reorder と作成・削除を直列化できない → reorder Service は当該 project の Category 全行を **id 昇順で `lockForUpdate()`** → ロック後に集合一致を再検証（増減時は 409/再取得）→ 配列順に再採番。作成・削除も同じ project スコープのロック規約で直列化。表現を「後勝ち」→**「ロック取得順に直列化」**に修正。
- [Suggestion→採用] parent_cut_id も same-manual を将来必須条件（relation 経由解決・cross-manual 404）に追加。

### 改訂後の該当箇所（抜粋）

**スコープ7（FormRequest）**:
> Category（**name のみ**。`sort_order` は入力から除外＝専用 reorder 操作の契約を迂回させない）・VideoManual（title/`category`〔別名〕）の Store・Update、Category reorder（順序配列）。**`sort_order` は作成時に Service が末尾値を採番し、以後の変更は reorder Service のみが行う**。

**実装方針（Category sort_order は専用 Service のみ操作）**:
> - 作成: Store Service が当該 project 内の `max(sort_order)+1`（末尾）を採番。
> - 並べ替え: `PATCH .../categories/reorder` → `ReorderCategoriesRequest`。「送信 id 集合が当該 project の Category 集合と完全一致（distinct・過不足なし）」検証、不一致は 422。
> - 並行制御: reorder Service は 1 transaction 内で当該 project の Category 全行を **id 昇順（決定的順序）で `lockForUpdate()`** → ロック後に集合一致を再検証（増減時は 409/再取得）→ 配列順に一括再採番。作成・削除も同じ project スコープのロック規約で直列化。「ロック取得順に直列化」。

**Tier B 将来必須条件**:
> - `cuts.adopted_take_id`: 採用 API は `cut->takes()` 経由解決、cross-cut は 404。
> - `cuts.parent_cut_id`: 親手順は同一 `video_manual` 所属を relation 経由解決、cross-manual は 404。

上記以外は Round 3 版から変更ありません（全文は前ラウンドで提示済み）。
