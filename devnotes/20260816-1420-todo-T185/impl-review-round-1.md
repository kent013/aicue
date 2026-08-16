仮説: この差分の主な破綻点は、D&D の座標計算よりも「遅延実行時に index が別物を指すこと」と「実機前提の受け入れ条件が満たされている証拠」の 2 点に出る。そこを中心に見ました。

**resources/js/components/features/manual/ScenarioEditor.svelte**
- [Critical] `runSettled()` で遅延される並べ替えが `stepIndex` / `from` / `to` の数値 index に依存しており、複数の構造操作が IME 変換中に queue されると別の手順・急所へ適用され得ます。特に `movePointTo()` は実行時に `steps[stepIndex]` を取り直しているため、先に queue された手順入れ替えが compositionend で実行された後、ドラッグ元とは別の手順の急所を並べ替える可能性があります。`clientKey` または `id` など安定キーを snapshot して、実行時にそのキーで対象を再解決する形にすべきです。
- [Warning] `dragOwner` による controller またぎの排他は、同時進行中の手順/急所ドラッグ取り違えには効いています。ただし上記の「IME 遅延 queue 後の index ずれ」は別問題なので、現行テストの負のコントロールでは塞げていません。

**resources/js/components/features/capture/TakeStrip.svelte**
- [Suggestion] 実装自体は設計どおりサーバ権威を維持しており、楽観更新もありません。`run()` の `Promise<boolean>` 化も既存呼び出しを壊す形には見えません。
- [Suggestion] 成功時に `onChanged()` が呼ばれることはサーバ権威の要なので、D&D 成功ケースでも明示 assert があると回帰防止として強くなります。

**resources/js/lib/dnd/list-reorder.ts**
- [OK] 挿入 index と最終 index の分離は妥当です。`moveItem()` も `undefined` 要素を値で存在判定しない実装になっており、off-by-one の中核はよく固定されています。

**resources/js/lib/dnd/pointer-drag.ts**
- [OK] `finish(commit, notify)` は pointerup / pointercancel / Escape / destroy の出口として機能しており、pointer capture と rAF の解放順も妥当です。`destroy()` で `onCancel` を呼ばない契約もテストされています。
- [Suggestion] `setPointerCapture()` が例外を投げる環境まで守るなら try/catch できますが、通常の pointerdown 経路では必須修正ではありません。

**resources/js/components/atoms/DragHandle.svelte / DragHandle.types.ts**
- [OK] Button と分けた atom 化、Lucide 使用、`disabled` 不使用、`rounded-sm`、shadow/gradient/scale 不使用はいずれも設計・DESIGN.md に合っています。
- [Suggestion] ボタン要素で Enter/Space が実質 no-op になるため、aria-label は「上下キー」で操作することが分かる現状で最低限成立しています。将来、より厳密にするなら `aria-describedby` などで補助できます。

**tests/js/components/features/manual/ScenarioEditor.test.ts**
- [Warning] IME 中の D&D は 1 件だけ検証されていますが、複数の pending 構造操作が queue されたときの index ずれを検出できません。Critical の再現テストを追加してください。
- [OK] 多点入力と controller またぎ排他のテストは意味があります。ユーザー提示の負のコントロールも有効です。

**tests/js/components/features/capture/TakeStrip.test.ts**
- [Suggestion] 端操作の busy 検証が常に `take-adopt-10` を見ているため、末尾テイク操作時の対象行を直接見ていません。`testId` に応じた対象 take を見ると空振りしにくくなります。

**tests/js/lib/dnd/*.test.ts / tests/js/setup.ts / tests/js/support/pointer-capture.ts**
- [Warning] 変更テスト内に `as DOMRect` / `as HTMLInputElement` など素の型アサーションがあります。今回のレビュー観点では「素の型アサーション不使用」が明示されているため、`new DOMRect(...)` や typed helper へ寄せるのが安全です。
- [OK] pointer capture スタブと `withoutPointerCapture()` は、capture 有無で結果が変わらない設計の分岐確認として妥当です。

**devnotes/20260816-1021-drag-and-drop-reordering/ios-acceptance.md**
- [Critical] 詳細設計の A3 は iOS Safari 実機確認記録を完了条件にしていますが、提示された差分・検証結果にはその記録がありません。自動テストで代替しない方針なので、記録なしでは完了扱いにできません。

CHANGES_REQUESTED