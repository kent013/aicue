# 対応マトリクス: impl-review Round 1

全体判定 **APPROVED** (Critical 0 / Warning 0 / Suggestion 2)。

## [Suggestion] Browser テストの `click('メンバー')` / `click('通知を確認')` は文言変更に弱い
- 判断: **一部対応する (コメントで明示。locator は文言のまま維持)**
- 根拠:
  1. 詳細設計 施策 4 の「実装時の注意」で
     **「文言が変わった場合は UI を変えるのではなくテスト側を実装に合わせる」**と明記済み。
     テスト都合で UI に `data-testid` を足すのは、この設計判断に反する。
  2. サイドバーの nav item には `data-testid="nav-item-{href}"` が既にあるが、
     `SidebarNavItems` は **desktop / mobile の 2 箇所で描画される**ため
     testid が document 内で重複する。文言 locator から testid へ寄せると
     strict-mode 的な曖昧さを新たに持ち込むリスクがあり、robustness が上がるとは限らない。
  3. `Dashboard.svelte` の TextLink「通知を確認」には testId が無く、
     付与するには UI 側の変更が必要になる (上記 1 に抵触)。
- 対応内容: 両テストの locator 直下に
  「文言は実装 (`AppLayout.svelte` の `navItems` / `Dashboard.svelte` の TextLink) 由来。
  文言変更時は UI ではなく本テストを追随させる」というコメントを追記し、
  壊れたときの直し方をテスト内で自己完結させた。

## [Suggestion] `fs.readdir(..., { recursive: true, withFileTypes: true })` の `parentPath` 依存は Node 差異で壊れやすい
- 判断: **見送る (既に既存テストと同一実装であるため)**
- 根拠: 指摘は「既存テストの安定実装へ揃えるとより安全」だが、
  `listSourceFiles` は既存 `tests/js/architecture/svg-inline-allowlist.test.ts` の
  `listSvelteFiles` を拡張子だけ拡張した**同一実装**であり、
  `(entry as unknown as { parentPath?: string }).parentPath ?? dir` という
  Node 差異のフォールバックも同じ形で持っている。既に「既存の安定実装」に揃っている。
- 対応内容: 変更なし。1 テストのためのユーティリティを横断モジュール化しない方針
  (詳細設計 施策 7 の注記) も維持する。
