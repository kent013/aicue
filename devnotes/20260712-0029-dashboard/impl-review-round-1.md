## Critical
- `なし`

## Warning
- `resources/js/pages/Dashboard.svelte`（`recentCard` 内リンク）  
  - 根拠: 一覧詳細・編集・作成の一部が `href="/projects/..."`、一方で他導線は `href="/app/projects/..."` を使っており、同ページ内でプレフィックス規約が混在。  
  - 失敗シナリオ: ルーティングが `/app/projects/*` に統一されている環境では、`/projects/*` 系リンクが 404 か意図しないページ遷移を起こし、ダッシュボードから主要導線が部分的に壊れる。  
  - 備考: テスト `tests/js/pages/Dashboard.test.ts` も `/projects/...` を期待しているため、現状テストはこの不整合を検知できない（仕様誤固定の可能性）。

- `app/Services/Dashboard/DashboardService.php`（`billingSummary()` の `storageUsagePercent` 算出）  
  - 根拠: `floor($used / $limit * 100)` を `int` 化し 100 上限だけ clamp、下限 clamp がない。`occupiedBytes` が何らかの理由で負値を返した場合に負の percent がそのまま DTO に入る。  
  - 失敗シナリオ: データ不整合や将来の実装変更で `used < 0` が起きると UI に `-5%` など不正値が表示され、状態判断（高使用率警告など）の前提が崩れる。  
  - 影響度: すぐ exploitable な脆弱性ではないが、集計の堅牢性としては防御不足。

## Suggestion
- `tests/Feature/DashboardTest.php`  
  - 提案: 「ジョブ失敗時」の明示テストを追加（ユーザー要件観点で指定あり）。今の実装は `in_progress` が queued/running 限定で、failed は表示対象外になる設計だが、これを契約として固定するテストがあると回帰に強い。  
  - 失敗シナリオ: 将来クエリ条件が緩んで failed が混入し、進行中カードに誤表示される。

- `app/Services/Dashboard/DashboardService.php`（`inProgress()`）  
  - 提案: `manuals` が空のとき、`AnalysisJob`/`RenderJob` クエリを早期 return でスキップすると無駄クエリを 2 本削減可能。  
  - 失敗シナリオ: 機能不具合はないが、manual 0 件組織が多い期間に無駄な DB 負荷が積み上がる。

- `resources/js/types/dashboard.ts` と PHP DTO 群  
  - 提案: 「PHP DTO ↔ TS 型」の乖離検知を軽量に追加（例: JSON schema 生成 or 契約テスト）。現状コメントで「対で保守」前提だが機械的保証はない。  
  - 失敗シナリオ: 片側だけフィールド変更してもビルドは通るケースがあり、実行時に表示欠落・分岐崩れが発生する。

総評: セキュリティ（cross-org/tenantキー不信/認可）と DTO 経由設計は全体として良好で、マージブロッカーは見当たりません。上記 Warning のリンク整合だけは本番導線に直結するので、マージ前に最終確認を推奨します。