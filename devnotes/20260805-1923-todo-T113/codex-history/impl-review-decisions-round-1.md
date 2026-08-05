# 対応マトリクス: impl-review Round 1

全体判定は **APPROVED**。[Critical] / [Warning] は 0 件。[Suggestion] 2 件をいずれも採用した。

## [Suggestion] S1 の `PasskeyLoginPolicy` 契約が operations.md の認可契約に無い

- 判断: **対応する**
- 根拠: bug-hunt が「何を破れば finding か」を判断する正本は operations.md の認可契約節であり、
  ストーリー側の逸脱アイデアにしか書かれていないと、S1 を実走しないシャードから契約が見えない。
  `PasskeyLoginPolicy` は `docs/auth-security-mechanisms.md` §5 の「アプリが被せる 5 つの不変条件」の
  4 番目として実装済みの契約なので、台帳側に置くのが正しい粒度。
- 対応内容: `.claude/skills/app-bug-hunt/operations.md` の
  「パスキー / ログイン手段の認可・guard 契約」節に
  「**TOTP confirmed なら passkey login は拒否** (`PasskeyLoginPolicy`)」を 1 項目追加した
  (vendor の `PasskeyLoginController::store()` が two-factor challenge を通らない機序と、
  通ったら Critical であることを明記)。

## [Suggestion] W16 が `|| true` / `echo` による soft-fail 偽装を弾けない

- 判断: **対応する**
- 根拠: 指摘のとおり `includes` 判定は `bash scripts/bug-hunt-inventory-check.sh || true` を素通りさせる。
  既存 W6 / W14c が同じ理由で完全一致を要求しており、新規ゲートを最初から緩く作る理由がない。
  `continue-on-error` は W13 が別途 deny-by-default で禁じているので、残る抜け穴は shell 側の soft-fail のみ。
- 対応内容: W16 を「drift 検知に言及する実行行の集合が
  `["bash scripts/bug-hunt-inventory-check.sh"]` と**完全一致**すること」へ強化した。
  実 workflow を一時改変して `|| true` を挿入 → W16 が 1 failed になることを実測で確認し、復元後 27 passed。
