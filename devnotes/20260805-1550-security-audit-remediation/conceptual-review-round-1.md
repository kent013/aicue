全体判定: **CHANGES_REQUESTED**

大筋は妥当です。S1/S2/S3 は存在オラクルの主因を正しく捉えており、North Star への貢献も「顧客 SOP を預かる基盤の信頼性」として十分説明できています。ただし、S4 とスコープ外 1 の扱いがまだ弱く、このままでは「同じ穴が再発したら落ちる」保証になり切っていません。

**使命との整合性**

[Suggestion] 本改善は機能追加ではなく信頼境界の修復なので、使命との整合性はあります。SOP / project id / users.id の存在漏えいを閉じることは、AI-CUE が業務データを預かる前提に直結します。

**禁止事項・セキュリティ不変条件**

[Critical] スコープ外 1 の `verified` / 2FA 強制 gate 残存を「exemption inventory に凍結」だけで済ませるのは弱いです。これは「子は親に属する: nested route の不整合は認可より前に 404」に対する既知違反を残す判断であり、アプリ都合で不変条件を緩めているように見えます。

修正提案: 今回直さないなら、少なくとも以下を設計に追加してください。

- 対象 route 一覧
- 漏れる条件の正確な象限
- 影響する認証状態
- 恒久対応案
- TODO 登録先
- exemption の期限または削除条件
- Architecture テストで「既存 exemption 以外は fail」だけでなく「exemption 対象 route が増えたら fail」

[Warning] S4 の exemption が「理由 30 文字以上」だけでは形骸化しやすいです。理由の長さではなく、`risk`, `owner`, `remediation_todo`, `expires_or_revisit_condition` を必須にした方が実務上維持できます。

**実現可能性**

[Warning] S1 の新順序表から `SubstituteBindings` が消えている点が危険です。`api.project-in-org` が `{project}` の implicit binding 済みモデルに依存するなら、実行順は少なくとも以下として明記すべきです。

```text
Authenticate
→ Throttle
→ SubstituteBindings
→ ResolveApiActor
→ api.project-in-org
→ api-key.ability:*
→ IdempotentRequest
```

修正提案: 設計文の順序表を Laravel の実実行順に合わせ、Architecture テストも `gatherMiddleware()` の宣言順ではなく priority 適用後の middleware stack を検査すると明記してください。

[Warning] S2 は方向性として妥当ですが、`project.in-current-org` が `{project}` 不在 route で完全 no-op であることを Feature / Architecture テストで固定する必要があります。業務 group 全体に先頭配置するため、将来 `{project}` 以外の route へ副作用が出ると課金ゲートの到達性を壊します。

[Warning] S5 の Laravel `TrustProxies` fallback 理解は概ね妥当ですが、`config('trustedproxy.proxies')` を本当に参照するには、アプリが使っている TrustProxies 実装と設定キー名の実装確認が必要です。Laravel 本体 / fideloper 系 / 独自 middleware のどれかで設定キーがズレると、意図せず全 proxy 非信頼または設定無視になります。

修正提案: 詳細設計で `TrustProxies` の実クラス、参照 config key、`bootstrap/app.php` の `trustProxies()` 呼び出し有無、config cache 時の挙動をテスト対象に入れてください。

**期待効果の妥当性**

[Warning] S1/S2 の順序反転で閉じる象限の説明は概ね正しいです。ただし「閉じ残る象限なし」とは言えません。設計自身が `verified` / 2FA 強制 gate の残存を認めているため、主張は「今回対象の API ability / subscription / recent-auth 起因の oracle は閉じる」に限定すべきです。

修正提案: 期待効果表の「同型の穴の再発」は「exemption 登録済みの既知残存を除く」に修正してください。

**リスク**

[Critical] S4 の `ShortCircuits` / `Transparent` 分類だけでは、middleware の条件付き短絡を安全に扱い切れません。たとえば route / guard / request type / auth state によって短絡する middleware は分類が粗すぎると、過検出か見逃しのどちらかになります。

修正提案: 分類を最低限以下に分けるべきです。

- `AlwaysTransparent`
- `MayShortCircuitBeforeTenantGuard`
- `MayShortCircuitButRouteIndependent`
- `TenantGuard`
- `ExemptedWithReason`

さらに「route parameter に依存せず同一応答になる short-circuit」は oracle になりにくいので、単純な `ShortCircuits` 一括禁止より、`parameter-sensitive tenant guard より前に route-dependent または auth-state-dependent short-circuit を置かない` という不変条件に寄せる方が維持可能です。

[Warning] S6 の `RedirectToHttps` を global middleware の最後へ移す設計は妥当ですが、`HandleInertiaRequests` や route middleware より前に走るという理解は確認が必要です。global append は global stack の末尾であり、route middleware より前という前提を Feature テストで固定してください。

**スコープの適切さ**

[Warning] Low 群やメール通知をスコープ外にする判断は妥当です。一方で `verified` / 2FA 強制 gate は High-1 と同型の既知残存なので、単なるスコープ外ではなく「既知リスクとして今回の完了条件から除外する理由」をもっと厳格に書く必要があります。

修正提案: 「別 TODO」とするだけでなく、今回の PR で TODO 登録まで完了条件に含めてください。

**型安全性**

[Suggestion] S7 は DTO/JsonResource には直接関係しませんが、`SecurityEventType` enum と subscriber map で表現する方針は PHPStan level 10 と相性が良いです。Architecture テストで enum case の記録経路を走査する案も妥当です。

[Warning] `TrustedProxiesConfigValidator` は env 文字列の parse 結果を `list<non-empty-string>` 相当に正規化し、`'*'`, `'**'`, `'REMOTE_ADDR'`, 空白混入、空要素を明示的に弾く設計にしてください。ここを `array<string>` 程度で曖昧にすると PHPStan だけ通って実行時設定ミスを逃します。

結論として、S1/S2/S3/S5/S7/S8 の方向性は承認可能です。差し戻し理由は主に S4 の再発防止テスト設計と、`verified` / 2FA 強制 gate 残存の扱いです。ここを「既知違反の凍結」ではなく「期限付き・対象固定・増分禁止・TODO 化」まで引き上げれば、APPROVED にかなり近い設計です。