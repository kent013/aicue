# 対応マトリクス: design-review Round 2

## [Critical] (施策 5/7) `errors.account` を配列で渡す前提が確定していない
- 判断: **対応する** (指摘が正しい。設計を変更した)
- 根拠 (実査): `vendor/inertiajs/inertia-laravel/src/Middleware.php` L223-235 の
  `resolveValidationErrors()` は `$this->withAllErrors ? $errors : $errors[0]` で、
  既定は `protected $withAllErrors = false` (L32)。本アプリの `HandleInertiaRequests` は
  これを override していない。よって **field ごとに先頭 1 件しかクライアントへ届かない**。
  「blocker ごとに 1 メッセージ」を配列で投げる設計は 2 件目以降が静かに消える = 動かない UI だった。
- 対応内容:
  1. `ValidationException` は **1 本の要約文字列**にする
     (`次の対応が完了するまで退会できません: 「A」オーナーの移譲、「B」サブスクリプションの解約`)。
     DTO の `message()` を `requirementLabel()` (組織ごとの短い必要対応) に変更。
  2. 組織ごとの詳細・導線は **redirect back 後に再評価される props (`accountDeletionBlockers`)** が持つ、
     という transport 設計を明記。
  3. フロントの `errors.account` 正規化は**現行のまま**にし、配列表示への変更は**入れない**
     (届かないものを表示する UI を作らない)。
  4. `withAllErrors` の有効化は採らない (アプリ全体の error 表現を変える副作用が大きい)。
  5. テスト「複数 blocker の到達性 (transport 契約)」を追加:
     2 組織でブロック → 要約に両方が含まれること + redirect back 後の `GET /settings` を
     `assertInertia` で検査し `accountDeletionBlockers` が 2 件返ること。
     **session の MessageBag だけを見ない** (Inertia の先頭 1 件縮退を検出できないため)。

## [Warning] (施策 2) action 導出契約を固定するテストが無い
- 判断: **対応する**
- 対応内容: guard/DTO テストに #10 を追加 (両理由の順序 / 非 current org の billing action /
  理由の重複入力でも action が重複しない)。

## [Warning] (施策 7) `orphanBillingOrganizationIds()` の責務表示
- 判断: **対応する**
- 根拠: 本メソッドは Owner 不在を判定せず、入力契約を信用する純フィルタ。
- 対応内容: docblock に「**入力契約**: 呼び出し側が Owner 不在の組織を渡す。Owner 判定は
  `organizationsWithoutOwner()` 側の責務」を明記。テスト #8 の名称も
  「渡された組織のうち課金中の id を返す」に変更し、Owner 判定のテストは #9 (membership 側) に分離。

## [Suggestion] (施策 1) `(int) $org->getKey()` ではなく `Assert::integer()`
- 判断: **対応する**
- 根拠: 既存 `OrganizationMembershipService::keyOf()` と同じ作法 (黙ってキャストしない)。
- 対応内容: コード例を `Assert::integer($key)` に変更し、`use Webmozart\Assert\Assert;` を追加。

## [Suggestion] (施策 5) stale action (所属変更後の 404) の UX 契約を明記
- 判断: **対応する**
- 対応内容: 「404 は Inertia のエラーページ遷移になり `onError` に来ない。これは存在秘匿の 404 契約どおりで
  許容する (専用フォールバック画面は作らない)。`onError` は検証エラー等で無反応に見せないための保険」と明記。

## [Suggestion] (施策 8) テスト参照は番号でなく名前で
- 判断: **対応する**
- 対応内容: docs 追記項目 2 をテスト名参照に変更 (番号は並べ替えに弱いため)。

## APPROVE 済み (追加対応なし)
- 施策 1 / 3 / 4 / 6 / 8: Round 1 の Critical 解消と反論 2 点 (closure DI / `use RuntimeException` 不要) を受理。
