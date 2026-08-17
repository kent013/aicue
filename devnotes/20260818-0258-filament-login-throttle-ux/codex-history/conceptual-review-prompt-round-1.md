## アプリの使命・禁止事項 (AGENTS.md より)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

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


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js、Filament v4 管理画面）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: PHPStan level 10 を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: filament-login-throttle-ux (機能 filament-login-throttle-display の追従)

## 背景・課題

Filament 管理画面 (`/admin`) のログインは vendor 標準の `Filament\Auth\Pages\Login` を
そのまま使っている (`AdminPanelProvider::panel()` の `->login()` 引数なし)。

vendor の `authenticate()` は先頭で流量制限を評価し、上限に達すると
**通知を出して `return null` するだけ**である。

```php
// vendor/filament/filament/src/Auth/Pages/Login.php
public function authenticate(): ?LoginResponse
{
    try {
        $this->rateLimit(5);
    } catch (TooManyRequestsException $exception) {
        $this->getRateLimitedNotification($exception)?->send();

        return null;   // ← 直前の試行で入った入力エラーはそのまま残る
    }
    ...
```

一方 Livewire は **入力エラーを次の要求へ持ち越す**。
`SupportValidation::dehydrate()` が誤りの一覧をコンポーネントの記憶領域 (`errors` memo) へ書き、
`hydrate()` で復元する。復元されるのはコンポーネントのプロパティに対応するキーだけだが、
ログイン画面の誤りは `data.email` に入る (`throwFailureValidationException()`) ため
`data` プロパティ由来と判定されて**確実に持ち越される**。

帰結として、**上限に達した後の画面は「認証に失敗しました。」という前の試行の入力エラーを
メールアドレス欄に表示し続ける**。上限到達の通知 (トースト) は数秒で消えるため、
最終的に利用者の目に残る説明は「入力が間違っている」という**実態と食い違う理由**になる。
運用者は正しい資格情報を入れ直しても弾かれ続ける理由が分からず、
パスワードの再設定など不要な操作へ誘導される。

裁定 AG-017b は本件を採用済み・未着手として登録している
(本リポジトリでの着手は本設計が最初)。

### 現状の事実確認 (実読で確認したもの)

| 事実 | 根拠 |
|---|---|
| panel は vendor の Login をそのまま使う | `app/Providers/Filament/AdminPanelProvider.php` の `->login()` |
| 上限到達時は通知のみで早期 return する | `vendor/filament/filament/src/Auth/Pages/Login.php::authenticate()` |
| 入力エラーは次の要求へ持ち越される | `vendor/livewire/livewire/src/Features/SupportValidation/SupportValidation.php` の `dehydrate`/`hydrate` |
| 通知そのものは表示される (画面に出ない訳ではない) | `vendor/filament/filament/resources/views/components/layout/base.blade.php` が `Filament\Livewire\Notifications` を描画。日本語訳も vendor に同梱 (`ログインの試行回数が多すぎます` / `:seconds 秒後に再試行してください。`) |
| 多要素チャレンジ側も同じ早期 return を持つ | 同 `isMultiFactorChallengeRateLimited()` |
| 上限値は 5、鍵は `livewire-rate-limiter:sha1(コンポーネント名｜メソッド名｜IP)` | `vendor/danharrin/livewire-rate-limiting/src/WithRateLimiting.php` |

## 改善アイデア

**「上限に達した瞬間に、前の試行の入力エラーを消す」** ことだけを行う。

Filament 公式の作法どおり、panel に独自のログインページクラスを差す
(`->login(App\Filament\Auth\Login::class)`) 。独自クラスは vendor の
`Filament\Auth\Pages\Login` を継承し、**上限到達時にだけ呼ばれる 1 メソッド
`getRateLimitedNotification()` だけを上書きする**。上書き内容は
「持ち越された入力エラーを消してから、vendor の通知をそのまま返す」の 2 行である。

これで画面には**上限到達の通知だけ**が残り、入力欄には矛盾する説明が出なくなる。
同じメソッドは多要素チャレンジの上限到達でも呼ばれるため、そちらの食い違いも同時に消える。

### なぜ `authenticate()` を上書きしないのか

| 案 | 判断 | 理由 |
|---|---|---|
| `authenticate()` を丸ごと写して上書き | 却下 | vendor の 80 行超を複写することになり、Filament 更新のたびに黙って古くなる |
| 子で `rateLimit(5)` を呼んでから `parent::authenticate()` | 却下 | `rateLimit()` は**評価と計上を両方行う**ため 1 回の送信で 2 回計上され、**実効の上限が 5 から半減する**。ドメイン固有規約 5 「閾値は既存値を変えない」に反する |
| 子で「計上せずに超過だけ確認」してから `parent::authenticate()` | 却下 | 上限値 5 を子が知る必要があり、vendor 側の値と二重管理になる |
| `getRateLimitedNotification()` だけ上書き | **採用** | 上限到達時にだけ呼ばれる vendor の拡張点で、上限値も判定順序も vendor に残る。`authenticate()` を継承のまま保てるため、後述の既存検査 (`ThrottleExemptionPremiseTest`) が **実際に使われるクラス**を対象にしても成立し続ける |

