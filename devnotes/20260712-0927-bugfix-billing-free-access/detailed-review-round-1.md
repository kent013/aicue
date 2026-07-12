全体として、**問題設定→原因仮説→設計反映→テスト固定**の筋が通っており、F-07 の本質（「未契約=遮断」という誤ったゲート意味論）に正面から対応できています。  
ただし、実装前に潰すべき重要点があるため判定は `CHANGES_REQUESTED` です。

**施策別判定**
- 施策1 `BillingAccess` 書き換え: `APPROVE`
- 施策2 理由明示（flash + 402）: `REQUEST_CHANGES`
- 施策3 `has_billing_access` リネーム: `APPROVE`
- 施策4 `plan_code` 不変条件の明文化: `APPROVE`
- 施策5 テスト更新・追加: `REQUEST_CHANGES`

**指摘事項**

- [Critical] `expectsJson()` 判定だけでは API クライアント種別を取りこぼす可能性  
  - 背景: Laravel では `expectsJson()` は XHR/Accept 等の複合判定で、`fetch`/一部クライアントやヘッダ条件によっては意図どおり JSON 扱いにならないケースがあります。課金ゲートの挙動分岐はセキュリティ境界に近いので曖昧性を残すべきでないです。  
  - 修正案: `if ($request->expectsJson() || $request->wantsJson())` へ統一、またはプロジェクト標準の JSON 判定ヘルパに寄せる。テストに「`Accept: application/json` だが XHR ではない」ケースを1本追加して 402 を固定。

- [Critical] `tests/Pest.php` の `createOrganizationWithOwner` 既定変更は横断影響が大きく、設計書内の「影響なし」前提が強すぎる  
  - 背景: 既存テストが「暗黙に subscription 行の存在」を前提にしている可能性があり、`subscribed:` 文字列検索だけでは検出不能です（引数省略呼び出しが多数あり得る）。  
  - 修正案:  
    1) 互換性維持のため当面は `createOrganizationWithOwner()` を変更せず、`createFreeOrganizationWithOwner()` を新設（または既存維持＋明示的 free ヘルパ追加）。  
    2) その上で F-07 関連テストのみ free ヘルパへ切替。  
    3) 将来の一括移行は別PRで実施。  
    ※「後方互換の並走を残さない」原則は理解しますが、ここはテスト基盤変更の blast radius が広く、段階移行の方が安全です。

- [Warning] 施策2の文言統一方針は良いが、`abort(402, message)` の body 形式がクライアント期待と一致するか不明  
  - 背景: Inertia/SPA 側で 402 をどの形式で受けるか（HTML error page / JSON）は呼び出し経路依存。  
  - 修正案: API系エンドポイントでは `HttpException` の message に依存せず、既存のエラーレスポンス規約（DTO/Resource 例外規約）があるならそちらへ揃える。最低でも Feature テストで `response->json('message')` 固定可否を確認。

- [Warning] `has_billing_access` へのリネームは妥当だが、Inertia payload 契約変更として破壊的  
  - 修正案: `tests/Feature/DashboardTest.php` と `tests/js/pages/Dashboard.test.ts` だけでなく、`resources/js` 全体の参照探索（`rg has_active_subscription`）結果を設計受け入れ条件に明記。API 契約変更履歴（devnotes）に明記。

- [Suggestion] `BillingAccess::hasActiveAccess` は命名が新意味論とズレる  
  - 提案: 今回は互換維持で据え置き可。ただし将来 `hasBillingAccess` へメソッド名も寄せる TODO を残すと可読性が上がります（クラス名・DTO名と整合）。

- [Suggestion] plan_code 不変条件のテストを1本だけ追加すると設計の寿命が伸びる  
  - 提案: `PlanSeeder` 由来の前提（free は Stripe price を持たない）を Feature/Architecture テストで固定。将来プラン追加時の破壊を早期検知できます。

**レビュー観点別サマリ**
- 正確性: 主要ロジックは妥当。`plan_code null => free許可` は原因に直結。
- 既存整合性: Laravel/Cashierの使い方は自然。  
- PHPStan Lv10: 設計上は問題少。array-shape 更新を忘れなければ通る見込み。  
- テスト網羅: 方向性は良いが、テスト基盤ヘルパ変更の戦略を再設計すべき。  
- DTO/JsonResource: 方針順守。  
- Inertia/API 使い分け: 概ね適切。JSON判定条件の強化が必要。  
- 副作用/後退: 最大リスクは `tests/Pest.php` 既定変更。  
- 波及変更: 施策3は網羅的。さらに全体検索条件を受け入れ基準化すると堅い。  
- セキュリティ: AGENTS不変条件との衝突は見当たらない。  
- DESIGN/Atomic: 今回変更範囲では大きな懸念なし。

**全体判定**
- `CHANGES_REQUESTED`  
  - 必須対応: 上記 Critical 2点（JSON判定の堅牢化、テストヘルパ変更戦略の見直し）を設計に反映してください。  
  - これが反映されれば、再レビューで `APPROVED` 可能です。