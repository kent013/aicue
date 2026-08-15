全体判定: **CHANGES_REQUESTED**

設計の方向性は概ね妥当です。特に「終局書き込みを `attempts` 世代で CAS 化する」「安全に再実行できる webhook だけを自動回収する」という軸は、AGENTS.md の「結果の一回性は永続状態遷移で担う」に沿っています。

ただし、実装前に直すべき点がいくつかあります。

## A. 再実行安全性の分類を型で持つ

判定: **APPROVE**

[Suggestion] `SafeToReplay` の behavioral test は、同一 `event_id` の二重発火ではなく、**異なる `event_id` で同じ Stripe object id / invoice id / session id を処理する**形を必ず含めてください。  
同一 `event_id` だと webhook event 行の冪等性だけを見てしまい、分類の根拠である `purchase:` / `monthly:` / `recharge:` / `refund:` の下位冪等キーを十分に検証できません。

## B. 回収待ちの状態と理由を足す

判定: **REQUEST_CHANGES**

[Warning] `recovery_reason IS NOT NULL` ⟺ `status = recovery_pending` がコメントとテストだけに依存しています。  
修正案: 可能なら migration で CHECK 制約を追加してください。

```sql
(recovery_reason is null and status <> 'recovery_pending')
or
(recovery_reason is not null and status = 'recovery_pending')
```

Laravel migration 上で DB 方言差を避けるなら、少なくとも Architecture/Feature テストで「書き込み経路が `recovery_reason` を直接触らない」ことを inventory 化してください。現状のテストだけだと、将来の別経路が不変条件を壊しても検出が弱いです。

[Warning] `recoverStale()` の主クエリに対応する index が設計にありません。  
5 分ごとの cron で `status='received' and updated_at <= threshold order by id` を見るため、件数が増えると全表走査になり得ます。

修正案: migration に複合 index を追加してください。

```php
$table->index(['status', 'updated_at', 'id'], 'stripe_webhook_events_status_updated_id_idx');
```

`recovery_pending` 件数を監視対象にするなら、`status` 単独または上記 index で足りるかも確認してください。

## C. 終局書き込みを世代付きの条件付き UPDATE にする

判定: **APPROVE**

[Warning] CAS が守る範囲の説明は正確ですが、`finalize()` の戻り値を `handle()` 側で無視する点はテストで固定してください。  
修正案: 「旧 worker が成功したが、すでに別世代へ進んでいたため `processed` にしない」ケースで、HTTP 経路が例外を投げないこと、かつ行状態を上書きしないことを明示的に assert してください。

[Suggestion] `finalize()` の `$status` は実質 `Processed | Failed | Received` の限定集合なので、将来 `RecoveryPending` を誤って渡せないよう、private method でも docblock だけでなく呼び出し側テストで守るとよいです。

## D. 滞留回収を足す

判定: **REQUEST_CHANGES**

[Warning] `customer.updated` など未対応 event を `recovery_pending` に置く設計は、通常処理経路の挙動とズレています。  
現行 `process()` は未対応 type を no-op で `processed` にします。一方、滞留回収では `UnhandledEventType` として永続的な運用対応対象にします。これは「処理対象外なので確認のみ」という説明と合っていますが、正常経路との差分が大きく、監視ノイズになります。

修正案: どちらかに寄せてください。

- 案1: 未対応 type は回収でも `processed` にする。通常経路と同じで、運用ノイズが少ない。
- 案2: `recovery_pending` に置くなら、docs に「正常経路では processed だが、滞留時は crash の痕跡として pending に残す」と明記し、テスト名もその意図に合わせる。

[Warning] 生存中 worker が閾値を超えた場合、順序依存 event は `recovery_pending` に移され、元 worker が成功しても `finalize()` が失敗して行が pending のまま残ります。  
設計上「HTTP 処理は秒オーダー」として許容しているのは理解できますが、外部 API 遅延や一時停止で起き得ます。

修正案: 少なくとも docs とテスト計画に「閾値超過中の生存 worker を誤検知した場合、domain side effect は起き得るが webhook 行は recovery_pending に残る」ことを明記してください。より堅くするなら `received` 兼用ではなく `processing_started_at` 相当の列を持つ設計を再検討してください。

[Warning] `StaleWebhookClaimDto::logContext()` の戻り値 PHPDoc が本文中の注意だけで、コード例にはありません。PHPStan level 10 前提なら明示してください。

修正案:

```php
/**
 * @return array{
 *     event_id: string,
 *     type: string,
 *     attempts: int,
 *     status: string,
 *     reason: string|null
 * }
 */
public function logContext(): array
```

[Suggestion] `payload` を `array<mixed>` として扱う方針は妥当です。ただし DTO に保持する payload は大きくなり得るので、`MovedToRecoveryPending` では payload を持たない別 DTO に分けるか、少なくとも「通知・ログへ載せない」だけでなく「長期保持しない」意図をコメントしてください。

## E. cron を配線する

判定: **REQUEST_CHANGES**

[Warning] cron の観測対象が `recovery_pending` 件数に寄りすぎています。  
`retryScheduled` が増えている、または scheduler 自体が止まっている場合は、`received` の滞留行も重要な観測点です。

修正案: docs と運用コメントに次を追加してください。

- `status='received' and updated_at <= now - stale_threshold` の件数
- `billing:recover-stale-webhook-events` の `retryScheduled` 件数
- `recovery_pending` 件数

[Suggestion] `withoutOverlapping()` と `onOneServer()` は既存 cron と同じ前提に乗る設計でよいです。ただし cache driver 不備時の挙動は既存運用前提に依存するため、新しい保証として書かないでください。

## F. コメントとドキュメント修正

判定: **APPROVE**

[Suggestion] docblock は詳細を書きすぎると `docs/architecture.md` と二重管理になります。設計書自身も言っている通り、正本は `docs/architecture.md` に寄せ、クラス docblock は「なぜ recoverStale が必要か」「CAS の責務」程度に短くするのがよいです。

## 追加で必要なテスト

[Warning] 以下は実装前にテスト計画へ入れてください。

- `recovery_reason` と `status=recovery_pending` の双方向不変条件
- 複合 index 追加後の migration rollback
- 未対応 event の滞留回収が「processed にする」または「pending に残す」どちらかの仕様で固定されていること
- `received` 滞留行が purge で消えないこと
- 異なる `event_id` で同じ Stripe object を処理しても付与が 1 回であること

このまま実装に進むには少し危険です。主に、DB 不変条件・index・未対応 event の扱い・運用観測点を設計に戻してから進めるべきです。