Critical なし。  
重点修正（`reloadScenario` の `onSuccess` で `reseed()` 実行＋`reloading` フラグ）で、前回 Warning の「409後に stale `$state` が残って無限競合」問題は解消できています。

- [Warning] `resources/js/components/features/manual/ScenarioEditor.svelte`（`isScenarioDocument`）
  - 問題: type guard が `scenario_version` と `steps` 配列性しか見ておらず、`steps` 要素の shape 不正でも通る。
  - 根拠: 置換後の編集・保存で `rowOf(step)` 前提が崩れると runtime 例外や不正 payload 化の余地が残る（特に reload 経路は外部応答依存）。
  - 修正案: `steps.every(...)` で最低限 `id:number` / `scene:string` / `points:Array` まで検証する軽量ガードに拡張（point も同様）。防御的パース方針と整合。

- [Suggestion] `resources/js/components/features/manual/ScenarioEditor.svelte`（`reloadScenario`）
  - 問題: `router.reload` の `onError` 未使用。ネットワーク系失敗時は `onSuccess` 非到達で `genericError` が出ず、ユーザーが無反応に見える可能性。
  - 根拠: 現状は `onFinish` で `reloading` を戻すのみ。
  - 修正案: `onError` で「最新取得に失敗」系メッセージを `genericError` に設定。

- [Suggestion] `tests/js/components/features/manual/ScenarioEditor.test.ts`
  - 問題: 今回追加2件は狙い通り有効だが、`router.on("before")` で `reloading=true` 時に confirm をスキップする回帰テストが未追加。
  - 根拠: 今回の二重確認防止の本質ロジックを直接固定できていない。
  - 修正案: `window.confirm` spy＋`router.on` コールバック発火で、`reloadScenario` 実行中は confirm 未呼び出しを1本追加。

全体評価:
- 楽観ロック成立性: `expected_version`/409/reseed の流れは実装・テストとも一貫。
- データ喪失/二重確認/レース: 重大な破綻は見当たりません。
- 禁止事項・セキュリティ不変条件: diff上で明確な抵触なし（保護キー拒否、404/422/409の境界も適切）。  
このままでもマージ可能水準です。