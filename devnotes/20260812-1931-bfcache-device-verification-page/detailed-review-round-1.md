**全体判定: CHANGES_REQUESTED**

設計の方向性は妥当です。debug 限定、既存 `LocalOnly` + `auth` + 本物の bfcache guard を使う方針は AGENTS.md の制約と整合しています。ただし、証跡が後から壊れる可能性と、観測イベントからの推論が強すぎる箇所があり、このまま実装すると検証ページ自体の信頼性が落ちます。

**施策 1: 観測ライブラリ**
判定: REQUEST_CHANGES

[Warning] `guard-state-changed のみ観測 = invalid-wrong-route` は推論が強すぎます。guard の属性変化だけでは「A が再表示されたが lifecycle が無い」とは断定できません。開始直後や遅延した初回 guard でも同じ形になり得ます。  
修正案: `invalid-wrong-route` をこの条件から外し、`page-hide` / `page-show` が無い場合は原則 `incomplete` に倒す。どうしても wrong-route を判定したいなら、明示的な `away-navigation-started` などのイベントを追加して、その後に lifecycle が無い場合だけ判定してください。

[Warning] `sequence` の採番が reload 後に壊れるリスクがあります。sessionStorage から復元した進行中 trial に追記するなら、常に `max(sequence) + 1` で採番する必要があります。  
修正案: `nextSequence(events, trialId)` を純粋関数として追加し、テスト対象にしてください。

[Warning] `StorageFailedEvent.reason` に長さ制限がありません。debug 限定でも sessionStorage / コピー用証跡に載る文字列なので上限が必要です。  
修正案: 最大 120〜200 文字程度に制限し、validator でも固定してください。

**施策 2: route + controller**
判定: APPROVE

大筋で問題ありません。`LocalOnly` の内側、かつ `auth` 配下に置く設計は妥当です。props を渡さない Inertia ページにする判断も、debug ページへ余計なユーザー情報を流さない点で良いです。

[Suggestion] route テストでは middleware だけでなく、200 応答時の Inertia component が `Debug/BfcacheTrial` / `Debug/BfcacheTrialAway` であることも固定すると、controller の取り違えを検出できます。

**施策 3: 検証ページ A**
判定: REQUEST_CHANGES

[Warning] expired-session 後の手動確認フローで、証跡が追加観測により汚染される可能性があります。`/login` 到達後に再ログインして A を開き、`redirect-observed` を押す前に、新しい document の `pageshow(false)` や guard 変化が同じ trial に追記されると、軸 1 が `invalid-not-bfcache` / `inconsistent` へ崩れます。  
修正案: 最初の terminal な復帰観測後は lifecycle 自動追記を停止し、`redirect-observed` の手動追記だけ許可する状態にしてください。あるいは derive 側で「最初の有効な `trial-started < page-hide < page-show` 窓」以降の通常 reload を無視する規則を明文化してください。

[Warning] module scope で `crypto.randomUUID()` を直接評価すると、SSR やテスト import 時に壊れる可能性があります。  
修正案: `onMount` 内で `typeof crypto?.randomUUID === 'function'` を確認して初期化してください。bfcache 復元では document が保持されるため、onMount 初期化でも token の目的は満たせます。

[Warning] `navigator.standalone` は TypeScript 標準型に存在しません。  
修正案: `interface NavigatorWithStandalone extends Navigator { standalone?: boolean }` のように型を切り、`any` に逃がさず `boolean | null` に正規化してください。

[Warning] UI 設計に DESIGN.md / FormField atom の使用方針が明記されていません。  
修正案: 入力欄、ボタン、状態表示は既存 DS token と atom/molecule を使うこと、hex / 独自 radius / SVG 直書きを増やさないことを設計に追記してください。

**施策 4: 相方ページ B**
判定: APPROVE

責務を薄く保つ方針は妥当です。B で観測しない、logout 導線を新設しない、A へのリンクを復帰手段と誤認させない、という整理も既存制約に合っています。

[Suggestion] 「手動確認後に再ログインして A の stored report から `/login` 到達を記録する」手順を B または docs 側に明記してください。

**施策 5: vitest**
判定: REQUEST_CHANGES

[Warning] 現在の真理値表では、後続 reload / 再ログイン後の追記が trial verdict を壊さないことを検証できません。  
修正案: `valid-bfcache` 観測後に `page-show(false, token不一致)` が追加されても、設計上の terminal window を維持する、または明示的に不整合にする、どちらかを決めてテストしてください。

[Warning] `sequence` 再採番、複数 trialId 混入、同一 storage 内の trial grouping のテストが不足しています。  
修正案: `loadTrials()` が trialId ごとに分離すること、derive 関数に mixed trial events を渡した場合の扱いを固定してください。

**施策 6: architecture テスト**
判定: REQUEST_CHANGES

[Warning] unload 禁止テストの対象を debug ページだけに留めると、`AppLayout` に `beforeunload` が入った場合に検証条件が壊れます。これは debug 都合ではなく、認証済み画面全体の bfcache 契約に関わります。  
修正案: 未解決事項 1 は **(a)** を採用し、少なくとも `AppLayout` と bfcache guard 周辺も対象に含めてください。

[Warning] route gate は「LocalOnly グループ内」を構造的に完全証明するより、実効条件を固定する方が堅いです。  
修正案: middleware assertion に加え、非 local 404、DEBUG_LOGIN_* 未設定 404、guest redirect、auth + Basic 200 + no-store を正の/負のコントロールとして維持してください。

**施策 7: ドキュメント**
判定: APPROVE

T085 をクローズしない、実機確認未実施の記述を残す、2 経路 PASS を完了条件にする、という整理は妥当です。

[Suggestion] `unauthenticated-redirected` は自動判定ではなく manual confirmation を含むことを、TODO と supported-browsers の両方で同じ表現にしてください。

**未解決事項への判断**
1. `AppLayout` の unload 検出: **(a)** を推奨。これは debug 制約ではなく bfcache 保証の前提です。  
2. 2 経路の試行セット識別子: **(b)** でよいです。まずは devnotes 上の対応付けで十分です。  
3. `appendEvent` の read-back validation: **(a)** を推奨。証跡ツールなので、多少遅くても毎回 read-back して破損を即検出する方がよいです。