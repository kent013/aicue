# 対応マトリクス: conceptual-review Round 2

## [Warning] fake_storage 既定値が本文 false / 実装表 true で矛盾
- 判断: 対応する
- 対応内容: 実装方針表を「config 既定 false、bughunt は script が env で true 注入」に統一。制約・前提の
  「bughunt に閉じる」も「app-wide だが実効を限定」に修正。

## [Warning] Captcha/SSO を「fake 維持」と主張したが inventory から導けない
- 判断: 対応する (実行時分類に精緻化)
- 根拠: grep で bughunt runtime を確認。Captcha は RECAPTCHA_SITE_KEY 未設定で widget 不発火 + secret 未設定は
  非 production fail-open (Google へ実リクエストなし)。SSO は browser stories が email+password のみで走行対象外。
  mail は MAIL_MAILER=log で外部通信なし。Stripe は fake gateway bind。
- 対応内容: 「その他は fake」を系統別分類 (fake / 外部通信なし / 外部通信経路なし / 当該 journey で走行しない) に
  置換し、egress guard + 走行プロトコル 4 で外部実リクエストが機械遮断される旨を明記。唯一の実 API は Anthropic。

## [Warning] bughunt storage 既定値の供給元が不明確 (example だけでは保証できない)
- 判断: 対応する
- 対応内容: 「実効値は script の env -i 明示注入を正本とする」と確定。TESTING_FAKE_STORAGE は既定 true 注入 /
  --real-storage で false、TESTING_FAKE_LLM は --fake-llm 時のみ true 注入。example はコピー忘れでも既定 fake が
  崩れないよう、script 注入を authoritative に。

## [Suggestion] --real-storage を CLI help にも「未実装」表示
- 判断: 対応する
- 対応内容: usage/help に「未実装トグル」を明示する旨を追記。
