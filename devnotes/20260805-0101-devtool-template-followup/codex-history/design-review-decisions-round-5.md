# 対応マトリクス: design-review Round 5 (APPROVED)

全体判定: **APPROVED**。全 6 施策が APPROVE、Critical / Warning はゼロ。
以下は判定に影響しない [Suggestion] 2 件で、どちらも取り込んだ。

## [Suggestion] 施策3: `branding.js` の import が 2 行に分かれている

- 判断: **対応する**
- 根拠: 妥当。同一モジュールからの二重 import は lint / 可読性の両面で無駄。
  Round 3 で `KEYCHAIN_SERVICE` を足したときに既存の `BIN_NAME` 行と統合し忘れていた。
- 対応内容: `import { BIN_NAME, KEYCHAIN_SERVICE } from "../branding.js";` の 1 行に統合した。

## [Suggestion] 施策4: 5c-a の例示にある `applyAtomic()` では `api_url` を変更できない

- 判断: **対応する**
- 根拠: 正当。`applyAtomic(name, patch, verifyResult)` の `patch` は
  `MutableConnectionOptionsPatch`（`ca_bundle` / `http_proxy` / `https_proxy` /
  `allow_insecure` / `timeout_ms` / `retry_max` / `retry_backoff_ms`）で、
  **`api_url` を含まない**（`profile/writer.ts:41-71` の `applyPatchToEntry` が
  対象キーを明示列挙している）。設計書のコード例のまま実装すると
  「別プロセスが api_url を書き替えた」状況を再現できず、
  **TOCTOU テストが常に緑になる**（テストとして無意味になる）危険があった。
- 対応内容: 例を `saveConfigToPath(configPath, { ... api_url を B にした config ... })`
  による直接書き戻しへ変更した。これは「別プロセスが config を書き替えた」状況の
  忠実な再現であり、`ProfileWriter` の API 制約に縛られない。

---

## 判定

**APPROVED (Round 5)**。詳細設計として実装へ進める状態。
