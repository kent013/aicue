## S4 一時停止・再開

**判定: REQUEST_CHANGES**

- [Warning] 操作種別だけでは、古いtimeoutと新しい**同種操作**を識別できません。また古いcallbackが先に `pauseResumeTimeout = null` とすると、新しいtimeoutのhandleを失います。

  **修正案**: 操作ごとに世代IDまたは一意tokenを発行し、`op + token` が現在値と一致する場合だけpendingとhandleを解除してください。timeout callback内でも一致確認より先にhandleをnull化しないようにします。

```ts
let operationGeneration = 0;
let pendingOperation: {
    op: PauseResumeOperation;
    generation: number;
} | null = null;
```

イベントには識別子がないため、MediaRecorderイベントの順序保証を前提とする旨もコメントで明記してください。

## S7 テスト

**判定: REQUEST_CHANGES**

- [Warning] 「古いpause timeout」と「新しいpause要求」の同種交差ケースを追加し、新しいpendingとtimeout handleが維持されることを検証してください。

他の施策に残るCritical / Warningはありません。

## 全体判定

**CHANGES_REQUESTED**

操作種別による交差防御は改善されています。残件は同種操作を区別する世代管理と、古いcallbackによる新しいtimeout handle喪失の防止です。