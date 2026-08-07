# impl-review Round 3

Round 2 の唯一の指摘 ([Warning] 根拠文字列の後半が premise の保証範囲を超えている) に対応しました。

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

## [Warning] `passport.token` / `passport.device.code` の根拠の**後半**が premise の保証範囲を超えている

- 判断: **対応する**
- 根拠: 指摘が正確である。premise が機械検査しているのは
  「`StartSession` が無い」「`AuthenticatesRequests` 実装が無い」という**構造の不在**までであり、
  そこから「キーは IP になる」という**帰結**は導けない
  (独自 middleware が user resolver を差し替える余地が残るため)。
  Round 1 で前半は弱めたが後半 (`…キーは IP になる` /
  `…認証済み actor の bucket とは交わらない`) を残していたのは中途半端だった。
  この codebase は「効かない範囲を明記する」ことを重視しており、
  根拠文字列が premise より強い主張をしていると次に読む人が
  「機械検査で保証されている」と誤読する。
- 対応内容: 目録の根拠 2 本を premise が閉じている範囲ちょうどに切り詰めた。
  - `passport.token`: 「StartSession も framework の認証 middleware も通らないため、
    **session guard または framework の認証 middleware 経由で user へ倒れる経路がない**
    (この構造を premise が機械検査する)。」
  - `passport.device.code`: 「StartSession も framework の認証 middleware も通らず、
    **この 2 経路によって認証済み actor の bucket と交わる構造ではない**
    (この構造を premise が機械検査する)。」

  どちらも「IP になる」「交わらない」という**結果の断定を落とし**、
  premise が実際に検査している**構造の不在**だけを述べる形にした。

## Round 2 で解消と判定された箇所

- [Critical] (Round 1) `AuthThrottleCoverageTest` の責務境界コメント → 解消
- [Suggestion] (Round 1) `livewire.upload-file` の「専有」の対象限定 → 解消
- `InlineThrottleBucketRationale` の docblock → 指摘なし

## 検証

本ラウンドの変更も**根拠文字列 (コメント相当) のみ**であり、
検査ロジック・閾値・route 指定・limiter 登録には触れていない。
`InlineThrottleInventoryTest` の「目録の値は enum + 実質的な根拠文字列」は
30 文字下限を課すが、両根拠とも大幅に上回っているため下限割れは起きない
(再実行で確認する)。


## 修正後の根拠文字列 (`tests/Architecture/InlineThrottleInventoryTest.php` の `inlineThrottleInventory()`)

```php
        'passport.token' => [$statelessIp,
            'Laravel\Passport\RouteRegistrar::forAccessTokens() が middleware([\'throttle\']) を'
            .'ハードコードしており、設定でも RouteThrottleBinder でも置換できない'
            .'(後付けすると二重付与になり ThrottleCoverageInventoryTest が fail する)。'
            .'StartSession も framework の認証 middleware も通らないため、'
            .'session guard または framework の認証 middleware 経由で user へ倒れる経路がない'
            .'(この構造を premise が機械検査する)。'],

        'passport.device.code' => [$statelessIp,
            '上記 passport.token と同じく Passport がハードコードした throttle (既定 60/min)。'
            .'device authorization grant の code 発行 endpoint で StartSession も framework の'
            .'認証 middleware も通らず、この 2 経路によって認証済み actor の bucket と交わる構造ではない'
            .'(この構造を premise が機械検査する)。'],
```

いずれも「キーは IP になる」「認証済み actor の bucket とは交わらない」という
**結果の断定を落とし**、premise が実際に検査している**構造の不在**だけを述べる形にしました
(ご提案いただいた表現をほぼそのまま採用しています)。

## 再検証の結果 (すべて worktree 内で実行済み)

```
composer test -- tests/Architecture/InlineThrottleInventoryTest.php
    -> tests=7 passed=7 failed=0
composer test -- tests/Feature/Security/AuthThrottleCoverageTest.php   (Round 1 修正後)
    -> tests=31 passed=31 failed=0
composer phpstan          -> [OK] No errors
vendor/bin/pint --test    -> passed
```

Round 2 で「本文に記載が無い」とご指摘のあった `AuthThrottleCoverageTest` の結果を上に示しました
(31 件すべて green)。

なお Round 1 実施前の全件実行は
`composer test -> tests=3720 passed=3718 skipped=2 failed=0`、
`composer test:browser -> chromium 11 passed / 3 skipped, webkit 11 passed / 3 skipped` です。
Round 1 / Round 2 の修正はいずれも**コメント・根拠文字列のみ**で、
検査ロジック・閾値・route 指定・limiter 登録には触れていません。

## 依頼

Round 2 の Warning が解消しているか判定し、
**全体判定 (APPROVED / CHANGES_REQUESTED)** を明記してください。
