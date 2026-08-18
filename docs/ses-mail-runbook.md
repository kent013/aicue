# SES メール送信 / バウンス・苦情対応 runbook

SES 実送信への切替と、バウンス (届かなかった) / 苦情 (迷惑メール報告) の自動抑止 (サプレッション)
運用手順。

## 構成概要

```
アプリ ──送信──▶ SES ──配信──▶ 受信者
                  │ Configuration Set
                  ▼ (bounce/complaint)
              SNS topic ──HTTPS──▶ POST /ses/notification
                                     ├ VerifySnsSignature (署名検証)
                                     │    └ AwsSnsSignatureVerifier
                                     │         └ SnsCertificateFetcher (証明書取得の唯一の口)
                                     │              SSRF 検査 → 単一ロックで直列化 → HTTP 取得
                                     │              → PEM 確認 → 署名検証後にキャッシュ昇格
                                     └ SesNotificationController
                                          └ email_suppressions に upsert
アプリ 次回送信 ──MessageSending──▶ FilterSuppressedRecipients (抑止宛先を除去)
```

- 自前 DB (`email_suppressions`) が送信前チェックの正本。SES account-level suppression は最終防壁。
- Permanent バウンスと苦情のみ抑止 (Transient バウンスは再送余地を残す)。
- 証明書取得の予算 (接続 / リクエスト全体 / ロック寿命 / キャッシュ寿命 / 応答サイズ上限) は
  `config/services.php` の `services.sns_certificate` にある。**env にしない** —
  環境ごとに変えてよい運用値ではなく、値の大小関係そのものが契約である
  (`tests/Architecture/SnsCertificateFetchContractTest.php` が固定する)。

## インフラ前提 (コード外)

1. **DNS**: 送信ドメインの DKIM / SPF / DMARC を設定。
2. **SES**: production access 申請 (sandbox 解除)。Configuration Set を作成し、event destination を SNS topic に設定 (Bounce / Complaint を含める)。account-level suppression list を有効化。
3. **SNS**: topic を作成し、HTTPS subscription を `https://<APP_URL>/ses/notification` に向ける。最初の `SubscriptionConfirmation` はアプリが SDK `confirmSubscription` で自動確立する (要 `sns:ConfirmSubscription` 権限)。
4. **IAM**: 送信に `ses:SendEmail` / `ses:SendRawEmail`、購読確立に `sns:ConfirmSubscription`。対象 topic ARN・region を明示。EC2/ECS の instance profile (role) で付与する環境では `.env` に AWS アクセスキーを置かない (SDK が default chain で一時 cred を拾う)。
5. **監視**: SES / CloudWatch でバウンス率 (< 5%) と苦情率 (< 0.1%) を監視。本施策単独で閾値未満を保証するものではない。

## 環境変数

| env | 説明 |
|-----|------|
| `MAIL_MAILER=ses` | 本番で SES 実送信に切替 (local/test は `log`/`array` のまま) |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | SES / SNS の認証情報。**instance profile (role) を使う環境では空**にする |
| `AWS_DEFAULT_REGION` | SES / SNS のリージョン。**誤ると SNS confirm / 送信が別 region を向いて失敗** |
| `SES_CONFIGURATION_SET` | 送信に付与する Configuration Set 名 (未設定なら付与しない) |
| `SES_SNS_TOPIC_ARNS` | 受信を許可する SNS TopicArn (カンマ区切り)。**空のときは全通知を拒否** |

deploy パイプラインでは `php artisan production:preflight` (内部で `operations:check-mail-config` を呼ぶ)
が `MAIL_MAILER=log` のまま production に出るミスと `MAIL_FROM_ADDRESS` / `APP_URL` の
ドメイン乖離 (SPF/DKIM/DMARC reject の原因) を fail-fast 化する。

## 運用手順

### 誤抑止の解除

誤 complaint・アドレス再有効化などで送信を復活させたいとき:

```bash
php artisan mail:unsuppress user@example.com
```

- 自前 DB (`email_suppressions`) の行を削除する。
- **SES account-level suppression にも残っている場合は別途消す**: SESv2 `DeleteSuppressedDestination` API (AWS CLI: `aws sesv2 delete-suppressed-destination --email-address user@example.com`)。両方消さないと SES 側が drop し続ける。

### 抑止状況の確認

