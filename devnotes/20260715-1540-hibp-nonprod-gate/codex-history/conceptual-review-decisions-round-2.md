# 対応マトリクス: conceptual-review Round 2 (全体判定 APPROVED)

## [Warning] `private const array` は PHP 8.3+ 構文。プロジェクトの PHP バージョン前提を確認せよ
- 判断: 反論(前提充足を確認)
- 根拠: `composer.json` は `"php": "^8.4"`。かつ既存コードが型付き定数を実使用済み(`PasswordPolicy::MIN_LENGTH = public const int`、`FakeExternalsServiceProvider::PAYMENT_FAKE_ENVIRONMENTS = private const array`)。PHP 8.4 前提で `const array` は実行可能。設計に PHP 8.4 前提を明記して解消。
- 対応内容: 詳細設計の「コーディングルール/前提環境」に PHP 8.4 を明記(既存 typed const 前例あり)。

## [Suggestion] 「未知 env は既定 ON」は厳密には「fake_externals !== true の未知 env は ON」
- 判断: 対応する(文言精緻化)
- 根拠: 誤読防止に有効。
- 対応内容: 概念設計の改善アイデア節に「正確には fake_externals !== true の未知 env が ON」を補記。

## [Suggestion] fake_externals 分岐の残置は妥当(環境名推測ではなく独立契約の表現)/削除不要
- 判断: 反映済み(維持)
- 対応内容: 述語 2 を維持。

## その他 [Suggestion](使命整合 / 禁止事項なし / 効果基準 / スコープ / 型安全)
- 判断: 反映済み・維持。追加対応不要。
