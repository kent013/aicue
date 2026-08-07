# 対応マトリクス: design-review Round 3

## [Warning] 施策 5: tests 側 setter gate が「ファイル許可」に留まり exact-fit になっていない

- 判断: **対応する**（提案された「相対パス × シンボル × site 件数」をそのまま採用）
- 根拠: 指摘が正しい。設計例では同一ファイル内に 2 site（設定 + `finally` 復元）が
  存在するため、「許可ファイルなら何件でもよい」では許可済みファイル内への追加を検出できない。
  「exact-fit」と説明しながら検査強度が伴っていなかった。
- 対応内容:
  - 期待値表を **相対パス × シンボル × site 件数**へ置き換えた
    （provider 1/1/0、provider テスト 2/2/0、call-budget テスト 2/0/0、それ以外 0/0/0）。
  - 期待値を目録定数として持ち、**対称差ゼロ + 件数一致**を検査する形にした。
  - 失敗メッセージは `{相対パス}:{行} [{シンボル}] (関数/メソッド名)` を出すが、
    **行番号は期待値の識別子にしない**（整形で動くため診断情報限定）と明記した。
  - `ApiRequestor::httpClient`（getter）は状態を変えないため
    **app/ でも 0 件を要求しない**ことを明示した（将来 pin 値を読む正当なコードを塞がない）。
  - mutation #19（許可済みファイル内に 1 件足すと赤）を追加した。

## [Warning] 施策 6: `CountingStripeHttpClient` に `use Webmozart\Assert\Assert;` が欠落

- 判断: **対応する**
- 根拠: 指摘のとおり。import が無いと `Tests\Support\Billing\Assert` として解決され実行時に失敗する。
- 対応内容: 変更後コードへ import を追加し、
  「無いと `Tests\Support\Billing\Assert` に解決される」旨をコメントで残した。

## [Suggestion] 施策 6: `$expectedException` を `class-string<Throwable>|null` へ狭める

- 判断: **対応する**
- 対応内容: テストクロージャの直前に
  `@param class-string<Throwable>|null $expectedException` と
  `@param list<array{status: int, body: string}> $responses` の PHPDoc を追加した。

## 実装時確認へ落とす事項（Round 3 で合意済み）

以下は設計へ書ききらず「未解決 / 実装時に確認すること」に残す:

- `config/filesystems.php` / `config/services.php` の直接 `require` 有無
- SES mailer を局所的に解決するための具体的な config 値
- 新 enum が既存 TS 同期 gate の母集団に入らないこと
- AWS SDK / Laravel の実オブジェクトで `@http` / `@retries` が期待どおり見えること
- Stripe fixture の必要フィールドと厳密な呼び出し回数
- `docs/TODO.md` の T127 更新を別責務で行うこと
