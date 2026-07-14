## 施策1
**判定: REQUEST_CHANGES**

- [Warning] `onDownloaded` の契約が二重です。責務欄ではコールバック、インターフェースでは戻り値によるreloadとなっています。  
  修正案: コールバックを設けず、`run(): Promise<{ changed; hasPendingAck }>` に統一し、`onDownloaded` の記述を削除してください。
- [Suggestion] `maxRetries` が「初回を含む最大試行回数」か「初回＋再試行回数」かを明記し、呼び出し回数テストで固定してください。
- [Suggestion] `Content-Length` は非負の10進整数かつ `Number.isSafeInteger()` の場合だけ検査対象にすると堅牢です。

## 施策2
**判定: APPROVE**

- `changed` のみをreload条件にする設計、SSR境界、インスタンス安定性の説明はいずれも妥当です。

## 施策3
**判定: APPROVE**

- body fallback、状態分離、墓石掃除、部分成功、union網羅性まで十分にカバーされています。

## 施策4
**判定: REQUEST_CHANGES**

- [Warning] `auto-download` 全体をstub化すると、`running` ガードは存在しないため「online連打で`run`呼び出しが過剰化しない」は結線テストでは保証できません。  
  修正案: 結線テストではonlineごとに起動要求されることだけを検証し、多重実行抑止は施策3の実クラス単体テストで保証してください。Show側にも独立ガードを置くなら、その場合のみ結線テスト対象にできます。

## 施策5
**判定: APPROVE**

## 施策6
**判定: APPROVE**

- `Expose-Headers` と未公開時のdegrade条件が明確になっています。

## 全体判定
**CHANGES_REQUESTED**

3件のCriticalはすべて解消されています。残件は、`onDownloaded`契約の一本化と、online連打テストの責務修正という2件のWarningです。いずれも設計文面の整理で解消でき、実装方針そのものに重大な問題はありません。