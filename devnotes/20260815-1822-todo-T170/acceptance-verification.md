# T170 受入確認の記録 (detailed-design.md §受入確認 の実施結果)

対象設計: `devnotes/20260815-1536-worktree-secret-file-mode/detailed-design.md`
実装 worktree: `.claude/worktrees/tasks/T170` (ブランチ `todo/T170`)

## V-0 テストファースト (失敗の確認)

契約テスト 18 ケースを先に書き、実装前に実行した。

```
{"tool":"pest","result":"failed","tests":18,"passed":6,"assertions":23,"failed":12}
```

- 落ちた 12 件のうち D-1〜D-7 / D-12 は終了コード **127** (`provision_secret_file: command not found`) で失敗。
  新関数がまだ無いことによる失敗であり、テストが実体を見ていることの確認になる。
- S-2 は主経路の呼び出し行が無いため失敗、S-3 は関数定義が無いため fail-closed で失敗、
  S-4 / S-5 は現行の `cp` 供給と `.env.example` 代替を検出して失敗。
- 通ってしまった 6 件 (D-8 / D-9 / D-10 / D-11 / D-13 / S-1) は
  **「非ゼロで落ちること」「現れないこと」を見る否定形**なので、関数不在でも成立する。
  これは否定形ケースの性質上避けられない (実装後に初めて意味を持つ)。

## V-1 実装と静的検査

| 検査 | 結果 |
|---|---|
| `composer test -- --filter=SetupWorktreeRuntimeFilesContractTest` | `passed` 18/18 (Codex Round 1 の Suggestion 反映後の assertions 42) |
| `composer test` (全体) | `passed` tests 4918 / passed 4916 / skipped 2 / failed 0 (Codex 合議後に再実行) |
| `composer phpstan` | No errors (level 10) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` / `pnpm typecheck` | 通過 |
| `pnpm test` | 136 files / 1501 tests passed |
| `pnpm build` | built |
| `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | 通過 (10 files / 106 tests) |
| `bash -n scripts/setup-worktree.sh` | 構文 OK |
| `shellcheck` | 未導入のため実施せず |

## V-2 実走確認 (実際に worktree を作る)

実装 worktree のスクリプト実体で、対象を main のチェックアウトにして実走した。

```
cd /workspace
bash /workspace/.claude/worktrees/tasks/T170/scripts/setup-worktree.sh T170-verify
```

- `[0/7]` から `[7/7]` まで到達し `health check: OK` / `✅ worktree 作成完了` (終了コード 0)。
- 設計の確認ブロックをそのまま実行し `rc=0`:

```
OK: 600 .env (親 mode=644)
OK: 600 storage/oauth-private.key (親 mode=600)
OK: 600 storage/oauth-public.key (親 mode=660)
OK: 600 .env.bughunt.local (親 mode=644)
OK: public/build 供給あり
OK: artisan / pest が worktree 内で動く
```

  **親が 644 / 660 の 3 本が供給先で 600 になっている** = 本 TODO の目的そのものが実測できた。
  0600 にしたことで読めなくなる利用者がいないことも `php artisan --version` /
  `vendor/bin/pest --version` の実行で確認した。
- `[timing]` は各段とも従来と同程度 (供給段は 0s)。
- 後片付け: `scripts/teardown-worktree.sh T170-verify` + `git branch -D todo/T170-verify` 実施済み。

## V-3 必須不足で停止することの確認

設計どおり**実走では行わない** (親の `.env` を退避する形は並行セッションに危険)。
契約は D-8 (required 経路) と S-5 (見本による代替の不在) が固定しており、どちらも green。

## V-4 マージ後の再確認

1. マージ前に `.setup.lock` が空いていることを `flock -n` で確認した (取得できた = 保持者なし)。
2. main へマージ (`32ce637`) した直後に、**main 側のスクリプト**で使い捨て worktree を作って完走を確認した。
   mode の確認は表示ではなく終了コードに反映させた (644 のままでも `stat` は成功してしまい、
   マージでの取り込み漏れを偽グリーンにするため):

```
cd /workspace && scripts/setup-worktree.sh merge-verify && \
  test "$(stat -c '%a' /workspace/.claude/worktrees/tasks/merge-verify/.env)" = 600
→ MERGE-VERIFY OK: .env=600 (rc=0)
```

3. 後片付け: `scripts/teardown-worktree.sh merge-verify` + `git branch -D todo/merge-verify` 実施済み。
   `git worktree list` は main のみ、`git status` は clean。

## 後片付けの結果

- `scripts/teardown-worktree.sh T170` + `git branch -d todo/T170` 実施済み (テスト DB 5 件を回収)。
- V-2 / V-4 の使い捨て worktree (`T170-verify` / `merge-verify`) とそのブランチ・テスト DB も回収済み。
