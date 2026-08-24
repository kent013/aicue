# 対応マトリクス: design-review Round 5

> ## 追記（後任の設計セッションによる引き継ぎ）
>
> 本ファイルは当初「**上限に達したので未対応のまま残す**」という記録として書かれた。
> その後、**監督者の裁量でラウンド上限が +3 まで延長**され、後任の設計セッションが
> 引き継いだ。**下に記録された残件 5 件はすべて設計本文へ反映済み**である
> （したがって「設計本文には反映していない」という当初の但し書きは**もう当たらない**）。
>
> | 残件 | 採った解 | 反映先（`detailed-design.md`） |
> |---|---|---|
> | [Critical] `verify` の線形化（D1 / D2） | 二段構成。比較子は**専用の `credentials_revision` 列**（+ 第 2 層として issuer / client_id の実値） | D1「`verify` だけは二段構成にする」節 / A2 移行 1 に列を追加 / D2 の 5 操作の表を 2 通りへ分割 |
> | [Critical] 確認画面の描画方式（E1） | **(a) 専用の standalone Blade**。先例は `resources/views/mcp/authorize.blade.php` | E1「確認画面の描画方式」節 |
> | [Warning] B4 の docblock の矛盾 | 「業務上の拒否では例外を投げない / DB・基盤の障害は伝播し巻き戻す」へ書き換え | B4 `consume()` の docblock |
> | [Warning] C1 冒頭の旧記述 | 「接続 id と**生の subject**（`COLLATE "C"`）」へ修正 | C1 冒頭 docblock |
> | [Warning] A2 の CHECK 制約の実体と制約名 | 生 SQL + 明示の制約名 **2 本**（長さ / 制御文字）。保証範囲も明記 | A2 移行 2 の直後 |
>
> 判断の詳細と、Round 5 が「実装時に確認すればよい」とした項目への回答は
> `design-review-prompt-round-6.md` が持つ。

Round 5 の全体判定は **CHANGES_REQUESTED** であった。
以下は Round 5 を受け取った時点の記録（残件とその解決案）である。

## 承認を妨げる残件 2 件

### [Critical] `verify` の線形化（D1 / D2）

- **指摘**: `verify` は外部 HTTP を伴うため、他の更新操作と同じく
  「接続の行をロックしてから検査・変更」にすると、**外向き通信の間ずっと DB のロックを保持する**。
  これは B4 / C2 が避けている形と矛盾する。
  逆に、外部取得の**後**に単純にロックして `Verified` へ変えるだけだと次の競合が残る:
  (1) verify が旧 issuer の discovery を取得 → (2) update が認証材料を変更 →
  (3) verify がロックして、**新しい認証材料を旧い取得結果で `Verified` にする**。
- **解決案（採るべき形）**: `verify` だけを**明示の二段構成**にする。
  1. ロックなしで、検証の対象となる**認証材料のスナップショット**を取る
  2. 外向き取得と検証（**ロックを持たない**）
  3. トランザクション開始 → 接続の行を `lockForUpdate()`
  4. issuer / client_id / client secret の保存値が**スナップショットと完全一致**することを再確認
  5. 一致するときだけ `Verified` へ遷移。不一致なら**結果を捨てて `Draft` のまま拒否**
  - `updated_at` だけに頼らない（時刻の精度と、無関係な表示名の更新を巻き込む）。
    **認証材料そのもの**、または**専用の revision 列**で比べる
  - 並行テストに「**verify の外部取得中に認証材料を更新すると、古い verify の結果が採用されない**」を追加する

### [Critical] メール昇格の確認画面の描画方式（E1）

- **指摘**: 「トークンを Inertia の props へ置かず、サーバが描画した hidden 項目へ入れる」と書いたが、
  **具体的な描画方式と変更ファイルが無い**。Svelte / Inertia のページへ hidden を渡すなら
  それは通常 Inertia の prop であり、Inertia を使わないなら Blade 等の専用の応答が要る。
- **解決案（どちらかへ確定する）**:
  - (a) **専用の Blade 画面**を足し、変更ファイル・CSRF・design token・`no-store`・
    `Referrer-Policy: no-referrer`・外部リソースなしを明記する
  - (b) **Inertia の prop として渡すことを受容**し、履歴の暗号化・`no-store`・
    画面遷移後の除去を保証に含める
  - 本設計の他の画面がすべて Inertia であることを踏まえると **(b) が既存の作法に近い**が、
    「props に置かない」と書いた当初の意図（履歴の暗号化に依存しない）を捨てる判断になるため、
    どちらを採るかは次のラウンドで明示的に決める必要がある

## 文書整合の残件 3 件（実装の変更を伴わない）

### [Warning] B4 の docblock の矛盾

- 「トランザクションの中で例外を投げない」が `EnterpriseSsoAttemptStoreFailure` を投げる実装と矛盾する。
- **解決案**: 「**業務上の拒否では例外を投げない。DB・基盤の障害は例外として伝播し巻き戻す**」へ書き換える。

### [Warning] C1 の冒頭 docblock に旧記述

- 「引き当ての鍵は (接続 id, subject の**指紋**)」が残っている。
- **解決案**: 「接続 id と**生の subject**（`COLLATE "C"`）」へ直す
  （Round 4 で別の箇所は直したが、冒頭の 1 か所が残った）。

### [Warning] A2 の CHECK 制約の実体と制約名

- 方針とテストは書いたが、移行のコード例に制約の実体と名前が無い。
- **解決案**: 制約名を明示して書く。例:
  ```sql
  CONSTRAINT enterprise_identities_subject_octet_length_check
  CHECK (octet_length(subject) BETWEEN 1 AND 255)
  ```
  制御文字の禁止も DB の不変条件にするなら同じく CHECK へ置き、
  しないなら**DTO だけの保証である**と明記する。

## Codex が「実装時に確認すればよい」と明示した項目（承認の妨げではない）

- ssrf-pin v0.4 の確定 API と例外の契約
- `PinnedHttpClient` が投げる例外の固定理由コードへの変換
- `COLLATE "C"` と CHECK 制約のスキーマ取得結果の表記
- G2 の保護対象語彙による誤検出
- 並行ハーネス上の ready / go の同期点
- URL の query に載るトークンがプロキシ / CDN のログへ残りうることの運用上の受容確認
- `subject` を ASCII 限定にするか UTF-8 の非制御文字まで許すかの正典確認

## 施策別の到達点（Round 5 時点）

| 施策 | 判定 |
|---|---|
| A1 / A3 / B1 / B2 / B3 / C2 / F1 / F2 / F3 / F4 | **APPROVE** |
| A2 / B4 / C1 | REQUEST_CHANGES（**文書整合のみ**） |
| D1 / D2 | REQUEST_CHANGES（**verify の線形化**） |
| E1 | REQUEST_CHANGES（**確認画面の描画方式**） |
