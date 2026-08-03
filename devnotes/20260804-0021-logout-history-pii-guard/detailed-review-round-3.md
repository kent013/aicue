## 全体判定

**CHANGES_REQUESTED**

暗号化・clearHistory・MutationObserverの中核設計は成立しています。残る主問題は、「ログアウトUIがInertia経路一本」という安全条件がコメントだけで、機械的に固定されていない点です。

## 施策1

判定: **APPROVE**

- [Critical] なし
- [Warning] なし

## 施策2

判定: **REQUEST_CHANGES**

- [Warning] 保証条件の「応答を受信したタブ」は、実装よりわずかに広い表現です。ネットワーク受信後、`page.set()` より前に処理が中断されれば鍵は消えません。

  修正案: 「`clearHistory: true` を含むInertia pageを**クライアントが適用したタブ**」としてください。実装上の境界は受信ではなく、`page.set()` 冒頭の`history.clear()`完了です。

- [Suggestion] docblockの「302をXHRが追従して必ず受け取る」も「正常完了時に適用する」とすると、通信断やJS例外まで保証する誤読を避けられます。

## 施策3

判定: **REQUEST_CHANGES**

- [Warning] サーバ応答からversionを取得する方向は正しいですが、`page.version`は`null`になり得ます。その場合、`X-Inertia-Version => null`を渡すのは実ブラウザの挙動と一致しません。

  修正案: `version`が文字列ならヘッダを付け、`null`なら`X-Inertia-Version`自体を省略してください。あるいはテスト環境でversion必須が契約なら、`expect($version)->toBeString()`で明示的に落とします。

- [Suggestion] 最初の`/dashboard`応答とlogout着地の間でasset versionが変われば409になりますが、単一Featureテスト中には通常変化しないため問題ありません。

## 施策4

判定: **REQUEST_CHANGES**

- [Warning] MutationObserverの強化内容は、通常DOM・同一document・Svelteテキストノードという本件の範囲では十分です。ただし`body`自体が置換された場合、旧`body`に付いたobserverでは検出できません。

  修正案: `document.body`ではなく`document.documentElement`を監視対象にすると、`body`置換も捕捉できます。初期・現在判定も`document.documentElement.textContent`基準に揃えると「DOM出現」という説明と一致します。

- [Warning] 正のコントロールを一時的にコメントアウトするred手順は、戻し忘れを生む手作業です。

  修正案: 「F-4-01再現テスト」と「暗号化成立の正のコントロール」を別テストに分けてください。実装前は前者がPII復元でfail、後者が平文stateでfailし、実装後は双方greenになります。

## 施策5

判定: **APPROVE**

- [Critical] なし
- [Warning] なし

## 施策6

判定: **REQUEST_CHANGES**

- [Critical] 経路Cの安全性は「ログアウトUIがInertia visit一本」に依存しますが、その不変条件がdocblockと文書だけです。将来JSON 204のlogout UIを追加しても、現在のFeature/Browserテストはgreenのままです。これは「不変条件はArchitecture/Featureテストへ登録」という規約を満たしません。

  修正案: frontend内の`/logout`呼び出しをdeny-by-defaultで走査し、許可された単一ハンドラだけをinventory登録するArchitectureテストを追加してください。より堅くするならlogout処理を専用モジュールへ一本化し、コンポーネントからの直接`router.post`・`fetch`を禁止します。

- [Warning] 文書中の保証条件も「受信」から「Inertia clientがpageを適用」に変更してください。

MutationObserverとversion取得の設計方向は正しく、上記の機械的な不変条件を追加すれば承認可能です。