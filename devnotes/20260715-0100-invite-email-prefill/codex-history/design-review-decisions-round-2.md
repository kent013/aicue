# 対応マトリクス: design-review Round 2

## [Warning] S5: active token が GET 後も session 維持される明示テストが無い
- 判断: 対応する
- 根拠: 妥当。resolver が active token を forget しないこと (= POST で招待参加できる回帰保護) を固定すべき。
- 対応内容: active token ケースに `assertSessionHas('invitation_token', $token)` を追加。

## [Suggestion] S3: 「no-store が bf-cache を防ぐ」断定は実装差あり
- 判断: 対応する
- 対応内容: 記述を「HTTP キャッシュ (共有/中間プロキシ/ブラウザ HTTP キャッシュ) への保存を禁止」に修正。コメント文言も同様に調整。

## [Suggestion] S3: ヘッダテストは完全一致より directive 追加許容が堅牢
- 判断: 対応する
- 対応内容: S5 の Cache-Control 検証を「`no-store` ディレクティブを含む (str_contains 相当)」に変更し、middleware による別ディレクティブ付与を許容。
