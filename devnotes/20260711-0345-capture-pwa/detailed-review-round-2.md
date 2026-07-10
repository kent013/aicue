## 各施策判定

| 施策 | 判定 |
|---|---|
| 1. DBスキーマ | APPROVE |
| 2. Quota拡張 | REQUEST_CHANGES |
| 3. ストレージ基盤 | APPROVE |
| 4. upload-url発行 | APPROVE |
| 5. テイク登録 | REQUEST_CHANGES |
| 6. テイク管理 | APPROVE |
| 7. routes / Controller / Policy | APPROVE |
| 8. sync | APPROVE |
| 9. S3掃除 | REQUEST_CHANGES |
| 10. PWAフロント | REQUEST_CHANGES |

Round 1 の全指摘は適切に反映されています。ただし、登録確定と掃除の競合に新たな重大問題があります。

## 指摘

### [Critical] 登録確定と stale sweeper の競合で、登録済み動画が削除され得る

該当: 施策5・9

`sweep()` は stale 行を取得した後、状態を再確認せず `released` 化してオブジェクトを削除します。一方、登録処理の確定 transaction は、手元の `$reservation` を無条件で `completed` にします。

次の競合が成立します。

1. sweeper が `verifying` 行を取得
2. 登録処理が Take を作成し予約を `completed`
3. sweeper が古いモデルを `released` に上書き
4. sweeper が登録済み Take のオブジェクトを削除

逆順でも、sweeper が `released` にした予約を登録処理が `completed` に戻し、削除済みオブジェクトを参照する Take を作成できます。

修正案:

- sweeper は各行を条件付きUPDATEで claimする。
  - `WHERE id = ? AND status = verifying AND updated_at < cutoff`
  - `verifying → released` の更新成功時だけ削除する。
- 登録確定側も予約を再取得または条件付き更新する。
  - `WHERE id = ? AND status = verifying`
  - `verifying → completed` のCAS成功後だけ Take を作成する。
- 両者のCASの勝者だけが後続処理を行う。
- 競合テストとして以下を追加する。
  - 登録側が先に `completed` を獲得した場合、sweeperは削除しない。
  - sweeperが先に `released` を獲得した場合、Takeは作成されない。
  - stale一覧取得後に状態が変化するケース。

### [Warning] Quotaのoverflowガードより前に加算している

該当: 施策2・4

`checkAddition()` 内にはoverflowガードがありますが、呼び出し側で先に次を計算しています。

```php
$this->usage->bytesUsed($lockedOrg) + $this->usage->bytesPending($lockedOrg)
```

この加算がoverflowすれば、追加したガードには到達しません。

修正案:

- `checkAddition()` に `used`、`pending`、`addition` を別々に渡して段階的に検査する。
- または安全加算用のprivate methodで `current` を組み立てる。
- `current >= 0`、`addition >= 0` も事前条件としてAssertまたは例外で固定する。

### [Warning] PHP DTOとTypeScriptのテイク契約が一致していない

該当: 施策5・7・10

`CaptureTake` は `download_ack_token` を必須フィールドとして定義していますが、施策5の `CaptureTakeData` shapeとfactory引数には同フィールドがありません。施策7で付与すると説明されていますが、DTOの責務・生成経路が確定していません。

修正案:

- `CaptureTakeData::fromTake()` に `?string $downloadAckToken = null` を追加。
- `download_ack_token: string|null` を全応答で常に出力する。
- store/update/adopt等の応答では `null`、詳細GETの採用テイクのみ値を設定する。
- PHP Resource shapeとTypeScript型の契約テストを追加する。

### [Warning] Service Workerのキャッシュ対象を明示的に制限する必要がある

該当: 施策10

「静的アセットをstale-while-revalidate」だけでは、実装時にInertia HTML、署名URL、ユーザー別レスポンスまでキャッシュ対象になる危険があります。

修正案:

- `GET` かつ同一オリジンのfingerprinted build assetだけを対象とする。
- `/app/*` navigation、書き込み、JSON、署名URL、S3 URLは常に除外する。
- cache version更新と旧cache削除を規定する。
- API/Inertia応答をキャッシュしないVitestまたはSW単体テストを追加する。

## Round 1修正の確認

- checksumはbase64・44文字へ統一され、整合しています。
- presign期限は`DateTimeInterface`へ修正されています。
- nested routeはrelation再解決と実HTTPテストの二重防御になっています。
- claim後の期限判定、重複path除去、軽量CSRF endpoint、書き込み形inventory検出も妥当です。

## 全体判定

**CHANGES_REQUESTED**

特に施策5・9のCAS競合制御はデータ消失を防ぐため必須です。これを解消し、Quota加算とDTO契約を揃えれば承認可能です。