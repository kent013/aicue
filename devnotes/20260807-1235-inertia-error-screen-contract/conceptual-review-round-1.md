全体判定: **CHANGES_REQUESTED**

設計方針は使命に沿っており、respond callback の単一スロット問題まで踏まえている点は妥当です。ただし、実装前に固定すべき契約が数点不足しています。

## 1. 使命との整合性

[Suggestion]  
419/404/429/403/500 を Inertia 面で「閉じ込めモーダル」ではなく画面遷移として扱う方針は North Star に直接貢献します。特に撮影 PWA のセッション切れ 419 は現場作業者の詰みになりやすく、改善対象として妥当です。

## 2. 禁止事項違反

[Warning]  
`Error.svelte` の CTA を disabled 化しないことを設計に明記してください。  
禁止事項 8 により、「戻る先がない」「認証状態が不明」などを理由にボタンを無効化する UI は不可です。

修正提案:  
`ErrorScreenData` は常に少なくとも 1 つの固定 destination を持つ契約にし、Feature または JS test で props から CTA が常に描画されることを固定してください。

## 3. 実現可能性

[Warning]  
`Error.svelte` が lazy chunk になる場合、500/503 時に追加 JS chunk の取得失敗でエラー画面自体が描画できない可能性があります。非 Inertia フルロードでは Blade が最後の砦ですが、Inertia XHR では新規 Error ページ chunk が必要になる構成だと「今日より悪くならない」が崩れます。

修正提案:  
`resources/js/app.*` の page resolver 方針を確認し、`Error.svelte` が初期 bundle に含まれる、または少なくとも chunk fetch 失敗時のフォールバック方針を設計に追加してください。テストでは難しいため、設計上の明示契約が必要です。

[Warning]  
「respond callback が 1 本しか無いこと」の gate は、単純な `$exceptions->respond(` 検出だけでは不十分です。設計内でも触れている通り、`Inertia::handleExceptionsUsing()` や `respondUsing()` が同じ単一スロットを奪います。

修正提案:  
Architecture test の検出対象に少なくとも以下を含めてください。

- `$exceptions->respond(`
- `respondUsing(`
- `Inertia::handleExceptionsUsing(`

その上で、許可される登録箇所を `bootstrap/app.php` の既存 1 箇所だけに固定するのがよいです。

## 4. 期待効果の妥当性

[Suggestion]  
期待効果は合理的です。ただし「429 の待ち時間が表示される」は、`Retry-After` が存在する場合に限るため、文言は「表示可能になる」程度に弱めるのが正確です。

## 5. リスク

[Warning]  
`Location` ヘッダ素通しを P4 に置く方針は妥当ですが、409 version mismatch と `Inertia::location()` の両方を守るなら、Feature test は status 409 だけでなく `X-Inertia-Location` ありの応答が差し替えられないことを直接固定すべきです。

修正提案:  
テストケースを分けてください。

- `409 + X-Inertia-Location` はそのまま
- `409` は目録未登録によりそのまま
- `302/303 + Location` は P1 でそのまま
- `4xx/5xx + Location` が仮に来ても P4 でそのまま

[Warning]  
`TenantBoundaryPrecedenceTest` の body 正規化は妥当ですが、正規化対象を「入力 id の echo」だけに限定する契約が重要です。ここが緩いと存在オラクル検査が空洞化します。

修正提案:  
正規化 helper はテストローカルに置き、置換対象をリクエスト URL 内の該当 id 文字列に限定してください。置換 0 件 fail に加え、正規化後も status/header/body が完全一致することを維持する方針でよいです。

## 6. スコープの適切さ

[Suggestion]  
裁定 (a) と (c) を混ぜない判断は適切です。今回の変更は「Inertia XHR の画面遷移契約」に閉じており、過大ではありません。

[Warning]  
ただし `ApiExceptionRenderer::rateLimitDetails()` の変更は (c) 側の既存挙動に触れます。「実挙動は変わらない」とするなら、API 429 の `Retry-After` が整数・不正文字列・未設定の場合の回帰テストを追加してください。

修正提案:  
共有 SoT 化と同じ PR に、API 封筒 JSON の retry-after contract test を入れるべきです。

## 7. 型安全性

[Warning]  
DTO 方針はよいですが、Inertia props へ DTO を渡す経路を明確にしてください。DTO を作るだけで、最終的に配列を手書きすると型安全性の恩恵が薄くなります。

修正提案:  
`ErrorScreenData` に `toInertiaProps(): array` 相当の明示変換を持たせるか、既存 DTO/Resource の作法に合わせて「props 生成の唯一の入口」を固定してください。PHPStan level 10 前提なら、array shape の phpdoc も必要です。

---

結論として、設計の方向性は承認可能に近いです。実装前に **Error.svelte の chunk/fallback 契約**、**respond 単一スロット gate の検出範囲**、**Retry-After の API 回帰テスト** を設計へ追記すれば APPROVED にできます。