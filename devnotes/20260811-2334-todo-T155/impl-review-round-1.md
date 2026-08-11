全体判定: **APPROVED**

重大な問題は見当たりません。設計の主張どおり、実装はサーバ側の権限・DTO・Resource を触らず、`Capture/Show` に復路リンクを追加し、DOM 契約と最弱 principal の到達性をテストで固定しています。mutation 実測も、D の不一致理由まで追えており、テストの効き方の説明として妥当です。

**ファイル別判定**

`resources/js/pages/Capture/Show.svelte`: **APPROVE**

- [Suggestion] `href` がテンプレート文字列直書きなのは既存流儀に合っています。将来 route helper を導入するならここも対象ですが、本件で抽象化する必要はありません。
- DESIGN.md / Atomic Design 観点では、既存 `TextLink` atom と `@lucide/svelte` の `BookOpen` を使っており、hex 直書き・SVG 直書き・階層逆流は増えていません。

`tests/js/pages/CaptureShow.test.ts`: **APPROVE**

- [Suggestion] `pathOf()` は pathname のみ比較なので、`/projects/1/manuals/5?x=1` のような query/hash 追加は検出しません。今回の退行リスクは「撮影 PWA 側 URLに戻る」「リンクが消える」「順序が変わる」が中心なので許容範囲です。href 完全固定をより強く主張するなら `pathname + search + hash` で比較してください。
- status 全数を `VIDEO_MANUAL_STATUS_LABELS` から取る設計は、フロント内の二重管理を避けられており妥当です。PHP enum とのドリフトを保証しない点も文書化されています。
- mutation A/B/C/D'/E/F の実測により、主要退行は検出できることが示されています。D が赤くならない理由も Lucide 側の自動 `aria-hidden` によるもので、テストの契約が「ソース行」ではなく「描画結果」である説明として正しいです。

`tests/Feature/Capture/CaptureReturnPathTest.php`: **APPROVE**

- 最弱 principal の `project_member` で、全 `VideoManualStatus::cases()` に対して capture 側と PC 詳細側の両方を叩いており、設計の片方向含意を現実的な範囲で固定できています。
- `assertOk()` だけでなく Inertia component まで見ているため、200 の別画面逃がしも検出できます。
- Factory 使用、戻り値型 `void`、個別 transaction 不使用で、PHPStan level 10 / テスト規約上の問題は見当たりません。

`docs/architecture.md`: **APPROVE**

- 実装済みの復路導線、status 述語を共有しない理由、テストが保証する範囲、保証しない範囲が明確に分かれています。
- 「構造的同一性はテストで証明しない」と明記しており、保証範囲の誇張はありません。
- T154 側の「含まない」記述も、実装済み事実と残る非保証に更新されていて整合しています。

**確認観点**

DTO / JsonResource パターン: アプリ側 API 応答変更なしのため違反なし。  
セキュリティ: 認可経路の変更なし。追加リンクの妥当性は Feature テストで最弱 principal 到達を固定。  
テスト: 通常検証一式と mutation 実測が添付されており、退行検出能力の説明も十分です。