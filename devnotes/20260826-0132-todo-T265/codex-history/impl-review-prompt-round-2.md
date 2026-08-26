# Round 2: 検証で判明した追加対応の追差分レビュー

Round 1 の APPROVED 後、フルスイート検証で `TemplateDivergenceFingerprintTest` F10 が赤になった。
原因: 詳細設計の「乖離台帳の確認」節は `coverage/README.md` ほかが指紋母集団・採用時債務一覧の
どちらにも無いと記載していたが、現実には README.md は指紋母集団 (281 件) と採用時債務一覧
(142 件) の両方に在った (correlate.py / test_correlate.py は既存登録 D14 ref-ok の対象パスに
既に在るため F9 は緑)。債務は「採用時点の凍結された観測」なので変更したまま残せない。

対応 (3 択のうち「意図的逸脱として登録し債務から削る」を採用):
- 採用時債務一覧から README.md の行を削除 (142 → 141 件)
- LedgerPins::ADOPTION_DEBT_COUNT を 142 → 141 に同じ変更で減算
- 既存登録 D14 ref-ok (実行済み route 記録の別実装。correlate.py / test_correlate.py を既に対象と
  する) の対象パスへ README.md を追加し、説明・保証機構・再判定条件を今回の t2 追従に合わせて追記
  (新規登録は起こさないため DIVERGENCE_ENTRY_COUNT は 54 のまま)

検証結果:
- TemplateDivergenceFingerprintTest: 15/15 緑 (F10/F11/F12 含む)
- TemplateDivergence* + BughuntCoverage* Architecture テスト: 20/20 緑
- composer phpstan: No errors
- BughuntSelfTestExecutionTest のフルスイート失敗 2 件は main 側 (本差分なし) で同一署名
  ([y6b] 停止不能 group なのに rc=0 / pidfile 削除、shard-8 worker 所有確認不能) の再現を確認済み
  = 本差分と無関係の既存事象
- フルスイート再実行は現在進行中 (F10 の解消と「既知 2 系統以外ぜんぶ緑」の最終確認)

追差分は以下。この対応の妥当性 (特に D14 への追加が新規登録より適切か、記載の正確性) をレビューし、
全体判定 (APPROVED / CHANGES_REQUESTED) を出すこと。

## 追差分 (git diff)

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index d8e559bf..0638befe 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -731,10 +731,10 @@ ## D14 実行した route の記録をアプリ側の観測器で採る (退避
 
 | 行 | 内容 |
 |---|---|
-| 対象パス | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `bootstrap/app.php` / `config/bughunt.php` / `.claude/skills/app-bug-hunt/coverage/build_executed.py` / `.claude/skills/app-bug-hunt/coverage/correlate.py` / `.claude/skills/app-bug-hunt/coverage/test_correlate.py` |
-| 業務要件起因の説明 | 記録が採れていないことと本当に叩けていないことを取り違えると操作到達の一覧そのものが嘘になるため、遮断 middleware の内側で 1 要求 1 行を機械記録する。併せて、割当列が複数値になった目録を照合器が取り違えずに読む |
-| 揃え続ける不変条件と保証機構 | 主入力が揃わない走行は成功にしない。`BughuntExecutedRouteOrderingTest` が記録器の位置を、集約と照合の 2 つの Python ツールが終了コード 3 を担う。割当セルの分解は `test_correlate.py` が値域の両方向で固定する |
-| 再判定の条件 | 家系の正典が退避 → 正規化 → route 名解決の 3 段へ揃える裁定を出したとき / web グループ外の面を分母に載せるとき / 家系の正典が割当列の分解を実装したとき |
+| 対象パス | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `bootstrap/app.php` / `config/bughunt.php` / `.claude/skills/app-bug-hunt/coverage/build_executed.py` / `.claude/skills/app-bug-hunt/coverage/correlate.py` / `.claude/skills/app-bug-hunt/coverage/test_correlate.py` / `.claude/skills/app-bug-hunt/coverage/README.md` |
+| 業務要件起因の説明 | 記録が採れていないことと本当に叩けていないことを取り違えると操作到達の一覧そのものが嘘になるため、遮断 middleware の内側で 1 要求 1 行を機械記録する。併せて、割当列が複数値になった目録を照合器が取り違えずに読む。この別実装の入出力契約 (主入力 6 点と終了コードの写像) の正本は `coverage/README.md` であり、README も本登録の対象パスとして一緒に育てる (2026-08-26、家系正典 t2 追従の自己テスト追記に合わせて債務一覧から本登録へ移した) |
+| 揃え続ける不変条件と保証機構 | 主入力が揃わない走行は成功にしない。`BughuntExecutedRouteOrderingTest` が記録器の位置を、集約と照合の 2 つの Python ツールが終了コード 3 を担う。割当セルの分解は `test_correlate.py` が値域の両方向で固定する。主入力 6 点の欠落は `test_correlate.py` が 1 点ずつ pin し (非 0 終了 + worklist 無出力)、実ルーター経路 (`route:list` fallback) の実登録・実走も同テストが固定する (家系正典 t2 の aicue 形) |
+| 再判定の条件 | 家系の正典が退避 → 正規化 → route 名解決の 3 段へ揃える裁定を出したとき / web グループ外の面を分母に載せるとき / 家系の正典が割当列の分解を実装したとき / 家系の正典が t3 以降へ進んだとき |
 | 決めた日 | 2026-08-15 |
 | 決めた人 | 開発者 |
 | 根拠 | T164 |
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index 38e9d96d..ec1021f9 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -31,7 +31,7 @@ private function __construct() {}
      *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
      *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
      */
-    public const int ADOPTION_DEBT_COUNT = 142;
+    public const int ADOPTION_DEBT_COUNT = 141;
 
     /**
      * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
diff --git a/tests/Support/TemplateDivergence/adoption-debt.tsv b/tests/Support/TemplateDivergence/adoption-debt.tsv
index 724fe5be..a554958c 100644
--- a/tests/Support/TemplateDivergence/adoption-debt.tsv
+++ b/tests/Support/TemplateDivergence/adoption-debt.tsv
@@ -1,6 +1,5 @@
 # template_ledger_commit=a078806b0574518ddc64966f60f7d536b1338b2f
 .claude/agents/bughunt-shard.md	85c2a7b649178200415baa06768940aebb7d9ffce8f615c23da856dbec8922cf
-.claude/skills/app-bug-hunt/coverage/README.md	644e649a15d603d9ffd60f708fe6ce444ff9e83fd13c15264c78514943872d1f
 .claude/skills/app-bug-hunt/coverage/fixtures/executed.sample.json	360f716d2f09e68d63963c7bac2254c6c2c5a91329a292a9b2ce9dff5cc79fc3
 .claude/skills/app-bug-hunt/coverage/merge_pcov.py	58188a2395e3e6217e8a7c529747290a6b320c6a3258f9f4902ad2cc83fbe667
 .claude/skills/app-bug-hunt/coverage/test_merge_pcov.py	af796fa2dc20752f5022543cae3029de5a71f2b3a0474a9d8aafc155935388ab
```
