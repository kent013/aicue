了解しました。  
ご指定どおり**コマンド実行・書き込みは行わず**、提示テキストのみで詳細設計レビューを実施します。

---

**全体判定**  
- **CHANGES_REQUESTED**

---

**施策1: ルート追加 + IDOR inventory 登録**  
- **判定: APPROVE**
- [Suggestion] `PUT /scenario` を web ルートに置く判断は妥当。`scopeBindings` + org guard + policy の三層で、AGENTS.md の「認可前404」原則に整合。

---

**施策2: Scenario ドメイン型（enum / DTO / 例外 / Resource）**  
- **判定: REQUEST_CHANGES**
- [Warning] `ScenarioPointData::id` が `int` 固定だと、フロント仕様（未保存行 `id: null`）と往復で齟齬が出ます。  
  **修正案:** 出力DTOは「保存後レスポンス専用」に寄せるならTS側を `id: number` に統一。edit propsでも未保存行はフロント内部状態のみで保持し、サーバDTOは常に `int` に統一。  
- [Warning] `ScenarioConflictResource::CODE` 定数宣言が `public const string CODE` になっており、PHP構文上不正（型付きclass const不可）。  
  **修正案:** `public const CODE = 'scenario_conflict';` に修正。  
- [Suggestion] `ScenarioDocumentData::fromManual()` は `whereNull/where` の繰り返しで O(n^2) になり得るため、`groupBy('parent_cut_id')` で1回整形にすると安全。

---

**施策3: UpdateScenarioRequest**  
- **判定: REQUEST_CHANGES**
- [Critical] `steps.*.points` に `present` を課しつつ `steps.*` を `array` のみとしているため、`points` 欠落時のエラーパスが分かりづらく、クライアント修正性が下がる。  
  **修正案:** `steps.*.points` に加えて `steps.*` に `required_array_keys:points` を追加（Laravel 12で利用可なら）し、欠落を明示。  
- [Warning] `narration/subtitle_secondary` を nullable受け入れ→Serviceで `''` 正規化は妥当だが、RequestとServiceで責務が分散。  
  **修正案:** `prepareForValidation()` で `null`→`''` 正規化し、DTOは非null文字列で統一（PHPStanと実装一貫性が上がる）。  
- [Suggestion] protectedキー拒否に `type/sort_order` を追加している方針は良い。`video_manual_id` 等トップレベル禁止も明示されており整合的。

---

**施策4: ScenarioService::save()**  
- **判定: REQUEST_CHANGES**
- [Critical] `scenario_version` を「実変更なしでも常に+1」とする仕様は、競合頻度を不要に増やしUXを悪化させる可能性大。概念上の“no-op save”で競合を誘発。  
  **修正案:** `changed===true` のときのみ version++。もし現仕様を維持するなら、設計根拠を `doc/10 §10.8-2` と明示的に一致させ、クライアント自動再読込戦略までセットで規定。  
- [Warning] 既存cut取得が `$locked->cuts()->get()->keyBy('id')` のみだと、`assertPayloadIds` 内で型不一致判定を丁寧に実装しないとすり抜け余地。  
  **修正案:** 既存集合を `stepsById` / `pointsById` に事前分離し、payload位置と厳密照合。  
- [Warning] `whereIn()->get()->each->delete()` はイベント目的で妥当だが、大量時にメモリ増。  
  **修正案:** chunk delete（例: `chunkById`）を検討。ただし上限有界なら現状許容。  
- [Suggestion] 共有不変条件（同一Tx + lockForUpdate）を明文化している点は非常に良い。後続経路追加時のArchitectureテスト昇格方針も妥当。

---

**施策5: Controller**  
- **判定: APPROVE**
- [Suggestion] `ManualScenarioController::update()` で `ScenarioResource` 返却は DTO/Resource 原則に適合。`response()->json()` 禁止にも準拠。  
- [Suggestion] `edit` props に `manual.status` と `scenario` 追加は、409表示・初期描画の一貫性に有効。

---

**施策6: フロントエンド**  
- **判定: REQUEST_CHANGES**
- [Critical] `save()` 内で 401/419（セッション期限・CSRF失効）分岐が未定義。実運用で「保存失敗しました」汎用文言化すると復旧導線不足。  
  **修正案:** 401/419を個別ハンドリングし、再認証モーダルまたはリロード導線を表示。  
- [Warning] dirty判定の `JSON比較` はフィールド順や不要キー混入で誤判定リスク。  
  **修正案:** 正規化関数（キー順固定・不要キー除去）経由比較、もしくは操作時にdirtyフラグを厳密更新。  
- [Warning] 「ボタンdisabled禁止」は遵守しているが、連打抑止が `saving=true` 依存のみだと多重送信レースが残る。  
  **修正案:** `requestId` 管理で最新レスポンスのみ反映、または保存中再押下時は即return。  
- [Suggestion] `router.reload({ only: ["scenario", "manual"] })` は妥当。破棄確認付きでデータ喪失配慮も良い。

---

**施策7: テスト計画**  
- **判定: REQUEST_CHANGES**
- [Critical] 「テストファースト」を掲げる一方、Service境界テストが cross-project 2件のみでは不足。`assertPayloadIds` の中核（重複/異物/階層不一致）をService単体で直接叩く設計が必要。  
  **修正案:** `ScenarioService` 直テストに、`id重複422`・`type不一致422`・`pointをstepへ移設422` を追加。  
- [Warning] Feature #9（no-opでもversion+1）は仕様として論争点。テスト固定前に仕様確定が必要。  
  **修正案:** 施策4のversion方針を先に確定し、期待値を一本化。  
- [Suggestion] 409レスポンスの `code` 厳密一致テストを追加すると、クライアント判別契約が強化される。

---

**施策8: ドキュメント**  
- **判定: APPROVE**
- [Suggestion] divergence理由と不変条件・drift防止テストまで紐づけており、将来保守性が高い。良い整理です。

---

**確定仕様整合チェック（指定ドキュメント）**  
- **§10.8-5 protectedキー不信:** 概ね整合（ネスト missing 明示は適切）。  
- **§10.8-6 レンダ中禁止:** rendering/analyzing 409設計は整合。  
- **§10.8-2 楽観ロック409:** 基本整合。ただし「no-opでもversion+1」は仕様解釈の明文化が必要。  
- **§9.4 document単位保存:** 設計全体は整合。  
- **概念設計APPROVEDとの差分:** `draft→ready` は妥当な詳細化だが、状態遷移表への追記前提で固定すべき。

---

**最終コメント（要修正優先順）**  
- まず **(1) class const構文不正**、**(2) version更新方針の仕様確定**、**(3) 401/419復旧導線**、**(4) Service境界テスト拡張** を解消してください。  
- 上記解消後は、設計の骨格（セキュリティ不変条件・DTO/Resource・認可前404）は非常に良く、再レビューで **APPROVED** 可能性が高いです。