`get〜` という名前のメソッドで状態を変えることになる点は認識している。
Filament が上限到達時に用意している拡張点はこの 1 つだけであり、
「上限に達したときにだけ通る経路」という意味は名前ではなく**呼ばれる位置**が担っている。
実装ではその理由を日本語コメントで残す。

### 上限到達を入力欄にも出すか (検討して見送る)

上限の説明を入力欄の誤りとしても出す案 (Fortify 側の `auth.throttle` と同じ見せ方) は見送る。

- 通知は現に描画されており (上表)、日本語訳も同梱されている = **説明が無い状態ではない**
- 同じ内容を通知と入力欄に二重に出すことになる
- 多要素チャレンジの上限到達時はメールアドレス欄が非表示のため、
  `data.email` に付けた説明は**見えない場所に置かれる**か、誤った欄を指すことになる

本件の欠陥は「説明が無いこと」ではなく「**古い説明が残って新しい説明と食い違うこと**」なので、
古い説明を消す 1 点で閉じる (思考原則 2)。

## 期待効果

- 使命への貢献: 直接の貢献ではなく**運用面の防護**。管理画面は SOP・組織・課金の設定を触る面であり、
  運用者が締め出された理由を誤解すると復旧が遅れる。理由の食い違いを消すことは
  「詰みを作らない」という本リポジトリの UI 方針 (禁止事項 8 と同じ思想) に沿う
- 上限到達後の画面から、実態と矛盾する「認証に失敗しました。」が消える
- 多要素チャレンジの上限到達でも同じ食い違いが起きなくなる
- 流量制限の**閾値・鍵の意味・判定順序は 1 つも変わらない** (vendor のまま)

## 実装方針（概要）

1. `app/Filament/Auth/Login.php` を新設 (vendor Login を継承、`getRateLimitedNotification()` のみ上書き)
2. `AdminPanelProvider` の `->login()` に独自クラスを渡す
3. 置き場所は `app/Filament/Auth/` とする。`app/Filament/Pages/` 配下に置くと
   panel の自動発見 (`discoverPages`) が**通常ページとして登録してしまい**、
   `/admin/login` とは別に管理画面のページ route と操作メニュー項目が生える。
   自動発見の対象は `Filament/Resources` `Filament/Pages` `Filament/Widgets` の 3 つだけなので、
   `Filament/Auth` に置けば構造として発見されない
4. 検査 (テストファースト):
   - 上限到達の再現テスト = 5 回失敗させてから 6 回目を送り、
     **入力エラーが残っていないこと**と**上限到達の通知が出ること**を固定する
   - 上限に達する前は従来どおり入力エラーが出ることも固定する (消しすぎの検出)
   - panel が使うログインページが独自クラスであり、`filament.admin.auth.*` の route 集合が
     変わっていないこと (自動発見で余計な route が生えていないこと) を固定する
5. 既存検査の追随: `tests/Feature/Security/ThrottleExemptionPremiseTest.php` は
   `default-livewire.update` の免除根拠として **vendor クラスの** `Login::authenticate()` に
   `rateLimit(` があることを走査している。panel が使うクラスが変わる以上、走査対象を
   **panel が実際に使うログインページクラス**へ差し替える (継承のままなので走査は成立する)。
   差し替えないと、独自クラス側で上限が外されても検査は緑のままになる

## 制約・前提

- 流量制限の閾値・鍵の生成規則は vendor 側に残す (ドメイン固有規約 5)
- 継承によりコンポーネント名が変わるため、`livewire-rate-limiter:sha1(...)` の鍵の値が変わる。
  帰結は**反映時に計上中の回数が 1 度だけ 0 に戻る**ことだけで、閾値も鍵の書式規約も変わらない
  (この鍵は `RateLimiter::for()` の名前付き制限ではないため `RateLimiterKeyConventionTest` の母集団外)
- `/admin` は Inertia でも `web` グループでもないため、ドメイン固有規約 3 (3 枚セット) と
  bug-hunt 目録の対象外。本件でその境界は動かさない
- `TooManyRequestsException` は Filament が公開メソッドの引数型として使っている型なので、
  上書きのために取り込む。`composer.json` へ直接依存として足さない (Filament の API 面である)
- 新規 PHP ファイルは `declare(strict_types=1)` + 日本語コメント

## スコープ外

- 流量制限の閾値変更・レーン新設・route 側への流量制限の追加
- 上限到達の入力欄表示 (上で検討して見送り)
- ログイン画面の見た目・文言の変更 (vendor 訳をそのまま使う)
- 一般利用者側 (Fortify) のログイン表示の変更
- 管理画面ログインの監査記録・通知連携などの新機能

## 台帳 (lctl) 参照の状況

`get_feature("filament-login-throttle-display")` を 5 回試行したが、いずれも
MCP サーバーからの応答が無く 300 秒で中断した (環境要因)。よって本設計は
**任務で与えられた裁定 AG-017b の要約**と、本リポジトリの HEAD および vendor の実読だけを
根拠に組んでいる。台帳が読める状態になったら、正典の是正形と本設計の差分を確認すること。