`email_suppressions` テーブルを直接参照 (`email` / `reason` / `suppressed_at`)。`email` は PII のため取扱い注意 (DB dump・閲覧権限)。

## 注意点

- **平文 email は PII**: ログには出さない (ログは `email_hash` のみ)。DB dump / 運用閲覧の取扱いに注意。将来の管理 UI 追加時は閲覧に認可を必須とする。
- **抑止時刻の意味**: `suppressed_at` / `provider_message_id` は最後の通知で上書きされ、**初回抑止時刻は保持しない**。初回記録時刻の近似は `created_at`。厳密な時系列は SES / CloudWatch ログを参照。
- **二重管理の乖離**: 自前 DB と SES account-level suppression は二重防御。乖離 (ローカルに無いが SES 側で抑止) は SES が drop するため安全側。自動 reconcile は将来 TODO。
- **証明書取得が競合すると 503 を返す**: 同時取得は 1 本に直列化してあり、取れなかった要求は
  待たずに 503 になる (SNS が再送する)。**403 と混ぜない** — 403 は恒久 (再送しても直らない)、
  503 は一時障害で再送に意味がある、という分離が抑止漏れを防いでいる。

## 障害一次切り分け

| 症状 | 確認 |
|------|------|
| 403 多発 | ① 受信 TopicArn が `SES_SNS_TOPIC_ARNS` allowlist に含まれるか ② region 不一致 ③ `SigningCertURL` が `sns.{region}.amazonaws.com/SimpleNotificationService-*.pem` 形式か ④ 両キー (`SigningCertURL` と `SigningCertUrl`) が同時に来ていないか ⑤ 証明書 host が private IP へ解決されていないか (SSRF 判定の恒久拒否) ⑥ 応答が PEM でない / 大きすぎる |
| 503 継続 | ① 証明書取得のネットワーク到達性 ② 証明書 host の名前解決 ③ 取得の競合 (同時 1 本に直列化しており、取れなければ待たずに 503) ④ キャッシュ / ロック基盤 (既定 `database` store) の障害。いずれも一時障害として SNS が再試行する |
| 400 多発 | SNS 以外からの不正 POST か疎通確認ミス |
| メールが届かない | `email_suppressions` に該当アドレスがないか確認 → あれば `mail:unsuppress` + SES 側削除 |

証明書取得まわりのログキー (いずれも `Log::warning`。平文は出さない):

| ログキー | 意味 |
|---------|------|
| `mail.sns.cert_cache_read_failed` | キャッシュ読みが例外。miss 扱いで続行する (取りに行く) |
| `mail.sns.cert_cache_write_failed` | 昇格 (キャッシュ書き) が例外。署名検証は済んでいるので落とさない |
| `mail.sns.cert_cache_forget_failed` | 読み戻せない値の削除が例外。miss 扱いで続行する |
| `mail.sns.cert_lock_release_failed` | ロック解放が例外。寿命の失効まで後続が 503 になる |
| `mail.sns.verification_unavailable` | middleware が 503 を返した (証明書取得の一時障害。件数の増加は再検討条件) |

## メールテーマの運用

markdown mail 全体 (Notification + markdown Mailable) に
`resources/views/vendor/mail/html/themes/template.css` を適用している
(`config/mail.php` の `markdown.theme = 'template'`)。テンプレート初期状態は
Laravel 標準 default.css のコピー。アプリのテーマを定義するときは:

1. `template.css` の色値だけを DESIGN.md front matter の token へ差し替える
   (セレクタ構成は default.css と同一に保つ = CssToInlineStyles 互換)
2. `tests/Feature/Mail/MailThemeDesignParityTest.php` の skip を外し、
   色の一致 (DESIGN.md との drift 検出) を機械検証する
3. 以降テーマ CSS の色を変える場合は DESIGN.md 側の token 変更として扱う

### env/config 変更時の反映

`MAIL_*` 等の env・config 変更は以下を必ず実施 (ShouldQueue 通知は worker プロセスの config を見るため):

```bash
php artisan config:cache   # config cache 再生成
# queue worker を再起動する
```

### Laravel upgrade 時の diff チェック

published theme は upstream の構造変更に自動追従しない。Laravel upgrade 時は
`resources/views/vendor/mail/html/themes/template.css` と
`vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/themes/default.css`
を diff 比較し、セレクタ構成の変化があれば published 側へ反映する。
