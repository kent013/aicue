# 変異検査ログ (T215 / 受け入れ条件 AC-8)

設計 §テストファースト計画 の変異 M1-M12 を 1 つずつ当て、色を実測し、
同じパッチの逆適用 (`git apply -R`) で戻したことの記録である。

- 変異パッチ: `devnotes/20260817-1309-todo-t215-job-deferral-gate-port/mutations/M*.patch`
- 当て方: `git apply <patch>` / 戻し方: `git apply -R <patch>` (広い取り消しは使わない)
- 判定は実行結果の JSON の `result` (`passed` = green / `failed` = red) で行う。
  **終了コードは判定に使えない** — `composer test -- --filter <名前>` は
  全件が通っても 1 を返す (並列実行の worker に 1 件も割り当たらない過程があるため。
  移植前から存在する既存テストを filter 指定しても同じ挙動になることを実測で確かめた)。

## 変異なしの基準

- `BASE gate rc=1 {"tool":"pest","result":"passed","tests":16,"passed":16,"assertions":132,"duration_ms":7720,"risky":5}`
- `BASE behavior rc=1 {"tool":"pest","result":"passed","tests":5,"passed":5,"assertions":35,"duration_ms":8869}`

## 変異ごとの実測

| # | 変異対象 | 実行した検査 | 期待 | 実測 | 逆適用で復元 | 戻した後の `git status --porcelain -- app/` |
|---|---|---|---|---|---|---|
| M1 | `app/Jobs/Manual/RunManualAnalysis.php` | JobDeferralTerminationGate | red | red | YES | 空 |
| M2 | `app/Jobs/Manual/RunManualAnalysis.php` | JobDeferralTerminationGate | red | red | YES | 空 |
| M3 | `app/Jobs/Manual/RunManualAnalysis.php` | JobDeferralTerminationGate | green | green | YES | 空 |
| M4 | `tests/Support/Queue/DeferringJobTemplate.php` | JobDeferralTerminationGate | red | red | YES | 空 |
| M5 | `tests/Support/Queue/DeferringJobTemplate.php` | JobDeferralTerminationGate | red | red | YES | 空 |
| M6 | `tests/Support/Queue/DeferringJobTemplate.php` | JobDeferralTerminationGate | red | red | YES | 空 |
| M7 | `tests/Support/Queue/JobDeferralContract.php` | JobDeferralTerminationGate | red | red | YES | 空 |
| M8 | `tests/Support/Queue/JobDeferralScanner.php` | JobDeferralTerminationGate | red | red | YES | 空 |
| M9 | `tests/Support/Queue/DeferringReleaseProbeJob.php` | DeferredRetryHorizon | red | red | YES | 空 |
| M10 | `tests/Support/Queue/DeferringThrowProbeJob.php` | DeferredRetryHorizon | red | red | YES | 空 |
| M11 | `tests/Feature/Queue/DeferredRetryHorizonTest.php` | DeferredRetryHorizon | red | red | YES | 空 |
| M12 | `tests/Feature/Queue/DeferredRetryHorizonTest.php` | DeferredRetryHorizon | red | red | YES | 空 |

## 期待の根拠 (どのケースが落ちるか)

- **M1** (red): E4 が `RunManualAnalysis <- …(middleware-new)` を出す
- **M2** (red): 秒数を書かない `new WithoutOverlapping` も E4 が拾う (spirux の申し送りへの回答)
- **M3** (green): 生成式に直結した `dontRelease()` は非退避 = fail-closed の境界が設計どおり
- **M4** (red): E10 (C4): 雛形の期限が加算 0 回になる
- **M5** (red): E10 (C2): 雛形が `$tries` を宣言する
- **M6** (red): E10 (C3): 雛形の `$maxExceptions` が 0 になる
- **M7** (red): E11 正例 2: 契約表から `WithoutOverlapping` が消えると検出できなくなる
- **M8** (red): `dontRelease()` 判定を常に真にすると生成式のマーカーが 1 つも残らなくなる。
  実測では **E11 (マーカー検出器の正例) と E12 (対比 3c / 3d) の 2 ケース**が同時に落ちた
  (設計では E12 だけを挙げていたが、正例側も同じ変異で落ちるのが正しい)
- **M9** (red): B3 前半: 期限が無くなると期限内でも回数で終端する
- **M10** (red): B4: `$maxExceptions` を 99 にすると 3 回目で終端しない
- **M11** (red): B4: ワーカーへ cache を渡さないと `$maxExceptions` が無言で効かなくなる
- **M12** (red): B1 対照: 期限の基準が push 時刻でなく固定値だと合わない

## 追記: `deferredHorizonRunWorker()` の cache 解決を 1 式へ直結した適合 (実装後半)

`composer test` (全数) で `CachePayloadPlainDataGateTest` (aicue 固有の既存 gate。移植元には
存在しない) が赤くなることが分かった。原因は `$cache = app('cache'); ... $cache->driver()` と
**変数を介して 2 行に分けた形**を、同 gate の受け手検出 (型宣言の直後に現れる変数だけを追跡する
簡易ヒューリスティック) が拾えないため (docblock の `@var` 型注釈はトークン解析の対象外)。

対応は 2 点:

1. `tests/Architecture/CachePayloadPlainDataGateTest.php` に新しい role
   `driver-handoff` (受け手を解決するだけで読み出し・書き込み・削除を一切行わない形) を追加し、
   自己検査 (検査 5b) に正負コントロールを足した (既存 3 role の判定は 1 行も変えていない)。
2. `tests/Feature/Queue/DeferredRetryHorizonTest.php` の `deferredHorizonRunWorker()` を
   `app('cache')->driver()` の 1 式直結へ書き換えた (振る舞いは不変。`$worker->setCache()` へ
   渡す値は同じ)。未使用になった `use ...Factory as CacheFactory;` import も削った。

これは施策 4 (振る舞い検査の移植) の許容差分を「docblock 2 箇所」から
「docblock 2 箇所 + cache 解決の 1 式直結化」へ広げる、実装中に判明した必要最小限の追加適合である
(`devnotes/.../verify-byte-parity.sh` の許容差分リストと `docs/architecture.md` の
「保証しないもの」記述は変えていない — 振る舞いそのものは変わらないため)。

再検証: M11 (cache を渡さない変異) を新コードに対して当て直し、B4 が期待どおり赤くなること、
`git apply -R` で戻した後に元の 5 件緑へ戻ることを実測した (このログの本文どおり)。
`CachePayloadPlainDataGateTest` (27 ケース) と `DeferredRetryHorizonTest` (5 ケース) を合わせて
32/32 緑、`composer test` 全数 (5652 件) も 0 failed を確認済み。
