## 施策1: 履歴 util

判定: **REQUEST_CHANGES**

- [Warning] `parseHistorySnapshot` は `clientKey` の型だけを検証し、step間・point間の重複を許します。破損履歴に重複キーがあると、Svelte keyed each の更新が破綻する可能性があります。  
  **修正案:** 復元対象全体で `clientKey` の一意性を検証し、重複時は `null` を返してください。空文字も拒否するのが安全です。

## 施策2: util 単体テスト

判定: **REQUEST_CHANGES**

- [Warning] 上記に対応し、step同士・point同士・stepとpoint間の `clientKey` 重複、および空文字を拒否するテストを追加してください。

## 施策3b: Draft型への clientKey 追加

判定: **APPROVE**

- `clientKey` を作業コピーだけに持たせ、payloadから除外する境界は妥当です。
- keyed each と履歴のround-tripにも整合しています。

## 施策3: ScenarioEditor

判定: **REQUEST_CHANGES**

- [Critical] 現行初期化では `toDraftSteps()` を2回呼んでいます。

```ts
let steps = $state(toDraftSteps(scenario.steps));
let snapshot = $state(serializeSteps(toDraftSteps(scenario.steps)));
```

`clientKey` 導入後はそれぞれ異なるキーが採番されるため、**初期表示直後から `dirty === true`** になります。離脱警告や保存済み表示も後退します。  
  **修正案:** 作業コピーを一度だけ生成し、同じ値からsnapshotを作成してください。

```ts
const initialSteps = toDraftSteps(scenario.steps);
let steps = $state<DraftStep[]>(initialSteps);
let snapshot = $state(serializeSteps(initialSteps));
```

- [Warning] `payloadSteps` を変更しない方針は正しいですが、型変更後の最重要セキュリティ境界です。  
  **修正案:** 保存リクエストに `clientKey` がstep/point双方で含まれないことを明示テストへ登録してください。
- [Suggestion] `clientKeySeq` は「モジュール内」ではなく通常のinstance scriptならコンポーネントインスタンスごとの状態です。コメントを「インスタンス内カウンタ」に直すと正確です。
- `relatedTarget` を使わない反論は妥当です。フィールド単位の履歴粒度が明確で、no-op抑止もあるため変更不要です。
- `restoreFrom` と破損時のfail-safe構成は改善されています。

## 施策4: ScenarioEditorテスト

判定: **REQUEST_CHANGES**

- [Critical] 初期表示直後にdirty表示がなく、Undo/Redoが無効であるテストを追加してください。上記の二重採番を直接検出できます。  
  **修正案:** render直後に `scenario-dirty-indicator` が存在せず、Undo/Redoがdisabledであることを検証します。
- [Warning] 保存payloadにstep/pointの `clientKey` が存在しないことを検証してください。
- [Warning] `vi.mock` はhoistされ、同一ファイル内の他テストへ影響しやすいです。  
  **修正案:** 実exportを保持するpartial mockと、各テスト後のmock復元を設計へ明記してください。

## 全体判定

**CHANGES_REQUESTED**

Round 1の主要指摘は適切に解消されていますが、`clientKey` 導入に伴う初期snapshotの二重採番はdirty/beforeunloadを即座に壊すため、実装前の修正が必要です。