# 実査ブリーフ: bug-hunt の SSO 外部遷移を塞ぐ (bughunt-sso-egress)

> aicue:T138 (external-seam-funnel) の詳細設計が **「独立 TODO `bughunt-sso-egress` へ分離」**
> と明記したまま**起票されずに残っていた**もの。棚卸しで発見した。
> 出典: `devnotes/20260809-0027-external-seam-funnel/detailed-design.md` の
> 「保証しないもの」1 番、および `conceptual-design.md` §182。

## 実コードで確認した事実

`config/testing.php` L16-17 が明記している:

> **SSO (Socialite) は fake しない** (差し替え先を作っていない。
>  bug-hunt のブラウザは SSO ボタンから実 IdP へ遷移する。)

つまり **bug-hunt を回すと、ブラウザが SSO ボタンから `accounts.google.com` 等の実 IdP へ
遷移しうる**。aicue:T130 で入れたテストレーンの HTTP 出口既定拒否は**別プロセスのブラウザには効かない**
(AGENTS.md 自身が明記)。

## なぜ塞ぐ価値があるか

- bug-hunt スキルの**禁止事項 4** は「許可する実外部接続は LLM プロバイダ API ドメインのみ」と定めている。
  現状は**その規約に穴がある**状態で、実際に run 20260811-003230 では
  shard 4 に「実 IdP ドメインへの遷移を検知したら即中断して報告」と指示して**回避**していた
  (= 探索の網を人手で狭めていた)。
- `PLAYWRIGHT_MCP_ALLOWED_ORIGINS` は自シャードのポートに限っているので**実際には遷移がブロックされる**が、
  それは「ブラウザ側の allowlist が守っている」のであって**アプリ側は塞いでいない**。
  この二重性が「どちらが本当の保証か」を曖昧にしている。

## T138 が fake を作らなかった理由 (蒸し返さないこと)

T138 の概念設計 §101:

> **SSO の fake を作らない (= bug-hunt の SSO 外部遷移は本 PR では塞がらない)。**
> 作ると `SocialAuthTest` / `RecentAuthTest` などの既存テストに波及する

**この判断は T138 のスコープでは正しい**。本 TODO はその波及を引き受ける側である。

## 設計で決めるべきこと

1. **何を塞ぐのか**。(a) Socialite の driver を fake へ差し替える / (b) bug-hunt レーンでだけ
   SSO ボタンを出さない / (c) 遷移先を自アプリ内のスタブへ向ける、等。
   **既存テストへの波及が最小**で、かつ**「アプリ側が塞いでいる」と言える**形を選ぶこと。
2. **既存テストへの波及**。`SocialAuthTest` / `RecentAuthTest` などが実際にどう壊れるかを
   **実コードで確認してから**方針を決める。T138 が懸念したのはこの波及である。
3. **既存の fake 宣言との整合**。aicue:T138 で
   `ExternalFakeWiringInventory` / `FakeExternalsServiceProvider` に captcha を配線した実績がある。
   **同じ形に揃えられるか**を確認する (揃えられるなら独自形を作らない)。
4. **`config/testing.php` の記述の更新**。現在「SSO は fake しない」と明記されているので、
   塞ぐなら**この記述も同一 PR で直す** (残すと嘘になる)。
5. **bug-hunt スキルの記述**。`SKILL.md` の禁止事項 4 と環境表 (「SSO は fake」) の更新が要るか。
6. **機械で守れるか**。「bug-hunt レーンで実 IdP へ出ない」ことを検査できるか。
   できないなら「保証しないもの」に正直に書く。

## 読むべき現行コード

- `config/testing.php` (fake の capability flag と SSO の但し書き)
- `app/Providers/FakeExternalsServiceProvider.php` と `tests/Support/ExternalFakes/ExternalFakeWiringInventory.php`
- `app/Http/Controllers/Auth/SocialAuthController.php` と Socialite の driver 解決
- `tests/Feature/Auth/SocialAuthTest.php` / `RecentAuthTest.php` (波及先)
- `devnotes/20260809-0027-external-seam-funnel/` の設計 (**なぜ本 PR で塞がなかったか**)
- `.claude/skills/app-bug-hunt/SKILL.md` の禁止事項 4 と環境表

## やらないこと

- **本番の SSO を壊さない**。fake は allowlist 環境 (local / testing / bughunt.local) に閉じる。
  `ProductionEnvGuard` が本番で fake を拒否する既存の仕組みを崩さない。
- **T138 が入れた到達点の目録の形を変えない**。
