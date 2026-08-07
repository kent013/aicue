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
