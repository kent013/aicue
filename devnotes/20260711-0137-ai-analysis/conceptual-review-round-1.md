**全体判定: CHANGES_REQUESTED**

提供文面ベースでは、North Star への寄与は非常に高く、Laravel 12 + Svelte 5 + Inertia.js でも十分実装可能です。  
ただし、ジョブ回復性・再解析フロー・入力上限制御の3点が未閉塞で、このままだと「解析中で詰む」「再実行できない」「長文 SOP で破綻する」可能性が高いため、概念設計のまま進めるのは危険です。

**1. 使命との整合性**
- [Suggestion] `SOP → 作業分解 → シナリオ生成 → Cut materialize` を既存 `ScenarioService` に合流させる方針は、North Star に正面から合致しています。
- [Critical] `status !== draft` で analyze を 409 にしている一方、`source-documents` では ready/manual への差し替えを許容しており、「SOP を更新したのに再解析できない」状態が発生します。これは「SOP 起点で標準化された動画を作る」という役割に反します。  
  修正提案: `ready` からの再解析を正式な状態遷移として定義してください。少なくとも「差し替え時に manual を再解析待ちへ戻す」か、「差し替え後に analyze 再実行を許可する」どちらかを明示し、既存 cuts をどう扱うかも決めるべきです。

**2. 禁止事項違反**
- [Suggestion] `Prompt` factory 経由、YAML 外出し、`disabled` 不使用、`JsonResource` 利用の方針は AGENTS.md に整合しています。
- [Warning] `InsufficientTicketsException` の JSON 応答は「フレームワーク標準レンダリング」と書かれていますが、実装次第では `response()->json()` 直書きに流れやすい箇所です。  
  修正提案: ここは概念設計の段階で「例外専用のレスポンス DTO/Resource もしくは renderable レスポンスクラスを使う」と固定してください。

**3. 実現可能性**
- [Critical] `trigger()` で manual を `analyzing` にした後、`dispatch(...)->afterCommit()` が失敗した場合の回復策がありません。キュー接続障害や dispatch 失敗で、`queued` job と `analyzing` manual が残留します。  
  修正提案: `queued` の滞留回復を前提にしてください。方法は、`queued` の古い job を再 enqueue する監視ジョブを置く、または DB queue への投入を同一トランザクションで確実化する、のどちらかです。
- [Critical] `run()` 冒頭で `status !== queued なら no-op` としているため、予約確保後・`running` 更新後にワーカーが異常終了すると、その job は再配送されても回復できません。`$tries = 1` と組み合わさると `manual=analyzing` / `reservation=Reserved` の孤児化が起きます。  
  修正提案: `running` の stale 検知と回復を設計に含めてください。`heartbeat_at` / lease 期限を持たせる、または step 永続化を前提に再入可能な pipeline にする必要があります。
- [Critical] 長い PDF/Excel をそのまま 3 段 LLM に渡す設計で、入力長の上限戦略がありません。日本語 SOP は表や注意書きで簡単に文脈長を超えます。`COST_ANALYSIS=1` 固定とも噛み合っていません。  
  修正提案: v1 でも `最大ページ数/最大文字数/最大シート数` を明示し、超過時は 422 で落とすか、前処理要約/分割方針を追加してください。
- [Warning] `smalot/pdfparser` と `PhpSpreadsheet` 自体は採用可能ですが、表崩れ・結合セル・縦書きで抽出品質が大きく揺れます。期待効果は parser 品質に強く依存します。  
  修正提案: `SopTextExtractor` の出力に「抽出元種別・抽出文字数・空率」などの診断情報を持たせ、失敗判定を早めに返せるようにしてください。

**4. 期待効果の妥当性**
- [Warning] 「二重課金/二重実行が起きない」とまで言い切るのは現状強すぎます。前述の stranded `queued/running` を潰さない限り、二重実行より先に「未完了のまま詰む」「予約だけ残る」系の障害が残ります。  
  修正提案: 効果主張は「冪等化の方向性を持つ」に留め、監視回復設計を入れた後に確定表現へ上げてください。

**5. リスク**
- [Warning] 差し替えで `source_document` 行とファイルを削除すると、過去 `analysis_jobs` の `source_document_id` が `null` になり、再現性・監査性が落ちます。設計文の「デバッグ・再現用」と矛盾します。  
  修正提案: source document は immutable にして active/superseded を持たせるか、少なくとも `analysis_jobs` 側にファイルハッシュ・original_name・抽出結果スナップショットを保持してください。
- [Warning] `TicketLedgerService::commit()` と `job status=succeeded` の原子的成功条件が曖昧です。ここが別トランザクション化すると「課金済みだが job は failed」の危険があります。  
  修正提案: 同一 DB connection / 同一 transaction での原子性を詳細設計で明文化し、テスト観点にも入れてください。
- [Warning] 共有ロック規約の inventory 記述がまだ閉じていません。`cuts`/`scenario_version`/`video_manuals.status` の実ライターは `ScenarioService` だけでなく、`AnalysisJobService`/`AnalysisPipeline` の fail path も含みます。  
  修正提案: allowlist は「クラス名」ではなく「どのメソッドが何を更新してよいか」まで落として定義してください。

**6. スコープの適切さ**
- [Suggestion] 機能としては妥当な最小閉路です。アップロード、解析、materialize、ポーリングまで揃って初めて価値が出るため、概念設計としてのまとまりは良いです。
- [Warning] 一方で 1 つの実装単位としては広めです。parser 追加、ジョブ状態機械、課金、UI、LLM 3 prompt、Architecture test まで同時に動かすため、失敗時の切り分けが難しくなります。  
  修正提案: 詳細設計/実装では少なくとも「状態機械と課金の閉塞」「parser/LLM DTO」「UI」の3層で段階化してください。

**7. 型安全性**
- [Warning] DTO / JsonResource 方針は良いですが、`result_json` と `extracted_json` を生配列のまま扱うと PHPStan level 10 で `array<string, mixed>` 汚染が広がりやすいです。  
  修正提案: `ExtractedSopData` / `WorkDecompositionData` / `GeneratedScenarioData` を永続化境界でも維持し、Eloquent custom cast か repository 層で DTO 復元を固定してください。
- [Suggestion] `ScenarioLimits` への昇格は妥当です。`UpdateScenarioRequest` と LLM DTO の制約を単一ソースに寄せる方が型・仕様の両面で安全です。

総評として、方向性は正しく、North Star に対して本質的です。  
ただし、実装に入る前に「再解析状態遷移」「queued/running の孤児回復」「LLM 入力上限」の3点を概念設計に追記しないと、v1 として運用不能な失敗モードが残ります。