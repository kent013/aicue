## 全体判定

**CHANGES_REQUESTED**

施策2の「無条件実行」は妥当ですが、JSON 204 経路を保護できるという説明には重要な不足があります。施策4も監視方法を少し強化する必要があります。

## 施策1: History暗号化

判定: **APPROVE**

- [Critical] なし
- [Warning] なし
- [Suggestion] なし

グローバル適用、経路A/B/Cの責務分離、暗号化の正のコントロールはいずれも妥当です。

## 施策2: LogoutResponse

判定: **REQUEST_CHANGES**

- [Critical] JSON 204 応答では、`Inertia::clearHistory()` はサーバセッションへフラグを積むだけで、クライアントの `sessionStorage` 鍵をその場では削除しません。

  したがって次の順序ではF-4-01が残ります。

  1. Inertia認証画面で `fetch('/logout', { Accept: 'application/json' })`
  2. 204を受け取るが画面遷移しない
  3. 次のInertia応答を受ける前にブラウザバック
  4. 鍵が残っているため旧履歴を復号・swapできる

  修正案: 無条件の `Inertia::clearHistory()` は維持して構いません。ただしJSON logoutを実アプリ経路として許容するなら、204後に必ずInertiaページへ遷移する、またはクライアント側で直ちにhistory鍵を破棄する契約が必要です。テスト専用ヘルパなら、経路Cの保証対象外であり経路Bの再現補助に限定されることを明記してください。

- [Warning] 「`X-Inertia` 分岐を入れるとセキュリティホール」という反論自体は成立しますが、「無条件実行だけでJSON logoutも保護できる」とは言えません。

  修正案: docblockを「無条件実行は必要条件。ただし204では次のInertia応答までクライアント鍵は残る」に修正してください。

- [Suggestion] JSON logout後のFeatureテストは「遅延フラグが消費される」契約としては有効ですが、直後のpopstate安全性を証明するテストではありません。

## 施策3: Featureテスト

判定: **REQUEST_CHANGES**

- [Warning] `Inertia::getVersion()` は環境によって `null` になり得ます。`withHeaders()` にそのまま渡す前提は不安定です。

  修正案: versionが文字列の場合だけ `X-Inertia-Version` を付与してください。サーバ側versionが`null`ならヘッダ不要です。

- [Suggestion] Laravelテストクライアントは302を自動追従していません。手動GETは「実ブラウザと同じ最終リクエスト」を検証しますが、「XHRが302を追従すること」自体はBrowserテスト側の責務、とコメントすると正確です。

- [Suggestion] JSON logout後のテスト名は「次のInertia応答にclearHistoryが載る」に限定すべきです。「JSON logout経路の履歴復元が安全」と誤読させない名称にしてください。

## 施策4: Browserテスト

判定: **REQUEST_CHANGES**

- [Warning] 現在のMutationObserverは、PIIを追加して同一タスク内で削除した場合、callback実行時の`document.body.innerText`には残っておらず取り逃す可能性があります。

  修正案: callbackで現在DOMだけでなく、各`MutationRecord`の`addedNodes[].textContent`も検査してください。文字列置換も扱うなら`characterDataOldValue: true`を指定し、`oldValue`も確認します。

- [Warning] MutationObserverが証明するのは「通常DOMにPII文字列が出現しなかったこと」であり、厳密な「paintされなかったこと」ではありません。

  修正案: コメントを「途中のDOM出現を検出」に修正してください。本件のPIIがSvelteの通常テキストDOMで描画される前提なら、実用上は十分強い検証です。

- [Warning] 実装前はArrayBuffer正のコントロールで先に失敗するため、同じ実行で「併せて`__piiSeen === true`」までは確認できません。

  修正案: red確認を「暗号化不成立でfail」と「一時的に正のコントロールを外してPII復元を確認」の2段階に分けるか、再現済みbug-hunt結果を根拠として後者を省略してください。

## 施策5: 責務コメント

判定: **APPROVE**

- [Critical] なし
- [Warning] なし

経路BとCの重複・競合の説明は妥当です。

## 施策6: 文書更新

判定: **REQUEST_CHANGES**

- [Warning] JSON 204 logoutを含めて「ログアウトを実行したタブ」を一律保証すると、施策2の即時反映されない経路まで包含します。

  修正案: 経路Cの保証条件を「`clearHistory: true`を含むInertia応答を受信したタブ」に厳密化するか、すべてのlogout UIがその応答を必ず受ける契約を明記してください。