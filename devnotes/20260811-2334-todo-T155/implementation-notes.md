# 実装メモ: T155 撮影 PWA からマニュアル詳細への戻り導線

設計: `devnotes/20260811-2334-capture-return-path/`(概念 Round 1 APPROVED /
詳細 Round 4 APPROVED)。実装レビュー: `impl-review-round-1.md`(Round 1 APPROVED)。

## 施策と結果

| # | 施策 | 結果 |
|---|---|---|
| 1 | `Capture/Show` ヘッダーに PC 詳細への復路リンクを 1 本追加 | 完了 (12 行) |
| 2 | Vitest で DOM 契約を固定 (href / accessible name / DOM 順 / 全 status) | 完了 (7 ケース) |
| 3 | Feature テストで最弱 principal の復路到達を固定 | 完了 (5 ケース = 全 status) |
| 4 | `docs/architecture.md` 同期 (導線契約の追記 / T154 の「含まない」解消) | 完了 |

**アプリ実装コード (route / controller / DTO / policy / Service) の変更は 0 行**。

## fail 先行

施策 2 のテストだけを先に置いた時点で **新規 7 ケースすべてが赤** (リンク不在) であることを
実測してから施策 1 を実装した。

## mutation の実測 (設計の予測との対比)

| # | mutation | 予測 | 実測 |
|---|---|---|---|
| A | 復路リンクを `{#if isCaptureNavigable(manual.status)}` で包む | 非 navigable な 3 status のケースだけ赤 | **一致** (3 failed / 17 passed) |
| B | href を `/app/projects/...` (撮影 PWA 側) に戻す | 1 本目だけ赤 | **一致** (1 failed / 19 passed) |
| C | 新リンクを既存リンクの前へ移す | 2 本目 (DOM 順) だけ赤 | **一致** (1 failed / 19 passed) |
| D | アイコンの `aria-hidden="true"` を外す | 1 本目の svg 属性 assert が赤 | **不一致 — 赤くならなかった** (20 passed) |
| D' | アイコンに `aria-label="書籍"` を付ける | (設計に無い追加) | 7 ケース赤 |
| E | `VideoManualController::show()` の `Gate::authorize('view')` → `'update'` | PC 側 assert が全 status で赤 | **一致** (5 failed。403) |
| F | `Inertia::render('Manuals/Show')` → `'Manuals/Edit'` (1 語) | `assertOk()` は通り component assert だけ赤 | **一致** (5 failed) |

### mutation D が赤化しなかった原因 (実コードで特定)

`@lucide/svelte@1.17.0` の `dist/Icon.svelte` が

```svelte
{...!children && !hasA11yProp(props) && { 'aria-hidden': 'true' }}
```

を持ち、**a11y prop も children も無いとき `aria-hidden="true"` を自動付与する**。
したがって明示している `aria-hidden="true"` は**冗長**で、消しても描画結果が変わらない =
テストが固定しているのは「我々のソース行」ではなく**「svg が aria-hidden であるという結果」**である。

代替として mutation D' (アイコンに `aria-label` を付ける = `hasA11yProp` が真になり自動付与が消える)
を実施し、accessible name が汚れて **7 ケースが赤くなる**ことを実測した。
明示指定は既存の `ArrowLeft` と同形で意図を残すため残置している。

## 設計からの乖離 2 点 (実装時に判明。いずれもテストの書き方のみ)

1. `expect(link).toHaveAttribute("href", "/projects/1/manuals/5")` は**失敗する**。
   Inertia の `Link` が href を絶対 URL (`http://localhost:3000/...`) へ正規化して描画するため。
   origin に依存させないため `new URL(...)` の `pathname + search + hash` で比較する
   `pathOf()` に変更した (query / hash が付く退行も落ちる)。mutation B の赤化は変更後に再実測済み。
2. `getByRole("link", { name: "…", exact: true })` は **`pnpm typecheck` が落ちる**
   (`ByRoleOptions` に `exact` は無い)。`name` に文字列を渡すと既定で完全一致になるため
   オプションごと削除した。mutation D' が削除後も赤くなることを再実測済み。

## 検証コマンド (すべて worktree 内)

- `composer test`: 4513 tests / 4511 passed / 2 skipped / 19466 assertions
- `pnpm test`: 130 files / 1329 tests passed
- `composer phpstan` (level 10): No errors (897 files)
- `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm build`: 緑
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106 tests): 緑

## 保証しないもの (docs/architecture.md §撮影 PWA の運用契約 が正本)

- standalone PWA で同一窓に留まること / 狭幅ヘッダーの実レイアウト / 実ブラウザでの実タップ遷移
- 到達条件の**構造的同一性** (固定したのは最弱 principal + Factory 既定データでの到達可否と着地 component)
- フロント `VideoManualStatus` union と PHP enum のドリフト検出 (既存の「当面手動確認」のまま)
