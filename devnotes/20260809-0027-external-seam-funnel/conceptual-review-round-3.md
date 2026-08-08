全体判定: **CHANGES_REQUESTED**

Round 2 の実質的な論点は解消されています。ただし、委譲検査について本文内に旧方式が残っており、実装契約が二通りに読めるため、概念設計として1点だけ修正が必要です。

### 1. 使命との整合性

[Suggestion] 検知 v1 として使命への間接的な貢献が適切に限定されています。SSO外部遷移が残ることも明示されており、過大な主張はありません。

### 2. 禁止事項違反

[Suggestion] 禁止事項への抵触はありません。Architectureテスト、テストファースト、PHPStan level 10、追跡対象ファイルだけを完了条件とする方針も妥当です。

### 3. 実現可能性

[Warning] §2-1と§4 S3で委譲検査の仕様が一致していません。

§2-1では次の契約です。

- 委譲母集団を実際に導出して生存確認
- 委譲先のtest名を固定

一方、§4 S3には旧方式のまま次が残っています。

> 指定の識別子 (`EXTERNAL_CLIENT_BOUNDARY_INVENTORY` / `PrismDirectDispatchScanner` / `socialProvidersConfig`) を含む

これでは、実装者が識別子検索だけを実装しても設計準拠と解釈できます。

修正提案: §4 S3の「委譲の実在」を§2-1と同じ二層契約へ書き換えてください。識別子検索を併用するなら補助検査と明記し、主要保証に数えないでください。

### 4. 期待効果の妥当性

[Suggestion] SSOについて「信頼属性の宣言漏れ検知」であり、宛先allowlistや遷移可否審査ではないと限定したため妥当です。

Captchaについても、bug-huntで既に有効なfake capability配下にbindする前提が明示されています。

### 5. リスク

[Suggestion] Stripeの採用siteと抑制siteを別コレクションで保持し、抑制件数ゼロを全走査で固定する設計は妥当です。

詳細設計では、抑制件数だけでなく抑制siteのパス・呼び出し位置を失敗メッセージに含めると原因を特定しやすくなります。

### 6. スコープの適切さ

[Suggestion] `SocialAuthController`を唯一の正規経路として名指し固定し、別gatewayやSSO fakeを作らない判断は、現時点の必要性に対して適切です。

独立TODOに分離したSSO遮断を本PRの完了条件に戻す必要はありません。

### 7. 型安全性

[Suggestion] entry、classification、dimension、delegationをenumとreadonly value objectで表現する方針はPHPStan level 10に適合します。

詳細設計ではコレクションの要素型もPHPDoc genericsで閉じ、`mixed`や未指定arrayを残さないことが必要です。

### 8. 既定拒否

[Suggestion] 次の点が成立しており、Round 2の指摘は解消されています。

- app/の走査母集団から未登録経路を検出する
- 母集団ゼロを失敗させる
- Stripeの抑制による偽陰性をゼロ固定する
- SocialLoginは別クラスを登録・免除できない
- 委譲対象の母集団も本gate側で生存確認する

未知の次元を検出できない限界も正確に記述されています。

### 9. 二重管理

[Suggestion] provider、object storage、LLMの到達事実を新目録へ再宣言せず、委譲する責務分離は妥当です。`AppServiceProvider`が異なる到達事実で両目録に現れる説明も十分です。

### 10. 保証範囲の誠実さ

[Suggestion] 遮断、走査根、vendor内部、宛先集合、委譲先assertの弱体化、未知の次元、管理外envについて、保証しない範囲が具体的に記述されています。

§4 S3の旧記述を解消すれば、保証範囲との不整合もなくなります。

結論として、設計上の主要問題は解消されています。**§4 S3の委譲検査を、§2-1で定義した「behavioralな母集団生存確認 + test名固定」に統一すればAPPROVEDにできます。**