**レビュー結果**

- 実装差分・設計要約・提示テスト結果を突合した限り、**重大な不整合は見当たりません**。
- 依頼観点（設計一致/正確性/網羅性/セキュリティ/DESIGN.md/Atomic Design）で評価しました。

**`resources/js/types/manual.ts`**
- **判定: OK**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - `CAPTURE_NAVIGABLE_BY_STATUS` を `satisfies Record<VideoManualStatus, boolean>` で固定し、`isCaptureNavigable()` を単一ソース化している点は設計どおりで良いです。
  - `ready/published=true`、他=false の定義は、要件（capture 一覧との整合）に一致しています。

**`resources/js/pages/Manuals/Show.svelte`**
- **判定: OK**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - `captureNavigable || canManage` で action コンテナを出し分け、空コンテナを残さない実装は設計どおりです。
  - `canManage` 非依存で撮影導線を表示する条件分離も要件に一致しています。
  - `Button` atom + `@lucide/svelte` `Camera` を使っており、DS/Atomic Design 観点も問題ありません。

**`resources/js/pages/Manuals/Edit.svelte`**
- **判定: OK**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - ヘッダ側へ撮影導線を分離配置しており、保存操作との視覚分離という設計意図を満たしています。
  - `isCaptureNavigable(manual.status)` 条件で非表示制御（disabled 不使用）になっており、禁止事項 #8 に準拠しています。

**`tests/js/types/manual.test.ts`**
- **判定: OK**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - 状態ごとの真偽テーブル検証 + キー網羅確認があり、predicate の回帰防止として十分です。

**`tests/js/pages/ManualsShow.test.ts`**
- **判定: OK**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - 表示条件（ready/published/draft）と `canManage=false` ケースを押さえており、仕様の中核を適切にカバーしています。
  - `href` の厳密照合（start/end固定）も妥当です。

**`tests/js/pages/ManualsEdit.test.ts`**
- **判定: OK**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - `published` で表示・`draft` で非表示の追加は要件に一致しています。
  - `href` 検証も Show 側と同等方針で一貫しています。

**セキュリティ観点**
- **Critical**: なし  
  本件はフロント導線追加で、認可境界はサーバ側既存ルートに委譲。差分内に IDOR を新規発生させる実装は見当たりません。
- **Warning**: なし
- **Suggestion**:
  - 将来、`/app/projects/:project/manuals/:manual` のルート仕様変更時に、`isCaptureNavigable` の条件とルーティング要件の乖離が起きないよう、仕様コメント維持を推奨。

**全体判定**
- **APPROVED**