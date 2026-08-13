# 対応マトリクス: design-review Round 2

全件受け入れ（反論なし）。Critical 2 件はいずれも Round 1 対応の際に私が作り込んだ欠陥。

## [Critical] terminal window 確定後に lifecycle 記録を止めると軸 2 が壊れる（施策1・施策3）
- 判断: **対応する**
- 根拠: 指摘が完全に正しい。失効セッション経路の時系列は

  ```
  A → B 離脱 : page-hide          ← 軸 1 の往路
  A を bfcache 復元 : page-show    ← ここで軸 1 の窓が確定
  pending → verifying
  location.replace('/login')
  page-hide                        ← 軸 2 の「秘匿維持のまま離脱」の観測
  ```

  であり、窓確定直後に自動追記を止めると**最後の `page-hide` が記録されない**。
  `unauthenticated-redirected` / `hidden-then-left` の導出根拠がまるごと消える。
  Round 1 の汚染対策を作る際に、軸 2 の観測範囲を考慮し忘れていた。
- 対応内容: **2 つの概念を分離**した。
  - **軸 1 window**: 最初に成立した `started < away-started < hide < show` の窓。
    **軸 1 はこの窓しか参照しない**（後続イベントを無視する）
  - **観測終了 (observation end)**: 軸 2 が終端するまで lifecycle と guard 状態を記録し続ける。
    終端条件は `authenticated-unhidden` / `retry-hidden` /
    秘匿維持状態での復元後 `page-hide` / `trial-aborted` のいずれか
  - 保存側で後続 lifecycle を捨てるのをやめ、**導出側で軸ごとに観測範囲を固定**する
    （証跡としても完全になる。Codex の修正案どおり）

## [Warning] 軸 2 の `page-hide` が往路と復元後リダイレクトで区別されていない
- 判断: **対応する**
- 根拠: 現行規則だと A→B の往路 `page-hide` を軸 2 の終端として誤って拾いうる。
- 対応内容: 軸 2 が参照する `page-hide` を
  **軸 1 window の `page-show.sequence` より後に発生したものに限定**した。
  施策 5 に「往路の hide を軸 2 の redirect hide として使わない」テストを追加。

## [Warning] derive 関数の複数 `trialId` 契約がシグネチャから決まらない
- 判断: **対応する（推奨案を採用）**
- 根拠: `deriveTrialVerdict(events)` に対象 trialId が渡らないので
  「対象以外を無視する」の対象を一意に選べない。Round 1 の対応が中途半端だった。
- 対応内容: **derive 関数は単一 trialId の配列だけを受ける**契約にし、
  複数 ID が混入していたら `inconsistent` を返す。
  `loadTrials()` が既に分離を担うので、こちらが単純（Codex の推奨）。

## [Warning] `typeof crypto?.randomUUID` は feature detection として安全でない
- 判断: **対応する**
- 対応内容: 検出は `globalThis.crypto?.randomUUID` で行い、
  **呼び出しは receiver を失わないよう `globalThis.crypto.randomUUID()`** と書く。

## [Warning]「進行中試行」の定義が不足
- 判断: **対応する**
- 根拠: 軸 1 確定済み・軸 2 未終端・手動確認待ち・完了 を区別しないと
  listener の追記可否を決められない。
- 対応内容: **保存する status を増やさず、純粋導出関数で判定**する
  （保存値の stale 化を避ける。Codex の修正案どおり）。

  `collecting-axis1` / `collecting-axis2` / `awaiting-manual-confirmation` /
  `complete` / `aborted`

  この導出状態をもとに listener の追記可否を決める。

## [Critical] 施策5: terminal window のテストに復元後 `page-hide` が無い
- 判断: **対応する**
- 対応内容: 施策 5 の真理値表に以下を追加。
  - 往路 hide → 復元 show → pending → verifying → **復元後 hide** で `hidden-then-left`
  - 同列に `redirect-observed` を足すと `unauthenticated-redirected`
  - **往路 hide を軸 2 の redirect hide として採用しない**
  - 窓確定後の復元後 hide が保存されても軸 1 は `valid-bfcache` を維持
  - 軸 2 終端後の fresh load イベントが追加されても両軸の判定が崩れない

## [Warning] 施策5: 軸 1 真理値表が `away-navigation-started` を含んでいない
- 判断: **対応する**
- 対応内容: 全行を `started → away-started → hide → show` に統一し、
  `away-started` を意図的に欠落させるケースは別の負のテストとして期待値を明示した。

## [Warning] `away-started` 直後の時間差を `invalid-wrong-route` と誤判定しうる
- 判断: **対応する**
- 根拠: リンク押下と `pagehide` の間には正常な時間差がある。
  その瞬間に再描画すると正常な遷移を失敗として表示してしまう。
- 対応内容: `invalid-wrong-route` を
  **明示的な `away-navigation-failed` イベントからのみ導出**する形に変更した。
  押下後の次タスクで `document.visibilityState !== "hidden"` の場合に限り
  当該イベントを追記する。単に「`away-started` があり hide がまだ無い」状態は
  `incomplete`（安全側）。

## [Warning] 施策6: 「`beforeunload` があれば必ず bfcache 対象外」は断定が強い
- 判断: **対応する**
- 根拠: ブラウザ横断で断定できる話ではない。本設計全体が
  「ブラウザ挙動を出典なく断定しない」立場を取っているので、ここも揃える。
- 対応内容: 表現を「**対象外になる、または適格性を不安定にするため禁止**」に改めた。

## APPROVE された施策
施策 2 / 施策 4 / 施策 6 / 施策 7（施策 6 は上記の表現修正のみ）
