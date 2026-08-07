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
| S8 behavioral proof | REQUEST_CHANGES |
| S9 追随更新 | APPROVE |

## 指摘

### [Warning] 別ファイルへ置くプラン有効化テストから `expectNotThrottled()` を参照できる保証がない

設計では以下の配置になっています。

- `expectNotThrottled()`：`AuthThrottleCoverageTest.php`
- プラン有効化テスト：`ActivatePersonalTest.php`

Pestのテストファイルで宣言したグローバル関数を別テストファイルから利用する構成は、ファイル単独実行、ロード順、filter指定に依存します。特にmutation確認や絞り込み実行で `ActivatePersonalTest.php` だけを対象にした場合、関数未定義になる可能性があります。

これは`--parallel`以前のテスト分離性の問題です。

修正案：

- `ActivatePersonalTest.php`側ではヘッダ検査と429検査を直接書く。
- または共通化が本当に必要なら`tests/Support`の明示的なクラスへ置く。
- 今回は利用箇所が少ないため、直接記述が「今必要なものだけ作る」に最も合います。
- `expectNotThrottled()`も衝突しにくい`throttleProbeExpectNotThrottled()`へ変更すると、Pestのグローバル関数汚染を抑えられます。

### [Suggestion] mutation M9の対象テストを限定して記録する

M9-aで`recent-auth.password`のthrottleを剥がすと、「パスワード照合レーンを使い切る」テストは7回目の429期待で先に失敗します。したがって、`AuthThrottleCoverageTest`全体がgreenになるわけではありません。

M9は次のように対象を明記すると正確です。

- M9-a：Livewire、2FA管理、メール検証など、`recent-auth.password`をcross-lane probeとしてのみ使う個別テストでは偽greenになる。
- M9-b：同じ個別テストがヘッダ検査によって赤になる。
- パスワード照合レーン枯渇テストは対象外。

## 重点観点

1. vendor provenanceは十分に機械化されています。残るすり抜けは、名前空間allowlist自体の変更、vendor名前空間を偽装する自前クラス、独自middlewareによるuser resolver設定です。いずれも限界が明記され、通常の`App\...` routeはM10で拒否されるため、目録型gateとして妥当です。
2. Livewireの空振りは閉じています。ヘッダ存在と`remaining[5] === remaining[0] - 5`により、6リクエストが同一bucketを1回ずつ消費したことを確認できます。
3. 非自明なgateにはmutationが割り当てられています。文字数検査、母集団下限、共有グループの「2本以上」など単純な自己完結assertionには専用mutationがありませんが、17項目をさらに増やす必要はありません。
4. 実装順序に本質的な順序ミスはありません。S2/S3からS7まで一時的に既存gateが赤になる点も明示されています。S8を先行させることで問題の再現性も保存されています。
5. array storeとFactory利用による`--parallel`安定性にも問題はありません。ただし上記の別ファイル関数依存は解消が必要です。

## 全体判定

**CHANGES_REQUESTED**

Round 2のWarning 2件は解消されています。残件は実装ロジックではなく、S8のテストファイル間依存です。これをローカルなassertionへ直せば、設計全体は承認可能です。閾値変更はなく、既存の`6/min`、`10/min`、`60/min`は維持されています。