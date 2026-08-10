# 退会 (アカウント削除) runbook — 決済事業者側 customer の redaction

> 対象読者: 運用担当者。
> 関連: `docs/architecture.md` §退会 (アカウント削除) の課金ガード (T115) /
> lctl 台帳 feature `account-deletion-billing-guard` 標準形 v1 (裁定 AG-128) の必須 (1)。

## 0. この runbook が扱う範囲

**アプリは決済事業者側の顧客データを自動で消さない**。退会経路から決済事業者 API を
呼ばないのが T115 からの原則である (自 DB と外部サービスの二重書き込みを避ける /
解約を代行しない)。この原則は静的 gate
`tests/Architecture/AccountDeletionPathGateTest.php` と behavioral 2 本
(`tests/Feature/Auth/AccountDeletionTest.php`) が並存して固定している。

したがって決済事業者側の非表示化 (redaction) は **人手**で行い、
その**実施記録だけ**をアプリに残す。本 runbook はその手順である。

### 保証しないもの (誇張しない)

- **アプリからの自動 redaction は行わない**。実施はダッシュボード / 事業者 API 操作であり、
  アプリはその事実を記録するだけである。
- 記録列は「**実施したと運用者が申告した**」ことの証跡であって、事業者側で実際に
  非表示化が完了したことの検証ではない (完了確認は事業者側の job status を見る)。
- 静的 gate は**検知であって遮断ではない**。実行時の外部通信を止める機構ではない。

## 1. 対象組織の解決手順

**新しい探索経路を作らない**。起点は既存の日次バッチである:

```bash
php artisan billing:detect-orphan-billing-organizations
```

このコマンドは「Owner 不在かつ生きた課金責務が残る組織 (課金孤児)」を検出し、
**件数と organization id のみ**を `report()` する (組織名・メール等の PII は載せない)。
この id が redaction 検討の入口になる。

退会本人からの削除要請で個別に対象が判明した場合も、対象は organization id で名指しする。

## 2. 決済事業者 (Stripe) 側の操作

> **一次情報 (2026-08-10 確認)**
>
> - 非表示にする手順・対象オブジェクト・処理時間: <https://docs.stripe.com/privacy/redaction>
> - 削除要請の扱いと非表示化の位置づけ: <https://docs.stripe.com/privacy/deletion-requests>
>
> 引用 (要旨):
> - 「不正利用とリスクを防ぐために、**ほとんどの取引は作成から 90 日後に**削除できます」
>   (失敗した取引は直ちに / サンドボックス取引は即時 / 返金された取引は返金完了時点)。
> - 「すべての関連データを非同期で識別して編集するには、**最大 30 日**かかる場合があります。
>   この間、ジョブの `status` フィールドは `validating` または `redacting` です」。
> - 「顧客を削除する予定がある場合は、削除を遅らせる可能性のある新しい取引を防ぐために、
>   **まず顧客を削除**してください」。
>
> **注意 (2026-08-10 時点の観測)**: RedactionJob は**公開プレビュー**と明記されている。
> 一般提供の状態・API 形状は変わりうるので、実施前に必ず上記 URL を開き直すこと。
> 本 runbook の数値は上の 2 URL からの引き写しであり、**事業者仕様が変われば無効になる**。

手順:

1. 対象組織の customer id を確認する。**新しい探索経路を作らない**ため、
   §3 の記録コマンドの **dry-run 出力**をそのまま使う (dry-run は 1 列も書かない)。
   ```bash
   php artisan billing:mark-stripe-customer-redacted <organization_id>
   # => [dry-run] 組織 <id> の customer=cus_xxx を redaction 実施済みとして記録します (--apply で実記録)。
   ```
   `stripe_id` を持たない組織ならここで FAILURE になり、そもそも対象外だと分かる。
2. Stripe ダッシュボード / API で **まず Customer を削除**する
   (新しい取引が発生して redaction が遅延するのを防ぐため。一次情報の推奨手順)。
3. redaction job を作成し、検証エラーを解消してから実行する。
   **90 日の待機が必要な取引が残っている場合、その期間は job が通らない**。
   通らないことは異常ではない — 期間経過後に再実施する。
4. **redaction は取り消せない**。非表示にした取引は不審請求の申し立てで自動的に敗訴になり、
   返金もできなくなる。返金が必要な場合は**返金を先に**行う (一次情報の警告)。
5. job の完了 (最大 30 日) を待つ。

