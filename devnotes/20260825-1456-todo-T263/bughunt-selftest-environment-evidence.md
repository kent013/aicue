# フルスイートで観測した T263 非接触領域の flake の切り分け記録

本メモは T263 実装中のフルスイート実行で観測した、**T263 の差分と無関係な領域**の
テスト失敗 2 種の切り分け記録である。いずれも修正は本タスクのスコープ外 (事実の記録のみ)。

## 最終ツリーでのフルスイート実測 (3 回 — 同一差分で失敗集合が毎回変わる)

| 実行 | 結果 | 失敗 |
|---|---|---|
| full 2 回目 (実装完了直後) | 7857 passed / **0 failed** | なし (全緑の実績あり) |
| full 3 回目 | 7855 passed / 2 failed | EmailPromotion×2 (今回のみ)、Bughunt は緑 |
| full 4 回目 | 7855 passed / 3 failed | EmailPromotion×2 + Bughunt×1 |

同一差分で失敗集合が実行ごとに入れ替わる = どちらの系統も決定的な回帰ではない。
検査の弱体化 (skip 追加・検査緩和) は行わず、事実の記録のみで残工程へ進む。

## 事象 2: EmailPromotionTest の順序依存 flake (Livewire アセット注入)

```
$ cd .claude/worktrees/tasks/T263 && composer test -- --filter="EmailPromotionTest"
result: passed, 43 passed / 0 failed   # 単独実行は 2 回とも全緑
```

- 本テストは main に既存 (T253 由来) で T263 の新規ではない
- 単独緑 + 並列フルスイートでのみ赤 = 並列 worker の実行順序依存
  (先行テストが Livewire を起動した worker では全ページ応答へアセットが注入される) の既存 flake

最終ツリーでのフルスイート実行 (7861 tests) で `Tests\Feature\Auth\EmailPromotionTest` の
2 ケース (確認画面が外部リソースを読み込まない / トークン有効・無効で応答不変) が失敗した。
失敗の実体は素の HTML 応答へ `<!-- Livewire Styles -->` と `<script>` が注入されたこと。

- T263 はメール昇格 (EmailPromotion) 系に一切触れていない
- **worktree 内での単独実行では 43/43 passed** (2 回確認) — 並列プロセス内の実行順序で
  Livewire (Filament 依存) のアセット自動注入が有効化される順序依存 flake
- 同一ツリーの直前のフルスイート実行ではこの 2 件は緑だった (再現が確率的)

## 事象 1: BughuntSelfTestExecutionTest の環境依存失敗

日時: 2026-08-25 07:00-07:20 JST 頃 (ホスト load average ~9.2、13 日連続稼働)

T263 worktree でのフルスイート実行 (`composer test`) で
`Tests\Architecture\BughuntSelfTestExecutionTest` の 2 ケースが失敗した:

- `bug-hunt harness の self-test が通ること`
- `self-test が外から与えた BUGHUNT_SANDBOX を尊重し削除しないこと`

失敗の実体は `scripts/bug-hunt-shard.sh` self-test の (y6b) — 「TERM/KILL を no-op 化した
停止不能 group」で `stop_shard_workers` が rc=0 を返し pidfile を削除した — および
「pid は存在するが所有確認できない」系のエラー出力:

```
FAIL: [y6b] 停止不能 group なのに rc=0
FAIL: [y6b] 停止失敗時に pidfile が削除された (追跡情報喪失)
error: shard-8 worker (database-media) pid=1951437 は存在するが所有確認できない — kill せず pidfile 保持
```

## T263 変更起因ではないことの根拠

1. **T263 の差分は bughunt ハーネスに一切触れていない**。
   `git diff HEAD --stat` の対象は app/ 6 ファイル + tests/ 8 ファイル + devnotes のみで、
   scripts/ への変更は 0 件 (bug-hunt-shard.sh は不変)。
2. **変更を含まない main チェックアウト (/workspace、HEAD=66ca3748) で同一失敗が再現する**。
   実測 (同時刻帯に 2 回連続):

   ```
   $ composer test -- --filter="BughuntSelfTestExecutionTest"   # main / 1 回目
   result: failed, failed: 2
     FAIL: [y6b] 停止不能 group なのに rc=0
     FAIL: [y6b] 停止失敗時に pidfile が削除された (追跡情報喪失)
     error: shard-8 worker (database-media) pid=2425449 は存在するが所有確認できない — kill せず pidfile 保持

   $ composer test -- --filter="BughuntSelfTestExecutionTest"   # main / 2 回目
   result: failed, failed: 1
     error: shard-8 worker (database-media) pid=2742034 は存在するが所有確認できない — kill せず pidfile 保持
   ```

   失敗数・pid が実行ごとに変わる = 決定的な回帰ではなくホスト状態 (プロセス表・負荷) に
   依存する事象。
3. **同一 worktree・同一差分で同日中の先行フルスイート 2 回は本テストを含め緑だった**
   (1 回目: 7859 tests / 1 failed = AccountDeletionPathGateTest の目録未登録のみ。
   2 回目: 7857 passed / 0 failed)。差分は変わらず環境だけが変わって赤くなった。

## 判断

- self-test の stub (`setsid sleep 30` の process group) の生存/所有確認が、
  高負荷ホストのプロセス状態と干渉して誤判定する環境依存の flake と切り分ける。
- bug-hunt ハーネスは T263 のスコープ外 (AGENTS.md: bug-hunt はオプトイン基盤)。
  修正は別 TODO の議題であり、本タスクでは事実の記録のみ行う。
