# 対応マトリクス: design-review Round 3 (最終)

全体判定 **APPROVED**。Critical 0 件 / Warning 0 件 / Suggestion 0 件。
未対応の指摘は無い。

## ラウンド経過

| ラウンド | 判定 | 指摘 | 帰結 |
|---|---|---|---|
| 概念 Round 1 | APPROVED | Critical 0 / Warning 4 | Warning 4 件すべて概念設計へ反映 |
| 詳細 Round 1 | CHANGES_REQUESTED | Critical 1 / Warning 9 | Critical は一部反論 (paginator の links が props に無い) + allowlist 化で対応。Warning は 8 件対応 / 1 件は据え置き明記 |
| 詳細 Round 2 | CHANGES_REQUESTED | Warning 3 / Suggestion 1 | 全件対応 (`captureProgressOf` の記述と実装の食い違いを実装側の帰結へ合わせて訂正、完了条件に packages 系 3 コマンド追加、契約テストの並び順依存の解消) |
| 詳細 Round 3 | **APPROVED** | 0 | 実装へ進める状態 |

## 最終確認 (使命・禁止事項チェック)

- **使命との整合**: 一覧が答えるのは「どれがまだ出来ていないか」1 つに絞られ、
  制作パイプラインの内部状態を現場に見せない方向へ寄る (思考ゼロ)。
- **禁止事項 2 (テストなしの完了報告)**: Unit (写像) / Architecture (値集合同期) /
  Feature (絞り込み・payload 契約・着地先) / Vitest (一覧 3 値・PWA 語彙の維持) を計画済み。
- **禁止事項 2 (PHPStan の widen / baseline)**: 網羅 match に default を置かず、
  level 10 に未処理 case を検出させる設計。widen しない。
- **禁止事項 4 (`response()->json()` 直書き)**: 触るのは Inertia props と DTO のみ。増やさない。
- **禁止事項 8 (disabled UI)**: 新設なし。既存の「disabled 不使用」テストは無変更で緑。
- **思考原則 3 (後方互換の並走を残さない)**: 旧 `?status=` の受理経路は同じ変更で消す。
- **思考原則 4 (別物を統合しない)**: 撮影 PWA の撮影進捗と PC の制作状態は別物として維持し、
  コードの docblock と Vitest で宣言する。
- **セキュリティ不変条件**: 認可・テナント境界の判定 (`ManualRowAbilities` /
  `resolveOrganizationProject`) は無変更。payload から ownership/actor キーを受け取らない性質も不変。
  書き込み経路 (ドメイン規約 1 のシナリオ整合ロック) は 1 つも増やさない (読み取りと表示のみ)。
