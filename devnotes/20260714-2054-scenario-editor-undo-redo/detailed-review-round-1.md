**全体判定: CHANGES_REQUESTED**

以下、施策ごとに判定します。合意済み方針は尊重しつつ、詳細実装で破綻しうる点だけを挙げます。

**施策1 `scenario-history.ts`**  
判定: **APPROVE**
- [Suggestion] `boundHistory` の「in-place」契約は良いです。呼び出し側で再代入しない運用をテスト名/コメントでも明示すると保守性が上がります。
- [Suggestion] `MAX_HISTORY_CHARS` は十分実用的。将来チューニング用に `boundHistory(stack, entries, chars)` を使うテストを厚めにしておくのは妥当です。

**施策2 `scenario-history.test.ts`**  
判定: **APPROVE**
- [Suggestion] `boundHistory` の複合条件（件数超過かつ文字数超過）を1ケース追加すると、while条件の回帰検出力が上がります。
- [Suggestion] `pushHistory` の「before/current 同一で false」を redoクリア責務と合わせて明記する観点は適切です。

**施策3 `ScenarioEditor.svelte`**  
判定: **REQUEST_CHANGES**
- [Critical] `{#each steps as step (step)}` の key がオブジェクト参照のままだと、`undo/redo` で `steps = restored` 時に全行再生成され、フォーカス喪失だけでなく局所状態（入力中 IME 文脈/selection）の予期せぬリセットを過度に誘発します。  
  **修正案:** key を安定識別子へ変更（例: `step.id ?? \`new-\${index}\`` 相当。新規行の一時キーは生成時に `clientKey` を持たせるのが最善）。
- [Warning] `parseHistory` 内で `step as DraftPoint` / `points as DraftPoint[]` のアサーションが残っており、「unknown→guard→正規化」を徹底しきれていません。strict方針と少しズレます。  
  **修正案:** `isSerializedRow` を `value is SerializedRow` の type predicate 化し、`type SerializedRow = Omit<DraftPoint,"id"> & {id:number|null}` を導入してキャストを除去。
- [Warning] `onEditorFocusOut` が「編集フィールド外へのフォーカス移動」以外でも走るため、複合UI操作で想定より早く `flushPendingEdit` される余地があります。  
  **修正案:** `FocusEvent.relatedTarget` を見て、同一編集セッション内移動（編集可能要素→編集可能要素）では flush しない条件を追加。
- [Suggestion] `doUndo/doRedo` で履歴破損時に `resetHistory + toast` は妥当。加えて監視性のため `console.warn` 相当（テストで抑止可能）を入れると調査が容易です。

**施策4 `ScenarioEditor.test.ts`**  
判定: **REQUEST_CHANGES**
- [Warning] `parseHistory` fail-safe の実挙動テストを「通常不到達」で省略すると、防御コードの回帰を検知できません。  
  **修正案:** `serializeSteps` 返値を一時的に壊すモック、または履歴スタック注入可能なテストフック（テスト限定）を用意し、`steps 非破壊 + 履歴リセット + warning toast` を1件検証。
- [Warning] Svelte 5 runes の配列mutation追跡を前提にしているため、`push/splice/swap` 後に `dirty` と `canUndo/canRedo` が即時反映されることを明示テストした方が安全です。  
  **修正案:** 操作直後のボタン活性・dirtyインジケータを各1ケース追加。
- [Suggestion] `Ctrl+Z` と `Cmd+Z` の両方を検証し、mac系回帰を防ぐとより堅いです。

**補足評価（観点 5/6/7/8）**
- 既存保存系（409/419/dirty/beforeunload）との整合は概ね良好。`reseed()` で `resetHistory()` を呼ぶ判断は妥当。
- フロント限定の波及範囲も適切。PHP/API不変前提を守れています。
- Atomic/DS/Lucide 制約は満たしています。
- `runSettled` / `commitStructural` / `flushPendingEdit` の意味論は概ね整合。ただし `focusout` flush 条件の粒度は上記修正推奨。

以上より、現時点の最終判定は **CHANGES_REQUESTED** です。