仮説は「実装本体は詳細設計どおりだが、既存テスト置換による回帰検出力の低下がないか」です。実装経路、表示開始後の追随、認証境界を差分から確認しました。

### `app/Http/Responses/Fortify/ProfileUpdatedResponse.php`

指摘なし。

- `wasChanged('email')` により、氏名変更や同一メールを誤って認証画面へ送らない設計になっています。
- JSON 契約を先に処理しているため、`expectsJson` の 200・空 JSON 応答も維持されています。
- `verification.notice` への明示的 redirect であり、`redirect()->intended()` 禁止には抵触しません。
- 戻り値型、`instanceof User` による narrowing、null 安全性に問題は見当たりません。
- recent-auth、verified、認可の適用範囲にも変更はありません。

### `resources/js/components/features/billing/AutoRechargeCard.svelte`

指摘なし。

- `thresholdErrorText` と `maxErrorText` は threshold-first で排他的になり、原因でないフィールドを `aria-invalid` にしません。
- `FormField` の既存 `error` 経路を利用しており、Atomic Design上の責務逆流や共有 atom/molecule の不要な変更はありません。
- `inputErrorShown` による押下時提示と、その後の入力追随を維持しています。
- live region は常時DOMに存在し、本文のみ更新される設計です。`sr-only` と `aria-live="polite"` も設計に一致しています。
- hex、独自SVG、非DSスタイルの追加はありません。

### `tests/Feature/Auth/FortifyResponseTest.php`

指摘なし。

- メール変更、氏名のみ変更、JSON応答、通知、着地後のInertia flashまで主要分岐を検証しています。
- 特に着地画面を実際にGETして `flash.success` を確認しており、中間redirectでflashが失われる元バグを正しい理由で検出できます。

### `tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php`

指摘なし。

- staleセッションで変更が拒否され、再認証後の再送でのみ変更が成立することを確認しています。
- recent-auth境界を弱める変更ではなく、既存の409契約も維持しています。

### `tests/js/components/features/billing/AutoRechargeCard.test.ts`

[Warning] 表示開始後に「無効なままエラー理由だけが変化する」回帰テストが削除されています。

以前のテストは、maxを `0` から `5` に変えた際、入力が無効なまま文言が「範囲外」から「threshold以下」へ追随することを検証していました。新しいテストは各理由を個別に検証し、有効値への訂正でエラーが消えることも検証していますが、無効→別の無効という遷移は検証していません。

そのため、初回表示後にエラー文言だけを固定してしまう回帰が、現在のテスト群を通過する余地があります。これは設計書とコードコメントにある「提示開始後は理由が変われば文言も追随する」という契約、および「既存テストのカバレッジ喪失なし」の観点に反します。

maxを `0` で提示した後に `5` へ変更し、少なくとも以下を確認するテストを復元してください。

- max input の accessible description が「開始残高より大きい値」へ更新される
- 同じlive region要素の本文も同じ理由へ更新される
- maxだけが引き続き `aria-invalid="true"` である

## 全体判定

**CHANGES_REQUESTED**

実装本体は承認済み設計と整合しています。変更要求は、削除された「表示中のエラー理由変更」回帰検出力の復元のみです。