# bug-hunt report 2026-08-21 (shard 4 / run 20260821-095643)

- 対象 URL: http://127.0.0.1:8014
- DB: bug_hunt_4 (users=11、走行開始時 db-check で確認)
- 実行ストーリー: S6 (security/2FA/profile)。--deviate 込み、--real-llm (本shardではLLM呼び出しなし。
  UIとAPIの直叩きのみで完走できたため、real-llm接続は本ストーリーの範囲では発生していない)。
- skip したステップ:
  - passkey.confirm (recent-auth をパスキーで満たす経路): passkey.store が環境制約で実行不可のため、
    前提となるパスキーが 1 つも作れず未実行 (理由: 下記「環境制約」参照)。
  - 逸脱「再認証(recent-auth/confirm-password)を経ずに機微操作を直POST」の時間依存版
    (recent_auth_timeout=900秒 が経過した本当の stale 状態): サーバー時計を進める手段が無く未実施。
    代わりに remember-cookie 経由の「recent_auth 未 stamp」状態を作って `user-profile-information.update`
    への 409 (`recent_auth_required`) を確認済み (下記 finding 節参照。こちらは検証成功)。

## 画面カバレッジ
走行 8 / 9 (screens: settings ✓ / settings.security ✓ / password.confirm ✓(注記あり) /
recent-auth.confirm ✓ / recent-auth.status ✓ / notifications.index ✓ / session.status ✓(bfcache guard
の挙動から間接確認 + `GET /session/status` => 200 を直接確認) / passkey.registration-options ✓(オプション取得までは成功、ブラウザ側の
WebAuthn 実行が環境制約で失敗) / passkey.confirm-options ✗未走行(前提のパスキーが無いため到達不能))
- 注記: `password.confirm` (`/user/confirm-password`) は直接開いても独自ページを描かず
  `/recent-auth/confirm` へ即座にリダイレクトされる。config/fortify.php のコメント
  (「generic recent-auth へ統一する」) と整合する仕様consolidationであり finding にはしていないが、
  screens.md 側の記述が実体と合っているか要確認 (インベントリ修正提案参照)。

## 操作カバレッジ
走行 12 完全 / 3 部分 / 1 未走行 (対象 16件: user-profile-information.update ✓,
user-password.update ✓, two-factor.enable ✓, two-factor.confirm ✓, two-factor.disable ✓,
two-factor.regenerate-recovery-codes ✓, password.confirm.store ✓, recent-auth.password ✓,
settings.account.destroy ✓ (owner=ブロック確認 / member=即時削除確認), notifications.read ✓,
notifications.read-all ✓, notifications.open ✓, passkey.store 部分(サーバー側のoptions発行は確認、
ブラウザ側WebAuthn実行が環境制約で失敗), passkey.destroy 部分(IDOR側=他ユーザーID/不正ID/bigint超過
すべて404を確認、正規の自己削除は前提が作れず未確認), settings.password.store 部分(既存パスワード
ユーザーでの迂回不可=422を確認、パスワード未設定ユーザーの正常系は未確認), passkey.confirm ✗未走行)
- settings.account.deletion-request.store / .destroy (S6 割当だがストーリー本文に明記なし、UI上に
  「30日後に削除」ボタンとして存在): 作成・キャンセルとも動作確認済み (追加でカバー)。

## UI/UX 検証
- H11 (視覚破綻): settings / settings.security / notifications の desktop・mobile(375x667)・
  tablet(768x1024) で確認。崩れ・overflow・テキストの意味不明な欠落は無し。
- H12 (アフォーダンス/状態): 2FA有効/無効、パスキー未登録/登録済み等の状態ラベルがバッジで明示され判別可能。
  破壊的操作 (2FA無効化・リカバリコード再生成・アカウント削除) は全て確認ダイアログを経由しボタン階層も明確。
- H13 (レスポンシブ): mobile 375x667 / tablet 768x1024 で settings・settings.security・notifications を
  確認 (screenshots/S6-mobile-settings.png, S6-mobile-security.png, S6-mobile-notifications.png,
  S6-tablet-notifications.png)。横スクロール・要素はみ出し・操作不能は無し。確認後 desktop (1280x800) に復帰。
- H14 (a11y基礎): パスワード表示トグルボタンに名前あり、フォーム項目にラベルあり。見出し階層は
  h1(設定)→h2(各セクション)で自然。今回のスコープでは追加の欠落は見つからず。

