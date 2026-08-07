**tests/Support/StrayHttpRequestGuard.php**
- [Critical] `ALLOWED_URL_PATTERNS` の `http://127.0.0.1:*` / `http://localhost:*` 系が `Str::is()` glob なので、userinfo 形の外部 URL を許可します。例: `http://127.0.0.1:80@api.frankfurter.dev/` は pattern に一致しますが、実際の host は `api.frankfurter.dev` です。これは「loopback だけ許可」の不変条件を破り、未 fake 外部 HTTP が実送信されうる穴です。URL 文字列一致だけでなく PSR URI の parsed host/userinfo を見て、pattern に一致しても host が loopback でない場合は拒否する必要があります。

**tests/Architecture/StrayHttpEgressLaneGateTest.php**
- [Critical] `strayHttpEgressPatternViolations()` は pattern 形だけを検査しており、上記 userinfo bypass を検出できません。負のコントロールにも `http://127.0.0.1:80@evil.example/` / `http://localhost:any@evil.example/` が無いため、gate はこのクラスの偽緑を許します。S4 の「許可 URL パターンが loopback に閉じている」保証が空振りしています。

**tests/Feature/Support/StrayHttpRequestGuardTest.php**
- [Warning] case H は `127.0.0.1.evil.example` 型だけを見ており、実際に危険な userinfo 型を固定していません。上記修正に合わせて `Str::is()` 一致可否だけでなく、guard 実挙動として userinfo 外部 URL が accumulator に記録されることを self-test に入れるべきです。

**tests/Pest.php**
- [OK] Feature/Unit・Architecture・Browser の 3 レーン配線は S3 と一致しています。flush/reset の直列順も設計上の受容事項どおりです。

**tests/Support/Security/StrayHttpEgressExemption.php**
- [OK] enum は S4 の inventory 方針と一致しています。

**tests/Feature/Auth/RegistrationTest.php**
- [OK] コメント変更のみで、DTO/JsonResource・テスト挙動への影響はありません。

**tests/Feature/Security/AuthThrottleCoverageTest.php**
- [OK] 局所 `preventStrayRequests()` を同値の重複宣言として残す説明は設計 S5 と一致しています。

**tests/Feature/Security/ThrottleExemptionPremiseTest.php**
- [OK] コメント変更のみで、既存 assertion を弱めていません。

**横断判定**
DTO / JsonResource、DESIGN.md、Atomic Design は非該当です。PHPStan については `tests/` 対象外前提では問題を見つけていませんが、今回の blocker は型ではなく default-deny の許可境界です。

全体判定: CHANGES_REQUESTED