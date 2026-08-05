# 既知事項: global-test-lock.sh の sub-millisecond 子プロセス race

**発見**: T104 (CI レーン統合) の施策 6 実装中、`scripts/run-browser-test.contract.test.ts` の
sandbox 実走が理由不明で exit 1 になることから判明。

## 事象

`scripts/global-test-lock.sh` の `global_test_lock_run()` は、起動した子の
専用プロセスグループを best-effort 検証する:

```bash
pgid="$(ps -o pgid= -p "${_GTL_CHILD_PID}" 2>/dev/null | tr -d ' ')"
if [ -n "${pgid}" ] && [ "${pgid}" != "${_GTL_CHILD_PID}" ]; then
    _gtl_die "専用プロセスグループを作れなかった ..."
fi
```

コメントは「空 = 既に終了 (race) なので異常ではない」と明示しており、`-n` チェックで
空を許容する意図になっている。しかし **lane スクリプトは `set -euo pipefail` で動く**ため:

- 子が probe 前に終了すると `ps -o pgid= -p <dead pid>` が **exit 1** を返す
- `pipefail` によりパイプライン全体が exit 1
- **代入 `pgid="$(...)"` の終了ステータスがコマンド置換の終了ステータスになる**
- `set -e` により **その場でシェルが終了する**

つまり `-n` による race 許容は、`set -e` によって到達前に潰されている。

## 影響

- **実運用では顕在化しない**: ロック配下で走るのは `php artisan config:clear` /
  `php scripts/ci/ensure-test-db.php` / `vendor/bin/pest` で、いずれもミリ秒では終わらない。
- **顕在化するのはスタブ実行時**: 契約テストが `exit 0` するだけのスタブを使うと必ず踏む。
  T104 では sandbox スタブに `sleep 0.1` を入れて回避した
  (`scripts/run-browser-test.contract.test.ts` にコメントで理由を明記)。
- 失敗モードは **偽赤** (レーンが走らずに落ちる) であって偽グリーンではない。

## 修正案

`ps` の失敗を代入から切り離し、意図どおり「空 = race として許容」を成立させる:

```bash
pgid=""
pgid="$(ps -o pgid= -p "${_GTL_CHILD_PID}" 2>/dev/null | tr -d ' ')" || pgid=""
```

## なぜ T104 で直さなかったか

`scripts/global-test-lock.sh` は T099 のグローバルロック契約の正本ファイルであり、
T104 (CI レーン統合) のスコープ外。契約ファイルへの変更は
`scripts/verify-global-test-lock.sh` (層 1・C01〜C24) と
`tests/Architecture/GlobalTestLockInventoryTest.php` (層 2) の再検証を伴うため、
独立した TODO として設計・実装するのが適切と判断した。

**推奨**: TODO 化して追跡すること (impl-review R2 [Suggestion])。
