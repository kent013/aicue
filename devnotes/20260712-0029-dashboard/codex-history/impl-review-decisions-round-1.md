# 対応マトリクス: impl-review Round 1

Codex (gpt-5.3-codex, reasoning=high, one-shot) の最終マージ前レビュー結果への対応。

## Critical

なし。

## [Warning] Dashboard.svelte のリンク prefix 混在 (`/projects/...` vs `/app/projects/...`)
- 判断: 反論する (仕様通り・false positive)
- 根拠: `/app/projects/{project}/manuals/{manual}` は撮影 PWA のルート
  (`routes/web.php` の `->prefix('app')->as('capture.')` group、doc/10 §10.8-3 のルート分離)。
  「撮影する」ボタンは意図的に PWA へ deep link する。一方 `/projects/...` は
  通常の業務 Web ルートで、両方が実在する別ルート。混在は規約違反ではなく仕様。
- 対応内容: 変更なし。`tests/js/pages/Dashboard.test.ts` の期待値も正しい仕様を固定している。

## [Warning] `storageUsagePercent` に下限 clamp がない (負値がそのまま DTO に入る)
- 判断: 対応する
- 根拠: progress の clamp (`max(0, min(100, ...))`) と同じ防御水準に揃えるべき。
  データ不整合 (負の size_bytes) 時に UI へ負 percent が漏れるのは集計の堅牢性不足。
- 対応内容: `DashboardService::billingSummary()` を `(int) max(0, min(100, floor(...)))` に修正。
  Feature テスト「容量: storage_usage_percent は 0-100 に clamp される」を追加。

## [Suggestion] failed job を進行中扱いしない契約の明示テスト
- 判断: 対応する
- 根拠: `whereIn('status', [Queued, Running])` の絞りが将来緩んで failed が
  進行中カードに混入する回帰を防ぐ価値があり、コストも低い。
- 対応内容: Feature テスト「進行中ジョブ: failed の job は引き当てない」を追加
  (manual 行は残るが job_status/progress は null になる契約を固定)。

## [Suggestion] `inProgress()` で manuals 空なら job クエリ 2 本を省略
- 判断: 対応する
- 根拠: 3 行の早期 return で無駄クエリを削減でき、挙動は不変。
- 対応内容: `$manualIds === []` の早期 return を追加。

## [Suggestion] PHP DTO ↔ TS 型の乖離検知 (契約テスト / schema 生成)
- 判断: 見送る
- 根拠: T009 のスコープ外の横断基盤。既存の他 DTO/TS 対にも同じ課題があり、
  ダッシュボードだけ局所導入すると中途半端になる。「今必要なものだけ作る」原則に従い、
  必要性が顕在化した時点で全 DTO 横断の仕組みとして設計するのが適切。
- 対応内容: 変更なし (将来の改善候補として本マトリクスに記録)。
