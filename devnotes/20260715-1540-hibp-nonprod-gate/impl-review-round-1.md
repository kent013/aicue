**`app/Support/PasswordPolicy.php`**
- **Critical**: なし。
- **Warning**: なし。
- **Suggestion**: `PWNED_CHECK_DISABLED_APP_ENVS` は意図が明確で良いです。加えて将来の運用ミス防止として、`bughunt.local` のような特殊 env をこの定数に集約する方針を `docs` 側にも一文同期すると、仕様ドリフト耐性が上がります。
- 判定: 詳細設計に整合。`production` 先頭 `true` による fail-secure、denylist 反転による未知 env 既定 ON、`fake_externals` 非依存の責務分離は要件どおりです。`rule()` の配線も単一述語経由に集約され、設計意図に一致しています。

**`tests/Unit/Support/PasswordPolicyTest.php`**
- **Critical**: なし。
- **Warning**: `withPasswordPolicyAppEnv()` で `app()->instance('env', $env)` を使う方式は妥当ですが、テスト並列時に同一プロセス内で env を共有する実装へ今後変わると干渉リスクがあります（現状の Pest 並列モデルでは通常問題化しにくい）。
- **Suggestion**: 反射テストは「1本に限定」の設計方針どおりで適切です。将来 `Password` ルール実装差分に強くするなら、可能な範囲で black-box（バリデーション実行結果）寄りの補助ケースを1つだけ追加してもよいです。
- 判定: env matrix（production=true / 未知=true / 既知=false / fake_externals 非結合）を明示的に固定しており、回帰ガードとして意味があります。空テスト化の懸念は低いです。PHPStan level 10 観点でも問題は見えません。

**`tests/Feature/Auth/RegistrationTest.php`**
- **Critical**: なし。
- **Warning**: なし。
- **Suggestion**: `assertNotSent` の条件は十分ですが、可読性のために host 比較（`parse_url(..., PHP_URL_HOST)`）へ寄せると意図がより明確になります（任意）。
- 判定: F-4-01（非本番で HIBP 不送出）を Feature レイヤで固定できており、セキュリティ後退検知として有効です。`Http::fake` と `assertNotSent` の組み合わせは適切です。

**総合レビュー**
- 設計一致性: 良好（要件を過不足なく実装）。
- 正確性/セキュリティ: 良好（production fail-secure 不変条件を構造的に担保、未知 env 既定 ON、`fake_externals` 非結合）。
- PHPStan 適合性: 提示結果および差分内容から問題なし。
- テスト網羅性: 要求された分岐を十分にカバー。既存ケース非削除も満たしています。
- 禁止事項抵触: 該当なし（テストあり、型緩和なし、JSON直書き等なし）。

**最終判定: APPROVED**