# 実装メモ: T156 コピー失敗時に手段が残らない問題 (bug-hunt F-3-01)

設計: `devnotes/20260812-0845-code-snippet-copy-fallback/`(概念 Round 1 APPROVED /
詳細 Round 4 APPROVED)。実装レビュー: `impl-review-round-1.md`(Round 1 APPROVED)。

## 発端の訂正 (これが本 TODO の出発点)

bug-hunt run 20260811-003230 の F-3-01 は「headless Chromium が clipboard-write を
許可しないための検査環境要因」と説明されていたが、**その説明は実測で反証された**。
同じ `@playwright/cli` の headless Chromium で `CodeSnippet` の `copy()` と同一ロジックを
`http://127.0.0.1` 上に置いて叩いた結果:

| 観測項目 | 結果 |
|---|---|
| `navigator.permissions.query({name:'clipboard-write'})` | granted |
| `window.isSecureContext` | true |
| ボタン押下 | **「コピー完了」= 成功** |
| `navigator.clipboard.readText()` | `NotAllowedError` (**read だけ**拒否) |

アプリ側も `Permissions-Policy` に `clipboard-write` を列挙していない
(未列挙 directive の既定 allowlist は `self`)。よって**失敗の原因は未特定のままである**。
本 TODO は原因を追わず、「**失敗したときに手段が残らない**」という別レイヤーの問題を直した。

## 施策と結果

| # | 施策 | 結果 |
|---|---|---|
| 1 | 失敗経路の段階化 (選択 → legacy fallback → 案内) と状態の 4 値化 | 完了 |
| 2 | 契約 16 件をテストで固定 (既存 2 本は期待値更新・削除なし) | 完了 (ファイル全体 19 本) |

**サーバ側 0 行**。props も呼び出し 7 箇所も無変更。

## fail 先行

テストだけを先に置いた時点で **19 件中 15 件が赤** (残る 4 件は既存の描画・成功系)。

## mutation の実測 (予測との対比)

| # | mutation | 予測 | 実測 |
|---|---|---|---|
| M1 | `selectCode()` が常に false | 最低でも契約 3 | 一致 (契約 1/3/5/11/16 の 5 件。契約 14 は予測どおり赤化せず) |
| M2 | `tryLegacyCopy()` の try/catch 除去 | 契約 7 のみ | 一致 |
| M4 | `copy()` 冒頭の `status = "idle"` 削除 | 契約 9 のみ | 一致 |
| M5 | `copy()` 冒頭の `clearOwnSelection()` 削除 | 契約 9 | 一致 (契約 8・9) |
| M6 | 案内にも 2 秒タイマー | 契約 4 | 一致 |
| M7 | `manual-unselected` の文面を同一化 | 契約 14 | 一致 |
| M8 | `execCommand` の戻り値を無視 | 契約 3 | 一致 (8 件) |
| M9 | `attemptId` の比較削除 | 契約 10 | 一致 (契約 10・15) |
| M10 | `onDestroy` の `attemptId++` 削除 | 契約 15 | 一致 |
| M11 | 所有判定を完全に外す | 契約 12・13 | 一致 |
| M12 | 所有判定を `codeEl.contains(...)` へ弱める | 契約 13 のみ | 一致 |
| M13 | legacy 成功時の `clearOwnSelection()` 削除 | 契約 2 | 一致 |
| M14 | `onDestroy` の `clearOwnSelection()` 削除 | 契約 11 | **不一致 → 契約補強後に一致** |
| M15 | `clearOwnSelection()` の try/catch 削除 | 契約 16 | 一致 |

(旧 M3「`typeof` ガードだけ外す」は設計段階で削除済み。try/catch が拾うため観測できない)

### M14 が最初赤くならなかった原因と対処

契約 11 は結果 (`selectedText()` が空) だけを見ていたが、**jsdom は unmount による DOM 削除で
live range を自分で畳む**ため、破棄時の解除を実装から外しても空になる。つまり詳細設計
Round 2 の Critical 修正 (`onDestroy` での解除) は**どのテストにも守られていなかった**。
契約 11 に「`removeAllRanges` が呼ばれたこと」、契約 12 に「呼ばれないこと」の assert を
追加し、**M14 / M11 の再実測で赤化を確認**した。

### 破棄時の所有判定が効く範囲 (実測)

実装レビューの助言で「同じ code 内の部分選択を unmount でも奪わない」契約を追加しようとしたが、
**赤くなった**。jsdom で直接測ったところ、`host.remove()` の後に所有 range と利用者の部分 range が
**両方とも `(BODY, 0)` の同一点へ畳まれる**:

| | 削除前 | 削除後 |
|---|---|---|
| 所有 range | `(CODE,0) - (CODE,1)` | `(BODY,0) - (BODY,0)` |
| 利用者の部分 range | `(#text,0) - (#text,3)` | `(BODY,0) - (BODY,0)` |

よって破棄時に両者は区別できない。ただし**区別する必要も無い** — subtree の内側にあった選択は
削除そのもので失われており、そこで `removeAllRanges()` を呼んでも既に畳まれた選択を消すだけである。
**破棄時の所有判定が意味を持つのは、選択が削除される subtree の外にあるとき (契約 12) だけ**。

## 設計からの乖離 3 点 (すべてテスト側)

1. **契約 13 の観測点を unmount から「再試行」へ変更**。上記の live range 折り畳みにより、
   unmount 後の選択は実装の正否と無関係に空になるため。M12 で赤化を実測済み。
2. **`afterEach` の順序を変更** (`vi.restoreAllMocks()` を Selection の後始末より前へ)。
   契約 16 の throwing stub を張ったままだと、後始末の `removeAllRanges()` 自体が
   その stub を踏んでテストが落ちるため。
3. **契約 11 / 12 に `removeAllRanges` の呼び出し有無の assert を追加**。M14 の実測による。

## 検証コマンド (すべて worktree 内)

- `pnpm test`: 1343 passed / `composer test`: 4511 passed・2 skipped
- `composer phpstan` (level 10): No errors / `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build` / packages 3 種: 緑

## 保証しないもの (誇張しない)

- **bug-hunt が観測した失敗の原因は特定していない**。本 TODO は受け皿を作っただけで、
  「もう失敗しなくなる」とは言わない。原因特定には bughunt 環境での再走が要る。
- **`document.execCommand("copy")` が成功することを保証しない**。フォーカス起因なら同じく失敗する。
  確実に効くのは最終段 (選択 + 案内) だけ。
- **実ブラウザでの実挙動は確認していない**。Vitest は jsdom の DOM 契約と分岐のみ。
  iOS Safari で選択範囲からコピーメニューへ到達できるかも未確認 (Browser lane は追加していない)。
- **`addRange()` が例外を投げると既存選択だけが失われる**窓は塞いでいない (受容)。
- **契約 11 / 12 は `Selection.removeAllRanges` の呼び出しに依存する**。将来 `Selection.empty()` 等へ
  実装を変えるなら、テスト契約の更新が要る。
