**.env.example**
- [Suggestion] 設計通りです。`PASSKEYS_USER_HANDLE_SECRET=` を非コメント行で固定しており、Architecture test もコメント行だけで通らない形になっています。

**AGENTS.md**
- [Suggestion] 設計通りです。production 起動時 fail-fast と既存パスキー移行時の `APP_KEY` 維持手順が明記されています。

**app/Support/PasskeyConfigValidator.php**
- [Suggestion] 実装は詳細設計と一致しています。DNS ラベル検査、IP/localhost/単一ラベル拒否、origin と RP ID の境界判定、`notapp.example.com` の負のコントロールまで揃っています。
- [Suggestion] PSL 非対応を「既知の限界」としてテスト固定している点も妥当です。fail-open ではなく、スコープ外を明示した運用上の制約として扱えています。

**app/Support/ProductionEnvGuard.php**
- [Suggestion] 設計通りです。実効値は `passkeys.*`、検査専用キーは `fortify.passkeys.*` から読む分離が実装されています。
- [Suggestion] `isStringList()` で非 string を黙って落とさず violation にする方針も fail-closed で妥当です。
- [Suggestion] `Features::enabled(Features::passkeys())` によるキルスイッチも設計通りです。

**composer.json / composer.lock**
- [Suggestion] `laravel/passkeys` の直接要求追加は妥当です。lock 差分が content-hash のみで解決版不変なら、設計意図どおり最小差分です。

**config/fortify.php**
- [Suggestion] 設計通りです。`config/passkeys.php` ではなく `fortify.passkeys` に宣言しており、Fortify の写像前提に合っています。
- [Suggestion] `env()` / `parse_url()` の mixed 絞り込みも問題ありません。`APP_URL` path 除去、port 維持、宣言 origin の trim/小文字化、空要素保持の扱いもテストされています。
- [Suggestion] `PASSKEYS_USER_HANDLE_SECRET` の値を trim しない判断は、既存パスキー維持の運用契約と一致しています。

**docs/auth-security-mechanisms.md**
- [Suggestion] 設計通りです。正本が `config/fortify.php` であること、`config/passkeys.php` を置かないこと、Fortify 側写像と passkeys 側 pin の責務分離が書かれています。

**tests/Architecture/EnvExampleInvariantTest.php**
- [Suggestion] テストは十分です。行頭一致なのでコメントだけでは通りません。

**tests/Architecture/PasskeyPackageContractTest.php**
- [Suggestion] Fortify 写像の sentinel テストは重要な負のコントロールになっています。fallback と同値になる偽陰性を避けています。
- [Suggestion] `toHaveKey` の誤用を避けて `array_key_exists` にしている点も正しいです。
- [Suggestion] composer 制約の正規表現は OR / dev flag / `^0.20` を弾けており、目的に合っています。

**tests/Feature/Config/ConfigHardeningTest.php**
- [Suggestion] 設計に列挙された env 派生テストが揃っています。特に trailing comma の raw 保持、空白 secret の未宣言扱い、secret 非 trim が固定されています。

**tests/Feature/Support/ProductionEnvGuardTest.php**
- [Suggestion] baseline 追加、passkeys 有効前提、キルスイッチ、非 string 混入の fail-closed が揃っています。既存の “1項目ずつ崩す” テスト構造も維持されています。

**tests/Unit/Support/PasskeyConfigValidatorTest.php**
- [Suggestion] 境界条件と負のコントロールは十分です。`192.168.001.001`、大文字 RP ID、大文字 origin、userinfo、path、port 範囲、suffix 境界が押さえられています。

重大な不一致、PHPStan level 10 上の懸念、`response()->json()` 直書き、テスト不足、明確な fail-open は見当たりません。

APPROVED