## 3. 実施の記録 (アプリ側)

実施したら**必ず記録する**。記録が無いと、後から「どの customer を redact 済みか」を
検証できない。

```bash
# 既定は dry-run (何も書かない)
php artisan billing:mark-stripe-customer-redacted <organization_id>

# 実記録
php artisan billing:mark-stripe-customer-redacted <organization_id> --apply
```

記録されるのは 2 列セットである:

| 列 | 内容 |
|---|---|
| `organizations.stripe_customer_redacted_at` | 実施日時 |
| `organizations.stripe_customer_redacted_id` | 記録時点の `stripe_id` の写し |

- **日時だけでは足りない**。「**どの** customer を redact したか」が事後に検証できないと、
  `stripe_id` が差し替わる経路が将来できたときに監査列として意味を失う。
- **両列は同時に埋まり同時に NULL** である。これはアプリ層だけでなく **PostgreSQL の
  CHECK 制約** (`organizations_stripe_customer_redaction_pair_check`) が担保しており、
  アプリを迂回した直接 UPDATE でも片側だけは書けない。
- **このコマンドは決済事業者 API を呼ばない** (記録専用)。
- `stripe_id` を持たない組織には記録できない (fail-closed。写す値が無いため)。

### 二重実行したとき

既に記録済みなら **no-op で成功**し、実施日と customer id を表示する。
**上書きしない** — 最初の実施日が監査証跡だからである。

```
YYYY-MM-DD に記録済みです (customer=cus_xxx)。何もしません。
```

## 4. 実施者・実施日の残し方

- アプリが持つのは「いつ・どの customer に対して実施したか」までである。
  **誰が実施したかはアプリに残らない** (CLI 実行者を記録する仕組みを持たない)。
- 実施者・実施理由・事業者側 job id は**運用チケット側**に残すこと。
  本 runbook の URL と確認日、対象 organization id、コマンド出力を貼り付ける。

## 5. 監視

- `billing:detect-orphan-billing-organizations` の `report()` は既に監視対象である
  (`docs/architecture.md` の監視対象リスト)。
- 同じ organization id が**日をまたいで再報告され続ける**場合、redaction 待ち (90 日 / 最大 30 日)
  なのか、対応が止まっているのかを本 runbook の手順で切り分ける。
  再報告そのものは抑制状態を持たない冪等な観測であり、異常ではない。

## 猶予期間つき削除 (T142) の運用

即時削除に加えて **猶予 30 日の予約 (凍結方式)** が併存する。契約と設計判断の正本は
`docs/architecture.md` §退会の猶予期間つき削除 (凍結方式・30 日)。

1. **執行バッチ**: `account:purge-deletion-requests --apply` が daily (`onOneServer`) で走る。
   手で確認したいときは `--apply` 無しの **dry-run** で件数だけを見る
   (`due` / `deleted` / `blocked` / `unexpected` を出力。user id / email は出さない)。
2. **終了コードの読み方**:
   - `SUCCESS` かつ `blocked>0` = **業務上の保留**。件数を載せた `RuntimeException` が
     `report()` される (`ValidationException` を素で report しても既定 dontReport が握り潰すため)。唯一 Owner + 他メンバー / 生きた課金責務が
     残っている。予約は維持され翌日また試される。対応は本 runbook 冒頭の
     「退会をブロックしている組織」の解消手順と同じ (移譲 / 解約)。
   - `FAILURE` = **想定外**。`unexpected` の内訳は (a) インフラ障害、(b) 予約列の非正規行
     (片列だけ / 期限が予約時刻より前)。(b) は DB の CHECK 制約が本来拒否するはずの状態なので、
     **制約が落ちていないかを先に確認する** (`users_deletion_request_pair_check` /
     `users_deletion_purge_after_order_check`)。
3. **利用者からの「取り消したい」問い合わせ**: 取消は本人が `/settings` からいつでも実行できる
   (step-up 不要)。予約中は業務画面が `/settings` へ 302 されるため、
   **ログインさえできれば必ず取消画面に着く**。運用側で代行取消する導線は用意していない。
4. **決済事業者 API は 1 回も呼ばない**。予約・取消・執行のいずれの経路も自 DB しか触らない
   (静的検査は `AccountDeletionPathGateTest`、挙動は Feature テストが固定する)。
   解約は利用者自身が Customer Portal で行う (凍結中も `billing.portal` は通る)。
