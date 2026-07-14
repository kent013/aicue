# 概念設計レビュー Round 3 — Round 2 指摘への対応報告

Round 2 の 3 Warning に対応しました。

## [Warning] fake_storage 既定値の本文/表の矛盾 → 対応
- 実装方針表を「config 既定 false、bughunt は script が env で true 注入」に統一。制約・前提の表現も
  「app-wide だが実効を限定」へ修正。

## [Warning] Captcha/SSO の「fake 維持」主張が inventory から導けない → 対応 (実行時分類へ精緻化)
- grep で bughunt runtime を確認し、系統別に分類:
  - Stripe = fake (gateway bind)
  - mail = 外部通信なし (MAIL_MAILER=log)
  - Captcha = 外部通信経路なし (RECAPTCHA_SITE_KEY 未設定で widget 不発火 / secret 未設定は非 production fail-open)
  - SSO = 当該 journey で走行しない (browser stories は email+password)
  - S3 = fake (filesystems.default=local、provision の実効 env 検証が固定)
- いずれも egress guard + 走行プロトコル 4 で外部実リクエストは機械遮断。唯一の実 API は Anthropic に限定、と明記。

## [Warning] bughunt storage 既定値の供給元が不明確 → 対応
- 「実効値は script の env -i 明示注入を正本」と確定。TESTING_FAKE_STORAGE は既定 true 注入 / --real-storage で
  false。TESTING_FAKE_LLM は --fake-llm 時のみ true 注入。example コピー忘れでも既定 fake が崩れない。

## [Suggestion] --real-storage を CLI help に「未実装」表示 → 対応 (usage/help に明示)

---

修正した差分箇所の該当セクションを再掲します。全体判定をお願いします。

## 改善アイデア冒頭 + 外部系統の実行時分類

（本文冒頭を下記に差し替え）

bughunt の走行モードを **real-llm (既定)** に切り替え、fake-llm を opt-in にする。**LLM のみ実 API を利用**し、
その他の外部系統は「fake / 外部通信経路なし / 当該 journey で走行しない」のいずれかで**実外部通信を発生させない**。

> **外部系統の実行時分類 (grep で runtime 配線を確認)**:
> - Stripe (課金): fake (FakeExternalsServiceProvider::register が fake_externals 依存で fake gateway bind)
> - mail: 外部通信なし (MAIL_MAILER=log)
> - Captcha (reCAPTCHA): 外部通信経路なし (RECAPTCHA_SITE_KEY 未設定で widget 不発火 / secret 未設定は非 production fail-open)
> - SSO: 当該 journey で走行しない (browser stories は email+password)
> - S3: fake (filesystems.default=local)
> いずれも egress guard + 走行プロトコル 4 で外部実リクエストは機械遮断。唯一の実 API は Anthropic。

## 施策3 フラグ供給元の確定

- 実効値は script が env -i 隔離行へ明示注入する値を正本 (.env.bughunt.local dotenv より env -i 注入が優先)。
- TESTING_FAKE_STORAGE = 既定 true 注入 (fake) / --real-storage 時のみ false。
- TESTING_FAKE_LLM = --fake-llm 時のみ true 注入 (既定は注入せず = config 既定 false = real)。
- .env.bughunt.local.example はフラグの意味を説明するが、実行時既定は script 注入が保証。
- --real-storage は inert (consumer 未実装)。usage/help に「未実装トグル」を明示。

## 実装方針表 (該当行)

- config/testing.php: fake_llm (config 既定 false) / fake_storage (config 既定 false、bughunt は script が env で true 注入)。

## 制約・前提 (該当行)

- 本番挙動は不変 (実効の限定): 変更点は app-wide (config/testing.php・provider・guard) だが、実効は bughunt.local と
  script 注入 flag に限定 (両フラグ config 既定 false + production guard で本番安全)。
