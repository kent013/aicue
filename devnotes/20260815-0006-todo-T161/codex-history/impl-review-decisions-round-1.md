# 対応マトリクス: impl-review Round 1

全件受け入れ（反論なし）。うち 2 件は実バグだった。

## [Warning] `away-navigation-failed` の順序が固定されていない (bfcache-trial.ts)
- 判断: **対応する**
- 根拠: 詳細設計の「実装時の確認事項」に
  `trial-started < away-navigation-started < away-navigation-failed` の順序固定を
  挙げておきながら、実装は `.some(type === "away-navigation-failed")` で済ませていた。
  「離脱を試していないのに離脱失敗が記録されている」列を根拠にしてしまう。
- 対応内容: `hasOrderedAwayFailure()` を追加し、
  `trial-started` → `away-navigation-started` → `away-navigation-failed` の
  sequence 順が成立する場合のみ `invalid-wrong-route` を導出するようにした。
  テストに #9-b（failed が away より前）/ #9-c（away 無しの failed）を追加。

## [Warning] 軸2終端後の `guard-state-changed` で `failed-transition` に崩れる (bfcache-trial.ts)
- 判断: **対応する（実バグ）**
- 根拠: `states.length > 3 → failed-transition` としていたため、
  再ログイン後に A を開き直した fresh load の guard 遷移が積まれると
  終端済みの判定が崩れる。**失効セッション経路では手順上ほぼ必ず起きる**。
  設計の「軸 2 終端後に fresh load のイベントが追記されても崩れない」に違反していた。
  既存テストは終端後に `page-show` しか足しておらず、この経路を踏んでいなかった。
- 対応内容: 軸2の走査を**最初の終端で閉じる**形に変更した
  （guard 状態を 3 つ集めた時点、または秘匿維持のまま `page-hide` した時点で打ち切る）。
  テストに #9-b / #9-c / #9-d / #14 を追加し、
  `authenticated-unhidden` / `hidden-then-left` / `retry-hidden` の各終端後に
  guard イベントが積まれても崩れないことを固定した。

## [Warning] `value in ALLOWED_KEYS` が prototype 由来キーで例外化しうる (bfcache-trial.ts)
- 判断: **対応する（実バグ）**
- 根拠: `"toString" in ALLOWED_KEYS` は真になり、`ALLOWED_KEYS["toString"]` が
  関数になるため後段の spread が例外を投げる。validator は
  「壊れた入力を安全に弾く」のが仕事なので、例外化は契約違反。
- 対応内容: `Object.hasOwn(ALLOWED_KEYS, value)` に変更。
  `toString` / `constructor` / `hasOwnProperty` を type に入れても
  例外化せず `null` を返すテストを追加した。

## [Warning] `hasStoredPayload()` が sessionStorage.length を見ている (BfcacheTrial.svelte)
- 判断: **対応する**
- 根拠: Inertia など別キーがあるだけで「証跡が壊れていた」と誤表示する。
- 対応内容: `getItem(TRIAL_STORAGE_KEY) !== null` に変更。

## [Warning] 離脱リンクで保存失敗しても遷移が進む (BfcacheTrial.svelte)
- 判断: **対応する**
- 根拠: 証跡ツールとして正しくない。記録できないまま離脱すると
  証跡に穴が空いたまま A が bfcache に入る。
- 対応内容: `record()` を `boolean` 返却にし、`leaveToAway()` で失敗時に
  `preventDefault()` して遷移を止めるようにした。

## [Suggestion] `navigator.clipboard.writeText` の存在確認 (BfcacheTrial.svelte)
- 判断: **対応する**
- 対応内容: `typeof navigator.clipboard?.writeText !== "function"` を先に確認し、
  使えない環境では「画面を撮影してください」と案内するようにした。

## [Warning] 真理値表に対するテスト不足 (bfcache-trial.test.ts)
- 判断: **対応する**
- 対応内容: 上記に加えて `verifiedOsVersion` の負のコントロール
  （最大長超過 / 許可外文字）を追加した。テストは 75 → 84 件。
- 補足: Codex が「軸2 #14 `pending → null` が未固定」と指摘したが、
  これは既存の #7「verifying を経ずに null」として存在していた（番号のずれ）。
  紛らわしいので終端後ケースを #14 として追加し、番号の重複を解消した。

## [Warning] 「実ユーザー情報を渡さない」という表現が不正確 (route gate test / controller)
- 判断: **対応する**
- 根拠: 指摘が正しい。Inertia 共有 props の `auth.user` は**載る**し、
  載らなければ guard が作動せず検証が成立しない。テスト名が誤読を招いていた。
- 対応内容:
  - テスト名を「controller 固有 props を渡さない」に限定
  - **`auth.user` が載ることを正のコントロールとして固定するテストを追加**
    （guard の作動条件そのものなので、欠けたら検証ページが観測対象を失う）
  - controller の docblock も同様に表現を狭めた
