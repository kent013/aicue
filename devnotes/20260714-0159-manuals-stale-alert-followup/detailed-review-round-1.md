ありがとうございます。提示いただいた詳細設計は全体としてよく整理されており、**F-1-1 の根治方針（保存世代基準 + server 側抑制）**も妥当です。  
以下、施策ごとに判定します。

**施策1（migration 追加）**: **APPROVE**
- [Suggestion] `scenario_version_at_terminal` に index は不要判断で妥当（単純比較用途、参照は最新1件中心）。
- [Suggestion] 将来監査性を高めるならカラムコメントに「failed 時 snapshot」意図を明記すると運用で迷いにくい。

**施策2（failJob snapshot 書込み）**: **APPROVE**
- [Warning] `RenderJobService::failJob` で preview 失敗時も `VideoManual` lock 取得する変更は、並行負荷時の待ち増加要因になり得る。  
  修正案: 既存 lock 順序 `job -> manual` を明文化したまま、テストに「preview fail でも status 非変更」を加え、回帰を固定（設計内 7-B に明記済みなので実装時に必須化）。
- [Suggestion] Model PHPDoc 追加は必須。`$casts` 追加不要判断も妥当（int|null、DB型一致）。

**施策3（VideoManualService の display 解決）**: **APPROVE**
- [Critical] `latest('id')` 前提は「ID単調増加=最新」と一致しているが、もし将来バックフィル等で ID 順≠業務時系列になると判定が崩れる。  
  修正案: 設計注記で「本ドメインの最新定義は id 降順」を明示するか、`created_at` を最新基準に統一する方針を決める（どちらかに固定）。
- [Suggestion] `isStaleFailure` の共通化は良い。`snapshot null は隠さない` の保守方針も安全側。

**施策4（Controller 委譲）**: **APPROVE**
- [Suggestion] Inertia props shape 不変を維持できており良い。フロント非変更で吸収できる設計は後退リスクが低い。
- [Suggestion] `playbackJobId` を staleness 対象外に据え置く説明は十分。

**施策5（tooShort 分離）**: **REQUEST_CHANGES**
- [Warning] `strlen($text)` は UTF-8 マルチバイトで「文字の体感量」と乖離し得るため、短文判定が日本語で厳しすぎる可能性。  
  修正案: 仕様を「bytes基準」と明記し続行するか、`mb_strlen` 等の文字数基準へ寄せるかを設計で明示（現状は意図説明不足）。
- [Suggestion] 文言分離自体は UX 改善として妥当。`unextractable` の責務を維持している点は良い。

**施策6（Create.svelte oninput クリア）**: **APPROVE**
- [Suggestion] DESIGN/Atomic 観点で逸脱なし。`if (form.errors.title)` ガードも適切。
- [Suggestion] 他フィールドへ水平展開は別タスク化が安全（今回スコープ維持）。

**施策7（テスト計画）**: **REQUEST_CHANGES**
- [Critical] 「テスト先行」の実行保証が設計上まだ運用宣言に留まる。  
  修正案: 各施策に「最初に追加する失敗テスト名」を固定し、実装順を明記（例: 7-A-1 → 2 → 3 の順）。
- [Warning] 7-A の「factory/直接更新で組み立てる」方針は、`ScenarioService::save` 契約（no-opでもversion++）との接続検証が薄くなる。  
  修正案: 最小1ケースだけでも `save` 実経路を通す統合ケースを追加し、契約とのズレを防ぐ。

**全体判定**: **CHANGES_REQUESTED**

主な理由は2点です。  
1) 施策5の short 判定基準（bytes/文字）の仕様明確化不足、  
2) 施策7のテスト先行・実経路接続の担保不足。  

この2点を設計に追記できれば、全体は **APPROVED 相当**まで上がります。