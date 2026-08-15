全体判定: **CHANGES_REQUESTED**

主な理由は、導入スクリプトの権限判定が設計上の契約と矛盾していること、`set -euo pipefail` 下で証跡退避失敗がテスト結果を上書きしうること、導入経路 gate / 契約テストに false negative の穴が残っていることです。

## 施策 1: 導入スクリプトの新設

判定: **REQUEST_CHANGES**

- [Critical] `provision()` が `deps=satisfied` / `darwin` / `unsupported` でも常に `detect_privilege()` を呼ぶ設計になっています。これは「要求がある場合だけ権限を見る」という説明、および施策 6 の S1「deps satisfied では sudo 未起動」と矛盾します。
  修正案: `detect_privilege()` は Linux かつ `deps=missing` の場合だけ呼ぶ。

```bash
privilege="none"
if [ "${os}" = "linux" ] && [ "${deps}" = "missing" ]; then
    privilege="$(detect_privilege)"
fi
```

- [Warning] `BROWSER_TARGETS="chromium webkit"` を意図的に未クォート展開する方針は理解できますが、shell script の安全性としては配列の方が堅いです。
  修正案: 可能なら `BROWSER_TARGETS=(chromium webkit)` にして `"${BROWSER_TARGETS[@]}"` で渡す。文字列契約テストも配列形式へ更新する。文字列のまま行くなら、全展開箇所に `shellcheck disable=SC2086` と理由を必ず付ける。

## 施策 2: レーン起動時の事前確認と証跡退避

判定: **REQUEST_CHANGES**

- [Warning] `collect_lane_artifacts()` の `cp -R` がそのままだと、証跡退避の失敗で `set -e` によりスクリプト全体が落ち、Browser テスト本体の終了コードを上書きしえます。リスク欄では「警告して続行」と書かれていますが、提示コードはそうなっていません。
  修正案:

```bash
if ! cp -R "${SCREENSHOT_DIR}/." "${ARTIFACT_DIR}/${lane}/"; then
    echo "WARNING: Browser lane artifact copy failed for ${lane}" >&2
    return 0
fi
```

- [Warning] C11 は「失敗レーンでも退避される」だけでなく、「Chromium の証跡が WebKit 起動後も残る」ことまで見るべきです。この施策の本質は pest-plugin-browser の次回起動 cleanup から前レーン証跡を守ることです。
  修正案: sandbox test で 2 レーンを走らせ、1 レーン目が `x.png`、2 レーン目が別ファイルを書いた後、`storage/browser-test-artifacts/chromium/x.png` が残ることを確認する。

## 施策 3: CI

判定: **APPROVE**

- [Suggestion] `actions/cache@v4` / `actions/upload-artifact@v4` の実在確認は設計書にも書かれている通り、実装時に必ず行ってください。gate は `@version` を落として見るため、版の typo はテストでは拾えません。

## 施策 4: CI workflow gate

判定: **APPROVE**

- [Suggestion] W18 は `key` に `runner.os` / `runner.arch` / `hashFiles('pnpm-lock.yaml')` がすべて含まれることを明示的に見ると、設計意図との対応がより強くなります。現状の `contains("hashFiles(...)")` だけでも最低限は満たしています。

## 施策 5: 導入経路の一元化 gate

判定: **REQUEST_CHANGES**

- [Warning] `composer.json` の `scripts` は文字列だけでなく配列形式も取りえます。文字列だけを見る実装だと、`"test": ["pnpm exec playwright install chromium"]` のような経路を見逃します。
  修正案: `scripts` 配下の値を `string | list<string>` として正規化してから走査する。想定外型は違反または明示 fail に倒す。

- [Warning] token が `'playwright install'` の単純部分一致だと、`playwright   install` などの空白差分を見逃します。
  修正案: コメント除去後の実行行に対して `\bplaywright\s+install\b` 相当の正規表現で検出する。

- [Warning] `expect(is_executable($path))->toBeTrue()` を入れるなら、新規 shell script の git executable bit 設定まで実装手順に明記してください。CI では `bash scripts/...` で動いても、この Architecture テストだけが落ちます。
  修正案: 実装手順に `chmod +x scripts/setup-browser-testing.sh` を含める、または executable bit を契約から外す。

## 施策 6: 契約テスト

判定: **REQUEST_CHANGES**

- [Critical] S1「deps satisfied / sudo 可 / sudo 未起動」は、施策 1 の提示実装と矛盾しています。現状のままだと契約テストを正しく書いた瞬間に赤になります。
  修正案: 施策 1 の通り、`detect_privilege()` を `deps=missing` の場合だけ呼ぶ。そのうえで S1 を維持する。

- [Warning] 実 Playwright smoke は Linux なら常に `install-deps --dry-run` の出力が marker と一致する前提ですが、非 Ubuntu / apt-get 不在 / Playwright 側の unsupported platform では、設計上の fail-closed とは別理由で `pnpm test` が赤くなりえます。
  修正案: smoke の対象を「Linux かつ apt-get がある環境」に限定し、対象外は理由付き skip にする。`spawnSync.status === null` も明示的に失敗理由へ出す。

## 施策 7: 手順書と台帳の更新

判定: **APPROVE**

- [Suggestion] `docs/testing-browser.md` のトラブルシュートに、`storage/browser-test-artifacts/` は CI アップロード用で `.gitignore` 済み、ローカルでは削除してよい、という一文を足すと運用上の迷いが減ります。

## セキュリティ・副作用

アプリケーションコードに触れない方針は妥当です。AGENTS.md の主要なアプリ不変条件への直接影響はありません。

ただし、Browser 導入は OS 共有ライブラリ導入という特権経路を含むため、`sudo` を「必要な場合だけ」観測する設計に修正してください。現状の常時 `detect_privilege()` は、設計思想と契約テストの両方に反しています。