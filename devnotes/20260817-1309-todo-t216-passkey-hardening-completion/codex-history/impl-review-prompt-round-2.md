## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

Laravel + Svelte アプリ (aicue) の実装レビュアー。
これは aicue:T216「パスキー境界ハードニング未達 3 点の完遂」の実装レビュー **Round 2** である。

Round 1 では app/ と tests/ の差分を提示して **APPROVED** を得た。ただし Round 1 のレビュアーから
「提示 diff に docs / .env.example が含まれていないため、文書更新の実体確認だけが残る」
「`docs/template-divergence.md` 本体が diff に無いため件数定数 24 との一致が確認不能」
という残件が指摘された。

本ラウンドはその残件を閉じるため、**ドキュメント側の差分だけ**を提示する。

## レビュー観点

1. 設計 (下に要点を再掲) との一致性
2. 記述の正確性 — 実装が保証しないことを保証すると書いていないか (誇張の検出)
3. 既存記述との矛盾が残っていないか (施策 A は「版 pin の対象は laravel/passkeys だけ」という
   旧記述を書き換える必要があった)
4. 逸脱登録簿 (`docs/template-divergence.md`) の D25 エントリが、
   登録メタ表 9 行ちょうど / 状態の値域 / 対象パスの実在と重複なし / 冒頭件数と定数の一致
   という機械強制の書式に沿っているか
5. 運用者向けの記述 (.env.example) が、実装の受理範囲・拒否範囲と食い違っていないか

## 出力形式

ファイルごとに判定 (OK / 要修正) を出し、指摘を [Critical] / [Warning] / [Suggestion] に分類。
最後に **全体判定: APPROVED または CHANGES_REQUESTED** を書く。

---

## 設計の要点 (詳細設計より抜粋)

- 施策 A: `laravel/fortify` の版を 1.37 系へ**下限**固定する (制約と解決値の 2 面)。
  `docs/auth-security-mechanisms.md` §5 の「版 pin が対象にするのは laravel/passkeys だけ
  (laravel/fortify は 1.x の semver 管理なので minor pin を足さない)」という記述は
  本施策と正面から矛盾するので同じ変更で書き換える。
- 施策 B: 許可する接続元の正規形を `App\Support\PasskeyOriginCanonicalizer` ただ 1 か所に置く。
  変形は 3 つだけ (前後空白と小文字化 / 根を表す末尾スラッシュ 1 個の除去 /
  scheme に対応する既定 port の除去)。修復しないもの (path / query / fragment / 利用者情報 /
  二重スラッシュ / 非 ASCII ホスト) は検証器が拒否する。国際化ドメインは punycode を運用者に書かせる
  (変換は実装しない)。例外文には設定の生値を載せず、位置 (1 始まり) と環境変数名だけを出す。
- 施策 C: パッケージ側の削除処理 (`Laravel\Passkeys\Actions\DeletePasskey`) は行削除とイベント
  発火をトランザクションで包まない。本アプリは `EnsureLoginMethodRemains` が削除 route 全体を
  トランザクションで包むため、**同期の**購読が失敗すると削除ごと巻き戻る。
  購読が commit 後へ回されていたら成り立たない (保証範囲を誇張しない)。
  **登録経路にはこの埋め合わせが無い** (既知の窓)。
- 施策 D: 検査の置き場所 (設定の評価時ではなく本番起動時の関門) を逸脱として登録する。
  対象パスに `config/fortify.php` は**含めない** (宣言側で正規形へ寄せること自体は正典と同じ形で、
  逸脱しているのは「正規形でなかったときにどこで落とすか」だけであるため)。
  決めた日は逸脱を最初に決めた日 (前タスクの設計日 2026-08-15) を書く。

## 検証結果 (実測)

- composer test: 5717 tests / 5715 passed / 2 skipped / 0 failed
- composer phpstan (level 10): No errors
- vendor/bin/pint --test: passed
- pnpm lint / typecheck / build / typecheck:packages / build:packages: すべて成功
- pnpm test: 160 files / 1967 passed
- pnpm test:packages: 10 files / 106 passed

---

## ドキュメント差分 (git diff)

