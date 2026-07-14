Round 2 の 2 件の Warning に対応しました。

### [Warning] テスト方針が未記載 → 対応
概念設計に「テスト方針 (テストファースト)」節を追加:
- predicate 単体（ready/published=true、他の全状態=false、全 VideoManualStatus 網羅）
- Show/Edit で対象状態のみリンク表示
- Show で canManage=false でもリンク表示
- リンク先 URL が対象 project/manual を指す
詳細ケースは詳細設計「テスト計画」で確定。

### [Warning] 型安全性: 配列では status 追加時にコンパイルエラーにならない → 対応
`CAPTURE_NAVIGABLE_BY_STATUS satisfies Record<VideoManualStatus, boolean>` の網羅マップとして定義し、
`isCaptureNavigable()` はこの表を引く。`VideoManualStatus` に case が増えたら型エラーで検知できる。

更新後の該当箇所を再掲します。他観点は Round 2 で Suggestion のみ・方向性合意済みです。全体判定を再確認してください。

## 実装方針（判定部・抜粋）
- 判定は `resources/js/types/manual.ts` の型付き predicate
  `isCaptureNavigable(status: VideoManualStatus): boolean` 経由。判定表は
  `CAPTURE_NAVIGABLE_BY_STATUS satisfies Record<VideoManualStatus, boolean>` の網羅マップ。

## テスト方針（抜粋）
- predicate 単体 / Show・Edit 表示条件 / Show 権限非依存 / リンク先 URL を vitest で検証。
