# 概念設計レビュー Round 1 対応マトリクス (gpt-5.4)

全体判定: CHANGES_REQUESTED → 主要因は SOP 検索(extracted_json::text ILIKE)。

| # | 指摘 | 分類 | 対応 | 根拠 |
|---|------|------|------|------|
| 1 | SOP 検索の実体が「原稿」ではなく監査 JSON ダンプ検索。意味論ずれ | Critical | **対応(受入)** | extracted_json は write-only 監査スナップショット。原稿検索の実体にするのは誤り。v1 では SOP/原稿キーワード検索を **out-of-scope** に落とす |
| 2 | extracted_json::text ILIKE は false pos/neg 多く期待効果を保証できない | Critical | **対応(受入)** | 同上。#1 の out-of-scope 化で解消 |
| 3 | JSON 全文 LIKE は件数増で一覧性能を崩す(PC/PWA 共通劣化) | Critical | **対応(受入)** | 一覧クエリに毎回 JSON 部分一致サブクエリを載せる perf リスク。適切な検索投影(tsvector/検索用 text 列)無しに導入しない = out-of-scope |
| 4 | 「ギャップ #9 を閉じる」は過剰主張(作成者名検索・サムネイルは残る) | Warning | **対応** | 「一覧の発見性ギャップを部分的に縮小」に表現修正。残課題(SOP 検索・作成者名検索・サムネイル)を明示的残課題に列挙 |
| 5 | テスト計画への言及がない | Warning | **対応** | 概念設計にテスト方針(sort allowlist / mine / creator・updated_at props / cross-org 非漏洩 / paginate 整合)を追記 |
| 6 | PostgreSQL 前提自体は許容だが JSON 全文検索を正式仕様にするのが脆い。query object へ隔離を | Warning | **対応(受入・結果的に不要化)** | SOP 検索を out-of-scope 化するため隔離設計自体が不要に。将来実装時は検索専用投影で行う旨を残課題に記載 |
| 7 | mine + メタ表示 + PC sort は v1 妥当。過大は SOP 検索の PC/PWA 同時投入 | Warning | **対応** | SOP 検索を外し、mine(PC/PWA)+ 作成者/更新日(PC/PWA)+ sort(PC)に絞る |
| 8 | 型安全: sort は enum 相当 allowlist、mine は bool 正規化、creator は nullable shape | Warning | **対応** | sort は allowlist enum、mine は bool、行 props に creator: {id,name}\|null(system ユーザー等の欠落に備え null 許容)を明記。詳細設計で shape 固定 |
| 9 | セキュリティ: 検索の根は $project->manuals() に固定、sourceDocuments から逆引きしない | Warning | **対応(結果的に該当なし)** | SOP 検索を外すため sourceDocuments 逆引き自体が消える。一覧は現行どおり $project->manuals() 起点を維持と明記 |
| 10 | filter state を 1 DTO/array shape にまとめる / PC・PWA で query normalizer 共有 | Suggestion | **一部採用** | PHP 側は typed array shape で集約(既存 parseManualFilters を拡張)。PC/PWA の Controller 間共有ヘルパは過剰化を避け各 Controller に閉じる(条件が非対称: PC は sort あり/paginate、PWA は sort なし/ready・published 限定)。詳細設計で判断 |
| 11 | PWA sort 不採用 / サムネイル out-of-scope は妥当 | Suggestion | 追認 | 変更なし |

## 方針転換サマリー
- **SOP/原稿キーワード検索は v1 out-of-scope**(Critical 1-3 受入)。`q` は現行の title LIKE を維持(挙動不変)。
- 残す施策: (1) sort(PC, allowlist) (2) mine フィルタ(PC/PWA) (3) 作成者・更新日メタ表示(PC/PWA)。
- 概念設計に「テスト方針」節と「残課題(将来施策)」節を追記し、過剰主張を修正。