## findings
Critical 0 / High 0 / Medium 1 / Low 0 / 要確認 2
(以下 finding 詳細を severity 降順で。走行中に追記)

## F-4-01: recent-auth 経由のメール変更成功後、成功フィードバックが無い (H7)
- severity: Medium
- story/step: S6-3-b逸脱(メール変更のstep-up) / S6-1
- 再現手順:
  1. `owner-personal@example.com` / `NewPassword456` でログイン (「ログイン状態を保持」チェック)。
  2. ブラウザの `ai-cue-session` / `session_epoch` cookie だけを削除し `remember_web_*` を残したまま
     `/dashboard` へ再訪 (remember cookie 経由の自動再ログイン。`recent-auth/status` は
     `recent:false, confirmedAt:null` を返す = 未 stamp のstale状態)。
  3. `/settings` でメールアドレスを新しい値に変更して「保存」。
  4. 本人確認モーダル (`recent-auth-password-input` / `recent-auth-submit`) が出るので現在のパスワードを
     入力して「確認する」。
  5. `/email/verify` (メール認証) 画面へ遷移する。この遷移**前後**に feedback probe を仕込んで確認すると、
     「メールアドレスを変更しました」等の成功トースト/flashは一切出ない
     (`installed_now:false, seen:[], present_new:[], pending:0, errors:0`)。
- 期待: メール変更のような重要な機微操作が成功したら、遷移先が変わっても (再認証モーダル→別画面) その結果を
  ユーザーに明示する成功フィードバックが出る (T026 の対象操作のはず)。
- 実際: 本人確認を経て操作自体は成功しているが (実際に email/verify へ飛ぶので変更は効いている)、
  「メールアドレスを変更しました」に相当する成功メッセージが一度も表示されない。ユーザーは
  「本人確認 → 突然メール認証画面」という遷移だけを見せられ、直前の操作 (メール変更) が成功したのか
  失敗したのかを画面から判断できない。
- 阻害されたユーザージョブ: メールアドレス変更が完了したことの確認。ユーザーが変更の成否を確認できず、
  もう一度試すか、サポートに問い合わせるといった不要な行動を誘発しうる。
- 改善アクション候補: recent-auth の再認証モーダルを通過して元の操作 (プロフィール更新) を再送する際、
  成功時は (a) メール認証要求画面へ遷移する前に一瞬トーストを出す、または (b) メール認証画面自体に
  「メールアドレスを xxx へ変更しました。認証を完了してください」という文言を出す、のいずれかで
  「操作は成功した」ことを明示する。
- 証跡: screenshots/F-4-01.png (該当なし、テキストのみで確認。necessary であれば再現時に screenshot 追加),
  feedback-probe: `installed_now=false seen=0 present_new=0 pending=0 errors=0` (再認証モーダル送信直後、
  および /email/verify 到達後の 2 回とも同じ結果)
- 推定原因: 未調査 (プロフィール更新の成功 flash が、メール変更に伴う `email/verify` への強制遷移で
  レスポンスの flash がドロップされる可能性。5 分で特定できず)
- 関連既知情報: なし (要確認: 通常のプロフィール更新 (メール以外の変更や、stale-session を経ない
  通常のメール変更) では「プロフィールを更新しました。」トーストが出ることを本レポートで確認済み。
  本 finding はメール変更 + step-up + email/verify 遷移が絡む場合固有)

## 要確認-1: メール変更時の旧アドレスへの通知有無を確認できず
- 対象: `user-profile-information.update` (メールアドレス変更)
- 内容: ストーリーの逸脱アイデアに「変更成功時に旧アドレスへ通知されるか」とあるが、
  `tmp/bug-hunt/shard-4-cmd.sh mail-urls` は署名付き URL の抜き出しのみで、メール本文/宛先を
  確認する手段が無く判定できなかった。要確認として記録 (バグと断定しない)。

## 要確認-2: settings.password.store (パスワード未設定ユーザーの初回設定) の正常系が未検証
- 対象: `settings.password.store` (T107)
- 内容: SSO/パスキーのみで登録した「パスワード未設定ユーザー」がこの bughunt shard の DB に存在せず
  (ManualTestSeeder はパスワード付きユーザーのみ)、また新規に作る手段も塞がれている
  (下記インベントリ修正提案参照)。既にパスワードを持つユーザーでの**迂回不可**側は直 POST で確認済み
  (422 で `すでにパスワードが設定されています。パスワード変更フォームから変更してください。` を確認。
  fail-closed が機能している)。正常系 (パスワード未設定ユーザーが到達できる) は未検証のまま。

