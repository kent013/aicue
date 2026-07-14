以下、提示された**詳細設計のみ**を対象にしたレビュー結果です（実コード未適用前提）。

**全体判定**  
- **CHANGES_REQUESTED**

**施策1: config 追加**  
- 判定: **APPROVE**
- [Suggestion] `summary_recap_max_points` は `max(1, $max)` で下限防御済みで妥当。将来の運用事故防止として `config/manual.php` コメントに「0以下は1扱い」を明記すると保守性が上がる。

**施策2: lang 追加**  
- 判定: **APPROVE**
- [Warning] `line()` が未定義キー時に `$key` を返す設計は、文面欠落を静かに見逃しうる。  
  修正案: `line()` で `Lang::has($key)` を確認し、未定義時は `LogicException`（少なくとも test 環境で fail）にする。  
- [Suggestion] テスト側も同キー参照で期待値を組む方針は良い（文言ハードコード回避）。

**施策3: ScenarioBookendBuilder 新設**  
- 判定: **REQUEST_CHANGES**
- [Critical] `wrap()` が常に +2 step するため、既存上限 `MAX_STEPS=100` の意味が実質崩れる。設計書の「102許容」は仕様変更に当たり、境界定義が曖昧。  
  修正案: どちらかを明文化して実装・テストに固定する。  
  1) **総数上限維持**: generated が 98 超なら末尾から削る（または reject）  
  2) **上限再定義**: `MAX_STEPS` を「生成step上限」と改名し、materialized総数上限を別定数化  
- [Warning] `trim()` は全角空白を落とせず、日本語入力で「見た目空文字」が候補に残る可能性。  
  修正案: `preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $text)` 相当で正規化してから空判定。  
- [Suggestion] `subtitlePrimary` にも clamp を入れている点は堅実。`subtitleSecondary` の件数削減→最終truncateの順序も合理的。

**施策4: finalize で builder 呼び出し**  
- 判定: **APPROVE**
- [Suggestion] 共有ロック規約への適合は良好。`lockForUpdate` 済み manual を `wrap()` に渡して terminal tx 内で materialize する流れは一貫している。  
- [Suggestion] 依存注入追加に伴い、既存テストのコンストラクタ解決失敗が起きないか（コンテナ自動解決前提）を Feature で早期確認する。

**施策5: Unit テスト計画**  
- 判定: **APPROVE**
- [Warning] `line()` を fail-fast 化しない場合、キー欠落が Unit で拾いにくい。  
  修正案: 「全利用キー存在テスト」を1本追加（intro/summary 各キー）。  
- [Suggestion] `summary_recap_max_points=0/-1` を与える防御テストを追加すると、typed accessor + 下限補正の仕様が固定できる。

**施策6: Feature テスト計画**  
- 判定: **APPROVE**
- [Suggestion] 「再生成時に旧cuts不参照」を明示検証するため、1回目と2回目で全く異なる point 文言を返し、総括再掲が2回目由来のみになることを検証すると論点Bが強化される。  
- [Suggestion] lock 規約については既存 Architecture テストの守備範囲に寄せつつ、ここでは振る舞い（全置換・前後1件）に集中する方針で良い。

**施策7: 既存テスト期待値更新**  
- 判定: **REQUEST_CHANGES**
- [Warning] 件数を `2→4` に更新するだけだと、導入/総括の**位置・型・親子関係**の退行を見逃す。  
  修正案: 既存テスト更新時に最低限以下を追加確認  
  - 先頭/末尾 top-level が `ShotType::Hiki`  
  - 導入/総括は `parent_cut_id=null`  
  - 生成 point が中間 step にぶら下がる（親ID整合）  
- [Suggestion] 禁止事項との整合（テスト削除なし）は方針として適切。

**重点論点 A〜F 評価**
- A: **概ね妥当（APPROVE）**。v1の `CutType step/point` 制約と整合。  
- B: **妥当（APPROVE）**。terminal tx + locked manual + generatedStepsのみ参照は再生成安全。  
- C: **条件付き妥当（REQUEST_CHANGES）**。空白正規化と境界テスト追加が必要。  
- D: **妥当（APPROVE）**。inventory不要判断は「書き込み経路不増」で論理的。  
- E: **不足あり（REQUEST_CHANGES）**。件数更新だけでなく構造アサート強化が必要。  
- F: **要再定義（REQUEST_CHANGES）**。100→102許容は仕様変更のため明文化・固定必須。

必要なら次に、上記 Critical/Warning を反映した**テスト観点チェックリスト（そのままPest化可能な粒度）**を作成します。