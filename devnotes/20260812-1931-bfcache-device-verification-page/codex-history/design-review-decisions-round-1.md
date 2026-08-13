# 対応マトリクス: design-review Round 1

全件受け入れ（反論なし）。未解決事項 3 件も Codex の推奨どおり決定した。

## [Warning] 施策1: `invalid-wrong-route` の推論が強すぎる
- 判断: **対応する（提示された 2 案のうち「明示イベント追加」を採用）**
- 根拠: 指摘のとおり、guard 属性の変化だけでは「A が再表示されたが lifecycle が無い」とは
  断定できない。開始直後や遅延した初回 guard でも同じ形になる。
  ただし単に `incomplete` へ倒すだけだと、**plain anchor が何かに intercept されて
  full navigation にならなかった**という実際に起こりうる失敗を検出できなくなる。
  これは設計の中核前提（離脱は full document navigation）が崩れる事象なので、検出したい。
- 対応内容: `AwayNavigationStarted` イベントを追加した（離脱リンク押下時に同期記録）。
  `invalid-wrong-route` は「`away-navigation-started` は記録されたのに
  対応する `page-hide` が無い」場合に限定する。
  guard 属性変化のみを根拠にした判定は削除し、原則 `incomplete` に倒す。

## [Warning] 施策1: `sequence` 採番が reload 後に壊れる
- 判断: **対応する**
- 対応内容: `nextSequence(events, trialId): number` を純粋関数として追加し、
  常に `max(sequence) + 1` で採番する。施策 5 のテスト対象に含めた。

## [Warning] 施策1: `StorageFailedEvent.reason` に長さ制限が無い
- 判断: **対応する**
- 対応内容: 最大 200 文字に制限し、validator でも固定する。
  手入力値と同じ表に制約を明記した。

## [Suggestion] 施策2: route テストで Inertia component 名も固定せよ
- 判断: **対応する**
- 対応内容: 施策 6 のテスト項目に
  「200 応答の Inertia component が `Debug/BfcacheTrial` / `Debug/BfcacheTrialAway` であること」を追加。

## [Warning] 施策3: 手動確認フローで証跡が後続観測に汚染される
- 判断: **対応する（本レビューで最も重要な指摘）**
- 根拠: 指摘が正しい。`/login` 到達後に再ログインして A を開くと、
  新しい document の `page-show(persisted=false, token 不一致)` や guard 変化が
  同じ trial に追記され、**軸 1 が `valid-bfcache` から `invalid-not-bfcache` /
  `inconsistent` へ後から崩れる**。
  しかもこれは失効セッション経路では**必ず起きる**（手順上、再ログインして
  stored report を開くことが前提のため）。設計として破綻していた。
- 対応内容:
  - **terminal window** の概念を導入した。最初に成立した
    `trial-started < away-navigation-started < page-hide < page-show` の窓を
    その試行の判定対象として確定させる
  - 窓が確定した後は **lifecycle の自動追記を停止**する
    （記録するのは `redirect-observed` / `trial-aborted` の手動イベントのみ）
  - derive 側でも「窓確定後の `page-show` / `page-hide` は判定に用いない」規則を明文化した
    （保存側と導出側の二重防御。片方が漏れても壊れない）

## [Warning] 施策3: module scope の `crypto.randomUUID()` は SSR / テスト import で壊れる
- 判断: **対応する**
- 対応内容: `onMount` 内で `typeof crypto?.randomUUID === "function"` を確認して初期化する。
  bfcache 復元では component が再生成されないため `onMount` は再実行されず、
  **token の「Document 生存を示す」目的は onMount 初期化でも満たされる**
  （fresh load でのみ再生成される）。

## [Warning] 施策3: `navigator.standalone` は TypeScript 標準型に無い
- 判断: **対応する**
- 対応内容: `interface NavigatorWithStandalone extends Navigator { standalone?: boolean }` を定義し、
  `any` に逃がさず `boolean | null` に正規化する。

## [Warning] 施策3: DESIGN.md / atom の使用方針が未記載
- 判断: **対応する**
- 対応内容: 入力欄・ボタン・状態表示は既存 DS token と atoms/molecules を使い、
  hex 直書き・独自 radius・SVG 直書きを増やさないことを施策 3 に明記した。
  アイコンは Lucide を使う。

## [Warning] 施策5: terminal window / sequence / trial 分離のテストが不足
- 判断: **対応する**
- 対応内容: 施策 5 に以下を追加。
  - `valid-bfcache` 確定後に `page-show(false, token 不一致)` が追記されても
    判定が崩れないこと（terminal window の固定）
  - `nextSequence` が復元後も `max+1` を返すこと
  - `loadTrials()` が trialId ごとに分離すること
  - derive 関数に複数 trialId が混入したイベント列を渡した場合の扱い

## [Warning] 施策6: unload 禁止の対象を debug ページだけに留めるのは不十分
- 判断: **対応する（未解決事項 1 = (a) を採用）**
- 根拠: Codex の論拠が正しい。`AppLayout` に `beforeunload` が入ると
  検証ページ側をいくら縛っても検証条件が壊れる。
  そしてこれは **debug 都合ではなく、認証済み画面全体の bfcache 契約**である。
  guard が守ろうとしている経路 B そのものが無効化されるので、
  production コンポーネントを対象に含めるのは過剰ではない。
- 対応内容: 対象を `resources/js/pages/Debug/BfcacheTrial*.svelte` /
  `resources/js/lib/debug/` に加え、**`resources/js/components/templates/AppLayout.svelte` と
  `resources/js/lib/bfcache-guard.ts` / `resources/js/app.ts`** へ拡大した。
  テストの docblock に「これは debug 設備の都合ではなく経路 B の前提条件である」旨を書く。

## [Warning] 施策6: route gate は実効条件を固定する方が堅い
- 判断: **対応する（既定の方針を維持し明文化）**
- 対応内容: 構造の証明に寄りかからず、
  非 local 404 / `DEBUG_LOGIN_*` 未設定 404 / guest redirect /
  auth + Basic 200 + `no-store` を正負のコントロールとして維持することを明記した。

## [Suggestion] 施策7: manual confirmation の表現を TODO と supported-browsers で揃えよ
- 判断: **対応する**
- 対応内容: 両文書で同一の表現を使うことを施策 7 に明記した。

## [Suggestion] 施策4: 再ログイン後の手順を B か docs に明記せよ
- 判断: **対応する**
- 対応内容: B の表示内容に、失効セッション経路の残り手順
  （`/login` 到達を撮影 → `/debug/login` で入り直す → A の stored report で記録）を追加した。

## 未解決事項の決定

| # | 事項 | 決定 | 根拠 |
|---|---|---|---|
| 1 | `AppLayout` の unload 検出 | **(a) 対象に含める** | Codex 推奨。debug 制約ではなく bfcache 保証の前提 |
| 2 | 2 経路の試行セット識別子 | **(b) 持たない** | Codex 推奨。まずは devnotes 上の対応付けで足りる（思考原則 2） |
| 3 | `appendEvent` の read-back validation | **(a) 毎回行う** | Codex 推奨。証跡ツールなので破損の即時検出を優先する。イベント数は 1 試行あたり 10 件未満で性能上の問題は無い |