```diff
commit 1b033a8e912d759c3df5deef8fb2b2378f7694e6
Author: ISHITOYA Kentaro <kentaro.ishitoya@gmail.com>
Date:   Mon Aug 17 05:51:24 2026 +0000

    feat: T216 パスキー境界ハードニングの未達 3 点を閉じる
    
    施策:
    - A: laravel/fortify の版を 1.37 系へ下限固定する (制約と解決値の 2 面)
    - B: 許可する接続元の正規形を 1 か所へ置き、宣言側で受理・検証側で逸脱を落とす
         (末尾スラッシュと既定 port を正規化受理。例外文から設定の生値を除く)
    - C: パッケージ側の削除処理の非原子性と、関門による巻き戻りを固定する
    - D: 検査の置き場所 (本番起動時の関門) を逸脱 D25 として登録する
    
    Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>

diff --git a/.env.example b/.env.example
index 9840ba3..63977fd 100644
--- a/.env.example
+++ b/.env.example
@@ -209,6 +209,11 @@ PASSKEYS_USER_HANDLE_SECRET=
 # (RP ID = APP_URL の host、接続元 = scheme://host[:port])。
 # 同一オリジン PWA 前提のため通常は宣言不要。別ホストから撮影 PWA を配信するときだけ宣言する。
 # 接続元は CSV で、各 host は RP ID と一致するか RP ID の下位ドメインであること。
+# 接続元は宣言の時点で正規形へ寄せる: 前後空白と大文字小文字 / 末尾スラッシュ 1 個 /
+# 既定 port (https の :443、http の :80) は**書いてあっても受理して落とす**。
+# 修復しないもの (path / query / fragment / 利用者情報 / 非 ASCII ホスト) は
+# production 起動時に拒否される。国際化ドメインは punycode で書くこと。
+# 例: PASSKEYS_ALLOWED_ORIGINS=https://app.example.com,https://pwa.app.example.com:8443
 # PASSKEYS_RELYING_PARTY_ID=
 # PASSKEYS_ALLOWED_ORIGINS=
 
diff --git a/docs/auth-security-mechanisms.md b/docs/auth-security-mechanisms.md
index 68a50a3..f0da309 100644
--- a/docs/auth-security-mechanisms.md
+++ b/docs/auth-security-mechanisms.md
@@ -344,6 +344,22 @@ ### 運用上の注意
   **現行 `APP_KEY` の値をそのまま**宣言すれば既存パスキーは維持される
   (検査は「宣言されているか」を見ており、値が `APP_KEY` と同じかどうかは見ない)。
   以後 `APP_KEY` のローテートはパスキーに影響しない。
+- **許可する接続元は宣言の時点で正規形へ寄せる**。正規形の定義は
+  `App\Support\PasskeyOriginCanonicalizer` **ただ 1 か所**で、変形は 3 つだけ —
+  前後空白の除去と小文字化 / 根を表す**末尾スラッシュ 1 個の除去** /
+  scheme に対応する**既定 port の除去** (`https` の `:443` / `http` の `:80`)。
+  ブラウザが申告する接続元は既定 port を含まず、照合は webauthn-lib の
+  **厳密な文字列比較**なので、`:443` と書いた設定は一致せず**全手続きが無言で失敗する**。
+  末尾スラッシュ付き・既定 port 付きの宣言は、この正規化によって**受理されて正しく動く**。
+  検証器はここで正規形へ寄らなかった値 (= 宣言経路を通らずに設定された値) を落とす側に徹する。
+  正規化が**修復しない**もの (path / query / fragment / 利用者情報 / 二重スラッシュ /
+  非 ASCII ホスト) は検証器がそのまま拒否する。**国際化ドメインは punycode で書く**
+  (変換を実装すると、変換結果が正しいことを誰も検査できない層が増えるため)。
+  この置き場所 (設定の評価時ではなく本番起動時の関門) は
+  `docs/template-divergence.md` **D25** に逸脱として登録済み。
+- **起動時検査の例外文には設定の生値を載せない** (配備ログへ焼き付くため)。
+  出るのは**何番目の値か** (1 始まり) と**環境変数名**だけなので、
+  運用者は自分の `.env` の該当行を数えて特定する。
 - 起動時検査が見るのは**書式と相互整合まで**である。「その host を実際に運用しているか」
   「証明書があるか」は検査できない。**Public Suffix List も持たない**ため、
   `co.uk` のような public suffix を身元の識別子に置いた設定は起動時には通る
@@ -356,12 +372,25 @@ ### 運用上の注意
   置いても効かない死んだ設定になる。実効値と宣言値の一致は
   `PasskeyPackageContractTest` が固定する。
 - キー名は `laravel/fortify` / `laravel/passkeys` の契約であり、変わると宣言は
-  **無言で効かなくなり既定へ戻る**。版 pin (`composer.json` の直接要求 +
-  解決版検査) が対象にするのは **`laravel/passkeys` だけ**である
-  (`laravel/fortify` は 1.x の semver 管理なので minor pin を足さない)。
+  **無言で効かなくなり既定へ戻る**。版の固定は **2 つのパッケージの両方**を対象にする —
+  `laravel/passkeys` は 0.x で後方互換の保証が無いため 0.2 系へ、
+  `laravel/fortify` は**公式パスキー統合が入った 1.37 系**へ固定する
+  (1.37 未満への退行は `Features::passkeys()` という有効化点そのものを消す)。
+  どちらも `composer.json` の制約と `composer.lock` の解決値の 2 面を見る。
+  固定は**下限側**であり、minor 更新で赤くなるのは「契約検査の前提を読み直す契機」として
+  意図した挙動である (脆弱性対応で版を上げるときは同じ変更で固定値も直す)。
   Fortify 側の写像は `PasskeyPackageContractTest` の**実効値の契約テスト**が守る。
 - 未認証の challenge 発行 (`GET /passkeys/login/options`) は `throttle:passkeys` (10/min) で絞る。
   `config('fortify.limiters.passkeys')` が未設定だと Fortify が throttle を外し **無制限**になる。
+- **削除の原子性はアプリ側が埋めている**。パッケージ側の削除処理
+  (`Laravel\Passkeys\Actions\DeletePasskey`) は「行を消してからイベントを発火する」形で
+  2 つをトランザクションで包まない。本アプリは `EnsureLoginMethodRemains` が
+  削除 route 全体をトランザクションで包むため、**同期の購読** (監査記録など) が失敗すると
+  **削除ごと巻き戻る**。購読が commit 後へ回されていたら成り立たないが、
+  その形が入らないことはキュー投入の原子性のゲートが別途固定している。
+  **登録経路にはこの埋め合わせが無い** (手段を減らす操作ではないため関門が付かない) —
+  登録の購読側が失敗した場合、行は残りイベント処理だけが失われる (既知の窓)。
+  前提の固定は `PasskeyPackageContractTest`、実挙動は `PasskeyDeletionAtomicityTest`。
 
 ---
 
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index ef93329..2c9e383 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 23 件
+登録エントリ: 24 件
 
 ## 記録の原則
 
@@ -1386,3 +1386,52 @@ ### 関連
 - 実装: `app/Services/Auth/SocialiteDriverResolver.php` /
   `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php`
 - 設計: `devnotes/20260811-1736-bughunt-sso-egress/`
+
+## D25 パスキー設定の検査を「設定の評価時」ではなく「本番起動時の関門」で行う
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Support/PasskeyConfigValidator.php` / `app/Support/PasskeyOriginCanonicalizer.php` |
+| 業務要件起因の説明 | 撮影 PWA の主要ログイン導線がパスキーであり、設定の評価時に例外を投げる正典の形では開発環境とテストレーンまで起動不能にできる。本アプリは受け入れホストと接続元の信頼設定で「本番起動時に落とす」関門を先に確立しており、パスキーもそこへ相乗りする |
+| 揃え続ける不変条件と保証機構 | 正規形の定義は 1 か所 (`PasskeyOriginCanonicalizer`) で、宣言側は正規形へ寄せ、検証側は正規形からの逸脱を落とす。本番で書式・相互整合・導出鍵の宣言が不正なら起動しない (`ProductionEnvGuardTest` / `PasskeyConfigValidatorTest` / `PasskeyOriginCanonicalizerTest` / `PasskeyOriginDeclarationTest`) |
+| 再判定の条件 | 正典が検査の置き場所を変えたとき、または本番以外でも設定事故を早期に検出したい要求が出たとき |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260815-1111-passkey-config-hardening/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 設定が正規形でなかったときの落とし方 | 設定の評価時にその場で例外を投げる | 本番起動時の関門 (`ProductionEnvGuard`) で落とす |
+| 正規形へ寄せる場所 | 設定の宣言時 | 設定の宣言時 (ここは正典と同じ) |
+
+### なぜ正当な差分か (logic-driven)
+
+設定ファイルは**すべての環境で評価される**。評価時に例外を投げる形にすると、
+開発環境とテストレーンまで起動不能にできる。撮影 PWA の主要ログイン導線がパスキーである以上、
+設定事故を本番前に止める必要はあるが、その代償として開発が止まる形は取れない。
+本アプリは接続元の信頼設定 (TRUSTED_PROXIES) と受け入れホストで
+「本番起動時に落とす」関門を先に確立しており、パスキーもそこへ相乗りするほうが
+落とし方の置き場所が 1 つで済む。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「正規形の定義は 1 か所にあり、宣言側は正規形へ寄せ、検証側は正規形からの逸脱を落とす」
+
+- 正規形の定義は `PasskeyOriginCanonicalizer` ただ 1 つで、宣言側と検証側の両方が参照する
+- 本番で書式・相互整合・導出鍵の宣言が不正なら起動しない (`ProductionEnvGuardTest`)
+- 宣言経路が正規形へ寄せることは宣言経路そのものの再評価で固定する
+  (`PasskeyOriginDeclarationTest`)
+
+### 保証しないもの
+
+- 検査が走るのは `Features::passkeys()` が有効な**本番起動時だけ**である
+  (キルスイッチを切った環境には設定を要求しない)
+- 開発環境・テストレーンでは設定事故が起動時には表面化しない (これがこの逸脱の代償である)
+
+### 関連
+
+- 実装: `app/Support/PasskeyConfigValidator.php` / `app/Support/PasskeyOriginCanonicalizer.php`
+- 設計: `devnotes/20260815-1111-passkey-config-hardening/` /
+  `devnotes/20260817-1309-todo-t216-passkey-hardening-completion/`
diff --git a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
index d7d9698..bc6aa0c 100644
--- a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
+++ b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
@@ -34,7 +34,7 @@
  * **明示件数との同期検査であって、例外を許す一覧ではない**。個別の D 番号を名指しして
  * 規則を免除する仕組みは持たない。登録を足した / 消したら同じ変更でこの値も直す。
  */
-const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 23;
+const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 24;
 
 /** 逸脱の登録簿の本文 (読めないことは不合格)。 */
 function templateDivergenceMarkdown(): string
```
