# 実装レビュー Round 3 の対応マトリクス (Claude 側)

| # | 区分 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Warning | `program.ts` の冒頭 docblock に、撤回した強い因果関係 (「ルート設定で読むと `any` へ落ちて候補が消える」) が事実として残っている | **対応する** | `docs/architecture.md` と同じ言い方へ揃えた —「その**恐れ**がある。**ただしこの解決の失敗は現物では観測されていない**。したがって偽陰性を作らない側の**予防**であって、現に偽陰性が起きていたことの証拠ではない」 |
| 2 | Warning | `resolveOwner()` の単体分岐は固定できているが、**本番の結線**が `listPackageDirectories()` の全結果を渡すことまでは固定できていない (呼び出し側で `.filter(hasPackageTsconfig)` へ回帰しても検出できない) | **対応する** | 結線を純関数 `planOwners()` へまとめた (`packageDirs` = 全パッケージ / `programOwners` = `<root>` + tsconfig を持つパッケージ)。`createMirrorPrograms()` は**これだけ**を使う。見本の木で `planOwners(sandbox)` を直接呼び、`packageDirs` に tsconfig 無しのパッケージが**残る**こと・`programOwners` から**外れる**こと・その組で `resolveOwner()` が例外になることを固定した。呼び出し側で `packageDirs` を絞る回帰を入れるとこの試験が赤くなる。併せて「計画と実際に組み上がった program が食い違ったら例外」も `createMirrorPrograms()` に置いた |
| 3 | Warning | `composer test` のクリーンなフル実行結果が未提示 | **対応する** | 他のレーンを止めて再実行した。結果と、残った 1 件の扱いは下記 |

## `composer test` のクリーンなフル実行の結果

```
tests=7835 passed=7832 skipped=2 risky=5
errors=1: Tests\Architecture\BughuntSelfTestExecutionTest
  「bug-hunt harness の self-test が通ること」
  The process "'bash' 'scripts/bug-hunt-shard.sh' 'self-test'" exceeded the timeout of 120 seconds.
```

**これは本変更と無関係な、実行環境の容量に依存する時間切れである**と判断している。根拠:

- 本変更に **PHP の実行時コードの差分は 1 行も無い**
  (触った PHP は `tests/Support/TemplateDivergence/LedgerPins.php` の件数定数 2 つと
  `adoption-debt.tsv` の 1 行削除だけで、bug-hunt の経路には一切関与しない)
- **変更前の main で同じ検査を単独実行すると 154.8 秒かかる**。内側の
  `scripts/bug-hunt-shard.sh self-test` に 120 秒の上限が掛かっており、
  `--parallel --processes=4` で CPU を分け合うとこの上限を越える
- 同じ worktree で**直列に**実行すると 3/3 green になる
- 変更前の main でのフル実行を基準として取り直している (結果は最終報告に載せる)
