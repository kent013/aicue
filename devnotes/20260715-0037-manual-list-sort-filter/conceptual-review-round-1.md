**全体判定: CHANGES_REQUESTED**

`mine` フィルタ、作成者/更新日表示、PC の sort 追加自体は North Star に沿っています。ただし、`source_documents.extracted_json::text ILIKE` を前提にした SOP 検索は、機能名に対して意味論がずれており、期待効果・性能・将来保守の3点で設計の芯が弱いです。ここを修正しない限り、この設計をそのまま承認するのは難しいです。

**1. 使命との整合性**
- [Critical] SOP 検索の実体が「原稿検索」ではなく「AI 抽出 JSON ダンプ検索」になっています。North Star は SOP 起点ですが、`extracted_json` は write-only の監査スナップショットであり、利用者が期待する“原稿そのもの”ではありません。これでは「SOP 一語から確実に辿れる」という価値を過大に約束しています。  
  修正提案: v1 では SOP 検索を out-of-scope にするか、少なくとも検索対象を「検索用に正規化した平文テキスト投影」に切り出してください。検索対象が監査 JSON のままなら、UI/文言も「原稿検索」ではなく「抽出済み内容検索」へ落とすべきです。
- [Suggestion] `mine` フィルタと更新日/作成者表示は、現場で対象マニュアルへ最短到達する導線として妥当です。これは使命に素直に寄与します。

**2. 禁止事項違反**
- [Warning] 明示的な禁止事項違反は見当たりませんが、「doc/04・doc/05 のギャップを閉じる」という主張は過剰です。設計自身が作成者名検索とサムネイルを scope 外にしており、要件差分は残ります。  
  修正提案: 「ギャップ #9 を完全に閉じる」ではなく「一覧の発見性ギャップを部分的に縮小する」と表現を修正し、残課題を TODO として分離してください。
- [Warning] テスト計画への言及がありません。AGENTS.md ではテスト込みで初めて実装完了です。  
  修正提案: 少なくとも `sort allowlist`、`mine=1`、`creator/updated_at props`、`cross-org で漏れないこと`、`未解析 SOP が検索ヒットしないこと` の Feature テスト方針を設計に追記してください。

**3. 実現可能性**
- [Warning] Laravel 12 + PostgreSQL で `::text ILIKE` 自体は技術的には実装可能です。ただし、DB 固定が問題なのではなく、JSON 全文文字列化を検索面の正式仕様にすることが脆いです。  
  修正提案: PostgreSQL 前提は許容してよいですが、実装は `whereRaw` 1 箇所に閉じ込めるだけでは不十分です。検索専用の query object / repository に隔離し、将来 `tsvector` や検索用 text column に差し替えられる形にしてください。
- [Suggestion] PC と PWA で同じ条件群を持つなら、filter DTO か query normalizer を共有した方が実装ブレを防げます。

**4. 期待効果の妥当性**
- [Critical] `extracted_json::text ILIKE` は false positive / false negative が多く、期待効果の「SOP 一語からマニュアルを引ける」を合理的に保証できません。JSON のキー名、ラベル語、構造語にヒットし得る一方、抽出処理で落ちた原文語は拾えません。  
  修正提案: 期待効果を「補助的な検索」に弱めるか、検索対象を“利用者が見ている原稿テキスト”に合わせて設計し直してください。
- [Suggestion] `mine`、更新日、作成者表示の効果主張は妥当です。特に PWA では撮影現場での再発見性に効きます。

**5. リスク**
- [Critical] `source_documents.extracted_json::text ILIKE '%...%'` はデータ量が増えるほど一覧性能を崩しやすく、PC/PWA の両画面で共通劣化要因になります。v1 の一覧体験を不安定にするリスクがあります。  
  修正提案: v1 では SOP 検索を切るか、検索専用の派生列を用意してください。少なくとも「件数上限」「EXPLAIN 確認」「性能回帰テスト」を設計条件に入れるべきです。
- [Warning] 作成者名表示は既存慣行に沿うなら許容できますが、PWA 側まで表示面を広げるぶん、表示条件の org/project スコープ逸脱に敏感になります。  
  修正提案: 常に `$project->manuals()` 起点で解決することを明記し、`sourceDocuments` 側から manual を逆引きしない設計に固定してください。

**6. スコープの適切さ**
- [Warning] `mine` + メタ表示 + PC sort は v1 として妥当です。過大にしているのは SOP 検索を PC/PWA 両面へ同時投入している点です。  
  修正提案: v1 は「mine + 作成者/更新日 + PC sort」を先行し、SOP 検索は別チケットに分離するのが妥当です。どうしても同梱するなら、まず PC のみに限定した方がよいです。
- [Suggestion] PWA に sort を入れない判断は妥当です。doc/05 の要求外であり、現場の操作量も増やしません。
- [Suggestion] サムネイルを scope 外にした判断は妥当です。manual 単位の成果物がない状態での導入は、別のレンダリング責務を持ち込みます。

**7. 型安全性**
- [Warning] `sort` / `mine` / `q` をそのまま文字列で各所に流すと、Svelte 側 props・Controller・query builder で判定が散ります。PHPStan L10 では array shape の厳密化が必要です。  
  修正提案: `sort` は enum 相当の allowlist、`mine` は bool 正規化、props は typed array shape を固定してください。`creator` は nullable を含めた shape にして、UI 側も null-safe に寄せるべきです。
- [Suggestion] Inertia props は既存流儀で問題ありませんが、filter state は 1 つの DTO/array shape にまとめた方が保守しやすいです。

**8. セキュリティ不変条件**
- [Warning] `mine=1` 自体は `created_by` を payload から受けないため invariant に合っています。ただし、SOP 検索を relation 経由で広げる際に、manual の親 project / org スコープを外すと cross-org 漏洩の入口になります。  
  修正提案: 検索の根は必ず `$project->manuals()` に固定し、その内側で `whereHas('sourceDocuments', ...)` を使う形を設計に明記してください。
- [Suggestion] 作成者名の表示は read として妥当です。作成者名検索を scope 外にした判断も、CipherSweet 制約に照らして正しいです。

**特に諮られている3点への結論**
- `extracted_json::text ILIKE` による SOP 検索: PostgreSQL 固定前提そのものはこのリポジトリでは許容可能です。ただし、この実装方式は承認しません。問題は PG 固定ではなく、「監査 JSON を原稿検索の実体にする」ことです。v1 では out-of-scope へ落とすか、検索用平文投影を別途設計すべきです。
- 「自作のみ」+ sort + SOP 検索 + 作成者/更新日表示を PC/PWA 両方: SOP 検索を含むと過大です。SOP 検索を外せば、PC/PWA 両方の `mine` とメタ表示、PC の sort までは v1 妥当です。
- サムネイル out-of-scope: 妥当です。manual 単位のサムネイル成果物がない現状では、一覧改善の本筋から外れます。

承認に必要な最小修正は、`extracted_json::text ILIKE` ベースの SOP 検索を設計から外すか、少なくとも「正式な原稿検索ではない」前提に弱めることです。それが済めば、この設計は v1 の一覧改善としてかなり通しやすくなります。