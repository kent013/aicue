# 対応マトリクス: conceptual-review Round 3

## [Critical] SoftDeletes された Organization が畳み込みの母集団から漏れる

- 判断: **対応する (指摘は正しく、しかも v0 の実在バグである)**
- 根拠: 指摘を受けて実コードを再確認した。**現行 v0 に非対称が実在する**。
  - `organizationsWithExpiredEntries()` は `Organization::query()->whereHas(...)` なので
    `SoftDeletes` の global scope が効き、**論理削除済み組織は 1 件も列挙されない**
    → その組織の台帳は畳み込まれず、失効済み行も物理削除されない。
  - 一方 `countExpired()` は `TicketLedgerEntry::query()->where('created_at','<=',$threshold)` で
    組織を結合しないので、**論理削除済み組織の行も数える**。
  - 帰結: 退会した組織に期限超過の明細が 1 行でもあると
    `candidates > 0` かつ `processed = 0` で **`expiredRemaining` が永久に 0 にならず、
    `isPublicationReady()` が false のまま**になる (= 保持期限の宣言 gate が
    `horizon: NG` を出し続ける)。D23 の「退会後も保持義務に従って管理する」と矛盾する。
  - **これは今回の追従で持ち込むものではなく、既に壊れている**。同じ PR で直す
    (母集団の定義は畳み込みの中核であり、別 TODO へ切り出すと「正典追従は終わったが
    退会組織は畳まれない」状態が残る)。
- 対応内容:
  - 組織の列挙とロックの両方を **`Organization::withTrashed()`** 起点にする。
    `carryForward()` / `countExpired()` / dry-run の候補数がすべて
    **論理削除済み組織の台帳も対象にする**ことを設計に明記する。
  - **正典形 (id で回す) へは変えない**。走査器で実測したところ
    `Organization::withTrashed()->whereKey($organization->getKey())->lockForUpdate()` は
    `PrimaryKeyStaticQueryScanner::candidates()` が **0 件**を返す
    (識別子引数が解決済みモデル由来のため候補にならない)。
    つまり `withTrashed()` の追加で `DirectFetchInventory` の登録は**要らない**。
    「セキュリティ分類を必要なら足す」という指摘の前提 (id 起点へ変える必要がある) が
    実測で成立しないので、モデル反復のままにする方が変更が小さい。
    ただし**この実測を詳細設計に根拠として残す** (後から id 起点へ変える人が
    登録の要否を再判定できるように)。
  - テストを 5 本置く (active 組織 / 論理削除済み組織の明細も期限到来後に処理される /
    論理削除済み組織でも残高保存が成立する / 論理削除済み組織の期限超過明細が
    `expiredRemaining` に現れ、畳み込み後に 0 になる /
    `withTrashed()` が台帳の畳み込み以外の一般的な主キー取得へ転用されていないこと =
    テナント境界の迂回に使われていないことを、`withTrashed()` の出現箇所を
    畳み込みサービス 1 ファイルに限る形で静的 gate 側に足す)。

## [Warning] soft-deleted 組織への対応はスコープ外にできない

- 判断: 対応する (上記 Critical と同一の対応)
- 対応内容: 「スコープ外」節から誤解を招く記述を除き、母集団の定義を
  **§改善アイデア (A)** の第 1 段の一部として明記する。

## [Suggestion] 確認未了のまま lctl のセルを `implemented/v1` にしない

- 判断: 対応する
- 対応内容: 実装フェーズへの申し送りに
  「オーナー / 法務の確認 (§繰越行の保持分類 の 4 点) が完了するまで
  lctl のセルを `implemented` へ上げない」を明記する。

## [Suggestion] `source` の nullable 変換の書き方が曖昧

- 判断: 対応する
- 根拠: 実スキーマは `source` を **nullable** で持つ
  (`$table->string('source')->nullable()`。`source IS NULL` は P5 以前の legacy 行で、
  畳み込みでは purchased へ寄せず**独立した集約単位**として扱う既存契約がある)。
- 対応内容: DTO の変換規則を
  「`null` は `null` のまま保持する / **文字列だけ** `TicketSource::from()` へ渡す /
  それ以外の型 (bool / float / 配列) は例外」と明記する。

## [Suggestion] 使命 / 禁止事項 / リスク / 列分類 / 型安全性

- 判断: 見送る (追加対応なし)
