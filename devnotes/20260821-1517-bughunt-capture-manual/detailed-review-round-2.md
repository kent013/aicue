## 各施策の判定

### 施策1: F-1-03 adopt の保護キー入口防御

**判定: APPROVE**

実行順序の根拠が route 宣言順から `bootstrap/app.php` の実効 priority list に修正され、404 → 422 → 403 の主張は妥当です。FormRequest は route middleware と scoped binding の完了後に解決されるため、adopt への導入によって cross-org/cross-cut が422へ化ける構造にはなっていません。

テストも次を固定できています。

- 正しくネストされた認可済み操作では保護キーが422
- cross-cut/cross-org は保護キー混入時も404
- 同一組織内の非 project member はFormRequestがGateより先に422
- バリデーション失敗時に副作用なし
- 保護キー集合の増加をdatasetで追従

[Suggestion] datasetへ渡すpayloadは、将来 `MassAssignmentProtectedKeys::all()` にドット記法のキーが追加される可能性を考慮し、Laravelが解釈する入力構造と一致させてください。現状すべてトップレベルキーなら、その不変条件をテスト名またはコメントで明示すれば十分です。

---

### 施策2: create のファイル選択名表示

**判定: APPROVE**

空選択、再選択、未選択を含むテストが追加され、低リスクな変更として十分です。token使用、Atomic Design上の配置にも問題ありません。

[Suggestion] 成功後も同一画面に残る送信経路や `form.reset()` が存在する場合は、`selectedFileName` も同時に消去してください。成功時に必ず別画面へ遷移するなら追加対応は不要です。

---

### 施策3: show の登録済みSOP現況表示

**判定: APPROVE**

Round 1の主要な問題は解消されています。

- `ofMany(['created_at' => 'max', 'id' => 'max'])` による決定的な最新行
- `hasDocument` と `document` を同じrelation結果から生成
- `created_at` を空文字へ握り潰さずAssertで不変条件化
- Inertia propsにはDTOの配列を渡し、JsonResourceを不必要に挟まない
- 組織境界、manual境界、直接アクセス404を別々に検証
- Svelte既定エスケープによるファイル名の安全な表示
- PHP/TS契約の対応

[Suggestion] 変更ファイル一覧には、propsを変更しない方針となった `SourceDocumentUpload.svelte` が残っています。実際に変更しないなら一覧から外してください。

[Suggestion] 日時の「ロケール整形」は、既存utilityがない場合でもlocale/timezoneの契約を実装前に決めてください。SSRを利用する構成では、Nodeとブラウザの既定timezone差によるhydration差分を避ける必要があります。

[Suggestion] `Assert::notNull()` 後にPHPStanが日時型まで確定できない場合は型を緩めず、モデルの日時property型または `Assert::isInstanceOf(..., CarbonInterface::class)` で正確に絞り込んでください。

---

### 施策4: Phase A 発生源の再現・分類

**判定: REQUEST_CHANGES**

3分岐への修正と、最終network responseを証拠の正本にした判断は妥当です。ただし、Vitest回帰テストに空振りの余地が残っています。

[Warning] `router.on` をspy化するだけでは、mockされた `router.reload`、form helper、`<Link>` が実際に`before`イベントを発火する保証がありません。通常フローでイベントが0件でも「許可されないdestinationがない」としてgreenになり得ます。

修正案:

- mock routerのすべてのvisit入口が共通のbefore-event emitterを通るようにする
- 通常フローで、少なくとも期待する現URLへのreloadイベントが1件観測されたことをassertする
- 禁止destinationを合成入力として流し、判定器が確実に検出する負例を置く
- `<Link>`をmockする場合も、クリックが同じemitterへ到達する契約を固定する

これにより「違反ゼロ」と「母集団ゼロ」を区別できます。

---

### 施策5: Phase B 条件付き是正

**判定: REQUEST_CHANGES**

「発火元除去だけで十分なら包括ガードを作らない」という判断は適切です。URL正規化、origin一致、method正規化、single-use intent、保証範囲の限定も大幅に改善されています。

残る問題は次の3点です。

[Warning] `new URL()` の失敗時の扱いが未定義です。before handler内で例外になると、許可リスト方式なのにnavigationを止められない可能性があります。

修正案:

- URL解析を `try/catch` し、解析不能はin-appではないものとして拒否する
- `canonicalize()` も例外を外へ漏らさず、失敗を判別可能な戻り値にする
- malformed URL、異常scheme、dot-segment正規化のテストを追加する

[Warning] `visitExplicitly()` は「`router.visit()` が返る前にbefore eventが同期発火する」ことへ依存しています。単純なmockだけでは、導入済みInertia版の実契約を固定できません。

修正案:

- 可能なら外向き明示遷移を通常のnative anchorにし、トークン機構自体を不要にする
- wrapperを残す場合は、beforeが同期発火してintentを消費した後に`router.visit()`が戻る契約をテストする
- mockは実際のイベント順を再現し、非同期発火させた場合に誤許可しないことも確認する

[Warning] リスク欄に「認証失効等は `/app/` 外への正規遷移として通す」と残っており、本文の限定契約と再び矛盾しています。

修正案として、次のように限定してください。

> サーバ応答後の認証失効に伴うハードビジットはガード対象外であり妨げない。client-side programmatic visitは、認証失効を推測して一般許可せず、明示intentに登録した経路だけを許可する。

[Suggestion] 状態保証表はヘッダー行が二重になっているため整理してください。

## 全体判定

**CHANGES_REQUESTED**

施策1〜3は実装着手可能です。施策4はbefore-eventテストの空振り防止、施策5はURL解析失敗時のdeny動作・同期イベント依存・認証失効記述の整合を直せば、全体をAPPROVEDにできます。Critical相当の問題は残っていません。