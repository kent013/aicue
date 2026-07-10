# 問い合わせ (Inquiry) 本人削除 / 保持期限削除 Runbook

公開問い合わせフォームで受け付けた PII の削除運用手順。Inquiry は氏名 / メール /
会社・組織名 / 問い合わせ内容を CipherSweet で暗号化保存しており、本人削除要請と
保持期限超過の双方を手動 + 自動の組み合わせで運用する。

## 1. 本人削除要請への対応 (手動)

GDPR / 個人情報保護法に基づく本人からの削除要請を受領した場合の手順。

### 1-a. Filament 管理画面での削除 (少数件)

1. **受領・本人確認**: 要請者が本人であることを確認する (要請メールアドレスと問い合わせ時の
   メールアドレスの一致など)。
2. **検索**: Filament 管理画面 `/admin/inquiries` を開き、メールアドレスでテーブル検索する。
   email 列は CipherSweet の blind index (`inquiry_email_index`) で exact-match 検索される。
   - 保存時に `EmailNormalizer` (trim + 小文字化) で正規化しているため、検索側も同一正規化を
     通してから blind index 照合される (`InquiryResource` の searchable が実装)。
3. **削除**: 該当行を開き、`削除` アクションで物理削除する (AdminUser のみ実行可)。
   - 削除は `DeleteInquiryAction` 経由。hard delete (SoftDeletes 非使用) で、CipherSweet の
     `deleting` フックにより共有 `blind_indexes` テーブルの該当行も自動クリーンアップされる。
   - 構造化ログ `inquiry.deleted` (reason=manual、PII なし) が残る。
4. **削除確認**: 同じメールアドレスで再検索し 0 件であることを確認する。
5. **記録方針**: ログ・チケットに PII (氏名・メール等) を残さない。削除完了の事実のみ
   要請対応チャネル (チケット等) に記録する。

### 1-b. CLI での削除 (--email-file)

平文 email を argv / shell history に残さないため、email はファイル経由で渡す。

```sh
# 1. email を一時ファイルに書く (echo の history 残留に注意。エディタ推奨)
$EDITOR /tmp/subject-email.txt

# 2. dry-run で対象件数を確認 (status / 時刻を問わず本人の全 Inquiry が対象)
php artisan inquiry:purge --email-file=/tmp/subject-email.txt

# 3. 実削除
php artisan inquiry:purge --email-file=/tmp/subject-email.txt --apply

# 4. 一時ファイルを必ず削除する
rm /tmp/subject-email.txt
```

## 2. 保持期限超過の自動削除

`php artisan inquiry:purge` で保持期限を超過した問い合わせを削除する。

- **対象**: `status=spam` (`created_at` 基準) / `status=closed` (`closed_at` 基準。null は対象外) が
  `config('legal.inquiry_retention_days')` (既定 365) 日を超過したもの。
- **dry-run (既定)**: `php artisan inquiry:purge` → status 別の対象件数のみ表示 (削除しない)。
- **実削除**: `php artisan inquiry:purge --apply`。
- **保持日数の上書き**: `--older-than-days=N` (config 既定より短く即時 purge したい運用向け)。
- **スケジュール**: `routes/console.php` の `Schedule::command('inquiry:purge --apply')->daily();`
  により日次自動実行。
- 削除は `DeleteInquiryAction` 経由の model 単位 delete で CipherSweet の `deleting` フックを
  発火させ blind_indexes をクリーンアップする (query builder 一括 delete はフック非発火のため
  使わない)。

## 3. status / closed_at の取り扱い

- `closed_at` は `Inquiry::booted()` の `updating` フックで自動集約する (close で set、reopen で null)。
- このフックは Eloquent モデルイベント経由でのみ発火する。**bulk update
  (`Inquiry::query()->update([...])`) は使わない** (フック非発火で closed_at がずれ、closed の
  purge 基準が壊れる)。status 変更は Filament の `EditInquiry::handleRecordUpdate` (model 単位
  save) と `CreateInquiryAction` のみで行う。

## 4. reCAPTCHA secret 未設定 / 判定不能時の挙動

`App\Services\Captcha\RecaptchaVerifier` の挙動 (いずれも `Log::warning('recaptcha unavailable')`
+ `report(RecaptchaUnavailableException)` で監視に上がる):

- **secret 未設定** (`services.recaptcha.secret_key` 空): **production では fail-closed (false)**、
  local / testing では fail-open (true)。production で任意トークンが通過しないよう保証する。
- **transport error / timeout / 5xx**: fail-open (true)。Google 側障害でユーザーの問い合わせを
  止めない (honeypot + throttle:inquiry が backstop)。
- **4xx / `success:false` / hostname 不一致**: fail-closed (false)。

local では site key / secret 未設定のまま captcha 無しの縮退で動く。
**本番は必ず実キーを投入すること** (site key 未設定だとフロントが widget を描画せず、
secret 未設定だと fail-closed で全送信が弾かれる)。
