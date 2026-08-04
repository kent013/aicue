全体判定: **CHANGES_REQUESTED**

## 1. 使命との整合性

[Suggestion] T089-a の受容、popstate プローブの却下、T090-b の回復案内追加は使命と整合している。撮影中断を避けつつ、利用者が quota 超過から回復できる設計になった。

## 2. 禁止事項違反

[Suggestion] テスト方針、Architecture テスト追加、Plan Factory を作らない判断はいずれも禁止事項に抵触しない。AGENTS.md への変更を実装契機の追記だけに限定する反論も妥当。

## 3. 実現可能性

[Critical] `AuthenticationException` の render callback で `Inertia::clearHistory()` を呼び、`null` を返して通常の `/login` リダイレクトへ委ねる案では、原則としてフラグをログイン画面へ引き継げない。

`Inertia::clearHistory()` が設定するのは、そのリクエストで生成される Inertia response の `clearHistory` 属性である。認証失敗リクエストの応答は通常の redirect であり、`/login` の Inertia response はリダイレクト後の別リクエストになる。F6 の「着地が Inertia だから確実に消費される」は、このリクエスト境界を考慮していない。

修正提案: 詳細設計前に次のいずれかへ変更すること。

- 認証失敗時に session へ one-shot marker を flashし、次の Inertia response を生成する middleware で marker を消費して `Inertia::clearHistory()` を呼ぶ。
- `AuthenticationException` に対して、既定処理へ素通しせず、同一リクエストで `clearHistory: true` を含む Inertia login responseを返す。ただし URL、ステータス、非Inertiaアクセスとの整合確認が必要。
- Laravel adapter にリダイレクトを跨ぐ公式の永続化機構が実在するなら、その根拠とライフサイクルを設計へ明記する。

テストは「最終 `/login` payload」だけでなく、最初の認証失敗応答を自動追従しない状態からリダイレクト境界を再現する必要がある。

## 4. 期待効果の妥当性

[Warning] 上記が解決されない限り、T089-b の履歴鍵破棄は発生せず、主要な期待効果を満たさない。

修正提案: 採用方式について「認証失敗リクエスト → redirect → `/login` リクエスト → clearHistory 消費」の状態遷移を明記し、Feature/Browserテストで境界ごと固定する。

## 5. リスク

[Suggestion] Filament 認証失敗を安全側の偽陽性として許容する判断は妥当。ただし one-shot marker方式を採る場合、Filament利用後まで marker が残るため、次のInertia応答で消費されることを意図した仕様としてテストすべき。

## 6. スコープの適切さ

[Suggestion] `/billing` の表示、既存flash文言、Architectureテストに限定したRound 1対応は適切。Dashboard追加や構造化flashを作らない判断も妥当。

## 7. 型安全性

[Suggestion] DTOとTypeScript shapeの同期、enum全caseテスト、PHPStan level 10の方針に問題はない。one-shot markerを導入する場合も、文字列キーの散在を避けて専用middlewareまたは定数へ閉じ込めるべき。