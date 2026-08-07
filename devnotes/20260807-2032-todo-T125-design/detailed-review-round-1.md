**全体判定: CHANGES_REQUESTED**

設計の方向性は妥当です。inline throttle の共有 bucket を named limiter に移し、閾値を変えずにレーンだけ分離する判断は AGENTS.md の規約 5 と整合しています。DTO/JsonResource/Inertia/UI への影響もなく、変更境界は明確です。

ただし、gate の「空振りしない」「そのテストだけが赤になる」「exact-fit cap」という本設計固有の重点観点で修正が必要です。

**施策別判定**

| 施策 | 判定 |
|---|---|
| S1 `RateLimiterKeys` helper | APPROVE |
| S2 認証面 4 レーン登録 | APPROVE |
| S3 業務面 2 レーン登録 | APPROVE |
| S4 route 適用 | APPROVE |
| S5 inline 残置目録 gate | REQUEST_CHANGES |
| S6 レーン割当目録 gate | REQUEST_CHANGES |
| S7 キー規約検査拡張 | REQUEST_CHANGES |
| S8 behavioral proof | REQUEST_CHANGES |
| S9 既存テスト・文書追随 | APPROVE。ただし S5-S8 修正後に文言追随 |

**指摘**

[Critical] S8 の behavioral proof が一部 false green になりえます。  
`settings.password.store` は `recent-auth` が throttle より前に短絡すると、対象 throttle が実行されなくても `not 429` になります。つまり `password-set` が誤って `password-verify` に戻っても、テストが「巻き添えなし」と誤判定する可能性があります。2FA 管理系も password confirm / recent auth 系 middleware が前段にある場合は同じリスクがあります。

修正案: 独立性を確認する対象 request では `X-RateLimit-Remaining` または `X-RateLimit-Limit` が存在することを必ず検査してください。`settings.password.store` は既存 helper で recent-auth 済み状態を作るか、該当テストを `PasswordSetupTest` 側へ置いて既存前提を再利用してください。

[Critical] mutation 手順の「期待するテストだけが赤」が成立していません。  
例: M1 で `recent-auth.password` を `throttle:6,1` に戻すと、`InlineThrottleInventoryTest` だけでなく `ThrottleLaneAssignmentTest` も `password-verify` から route が消えるため赤になります。M8 も inline 化により S5/S6/S8 が同時に赤になるはずです。M4 も「missing limiter」テストではなく、主に割当完全一致で落ちます。

修正案: mutation log の期待赤を「primary failure」と「collateral failure」に分けて正確に書くか、単一 gate だけを赤にしたいなら、対象テストファイル単位で実行する手順に変更してください。現状の表のままでは gate 検証結果を誤読します。

[Warning] S5 の cap は exact-fit ではなく upper-bound です。  
`count > cap` しか見ていないため、件数が減った後に cap を下げ忘れると spare exemption 枠が残ります。これは「余裕を持たせない」という設計意図と矛盾します。

修正案: `count !== cap` を fail にするか、名前と説明を「上限」に寄せて exact-fit と呼ばないでください。本設計の狙いなら `count === cap` を推奨します。

[Warning] S6 の typo 検出が不十分です。  
`目録のレーン名はすべて実在する named limiter` は inventory 側の lane だけを見ています。route 側に `throttle:password-sett` と書かれた未知 named limiter 自体は列挙されません。完全一致テストで route 欠落は検出できますが、missing limiter としては検出できません。

修正案: 実 route から named throttle params を全収集し、inline 以外の params について `CacheRateLimiter::limiter($params) !== null` を検査する gate を追加してください。

[Warning] S1/S7 は「キー文字列が変わっていない」ことをやや過大に主張しています。  
`expectedKeyPrefixes` は prefix の一致であり、full key の同一性までは保証しません。bucket reset リスクを論じるなら suffix まで見るテストが必要です。

修正案: 少なくとも `passkeys` / `two-factor-secret-read` の authenticated / guest 固定 scenario で full key を検査してください。新 helper の unit test だけでなく、RateLimiter closure 経由の produced key も確認するのが安全です。

[Suggestion] S5 の vendor inline rationale は premise test を足すと強くなります。  
`passport.*` が本当に stateless で `StartSession` を持たないこと、`livewire.upload-file` が唯一の mixed inline route であることを middleware 実効列で固定すると、vendor 更新時の drift に気づけます。

**補足**

閾値変更は見当たりません。`6/min`, `10/min`, `60/min` は既存値を維持しており、この点は規約に沿っています。  
設計本体は良いので、上の gate 精度と mutation 手順を直せば Round 2 でかなり承認に近いです。