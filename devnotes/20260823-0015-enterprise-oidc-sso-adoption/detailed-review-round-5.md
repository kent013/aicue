Round 5で前回の主要指摘は解消されています。ただし、`verify` と認証材料更新の競合、および確認画面の実装方式に未確定部分が残っています。

## 承認を妨げるもの

### D1/D2: verifyの線形化

判定: REQUEST_CHANGES

- [Critical] `verify` は外部HTTPを伴うため、他の更新操作と同じように「接続行をロックしてから検査・変更」を行うと、外向き通信中ずっとDBロックを保持します。これはB4/C2で避けている設計と矛盾します。

  逆に、外部取得後に単純にロックしてVerifiedへ変えるだけでは、次の競合が残ります。

  1. verifyが旧issuerのdiscoveryを取得
  2. updateがissuer/client_id/secretを変更
  3. verifyが接続行をロックし、新しい認証材料を旧取得結果でVerifiedにする

  修正案: verifyだけを明示的な二段構成にしてください。

  1. ロックなしで、検証対象となる認証材料のスナップショットを取得
  2. 外向き取得・検証
  3. トランザクション開始
  4. 接続行を `lockForUpdate()`
  5. issuer/client_id/client secretの保存値がスナップショットと完全一致することを再確認
  6. 一致するときだけVerifiedへ遷移。不一致なら結果を捨ててDraftのまま拒否

  `updated_at` だけへの依存は時刻精度や無関係な表示名更新を巻き込むため、認証材料そのもの、または専用revisionで比較してください。

  並行テストには「verifyの外部取得中に認証材料を更新すると、古いverify結果が採用されない」を追加する必要があります。

### E1: 確認画面の描画方式

判定: REQUEST_CHANGES

- [Critical] 「tokenをInertia propsへ置かず、サーバが描画したhidden項目へ入れる」とありますが、具体的な描画方式と変更ファイルがありません。

  Svelte/Inertiaページへhidden値を渡すなら、それは通常Inertia propです。Inertiaを使わないならBlade等の専用レスポンスが必要です。

  修正案: どちらかへ確定してください。

  - 専用Blade画面を追加し、変更ファイル、CSRF、design token、no-store、`Referrer-Policy: no-referrer`、外部リソースなしを明記する
  - Inertia propとして渡すことを受容し、暗号化履歴・no-store・画面遷移後の除去を保証に含める

  現在の「propsには置かないがサーバ描画hiddenへ置く」だけでは実装経路が定まりません。

## 実装前に直す文書整合

### B4

判定: REQUEST_CHANGES

- [Warning] docblockの「トランザクションの中で例外を投げない」は、`EnterpriseSsoAttemptStoreFailure` を投げる実装と矛盾します。

  修正案: 「業務上の拒否では例外を投げない。DB・基盤障害は例外として伝播しrollbackする」へ書き換えてください。

### C1

判定: REQUEST_CHANGES

- [Warning] 冒頭docblockにまだ次の旧記述が残っています。

  ```text
  引き当ての鍵は (接続 id, subject の指紋)
  ```

  「接続idと生のsubject（`COLLATE "C"`）」へ修正してください。

### A2

判定: REQUEST_CHANGES

- [Warning] CHECK制約を置く方針とテストはありますが、移行コード例には制約の実体と制約名がありません。

  修正案: PostgreSQLで実際に生成するSQLまたはLaravel APIと、明示的な制約名を設計へ記載してください。例:

  ```sql
  CONSTRAINT enterprise_identities_subject_octet_length_check
  CHECK (octet_length(subject) BETWEEN 1 AND 255)
  ```

  制御文字禁止もDB不変条件に含めるなら、同じくCHECKへ置くか、DTOだけの保証だと明記してください。

## 実装時に確認すればよいもの

以下は承認阻害ではありません。

- ssrf-pin v0.4の確定APIと例外契約
- `PinnedHttpClient` が投げる例外の固定理由コードへの変換
- `COLLATE "C"` とCHECK制約のスキーマ取得結果
- G2の保護対象語彙による誤検出
- 並行ハーネス上のready/go同期点
- URL query tokenがプロキシ/CDNのログへ残り得るという運用上の受容確認
- subjectをASCII限定にするか、UTF-8非制御文字まで許すかの正典確認

## 施策別判定

| 施策 | 判定 |
|---|---|
| A1 | APPROVE |
| A2 | REQUEST_CHANGES |
| A3 | APPROVE |
| B1 | APPROVE |
| B2 | APPROVE |
| B3 | APPROVE |
| B4 | REQUEST_CHANGES |
| C1 | REQUEST_CHANGES |
| C2 | APPROVE |
| D1 | REQUEST_CHANGES |
| D2 | REQUEST_CHANGES |
| E1 | REQUEST_CHANGES |
| F1 | APPROVE |
| F2 | APPROVE |
| F3 | APPROVE |
| F4 | APPROVE |

## 全体判定

CHANGES_REQUESTED

承認を妨げる本質的な残件は2つです。

1. `verify` を外部HTTP中の長時間ロックなしで、認証材料更新と安全に線形化する  
2. EmailPromotion確認画面をBladeまたはInertiaのどちらで描画するか確定する  

B4/C1/A2の残件は実装変更ではなく、設計本文とコード例の整合修正です。