**Findings**

`tests/Feature/Security/AuthThrottleCoverageTest.php`
- [Critical] T125 セクションのコメントに「inline へ戻す変更を入れたらここが必ず落ちる」とありますが、提示された M8 実測で反証されています。整理自体は妥当で、検出責務は目録 gate 側にある、という結論も正しいです。ただしこのコメントはその整理と矛盾するため、マージ前に「behavioral proof は巻き添え 429 の消滅を固定し、inline 差し戻し自体は InlineThrottleInventoryTest / ThrottleLaneAssignmentTest が検出する」へ修正してください。

`tests/Architecture/InlineThrottleInventoryTest.php`
- [Warning] `passport.token` の根拠文字列や premise コメントに「session を持たないため `$request->user()` は常に null」「キーは IP 固定」といった断定があります。一方で同ファイル上部では「StartSession が無いことは `$request->user()` が絶対 null を意味しない」と保証範囲を限定しています。後者が正しい書き方なので、根拠文字列側も「現行の vendor action + session/auth middleware 不在という構造上、session guard 経由では user に倒れない」程度に弱めるのが安全です。
- [Suggestion] `livewire.upload-file` の根拠に「bucket を専有する」とありますが、正確には「認証済み actor bucket としては唯一」です。guest/IP 側の inline 共有まで専有とは読めるため、対象を明示すると保証範囲が締まります。

`app/Support/Http/RateLimiterKeys.php`
- 判定: 問題なし。`int` / 非空 `string` のみ user 分岐、それ以外を IP へ倒す判断は、`bool` / `float` の誤受理を避けており妥当です。full key 固定と Unit の negative control も対応しています。

`app/Providers/FortifyServiceProvider.php`
- 判定: 問題なし。S2/S4 のレーン分割、閾値維持、`password-verify` の合算は設計どおりです。Fortify binder 経由の vendor route 差し替えも妥当です。

`app/Providers/AppServiceProvider.php`
- 判定: 問題なし。業務面 2 レーンは設計どおりで、未認証 GET の `invitation-accept` と POST の `invitation-accept-submit` を分けた点も正しいです。

`routes/web.php` / `config/fortify.php`
- 判定: 問題なし。直書き 4、本体 config 1、binder 6 の適用方針に合っています。二重付与・付与漏れを防ぐ gate もあります。

`tests/Architecture/ThrottleLaneAssignmentTest.php`
- 判定: 問題なし。割当 exact match、空振り検出、named limiter typo 検出が揃っています。

`tests/Architecture/RateLimiterKeyConventionTest.php`
- 判定: 問題なし。full key 固定、共有グループの死活検査、pairwise 衝突検査があり、保証範囲も scenario 内に限定して書けています。

`AGENTS.md` / `docs/app-integration-guide.md`
- 判定: 概ね問題なし。「後退リスクゼロ」とは書かず、巻き添え 429 の単調緩和に限定している点は妥当です。上記の `AuthThrottleCoverageTest.php` コメントだけ、M8 の整理と整合させてください。

`tests/Unit/Support/Http/RateLimiterKeysTest.php`
- 判定: 問題なし。DB 非依存で、`is_scalar()` 回帰の negative control も入っています。

**M8 の扱い**
整理は妥当です。2FA 管理だけを inline に戻しても、巻き添え先が named 化済みなら behavioral test が赤にならないのは自然です。これは behavioral proof の欠陥ではなく、検出責務が目録 gate にあるケースです。ただし、その結論に合わせてテストコメントの過剰な断定は直す必要があります。

**全体判定: CHANGES_REQUESTED**