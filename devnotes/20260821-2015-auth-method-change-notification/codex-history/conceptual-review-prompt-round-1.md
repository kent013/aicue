【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【思考原則】
1. フレームワークのレンジ内でやる。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. 今必要なものだけ作る(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. 後方互換の並走を残さない。書き換えると決めたら同じ PR で旧実装を消す
4. 別物の概念を「似ているから」で統合しない
5. テストファースト。fail を確認してから実装に入る
6. タコツボ実装を避ける。各ステップで他要素との結合観点を確認する

【禁止事項 (AGENTS.md より抜粋・設計判断に直結する核)】
1. PHPStan level 10 のエラーを widen (型を緩めて黙らせる)・baseline 化しない
2. テストなしの実装完了報告をしない (不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
3. dev DB への破壊操作をエージェント判断で実行しない
4. `response()->json()` の直書きをしない (DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼びをしない (本設計は LLM を扱わないため非該当)
6. prompt 文字列のコード直書きをしない (本設計は非該当)
7. 操作系 POST の応答で `redirect()->intended()` を使わない (ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI を作らない
9. Artifact ツールでの成果物公開を行わない

【思考原則・ツール使用制限 (本スキル規定)】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。
データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。
先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示しているか、常に問え。
仕組みが機能していない段階で値を弄るな。方向性が間違っているなら設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【背景の補足 (レビューに必要な事実。コードは実際に確認済み)】
- TODO T110「認証手段変更のメール通知ポリシーの統一設計」。オーナーが 2026-08-21 に
  「方針は任せる。一般的なものに倣う」と決定し、これが本設計の判断根拠である。
- 既存の類似実装 2 件を確認済み: `EmailChangedSecurityNotification` (メール変更時、旧アドレスへ
  on-demand・同期送信)、`TwoFactorResetSecurityNotification` (組織管理者による 2FA 解除時、
  本人へ同期送信)。両方とも `ShouldQueue` を実装していない (同期送信)。
- 既存の監査記録 `App\Listeners\RecordSecurityEvent` は Fortify / Laravel Passkeys の vendor
  イベント (`TwoFactorAuthenticationConfirmed` / `TwoFactorAuthenticationDisabled` /
  `RecoveryCodesGenerated` / `PasskeyRegistered` / `PasskeyDeleted` / `Illuminate\Auth\Events\
  PasswordReset` 等) を 1 つの subscriber で購読し、イベント化されていない経路
  (パスワード変更・SSO 連携) だけ `SecurityEventRecorder::record()` を Service から直接呼ぶ、
  という 2 層構成になっている。
- `DELETE /user/passkeys/{passkey}` (パスキー削除) だけは `EnsureLoginMethodRemains`
  middleware が `DB::transaction()` で「ロック取得 → 判定 → `$next()` (controller・同期
  listener・レスポンス生成まで) 全体」を丸ごとラップしている。この middleware の docblock は
  「この transaction の内側で外部 I/O や `afterCommit` でない queue dispatch をしてはならない」
  と明記しており (ロールバック時に外部だけ実行済みという食い違いを避けるため)、`PasskeyDeleted`
  イベントはこの transaction の内側で発火する。AGENTS.md ドメイン規約 11 により `DB::afterCommit()`
  は app/ 全体で 0 件に固定 (`QueueDispatchAtomicityInventoryTest`) されており使用できない。
- 既定の queue 接続は `database` (`after_commit => false`)。既存の `PaymentFailedNotification` /
  `AccountDeletionRequestedNotification` はこの接続にそのまま乗せた `ShouldQueue` 通知である。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか（直接ではなく
   「業務データを守る土台」としての位置づけの妥当性を含めて判断してよい）
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Fortify + Laravel Passkeys + Socialite）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか（特にパスキー削除の `terminating()` 遅延方式、
   SSO 連携の register/linkToUser 分岐、初回パスワード設定を対象外とする判断）
6. スコープの適切さ: 過大または過小になっていないか（SSO 解除機能が未実装であることを理由に
   実装を見送る判断は妥当か）
7. 型安全性: 新設する enum ベースの通知設計は PHPStan level 10 を通せる形か

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（本文は別ファイル conceptual-design.md の全文。以下に転記する）
# 概念設計: auth-method-change-notification (T110)

## 決定の出所

TODO T110「認証手段変更のメール通知ポリシーの統一設計」について、オーナー (ishitoya@rio.ne.jp)
は 2026-08-21 に「方針は任せる。一般的なものに倣う」と決定した。本設計はこの一文を根拠として、
GitHub / Google 等が採る一般的な「認証手段が変わったら本人の登録メールへ通知する」慣行に倣い、
以下の骨子 (T110 起票時点で既に確定していた大枠) の範囲内で詳細を決める。

- 通知対象: パスワードの変更・リセット / パスキーの追加・削除 / 2 段階認証の有効化・無効化 /
  回復コードの再発行 / 外部ログイン (ソーシャル・SSO) の連携・解除
- メールアドレス変更の通知は既存 (T031 / T211 系) を変えずに並べて整理する
- 送り先はアカウントの登録メールアドレス。内容は「何が・いつ変わったか」+
  「心当たりがない場合の対処案内」。秘密情報 (トークン・コード・パスキーの識別子詳細) は載せない
- 送信は非同期 (queue 経由)。送信失敗が元の操作を失敗させない
- スコープ外: ログインのたびの通知 / アプリ内通知センターへの複製 / 管理者向け通知。
  既存の監査ログ (T108 S7) は変えない

## 背景・課題

現状、認証手段の変更を検知して本人に知らせる通知は 2 件だけ実装されている
(`App\Notifications\EmailChangedSecurityNotification` = メールアドレス変更時に旧アドレスへ、
`App\Notifications\User\TwoFactorResetSecurityNotification` = 組織管理者がメンバーの 2FA を
解除したときに本人へ)。一方、本人が自分でパスワードを変更した・パスキーを削除した・2FA を無効化した・
SSO を連携した、といった**同じくらい重要な自己操作**には通知が無く、セッション奪取後にこれらを
静かに変更されても本人が気づく手段が無い。監査ログ (T108 S7) には記録が残るが、これは事後調査用で
「今すぐ気づく」ための経路にはならない。

また、これらの発火点は Fortify のイベント (2FA) / Laravel Passkeys のイベント (パスキー) /
自前の Service 直接呼び出し (パスワード変更・SSO 連携) という異なる形で存在しており、
「新しい認証手段の増減が発生したら必ず本人に届く」という不変条件を場当たり的に各経路へ書くと、
将来経路が増えたときに漏れが生まれる。T110 はこれを一貫したポリシーとして設計することを求めている。

## 改善アイデア

1. **通知内容を 1 つの Notification クラスへ統一する**。
   対象イベントの種類 (パスワード変更・リセット・2FA 有効化・無効化・回復コード再発行・
   パスキー追加・削除・SSO 連携) を表す小さな enum を新設し、その enum を受け取って
   件名・本文を組み立てる単一の `Notification` クラスとする。これらは概念として
   「認証手段が変わったことを本人に知らせる」という**同一の通知ポリシー**であり、
   AGENTS.md 思考原則 4 (別物の概念を「似ているから」で統合しない) には抵触しない —
   むしろ T110 が要求している「統一設計」そのものである。
2. **発火点は既存の監査記録 (`RecordSecurityEvent`) と同じ構成に倣う**。
   既存の監査は「vendor イベントを購読する 1 subscriber」+「イベント化できない経路
   (Service 内の直接呼び出し) のみ個別に `SecurityEventRecorder::record()` を呼ぶ」という
   2 層構成になっている。通知もこれに倣い、
   - 新規 subscriber (`App\Listeners\Auth\NotifyAuthMethodChange`) が Fortify /
     Laravel Passkeys の既存イベント (`TwoFactorAuthenticationConfirmed` /
     `TwoFactorAuthenticationDisabled` / `RecoveryCodesGenerated` /
     `PasswordUpdatedViaController` / `Illuminate\Auth\Events\PasswordReset` /
     `PasskeyRegistered` / `PasskeyDeleted`) を購読して通知を dispatch する
   - イベント化されていない経路 (SSO 連携の `SocialAccountService::linkToUser()`) だけ、
     その場で直接 `$user->notify(...)` を呼ぶ
   単一の「通知窓口サービスクラス」を新たに挟むかどうかは詳細設計で判断する
   (既存の `SecurityEventRecorder` は「DB 書き込みの共通処理 (try/catch)」を持つために
   意味があるが、通知側は `Notification::send` 相当の 1 行呼び出し以上の共通処理が
   現時点で無いため、3 層目の抽象を追加する必要性は薄いと見ている。詳細設計で再検討する)。
3. **送信は既存の queue 設定に倣い非同期化する**。既存の `PaymentFailedNotification` /
   `AccountDeletionRequestedNotification` と同じく `ShouldQueue` を実装し、既定の
   `database` 接続 (`after_commit => false`) にそのまま乗せる (専用 queue 接続は起こさない)。
4. **メールアドレス変更の通知はそのまま**。`EmailChangedSecurityNotification` の実装
   (旧アドレスへ on-demand 通知・同期送信) は変更しない。今回追加する通知群と
   「認証手段の変更を本人に知らせる」という目的は共通するため、詳細設計の文書内では
   同じセクションに並べて整理する (実装は触らない)。

## 期待効果

- セッション奪取・内部不正・誤操作によって認証手段が静かに変更されたとき、本人が
  ほぼリアルタイムで気づける経路が増え、被害拡大前の対処 (パスワード再設定・サポート連絡) が
  可能になる (使命への直接寄与ではないが、動画マニュアル作成という業務データを守る
  土台としてのアカウントセキュリティを強化する)。
- 発火点を「vendor イベント購読 + 直接呼び出しの必要最小数」に集約することで、将来
  認証手段が増えても (使命ドキュメントにある v1 スコープの範囲内で) 通知漏れが起きにくい
  構造になる。

## 実装方針 (概要)

- 新設: `App\Enums\Auth\AuthMethodChangeEvent` (通知対象イベントの列挙)
- 新設: `App\Notifications\Auth\AuthMethodChangedNotification` (`ShouldQueue`)
- 新設: `App\Listeners\Auth\NotifyAuthMethodChange` (イベント購読。
  `AppServiceProvider::boot()` の `Event::subscribe(RecordSecurityEvent::class)` の隣に
  `Event::subscribe(NotifyAuthMethodChange::class)` を追加)
- 変更: `App\Services\Auth\SocialAccountService::linkToUser()` — 連携成功時に通知を追加
  (`register()` 内部の初回連携では通知しない。理由は下記「制約・前提」)
- **パスキー削除だけ特別な発火タイミングが必要**。`DELETE /user/passkeys/{passkey}` は
  `EnsureLoginMethodRemains` middleware が `DB::transaction()` で `$next()` (controller・
  同期イベントリスナー・レスポンス生成まで) を丸ごとラップしており、その docblock 自身が
  「この transaction の中で外部 I/O や `afterCommit` でない queue dispatch をしてはならない」
  と明記している (ロールバック時に外部だけ実行済みという食い違いを避けるため)。
  `PasskeyDeleted` イベントはこの transaction の内側で発火するため、`NotifyAuthMethodChange`
  はこのイベントに限り `app()->terminating(...)` でレスポンス確定後 (= commit 確定後) まで
  queue 投入を遅延させる。`DB::afterCommit()` は AGENTS.md ドメイン規約 11
  (`QueueDispatchAtomicityInventoryTest` が 0 件で pin) により使用できないため、
  HTTP kernel の terminate フックを使う。他のイベント (2FA・パスワード・パスキー登録・SSO 連携)
  はこの制約下にないため通常どおり listener 内で直接 dispatch する。詳細は詳細設計で述べる。

## 制約・前提

- **SSO 連携 (`SocialAccountService::link()`)** は `register()` (新規アカウント作成に伴う
  初回連携) と `linkToUser()` (ログイン中ユーザーが既存アカウントへ後から連携を追加) の
  両方から呼ばれる共有 private メソッドである。通知対象は「既存アカウントが新しい認証手段を
  獲得した」ことであり、新規登録時点の初回連携はこれに当たらない (本人がその場で登録した
  ばかりのアカウントに「連携しました」と通知するのは典型的な一般慣行にも無い冗長な通知)。
  したがって通知呼び出しは `linkToUser()` 側だけに置く。監査記録
  (`SecurityEventType::SocialAccountLinked`) は既存どおり両方から記録され続ける
  (T108 S7 を変えないため)。
- **SSO の「解除」機能は現在アプリに実装されていない** (該当する route / controller が
  存在しない。`grep` で確認済み)。AGENTS.md 思考原則 2 (今必要なものだけ作る) に従い、
  存在しない機能のためのコードは書かない。本設計は「解除が実装されたら、この通知ポリシーの
  対象イベントとして扱う」という方針だけを明記し、実装は解除機能自体を追加する将来の TODO の
  スコープとする。
- **パスワードの「初回設定」は対象外**。T110 のスコープ文言は「変更・リセット」であり、
  `PasswordCredentialService::setInitial()` (パスワード未設定ユーザーが初めて設定する経路) は
  「既存の認証手段が変わった」ではなく「無かった認証手段が増えた」に近く、対象から外す。
- **組織管理者によるメンバー 2FA 解除** (`TwoFactorResetSecurityNotification`) は既存の
  別ポリシー (加害者側ではなく組織管理者が正規に行う操作) であり、本設計が統一するのは
  「本人が自分の認証手段を変更したときの通知」である。両者は対象読者・文脈が異なるため
  統合しない (思考原則 4)。既存のまま変更しない。
- 対象イベントは Fortify / Laravel Passkeys の vendor イベント発火点をそのまま使う
  (新しいドメインイベントを自前で作らない。既にある発火点で十分カバーできるため)。

## スコープ外

- ログインのたびの通知
- アプリ内通知センターへの複製
- 管理者向け通知
- 既存の監査ログ (T108 S7)・監査 HMAC (T211) の変更
- `EmailChangedSecurityNotification` の実装変更 (整理のみ)
- SSO 連携の「解除」機能そのものの実装 (機能が存在しないため)
- パスワード初回設定時の通知
