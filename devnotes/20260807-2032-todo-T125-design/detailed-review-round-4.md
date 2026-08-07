## 施策別判定

| 施策 | 判定 |
|---|---|
| S1 `RateLimiterKeys` helper | APPROVE |
| S2 認証面4レーン | APPROVE |
| S3 業務面2レーン | APPROVE |
| S4 route適用 | APPROVE |
| S5 inline残置目録gate | APPROVE |
| S6 レーン割当gate | APPROVE |
| S7 キー規約検査 | APPROVE |
| S8 behavioral proof | APPROVE |
| S9 追随更新 | APPROVE |
| mutation確認手順 | REQUEST_CHANGES |

## 指摘

### [Warning] M9-a/M9-bの対象にプラン有効化テストを含めると期待結果どおりにならない

プラン有効化テストは今回、`throttleProbeExpectNotThrottled()`を使わず、`ActivatePersonalTest.php`内で残数ヘッダを直接検査する形になりました。

しかしM9-aのmutationは次の2点だけです。

- `recent-auth.password`からthrottleを剥がす
- `throttleProbeExpectNotThrottled()`のヘッダ検査を外す

この状態でも、プラン有効化テストの直接記述されたヘッダ検査は残ります。そのため同テストはgreenではなく、`X-RateLimit-Remaining`がないことで赤になります。M9-bでも「ヘッダ検査だけを戻す」の対象がhelperだけなら、プラン有効化テストの状態はM9-aから変化しません。

修正案はどちらかです。

- M9-a/M9-bの対象を、helperを使う3本（Livewire／2FA管理／メール検証）に限定する。プラン有効化の直接assertionは単純な同型検査として専用mutationを省略する。
- またはM9-aで`ActivatePersonalTest.php`の直接ヘッダ検査も外し、M9-bでhelperと直接ヘッダ検査の両方を戻す。

前者の方がmutationを小さく保てます。

## Round 3 指摘の確認

テストファイル間のhelper依存は解消されています。`throttleProbeExpectNotThrottled()`は`AuthThrottleCoverageTest.php`内に閉じ、`ActivatePersonalTest.php`ではassertionを直接記述しているため、ファイル単独実行、filter実行、`--parallel`のいずれでもロード順に依存しません。

実装本体、vendor provenance、Livewireの消費証明、exact-fit、full-key検査、レーン割当には新たな問題は見当たりません。閾値も変更されていません。

## 全体判定

**CHANGES_REQUESTED**

残件はM9の記述と実際のテスト配置の不一致だけです。設計・実装方針そのものは承認可能な状態です。M9の対象をhelper利用3本へ修正すれば、全体を`APPROVED`と判定できます。