## H7 未検証
0 件 (F-4-01 は「陰性」判定基準を満たしたため未検証ではなく確定 finding として記録済み。
他の操作は全て `installed_now:false` かつ肯定証拠あり、または直接的な状態変化で判定できたため
未検証に倒したものは無い)

## 環境制約 (バグではないが走行に影響したもの)
- **WebAuthn (パスキー) は本 shard の URL `http://127.0.0.1:8014` では原理的にテストできない**。
  WebAuthn 仕様は RP ID にIPアドレスリテラルを許さず、Chromium は
  `navigator.credentials.create()` で `SecurityError: This is an invalid domain.` を返す
  (実測: `GET /user/passkeys/options` の `rp.id` は `"127.0.0.1"` そのもの)。CDP の
  virtual authenticator を仕込んでも、ブラウザ側の RP ID 検証で止まるため回避できない。
  `http://localhost:8014` への遷移は 400 (Bad Request) になり使えなかった (別ホスト名では
  session/CSRF 系が一致しないと思われる。APP_URL の host 固定が原因の可能性)。
  → S6 の passkey 登録 (`passkey.store`)・削除の正規経路 (`passkey.destroy` の自己削除)・
  `passkey.confirm` (パスキーでの recent-auth) は**このシャード環境の構造的制約**により
  ブラウザ経由で完走できなかった。IDOR 側 (他人の passkey id / 不正な id への `passkey.destroy`)
  はパスキーの実在を前提としないため確認できた (すべて 404、finding なし = 正常)。
  - 親への提案: 並列 shard 用に `127.0.0.1:801{i}` ではなく `bughunt{i}.localhost:801{i}` 等の
    ドツテド DNS 名 (localhost サブドメインは大半のブラウザ/OSで 127.0.0.1 に解決される) を
    割り当てられれば、この制約を構造的に解消できる可能性がある (要検証: APP_URL / session
    cookie domain / CORS 設定の追従が必要)。
- SSO/パスキーのみの「パスワード未設定」ユーザーが `ManualTestSeeder` に存在せず、
  `settings.password.store` (T107) の正常系 (未設定ユーザーが到達・成功する経路) を検証できなかった。
  Google SSO ログインリンクは実際に外部 (`accounts.google.com` 相当) へ飛ぼうとする形で、
  bug-hunt の許可オリジン (自シャードのみ) の外に出るため egress guard の対象になり実行しなかった
  (禁止事項4に従い、正しくブロックされている前提で追跡していない)。

## インベントリ修正提案
1. `screens.md` の `password.confirm` (`user/confirm-password`) は実装上、独自ページを描かず
   `recent-auth.confirm` へ即時リダイレクトする (config/fortify.php のコメントに記載の意図的な
   統合)。screen として別行に残す設計が今も正しいか確認をお願いしたい (両者を1行に統合、または
   `password.confirm` に「recent-auth.confirm へ統合済み」の注記を付けるなど)。
2. S6 (および passkey を扱いうる S7 等) の並列 shard 環境で WebAuthn を用いた passkey 登録が
   `127.0.0.1` ホストでは構造的に不可能 (上記「環境制約」参照)。全 parallel shard 共通の制約と
   思われるため、S6 担当 shard 個別ではなく親のインベントリ/走行手順レベルでの対応 (ドメイン名
   割当の検討、または「passkey登録はコード到達確認 (`GET /user/passkeys/options` の 200 と
   `passkey.destroy` の 404-only IDOR 確認) までを合格基準とする」という運用上の割り切りの明文化)
   を検討してほしい。
3. `settings.password.store` の正常系 (パスワード未設定ユーザー) を bughunt 環境でテスト可能にするには、
   `ManualTestSeeder` に「SSO登録想定でパスワード未設定」のテストユーザーを 1 件追加するのが
   最も低コストと思われる (パスキー経由・実SSO経由のいずれも上記の理由でUI到達不能なため)。

## Critical/High 要約 (TODO 候補)
今回の走行で Critical/High の finding は 0 件だった。F-4-01 (Medium) のみ。
