レビュー仮説: 「遅延される構造操作が、発火時の index ではなく `clientKey` を捕捉し、実行時に再解決してから `commitStructural` する」形なら取り違えは閉じる。設計本文の実装方針自体はおおむね妥当ですが、テスト計画に未検出の枝があります。

**施策別判定**
| 施策 | 判定 |
|---|---|
| 1. `addPoint` を親手順の安定キーで解決 | APPROVE |
| 2. `removePoint` を親手順+急所の安定キーで解決 | REQUEST_CHANGES |
| 3. `removeStep` と削除確認ダイアログを安定キー化 | APPROVE |
| 4. 回帰テスト追加 | REQUEST_CHANGES |
| 5. 負のコントロール実測 | REQUEST_CHANGES |

**指摘**
[Warning] `removePoint` の no-op 分岐がテストで直接固定されていません。  
設計では [ScenarioEditor.svelte](/workspace/resources/js/components/features/manual/ScenarioEditor.svelte:233) の `removePoint(stepKey, pointKey)` で「親がない」「急所がない」場合に何もしない方針ですが、新規 6 件は「並べ替え後に正しい急所を消す」は見る一方、対象急所が既に消えているケースを見ません。ここが壊れると `findIndex === -1` から `splice(-1, 1)` で末尾の急所を誤削除する実装でも通り得ます。  
修正案: 追加で少なくとも次のどちらか、できれば両方を入れてください。

- IME 中に同じ `point-0-0-remove` を 2 回押す → `compositionEnd` 後、A は `急所A-2` だけ残る → Undo 1 回で `急所A-1`,`急所A-2` が戻る
- IME 中に A 手順削除 → A の急所削除 → B の急所追加 → `compositionEnd` 後、B の既存急所は消えず 3 件になる

[Warning] `runSettled` 呼び出し棚卸しが設計書上の手作業確認に留まっています。  
本件の不変条件は「遅延 queue に積まれる全構造操作」が対象なので、将来 `runSettled` 呼び出しが増えたときにレビュー記憶だけでは漏れます。  
修正案: JS architecture 系の軽いテストで `ScenarioEditor.svelte` の `runSettled` 呼び出し inventory を pin してください。最低限、呼び出し数と許可された関数名一覧が変わったら赤にするだけでも、次の構造操作追加時にこの設計へ戻れます。

[Suggestion] 実装コメントは方針説明としては正しいですが、本文どおりの長さをそのまま Svelte に入れるとやや重いです。既存の `moveStepTo` / `movePointTo` 程度に圧縮し、詳細な経緯は devnotes 側へ寄せる方が保守しやすいです。

**観点別補足**
PHP / DTO / JsonResource / Inertia Props / 認可について「該当なし」とする判断は妥当です。`payloadSteps()`、route、Resource、DTO に触れない前提なら、`clientKey` のサーバ混入リスクは既存の payload テストで守る整理で足ります。

DESIGN.md / Atomic Design も、新規 UI・アイコン・token 追加なしなので実質影響なしです。`disabled` を増やさない方針も規約に合っています。

**全体判定: CHANGES_REQUESTED**

実装方針は通せますが、`removePoint` の解決失敗 no-op と `runSettled` inventory の検出力を設計に足してから承認が妥当です。