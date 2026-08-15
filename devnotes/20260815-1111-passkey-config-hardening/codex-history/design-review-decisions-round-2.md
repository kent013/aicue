# 対応マトリクス: design-review Round 2

## ★設計の前提が 1 つ壊れていた（Codex の指摘ではなく、指摘の検証中に実測で判明）

Round 2 の「vendor 既定キー残存テストを具体化せよ」に答えるため
`vendor/laravel/fortify/src/FortifyServiceProvider.php` を読んだところ、
**`register()` → `configurePasskeys()` が `passkeys.*` を無条件に上書きしている**ことが分かった:

```php
config([
    'passkeys.relying_party_id'   => config('fortify.passkeys.relying_party_id', parse_url(config('app.url'), PHP_URL_HOST)),
    'passkeys.allowed_origins'    => config('fortify.passkeys.allowed_origins', [config('app.url')]),
    'passkeys.user_handle_secret' => config('fortify.passkeys.user_handle_secret', config('app.key')),
    ...
]);
```

`config([...])` は絶対値の設定なので、**当初案の「アプリ側 `config/passkeys.php` を新設する」は
そもそも効かない**（置いても Fortify に潰され、`APP_URL` / `APP_KEY` 由来の既定に戻る）。
これは「設定したのに効かない」という新種の事故を作る設計だった。

- 判断: **当初案を破棄し、宣言場所を `config/fortify.php` の `passkeys` ブロックへ移す**
- 根拠: `fortify.passkeys.*` は Fortify が用意した拡張点そのもの（思考原則 1: フレームワークのレンジ内でやる）。
  `config/passkeys.php` は新設しない。
- 対応内容:
  - 施策 1 を全面的に書き直した（宣言先 / 現行コードの記述 / 変更後コード / 波及）。
  - `ProductionEnvGuard` の読み出しを 2 系統に整理した。
    実効値（`passkeys.relying_party_id` / `allowed_origins` / `user_handle_secret`）は
    **Fortify の上書き後の姿**を読み、検査専用キー
    （`fortify.passkeys.raw_allowed_origins` / `user_handle_secret_declared`）は宣言元から読む。
  - `PasskeyPackageContractTest` に **「Fortify の上書き後も実効値がアプリ宣言と一致する」** 検査を追加した
    （この写しが切れると宣言が無言で無視されるため、本設計で最も重要な検査）。

## [Warning] mergeConfigFrom / config cache 往復のテストが具体化されていない（施策 1）
- 判断: 対応する
- 対応内容: `PasskeyPackageContractTest` に 3 本を具体コード付きで書いた。
  (1) Fortify 上書き後の実効値 = 宣言値、(2) config cache 往復（`fortify.passkeys.*` の 5 キー +
  `passkeys.relying_party_id`）、(3) vendor 既定キーの残存。
  (3) は実測に基づき `management_middleware === []`（confirmPassword=false のため）と
  `throttle === 'throttle:passkeys'`（`fortify.limiters.passkeys` から Fortify が組み立てる）まで固定した。

## [Warning] `trim()` と「現行 APP_KEY をそのまま宣言すれば維持できる」が一致しない
- 判断: 対応する（Codex の前者案を採用）
- 根拠: 指摘のとおり。`APP_KEY` は理論上任意の文字列で、前後空白を含む値を trim すると別の鍵になり、
  「そのまま宣言すれば維持できる」という運用契約が破れる。
- 対応内容: **値そのものは trim しない**。trim は「宣言されたか（空白だけではないか）」の判定にだけ使う。
  config の該当箇所にコメントで理由を書き、テスト計画に
  「前後に空白を含む値が trim されずそのまま入る」を追加した。

## [Warning] 制約検査の前方一致が `^0.2 || ^0.3` 等を通す
- 判断: 対応する
- 根拠: 指摘のとおり `str_starts_with('^0.2')` は `^0.20` / `^0.2.1 || ^0.3` / `^0.2@dev` を通し、
  「0.3 系を入れない」という検査の目的そのものを破る。
- 対応内容: `preg_match('/^\^0\.2(?:\.\d+)?$/', $constraint) === 1` の**完全一致**に変更し、
  設計本文の「許容する制約表現」も同じ形に書き直した。

## [Warning] 例外メッセージ `is not a registrable domain name` が保証を過大に表現している
- 判断: 対応する
- 根拠: `co.uk` を通す以上「registrable であること」は検査していない。文言が実装より強い。
- 対応内容: `is not an accepted production DNS name` に変更し、
  「public suffix (`co.uk` 等) はここでは reject されない（PSL を持たない）」を
  メッセージ本文にも書いた。コード側コメントも「登録可能なドメイン名」→
  「production で受け付ける dotted DNS 名」に直した。

## [Suggestion] `isDnsName()` は DNS 構文検証ではなく production policy だと docblock に書く
- 判断: 対応する
- 対応内容: docblock に「純粋な DNS 構文検証ではなく production の WebAuthn 用に採用する DNS 名の方針」
  と明記した（DNS 構文自体は全数字の末尾ラベルを全面禁止していない）。

## [Suggestion] `.env.example` の検査を行頭一致にする
- 判断: 対応する
- 根拠: `toContain` はコメント行 `# PASSKEYS_USER_HANDLE_SECRET=` でも通り、
  「宣言行として提示されている」ことを固定できない。実際に本設計は
  他の 2 キーを**意図的にコメントアウトで提示**するので、この差は現実に起こりうる。
- 対応内容: `expect($contents)->toMatch('/^PASSKEYS_USER_HANDLE_SECRET=/m')` に変更した。

## [Suggestion] 既知の限界テストの名前に `documented limitation` を入れる
- 判断: 対応する
- 対応内容: テスト名を
  `既知の限界 (documented limitation): public suffix の身元識別子は通る` に固定した。

## [判定] PSL 依存を追加しない判断 / isDnsName の偽陽性 / isStringList の既存影響
- Codex が Round 2 で「同意できる」「偽陽性は見当たらない」「既存 13 項目に影響しない」と回答済み。
  設計変更なし。
