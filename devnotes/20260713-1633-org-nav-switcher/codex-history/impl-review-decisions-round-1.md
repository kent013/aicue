# 対応マトリクス: impl-review Round 1

Codex 判定: **CHANGES_REQUESTED**

## [Critical] Escape の「トリガーへ focus 復帰」がテストで固定されていない / 発火対象が実装経路と不一致
- 判断: **対応する**
- 根拠: S3 の a11y 要件（Escape で閉じてトリガーへ focus 復帰）が回帰固定されていないのは正当な指摘。実装は open 中のみ `document` に keydown を張る方式のため、テストも `document` を発火対象にするのが実装経路と一致し意図が明確になる。
- 対応内容: `OrganizationSwitcher.test.ts` の Escape ケースを次のとおり修正。
  - 発火を `fireEvent.keyDown(document, { key: "Escape" })` に変更（実装の document リスナと一致）。
  - `expect(screen.getByTestId("org-switcher-trigger")).toHaveFocus()` を追加し focus 復帰を固定。
  - 再実行で 490 passed を確認。

## [Warning] native `<button>` 採用が詳細設計 S3「内部は atoms(Button) を合成」と不一致
- 判断: **対応する（差分理由を明記して整合）**
- 根拠: Button atom は variant スタイル（枠線・padding ramp）を強制し、id/aria-expanded/aria-controls を要する disclosure トリガーや menu-item 表現には過剰・不適合。native button が正しい設計判断。ただし設計書との差分は明記が必要という指摘は妥当。
- 対応内容: `OrganizationSwitcher.svelte` の先頭ドキュメントコメントに「設計との差分（意図的）」節を追加し、native button 採用理由・DS token は同一である旨・Lucide のみ/SVG 直書きなし維持を明記（コード内に恒久記録）。本 decisions ファイルにも記録。

## [Suggestion] `aria-controls` が閉状態でも参照される点の補助属性運用
- 判断: **見送る**
- 根拠: `aria-controls` は disclosure の標準実装であり、閉状態で参照先が非実在でも主要 SR で実害はない（WAI-ARIA APG の disclosure パターンに準拠）。追加の補助属性はオーバーエンジニアリング。

## [Suggestion] 管理リンク(Link) の onclick={close} で閉じることのテスト追加
- 判断: **見送る**
- 根拠: 実 `Link`（原物）を click すると Inertia 内部の `router.visit` が発火するが、本テストは `router.post` のみ mock しているため `router.visit` 未定義でテストが不安定化する。close 自体は switch ボタン経路（router.post 呼出後 open=false）と Escape/outside/focusout で十分固定済み。安定性を優先し追加しない。
