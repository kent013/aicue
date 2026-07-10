# 20260611 テンプレート抽出調査

`tmp/aigenba`(AI OJT シミュレーション)と `tmp/spirux`(AI UX 評価)の docs・実コードを調査し、
Laravel + Svelte SaaS テンプレートとして共通項を抽出するための中間成果物。

| ファイル | 内容 |
|---|---|
| `01-features-aigenba.md` | aigenba の機能インベントリ(カテゴリ別、[汎用]/[固有] タグ付き) |
| `02-features-spirux.md` | spirux の機能インベントリ(同上。docs が実コードより古い箇所の補正メモあり) |
| `03-feature-matrix.md` | 両アプリの機能突き合わせマトリクス(◎コア/○要パラメータ化/△判断対象/×固有) |
| `04-template-open-questions.md` | 相違点をテンプレートにどう落とすかの論点集(Q1〜Q12、背景・トレードオフの記録) |
| `05-decisions.md` | **Q1〜Q12 のオーナー決定事項(2026-06-11 確定。以後これが正)** |
| `06-default-team-pattern.md` | Default Team パターンの仕様(3 階層スキーマを維持しつつ Team 層を表示上スキップ) |
| `07-app-integration-guide.md` | アプリ組み込みガイド(LLM 設計者向け。ドメインロジックをテンプレ構造へマップする判定規則と不変条件)— テンプレ `docs/` 行きの草稿 |
| `08-template-architecture.md` | テンプレート構成設計(形態・モジュール×ドナー対応表・Architecture テスト 3 分類・リポジトリレイアウト・名前注入・更新還流) |
| `09-extraction-plan.md` | 抽出実行計画(Phase 0〜10、各フェーズの DoD と依存関係、保留事項の解消タイミング) |
| `10-ui-design-system.md` | UI・デザインシステム調査(多視点: 実装規約/トークン機構/機械統制/画面パターン/DS思想)。「機構は同型・テーマは別物」が骨子。Q13〜Q16 の新規論点 |
| `11-backend-data-conventions.md` | バックエンド・データ層の規約調査(レイヤ構造/Controller/FormRequest/Policy/例外/Enum/PHPStan/Event + migration/Model/Factory/Seeder)。**監査ログ 3 層正規形の推奨案**(保留事項 1 の解) |
| `12-testing-toolchain-infra.md` | テスト戦略(6層スイート/Dusk分離/E2E/worktree/CI/scripts)+ 設定・ルーティング・ミドルウェア・.env・i18n・法務・デプロイの調査。spirux bootstrap の `aigenba-test-` 残骸などの発見事項 |
| `13-process-assets.md` | LLM 駆動開発プロセス資産(AGENTS.md 構造/TODO・devnotes 運用/スキル内部/codex 連携/settings.json)。共通部 70-80% の実証とアプリ固有値の抽出ポイント一覧 |

## 重要な一次情報

- aigenba `docs/aigenba-spirux-divergence.md` — 両アプリ間の正当差分レジストリの**正本**(D1〜D9)
- spirux `docs/spirux-aigenba-divergence.md` — そのミラー
- 両者は「セキュリティ不変条件は双方向に強い方へ寄せ、logic-driven な差分のみ許容」という
  整列運用を既に行っており、テンプレート抽出はこの運用の延長線上にある

## ステータス

Q1〜Q12 はオーナー回答済み(2026-06-11)。決定の要点:
組織階層は **aigenba 型 3 階層**、API は **nested route + 広い prohibited 集合**、
IDOR 防御は **URL 整合 guard(aigenba 型)**、課金は **チケット台帳+Quota 両方同梱**、
LLM 防御は **コアのみ同梱**。詳細と保留事項(監査ログ命名ほか)は `05-decisions.md`。
