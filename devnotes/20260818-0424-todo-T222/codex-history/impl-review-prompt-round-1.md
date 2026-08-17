# T222 実装レビュー依頼 (Laravel + Svelte)

## アプリの使命 (North Star) — AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 — AGENTS.md より

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

実装規約: `declare(strict_types=1)` + 日本語コメント / Controller は薄く (Service 委譲) /
フロントは Svelte 5 runes + DS token のみ / component 階層は
`atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import /
PHP の `echo` `goto` `global` と開始タグ付きの出力記法は禁止。

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割 (system)

あなたは Laravel + Svelte の改善実装をレビューするコードレビュアーである。以下の観点でレビューせよ。

- **設計との一致性**: 詳細設計書のとおりに実装されているか。設計が「入れない」と決めたものを勝手に足していないか
- **正確性**: 中継 (flash の 1 hop 延命) の振る舞いが実際に成立するか。テストが偽陽性・偽陰性でないか
- **PHPStan 適合性 (level 10)**: 型の widen / ignore を使っていないか
- **DTO / JsonResource パターン**: JSON 応答の直書きが無いか
- **テスト網羅性**: 検査が degenerate PASS (常に緑) にならないか。負のコントロールがあるか
- **セキュリティ**: API キー平文 (`new_api_key`) の持ち越しが本当に消えているか。error の中継が fail-closed か
- **DESIGN.md 準拠**: color / radius / typography は token 経由か (本差分に CSS 変更は無い)
- **Atomic Design 準拠**: `resources/js/components/` の階層 (本差分に component 変更は無い)

### 出力形式

ファイルごとに判定を書き、指摘は **[Critical] / [Warning] / [Suggestion]** に分類せよ。
最後に全体判定として **APPROVED** または **CHANGES_REQUESTED** を明記せよ。

### 背景 (重要)

本実装は「家系の機能台帳 (lctl) が定めた正典 (laravel-claude-template@050ddc5) への追従」である。
`app/Support/Http/FlashNotificationRelay.php` / `tests/Architecture/FlashNotificationRelayDriftTest.php` /
`tests/js/architecture/flash-keys-sync.test.ts` は**正典からの逐語移植**であり、
コメントの日本語表現をアプリの文脈へ合わせる以外の改変は意図的に行っていない
(独自形になると家系の逸脱台帳への登録が必要になるため)。
したがって「正典の書き方をより良い書き方へ変える」提案は、**逸脱を作る費用**を踏まえて判断すること。
一方、aicue 固有の判断 (allowlist が 1 件であること・跳ね返り 2 箇所の置換・新規 Feature テストの
観測点の置き方) は本タスクの固有部分であり、遠慮なく指摘してよい。

## 詳細設計書

# 詳細設計: flash 通知の単一出典 (FlashNotificationRelay) と両レーンの drift gate

対象機能 (家系機能台帳): `inertia-integration` / 裁定 **AG-057** / 台帳上の aicue は `pending` (pre-t0)
概念設計: [conceptual-design.md](./conceptual-design.md) (conceptual-review Round 2 で APPROVED)
正典照合の記録: [codex-history/canon-reconciliation.md](./codex-history/canon-reconciliation.md)

> **本書は正典照合 (Step 0) 後に書き直したものである。**
> 照合前の版 (台帳へ到達できない状態で HEAD の課題から起こした設計) と、それに対する
> Codex 詳細設計レビュー 5 ラウンドの記録は
> `codex-history/detailed-design-pre-reconciliation.md` と `detailed-review-round-1..5.md` に残してある。
> 照合の結果 **全項目で正典へ寄せた** (自案の上積みは持ち込まない)。理由は照合記録に書いた。

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告 (不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen (型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作 (`migrate:fresh` 等) をエージェント判断で実行すること
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

本リポジトリ固有: `declare(strict_types=1)` + 日本語コメント (追跡下 PHP 全数) /
`echo`・`goto`・`global`・開始タグ付きの出力記法の禁止 / component 階層の単方向 import。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`) / **RefreshDatabase** グローバル適用 (個別 `DatabaseTransactions` 禁止)
- テストデータは Factory 生成 (本設計は新モデルを追加しないため Factory 追加なし)
- DTO + JsonResource パターン (本設計は JSON 応答を新設しないため対象外)
- コードフォーマット: `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 何を直すのか (HEAD の実測)

flash キーの語彙は 4 箇所に現れる。HEAD にはそれを束ねる出所が無い。

| 箇所 | HEAD の状態 |
|---|---|
| 書き手 | `app/` の `->with('success'|'error'|'info'|'warning', …)` が 83 箇所。ほかに `new_api_key` (API キー平文の 1 度きり表示) |
| 中継 | **無い** (中間 redirect を跨ぐ延命は各 middleware の `session()->reflash()` で行っている。2 箇所) |
| 共有 | `HandleInertiaRequests::share()` が 4 キーを**直書き**で `session()->get()` |
| 画面側 | `resources/js/lib/stores/flash-to-toast.ts` の `FLASH_KEYS` が**直書き** (export もされていない) |

壊れ方はどちらも無音である。

- 語彙を片側にだけ足すと、そのメッセージは**送られているのに 1 度も表示されない**
- 書き手が打ち間違えると (`succes` 等)、その通知は誰にも読まれずに消える
- 中間 redirect を跨ぐ延命が `reflash()` なので、**通知でない flash まで延命される**。
  aicue には `new_api_key` (API キーの平文) があり、課金ゲートの跳ね返りが挟まると
  平文が 1 hop 余分に session に残る

正典 (laravel-claude-template@050ddc5) は、語彙の SoT クラスと両レーンの drift gate、
そして「通知だけを 1 hop 延命する窓口」でこれを閉じている。本設計はその形へ追従する。

## Step 0: 正典照合 — **実施済み**

`codex-history/canon-reconciliation.md` に記録した (台帳 revision / 正典 commit / 3 点の比較結果 /
差異をどちらへ寄せたか)。実装タスクでは**照合をやり直す必要はない**が、着手時に
`get_feature("inertia-integration")` を 1 度引いて **feature_revision が `12-8f92b7e8ecfe` から
動いていないか**だけ確認すること (動いていたら差分を読む)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | PHP レーンの drift gate (先に赤にする) | `tests/Architecture/FlashNotificationRelayDriftTest.php` (新規) | 高 |
| 2 | TS レーンの drift gate (先に赤にする) | `tests/js/architecture/flash-keys-sync.test.ts` (新規) | 高 |
| 3 | 中継クラスの新設 (正典の移植) | `app/Support/Http/FlashNotificationRelay.php` (新規) | 高 |
| 4 | 共有 prop を SoT から導出 | `app/Http/Middleware/HandleInertiaRequests.php` | 高 |
| 5 | 画面側の `FLASH_KEYS` を export | `resources/js/lib/stores/flash-to-toast.ts` | 高 |
| 6 | 跳ね返りの延命を `reflash()` から中継へ | `app/Http/Middleware/RequireActiveSubscription.php` / `app/Http/Middleware/EnsureAccountNotPendingDeletion.php` / `tests/Feature/Inertia/FlashNotificationRelayBounceTest.php` (新規) | 中 |

実装順は **1 → 2 (赤を確認) → 3 → 4 → 5 → 6**。テストファースト (思考原則 5)。

---

## 施策 3: 中継クラスの新設 (正典の移植)

先に完成形を示す (施策 1・2 の検査対象になるため)。

### 変更箇所

- 新規: `app/Support/Http/FlashNotificationRelay.php`

置き場所・名前空間・定数名・メソッド名は**正典と同一**にする (照合記録の表を参照)。

### 波及変更

- TypeScript 型定義: `resources/js/lib/stores/flash-to-toast.ts` (施策 5)。
  `resources/js/lib/shared-props.ts` は `FlashPayload` を import するだけなので**変更不要**
- API Resource/DTO: なし (JSON 応答を新設しない。Inertia 共有 prop のみ)
- テストファイル: 施策 1・2・6 で新規追加

### 変更後コード (正典の移植。コメントは aicue の文脈に合わせる)

```php
<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * 中間 redirect (跳ね返り) を 1 hop だけ跨いでユーザー向け通知を届ける単一窓口。
 *
 * Laravel の一時メッセージ (flash) は「次の 1 要求で失効」する。操作 → 中間 GET →
 * 別 redirect のように**中間 GET が別 redirect を返す**経路 (例: 課金ゲートの
 * オンボーディングへの跳ね返り) では、通知が描画される前に失効し
 * 「押しても何も起きない」画面になる。本クラスはその跳ね返り地点で
 * 「ユーザーに見せる通知」だけを延命する。
 *
 * 設計境界 (延命しすぎない):
 *  - `session()->reflash()` は使わない。1 度きり表示の内部状態 (`new_api_key` =
 *    API キー平文の発行直後 1 度きり表示) まで延命し、状態の持ち越しを生むため。
 *  - `errors` は ViewErrorBag ごと延命しない。**着地画面が実際に描画するキー**
 *    (RELAYABLE_ERROR_KEYS) だけを抽出して置き直す。初期値は空 = error は一切中継しない
 *    (fail-closed)。着地画面が描画する error キーが生まれた時点で opt-in 追加する
 *    (無条件の中継は着地画面のフォーム error キーと衝突して無関係な赤字を生む)。
 *  - default 以外の名前付き error bag は中継しない (アプリ内に使用箇所が無い。fail-closed)。
 */
final class FlashNotificationRelay
{
    public const string SUCCESS = 'success';

    public const string ERROR = 'error';

    public const string INFO = 'info';

    public const string WARNING = 'warning';

    /** session に ViewErrorBag が入るキー (Laravel 規約)。 */
    public const string ERRORS = 'errors';

    /**
     * Inertia がユーザーへ届ける通知 flash キーの SoT。
     * `HandleInertiaRequests::share()` が読み出しに使う唯一の定義であり、一致は
     * `tests/Architecture/FlashNotificationRelayDriftTest.php` (middleware / 書き手) と
     * `tests/js/architecture/flash-keys-sync.test.ts` (画面側の読み手 = flash-to-toast) が固定する。
     *
     * @var list<string>
     */
    public const array NOTIFICATION_KEYS = [
        self::SUCCESS,
        self::ERROR,
        self::INFO,
        self::WARNING,
    ];

    /**
     * 跳ね返りの着地画面が実際に描画する error キー (opt-in allowlist)。
     * 初期状態は空 (fail-closed の no-op。ViewErrorBag 抽出は拡張点として残す)。
     *
     * @var list<string>
     */
    public const array RELAYABLE_ERROR_KEYS = [];

    /** 跳ね返りの直前に呼ぶ。通知の一時メッセージを 1 hop 延命し、表示可能な error だけを置き直す。 */
    public static function relayTo(Session $session): void
    {
        $session->keep(self::NOTIFICATION_KEYS);
        self::relayDisplayableErrors($session);
    }

    /**
     * 中継対象 error キーの契約型を宣言する accessor (拡張点)。
     *
     * 初期値は空だが、契約としては list<string> であり、RELAYABLE_ERROR_KEYS へ
     * opt-in 追加した時点で抽出が生きる。定数を直接 foreach すると初期状態では
     * 静的解析上の到達不能コードになるため、契約型で受け渡す
     * (型を偽る注釈や無視指定ではなく、拡張点の宣言として書く)。
     *
     * @return list<string>
     */
    private static function relayableErrorKeys(): array
    {
        return self::RELAYABLE_ERROR_KEYS;
    }

    /**
     * ViewErrorBag の default bag から allowlist のキーだけを抜き、新しい bag として置き直す。
     * `keep(ERRORS)` は使わない (bag 全体が延命され allowlist が無効化されるため)。
     * allowlist のキーが 1 つも無ければ何もしない (元の errors は次の保存で自然に失効する)。
     */
    private static function relayDisplayableErrors(Session $session): void
    {
        $errors = $session->get(self::ERRORS);
        if (! $errors instanceof ViewErrorBag) {
            return;
        }

        $bag = $errors->getBag('default');

        /** @var array<string, list<string>> $relayed */
        $relayed = [];
        foreach (self::relayableErrorKeys() as $key) {
            /** @var list<string> $messages */
            $messages = [];
            foreach ($bag->get($key) as $message) {
                if (is_string($message) && $message !== '') {
                    $messages[] = $message;
                }
            }
            if ($messages !== []) {
                $relayed[$key] = $messages;
            }
        }

        if ($relayed === []) {
            return;
        }

        $session->flash(self::ERRORS, (new ViewErrorBag)->put('default', new MessageBag($relayed)));
    }
}
```

### 設計上の判断

- **正典を削らずに移植する**。`relayTo()` と error の allowlist を落として「語彙の定数だけ」に
  すると、家系で唯一の独自形になり `docs/template-divergence.md` への登録が要る。
  一方 aicue には `relayTo()` の使い所が**既に 2 箇所ある** (`reflash()` を呼んでいる middleware。
  施策 6 で置き換えるので、移植した瞬間から呼び出し元のないコードにはならない)。
- **session 値の正規化 (文字列以外を null に倒す) は入れない**。正典が持っていないためで、
  入れると画面側の `if (message)` 判定と二重になり、形も家系から外れる。
- `RELAYABLE_ERROR_KEYS` は**空のまま入れる** (fail-closed)。aicue の跳ね返り着地画面
  (`onboarding.checkout` / `onboarding.billing-required` / `settings`) が
  error を描画し始めた時点で opt-in 追加する。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`void` / `list<string>`)
- [x] null 安全 (`$errors instanceof ViewErrorBag` で narrowing。`Assert` は不要)
- [x] DTO を返している — 該当なし (session を書き換える窓口。値を返さない)
- [x] Generics の型パラメータが正しい (`list<string>` / `array<string, list<string>>`)

### テスト計画

- [ ] 施策 1・2 を先に書き、**クラス未存在で赤**になることを確認してから実装する
- [ ] `relayTo()` の振る舞い (通知の延命 / 延命しすぎないこと) は施策 6 の Feature テストで固定する
- [ ] **error を中継しない契約 (`RELAYABLE_ERROR_KEYS` が空 = fail-closed) も施策 6 で固定する**。
      クラスのコメントで詳しく契約化している以上、`new_api_key` だけでは境界が閉じない
      (canon-design-review Round 1 の指摘)。具体のケースは施策 6 のテスト計画に置いた
- [ ] 個別の `DatabaseTransactions` を使わない (グローバル適用に従う)

### リスク

- `App\Support\Http` 名前空間は aicue に既存 (`app/Support/Http/` の有無は実装時に確認し、
  無ければ作る)。`Illuminate\Contracts\Session\Session` を受けるので、
  `Request` を引き回さずに済む (テストからも直接呼べる)。
- **保証範囲を誇張しない**: このクラスが保証するのは「跳ね返りの直前に呼べば通知が 1 hop 延命される」
  ことだけである。呼び忘れは検出しない (呼び出し点の目録は持たない = 正典と同じ)。

---

## 施策 4: 共有 prop を SoT から導出

### 変更箇所

- `app/Http/Middleware/HandleInertiaRequests.php` (L36-41 の docblock / L83-89 の `flash` 節 /
  import の追加。private メソッドを 1 つ足す)

### 波及変更

- TypeScript 型定義: なし (キー名も並びも変わらない)
- API Resource/DTO: なし
- テストファイル: 施策 1 の 1 本目 (`share()` の実出力キー集合) が対応する

### 現行コード

```php
use Illuminate\Support\Str;

            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'warning' => $request->session()->get('warning'),
                'visitKey' => Str::uuid()->toString(),
            ],
```

### 変更後コード (正典と同形)

```php
use App\Support\Http\FlashNotificationRelay;
use Illuminate\Support\Str;   // visitKey の発行に引き続き使う

            // 通知キー集合の SoT は FlashNotificationRelay::NOTIFICATION_KEYS
            // (FlashNotificationRelayDriftTest が一致を固定)。visitKey は通知ではなく
            // 二重表示を抑える見分け用のため中継の対象外で別建て
            'flash' => [
                ...$this->notificationFlashProps($request),
                'visitKey' => Str::uuid()->toString(),
            ],
```

```php
    /**
     * 通知の一時メッセージ (キー集合は FlashNotificationRelay::NOTIFICATION_KEYS から導出)。
     *
     * @return array<string, mixed>
     */
    private function notificationFlashProps(Request $request): array
    {
        $flash = [];
        foreach (FlashNotificationRelay::NOTIFICATION_KEYS as $key) {
            $flash[$key] = $request->session()->get($key);
        }

        return $flash;
    }
```

docblock も 1 行だけ直す (`flash.visitKey は …` の行は残し、SoT が中継クラスにあることを足す)。

### 設計上の判断

- **振る舞いは同値**。読み出し元 (session)・キーの並び (`NOTIFICATION_KEYS` の順 = 現行と同じ)・
  `visitKey` の発行時点 (share 評価時) と毎回新しい値になること、のいずれも変えない。
- `share()` の他の共有 prop (`currentOrganization` / `notifications` / `invitationInbox` /
  `sessionEpoch` など。正典より多い) には**触らない**。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`array<string, mixed>`)
- [x] null 安全 (`session()->get()` の `mixed` をそのまま `mixed` として載せる。正典と同じ)
- [x] DTO — 該当なし (Inertia 共有 prop は配列)
- [x] Generics の型パラメータが正しい

### テスト計画

- [ ] 施策 1 の 1 本目が**実出力**でキー集合を固定する (静的な字句検査は置かない = 正典と同じ)
- [ ] 既存の Inertia 系 Feature テストが緑であること (`composer test`)

### リスク

- 「middleware が中継を呼ばずに直書きへ戻る」形は、この gate では**キー集合が同じなら通る**。
  正典もそこは見ていない (見ているのは書き手の走査と共有 prop の集合)。
  照合前の自案はここに字句検査を積んでいたが、独自形になるため持ち込まない
  (判断の経緯は照合記録)。

---

## 施策 5: 画面側の `FLASH_KEYS` を export

### 変更箇所

- `resources/js/lib/stores/flash-to-toast.ts` (1 行の `const` → `export const` と docblock の追記のみ)

### 波及変更

- TypeScript 型定義: なし (型は増やさない。`FlashPayload` も現状維持)
- テストファイル: `tests/js/lib/flash-to-toast.test.ts` は**変更不要** (挙動を変えない)。
  施策 2 の gate が新しい import 元になる

### 変更後コード (正典と同形)

```ts
/**
 * flash の各キーと toast type の対応 (キーが入っていれば対応する type で addToast する)。
 * キー集合の SoT は PHP 側 FlashNotificationRelay::NOTIFICATION_KEYS。一致は
 * tests/js/architecture/flash-keys-sync.test.ts が固定する (export はその参照用で挙動不変)。
 */
export const FLASH_KEYS = ["success", "error", "info", "warning"] as const;
```

### 設計上の判断

- 正典の `flash-to-toast.ts` と aicue の HEAD は**この 1 行と docblock 以外が既に同一**である
  (`FlashPayload` / `consumeFlash` / `resetFlashConsumption` / visitKey de-dup まで一致)。
  したがって画面側の変更はこれだけで正典に揃う。
- 照合前の自案にあった union 型の導入・`readFlash` の正規化器・消費経路の目録は**入れない**
  (正典に無く、独自形になるため)。

### テスト計画

- [ ] `tests/js/lib/flash-to-toast.test.ts` が無変更で緑であること (挙動不変の確認)
- [ ] `pnpm typecheck` / `pnpm lint` / `pnpm build` が緑であること

### リスク

- `export` を足すだけなので実行時の影響は無い。`FLASH_KEYS` が公開 API になるため、
  将来これを画面側で読む箇所が増えうるが、SoT は PHP 側であることを docblock に明記する。

---

## 施策 1: PHP レーンの drift gate

### 変更箇所

- 新規: `tests/Architecture/FlashNotificationRelayDriftTest.php`

**正典の同名ファイルを移植する** (走査器・自己検証を含む)。aicue 固有の差は allowlist の中身だけ。

### 波及変更

- なし (テスト追加のみ)

### 検査の構成 (正典と同じ 3 本 + 自己検証)

| # | 検査 | 何を止めるか |
|---|---|---|
| 1 | `share()` の実出力 flash キー集合 (`visitKey` を除く) = `NOTIFICATION_KEYS` (集合比較。順序は契約にしない) | 共有が SoT からずれること |
| 2 | `app/` 全走査で、**走査器が拾えるリテラル書き手**のキーがすべて `NOTIFICATION_KEYS` か**理由付き allowlist** に属する | 打ち間違い (`succes`) / 読み手のない通知キーの新設 |
| 3 | allowlist が理由付きで**初期件数から増えていない** | 「通知」を allowlist へ逃がすこと |
| 自己検証 | 走査器の正例 3・負例 5 (`describe('writer 走査ロジックの自己検証')`) | 走査器が壊れて全件通る degenerate PASS |

### 変更後コード (要点のみ。全文は正典を移植する)

```php
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Http\FlashNotificationRelay;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

it('Inertia が共有する flash キー集合が FlashNotificationRelay の SoT と一致する', function (): void {
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));

    $shared = app(HandleInertiaRequests::class)->share($request);
    $flash = $shared['flash'];
    Assert::isArray($flash);

    $actual = array_values(array_diff(array_keys($flash), ['visitKey']));
    $expected = FlashNotificationRelay::NOTIFICATION_KEYS;
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

/**
 * flash 書き手走査の allowlist (通知 toast ではない flash キー)。
 *
 * **初期件数 = 1**。「ユーザー通知」をここへ足してはならない (NOTIFICATION_KEYS へ足す)。
 * 増え始めたら内部状態の flash の SoT を別に立てるサインとして扱うこと。
 *
 * @return array<string, string> key => 除外理由
 */
function flashWriterScanAllowlist(): array
{
    return [
        'new_api_key' => 'API キー平文の発行直後 1 度きり表示に使う内部状態の flash (OrganizationApiKeyController)。ユーザー通知の toast ではなく、中継で延命してもならない',
    ];
}
```

> **母集団の言い方を広げない**: 2 番が見るのは「`app/` の flash 書き手すべて」ではなく
> 「**走査器が拾えるリテラル書き手**」である。動的キー (`BillingFeedbackKind::FLASH_KEY` /
> 変数経由) と `[a-z_]+` 以外のキー (camelCase) は母集団に入らない。
> gate の docblock にもこの言い方で書く。

走査器 (`flashWriterSignificantTokens` / `flashWriterMatchingIndex` / `flashWriterLiteralKey` /
`flashWriterStatementHasRedirectRoot` / `flashWriterDepth1ArrayKeys` / `flashWriterKeysFromSource`)
と、`app/` 全走査の本体、自己検証の `describe` ブロックは**正典から逐語で移植する**。

走査器が拾う形 (正典の docblock より):

- `->with('key', $value)` — redirect チェーンの scalar 形。第 2 引数必須で拾い、
  単一引数の Eloquent eager load (`->with('relation')`) は拾わない。
  第 2 引数が closure (`fn` / `function` / `static`) なら constrained eager load とみなし除外
- `->with(['key' => $value, …])` — 連想配列形。**同一文が `back()` / `redirect(` / `to_route(`
  を起点とするチェーンのときだけ**、配列の深さ 1 のキーのみ拾う
- `->flash('key', …)` / `::flash('key', …)` — session への直書き・facade 形 (receiver を問わない)
- キーは `[a-z_]+` に限る (view composer の `seoHead` のような camelCase は対象外)
- コメント・空白はトークン化で除去済み

### aicue 固有の値 (実測で確定させた)

- **allowlist は 1 件 (`new_api_key`)**。正典は 2 件だが、aicue には `status` を書く経路が
  **app/ に 1 つも無い** (`EnumerationSafePasswordResetLinkResponse` は
  `back()->with('success', …)` を返す。`status` の語はコメントにしかなく、走査はコメントを数えない)。
  これは**母集団の実測差**であって形の逸脱ではない
- `->with(BillingFeedbackKind::FLASH_KEY, …)` (`billing_feedback_kind`) は**キーが定数なので
  検出外**である (正典の走査器も動的キーは拾えないと明記している)。この値は
  「共有 flash の 4 キーと衝突しない名前」として意図的に語彙の外にある
- 2 引数の eager load (`->with('a', 'b')`) は app/ に**存在しない** (実測)。
  したがって走査の誤検知は現時点で無い

> **実装時に必ず確認**: 移植した走査を最初に走らせたとき、`new_api_key` 以外の違反が
> 出ないことを実際に確かめる。出たら「通知なら `NOTIFICATION_KEYS` へ / 内部状態なら
> 理由付きで allowlist へ」を個別に判断し、**allowlist の件数テストも同時に直す**。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`array<string, string>` / `list<string>` /
      `list<array{0: int, 1: string}>` — 正典の注釈をそのまま持ち込む)
- [x] null 安全 (`file_get_contents` の結果を `Assert::string()`、`$shared['flash']` を `Assert::isArray()`)
- [x] DTO — 該当なし
- [x] Generics の型パラメータが正しい

### テスト計画

- [ ] 実装前に走らせ、`FlashNotificationRelay` 未存在で**赤**になることを確認する
- [ ] 走査器の自己検証 (正例 3 / 負例 5) を正典から移植する
      (= degenerate PASS 防止の負のコントロール)
- [ ] allowlist の件数は完全一致で固定する (増えても減っても赤)
- [ ] 個別の `DatabaseTransactions` を使わない

### リスク

- 走査は `app/` 配下のみで、`routes/` は見ない (正典と同じ)。routes に flash 書き手を
  置く形が生まれたら検出できない — **現状 `routes/` に flash 書き手は無い** (実測) が、
  保証範囲としては誇張しない。
- 動的キー (定数・変数経由) と、変数に受けた RedirectResponse への後付け `with([...])` は
  検出できない (正典の docblock が明記している限界)。

---

## 施策 2: TS レーンの drift gate

### 変更箇所

- 新規: `tests/js/architecture/flash-keys-sync.test.ts`

**正典の同名ファイルを移植する** (パス以外そのまま使える)。

### 波及変更

- なし (テスト追加のみ)

### 変更後コード (正典の移植)

```ts
import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { FLASH_KEYS } from "@/lib/stores/flash-to-toast";

/**
 * 画面側の読み手 (flash-to-toast.ts の FLASH_KEYS) ⇔ PHP 側の SoT
 * (FlashNotificationRelay::NOTIFICATION_KEYS) のキー集合一致 gate。
 *
 * flash キー集合は「書き手 / 中継 / 共有 / 画面側の読み手」の 4 箇所に現れる。PHP 側 3 箇所は
 * tests/Architecture/FlashNotificationRelayDriftTest.php が固定し、本テストが最後の鎖
 * (実際に画面へ出す側) を閉じる。片方だけキーを足すと、通知が誰にも読まれず消える
 * (または読み手のない共有キーが残る) ずれをここで落とす。
 *
 * PHP ソースからの抽出は正規表現で行う。定義形が変わって抽出できなくなった場合は
 * 0 キー = fail に倒す (0 件一致の偽の緑を作らない)。
 */

const ROOT = path.resolve(__dirname, "../../..");
const relaySource = fs.readFileSync(
    path.join(ROOT, "app/Support/Http/FlashNotificationRelay.php"),
    "utf-8",
);

/** NOTIFICATION_KEYS 定義ブロックの self::CONST 参照を、string 定数の実値へ解決する */
function phpNotificationKeys(): string[] {
    const block = relaySource.match(
        /const array NOTIFICATION_KEYS = \[([\s\S]*?)\];/,
    );
    if (!block) throw new Error("NOTIFICATION_KEYS definition not found in FlashNotificationRelay.php");

    const constValues = new Map<string, string>();
    for (const m of relaySource.matchAll(/const string ([A-Z_]+) = '([a-z_]+)';/g)) {
        constValues.set(m[1], m[2]);
    }

    const keys: string[] = [];
    for (const ref of block[1].matchAll(/self::([A-Z_]+)/g)) {
        const value = constValues.get(ref[1]);
        if (value === undefined) {
            throw new Error(`NOTIFICATION_KEYS references unresolvable const: ${ref[1]}`);
        }
        keys.push(value);
    }
    return keys;
}

describe("flash keys sync (画面側の読み手 ⇔ PHP 側の SoT)", () => {
    it("PHP ソースから NOTIFICATION_KEYS を 1 件以上抽出できる (0 件一致は定義形の変更 = fail)", () => {
        expect(phpNotificationKeys().length).toBeGreaterThan(0);
    });

    it("flash-to-toast の FLASH_KEYS と NOTIFICATION_KEYS が集合一致する", () => {
        const php = [...phpNotificationKeys()].sort();
        const js = [...FLASH_KEYS].sort();

        expect(js).toEqual(php);
    });
});
```

### テスト計画

- [ ] 実装前に走らせ、中継クラス未存在 (ファイル読み込み失敗) で**赤**になることを確認する
- [ ] `FLASH_KEYS` の export (施策 5) が無い状態でも**赤**になることを確認する
- [ ] `__dirname` からの相対解決が aicue の vitest 設定で効くことを確認する
      (既存の `tests/js/architecture/*.test.ts` と同じ流儀。ROOT が
       リポジトリルートを指すかは実装時に 1 度確かめる)

### リスク

- PHP ソースを正規表現で読むため、定数の定義形 (`const string X = 'y';` /
  `const array NOTIFICATION_KEYS = [ … ];`) を変えると抽出できなくなる。
  そのときは 0 件一致で fail に倒れる (偽の緑にはならない)。
- **保証範囲を誇張しない**: 見るのは**キー集合の一致だけ**である。共有 prop の名前 (`flash`) や
  見分けキーの名前 (`visitKey`) のずれは見ない (正典も見ていない)。
  それらは施策 1 の 1 本目 (実出力の集合) と、既存の
  `tests/js/lib/flash-to-toast.test.ts` の振る舞いテストが部分的に受け持つ。

---

## 施策 6: 跳ね返りの延命を `reflash()` から中継へ

### 変更箇所

- `app/Http/Middleware/RequireActiveSubscription.php` (L102-104)
- `app/Http/Middleware/EnsureAccountNotPendingDeletion.php` (L67-68)
- 新規: `tests/Feature/Inertia/FlashNotificationRelayBounceTest.php`

### なぜこれを同じ変更に入れるのか

- HEAD の 2 箇所はどちらもコメントで「**直前の flash (他画面の success/error) を着地先まで保つ**」
  と書いているのに、実装は `reflash()` = **全部の flash を延命する**。意図と実装がずれている。
- aicue には `new_api_key` (API キーの平文) を flash で運ぶ経路があり、
  跳ね返りが挟まると平文が 1 hop 余分に session に残る。中継はこれを構造的に外す。
- 施策 3 で移植する `relayTo()` の唯一の使い所であり、ここを直さないと
  「呼び出し元のないコード」を残すことになる (後方互換の並走を残さない = 思考原則 3)。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 新規 1 本。既存の
  `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php` /
  `tests/Feature/Auth/AccountDeletionGraceTest.php` が緑のままであることを確認する

### 現行コード

```php
// RequireActiveSubscription.php
        // 直前 hop で積まれた flash (例: 招待受諾の success) が、この gate-redirect の
        // 1 hop で消費され失われないよう延命する。
        $request->session()->reflash();

// EnsureAccountNotPendingDeletion.php
        // 直前の flash (他画面の success/error) を着地先まで保つ。理由の flash は積まない。
        $request->session()->reflash();
```

### 変更後コード

```php
use App\Support\Http\FlashNotificationRelay;

        // 直前 hop で積まれた通知 (例: 招待受諾の success) が、この跳ね返りの 1 hop で
        // 消費され失われないよう延命する。**通知だけ**を延命する (reflash() にしない):
        // API キー平文のような 1 度きり表示の内部状態まで持ち越さないため。
        FlashNotificationRelay::relayTo($request->session());
```

両ファイルとも同じ置き換えで、コメントはそれぞれの文脈に合わせて 1 行足す。

### 設計上の判断

- **`errors` は延命しない** (`RELAYABLE_ERROR_KEYS` が空)。跳ね返りの着地画面
  (`onboarding.checkout` / `onboarding.billing-required` / `settings`) は
  直前 hop のフォーム検証エラーを描画しない。実装時に**着地画面が `errors` を読んでいないこと**を
  確認し、読んでいたら allowlist へ opt-in 追加する (fail-closed で始める)。
- `BillingController` の `session()->keep(['error'])` (L609) は**触らない**。
  これは跳ね返りではなく着地側で意図的に 1 キーだけ残している箇所で、別の判断が既にある。

### テスト計画 (テストファースト)

> **観測点の置き方 (canon-design-review Round 1 の [Critical] 対応)**
> 「着地画面の共有 prop に `new_api_key` が無いこと」を見るテストは**意味がない**。
> 着地画面はもともとその prop を公開しないので、`reflash()` のままでも緑になる
> (= 偽陽性)。見るべきは**跳ね返り応答の直後の session** である。
> また `withSession([...])` は値を置くだけで**一時メッセージの世代情報を作らない**ため、
> `keep()` / `reflash()` / 要求終了時の失効を正しく再現できない。
> **必ず本物の要求境界を跨いで一時メッセージを作る** (テスト専用 route で
> `redirect()->with(...)` / `session()->flash(...)` を実行する)。

- [ ] 新規 `tests/Feature/Inertia/FlashNotificationRelayBounceTest.php`。
      2 つの middleware (`RequireActiveSubscription` / `EnsureAccountNotPendingDeletion`) の
      **それぞれ**について、次の順で 1 本の流れを固定する:
  1. テスト専用の web route で `redirect(<跳ね返る先>)->with('success', …)` を実行し、
     同じ要求で `session()->flash('new_api_key', […])` も積む (実際の要求境界を跨ぐ)
  2. 次の要求で対象 middleware の跳ね返りを発生させる
  3. **跳ね返り応答の直後の session** を直接 assert する —
     `success` は残っている / **`new_api_key` は無い**
     (この 1 行が `reflash()` では確実に赤くなり、`relayTo()` でだけ緑になる)
  4. 着地の GET で `flash.success` が Inertia 共有 prop に載る
  5. 着地 GET の**後**にもう 1 度読むと `success` が失効している (延命は 1 hop だけ)。
     このとき**再び中継を通る route を使わない** (通ると延命が繰り返され判定にならない)
- [ ] **error を中継しない契約 (fail-closed) の固定** — 同ファイルに置く:
  - [ ] 検証エラー (`errors` の default bag) を積んで跳ね返りを通し、
        跳ね返り直後の session に `errors` が**残っていない**
  - [ ] 名前付き bag (`errors` の default 以外) も**残っていない**
  - [ ] `errors` に `ViewErrorBag` でない値が入っていても再 flash されない (置き直しをしない)
  - [ ] 将来 `RELAYABLE_ERROR_KEYS` へキーを足すときは、**同じ変更で**
        「許可キーだけ残る (正例) / それ以外と名前付き bag は残らない (負例)」を足すことを
        テストの docblock に契約として書く
- [ ] 通知キーの検査範囲: 3 の観測は代表値として `success` で行い、
      **4 キーすべてを `keep()` することは `NOTIFICATION_KEYS` を回す実装で一意に決まる**ことを
      テストの docblock に明記する (正典と同形を優先。dataset で 4 キーを回してもよいが、
      回すなら 2 つの middleware × 4 キーになるため代表値 + 明記を既定とする)
- [ ] 既存の `RequireActiveSubscriptionMiddlewareTest` / `AccountDeletionGraceTest` が緑
- [ ] Factory でデータを作る (`createOrganizationWithOwner` 等の既存ヘルパを使う)
- [ ] 個別の `DatabaseTransactions` を使わない

### リスク

- `reflash()` → `relayTo()` で**延命される集合が狭くなる**。意図した縮小だが、
  現在 `reflash()` に依存して着地している flash が他にないかを、実装時に
  「跳ね返りの手前で flash を積む経路」から確認する
  (`new_api_key` は積まれていても着地に不要、`billing_feedback_kind` は
   跳ね返りではなく `/billing` 着地で消費されるため対象外)。
- **保証範囲を誇張しない**: この施策が閉じるのは**この 2 つの跳ね返り**だけである。
  新しい跳ね返りを作ったときに中継を呼ぶことは、機械では強制していない
  (正典も目録を持たない)。
- テストが**偽陽性になりやすい形**なので、上の観測点 (跳ね返り直後の session) を必ず守る。
  実装前に `reflash()` のままで新テストを走らせ、`new_api_key` の 1 行が**赤になること**を
  目で確認してから置き換えること (テストファーストの肝がここにある)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 新規 3 ファイル + 既存 4 ファイルの小変更で閉じる。ただし `HandleInertiaRequests::share()` と 2 つの middleware は他タスクと衝突しやすいので、単独ブランチで短時間に閉じる |
| 競合リスク | `HandleInertiaRequests.php` (共有 prop を足す他タスク) / `flash-to-toast.ts` (通知 UI の変更)。いずれも行単位では離れており解決は容易 |

## 検証コマンド (全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

## ドキュメント更新

- `docs/template-divergence.md`: **登録しない**。本設計は正典への収束であって逸脱ではない
  (allowlist が 1 件なのは母集団の実測差であり、形の逸脱ではない)
- `AGENTS.md` / `docs/architecture.md`: **追記しない**。新しい不変条件を足すのではなく、
  既存の「flash → toast」経路の出所を 1 つにするだけである (2 か所に書くと必ず食い違う)。
  契約の説明はクラスと gate の docblock に置く (正典と同じ置き方)

## この設計が扱わないこと (t1 の残り)

台帳の `canonical_version` は t1 で、t0 に次の 2 つを足す。**本設計はどちらも扱わない**
(別タスクとして起票する)。

1. **更新成功通知の全数申告 gate** (`tests/Architecture/MutationRedirectFlashTest.php`)。
   更新系の action が成功通知を伴って戻ることを静的に検査する。正典と spirux が持つ
2. **起動失敗時に白画面にしない配線** (`resources/js/lib/boot-failure.ts` + `app.ts` と
   その構文固定 gate)。正典のみが持つ

また、画面の解決処理と例外の防護 (aigenba 由来、AG-057 で t1 に採られた要素) も範囲外である。

## 実装差分 (git diff)

```diff
diff --git a/app/Http/Middleware/EnsureAccountNotPendingDeletion.php b/app/Http/Middleware/EnsureAccountNotPendingDeletion.php
index e02d575..e78ca04 100644
--- a/app/Http/Middleware/EnsureAccountNotPendingDeletion.php
+++ b/app/Http/Middleware/EnsureAccountNotPendingDeletion.php
@@ -7,6 +7,7 @@
 use App\DataTransferObjects\Account\AccountDeletionStateDto;
 use App\Enums\Account\AccountDeletionFreezeAllowance;
 use App\Models\User;
+use App\Support\Http\FlashNotificationRelay;
 use Closure;
 use Illuminate\Http\Request;
 use Symfony\Component\HttpFoundation\Response;
@@ -64,8 +65,10 @@ public function handle(Request $request, Closure $next): Response
             abort(Response::HTTP_CONFLICT, self::FROZEN_MESSAGE);
         }
 
-        // 直前の flash (他画面の success/error) を着地先まで保つ。理由の flash は積まない。
-        $request->session()->reflash();
+        // 直前の通知 (他画面の success/error) を着地先まで保つ。理由の flash は積まない。
+        // **通知だけ**を延命する (reflash() にしない): API キー平文のような
+        // 1 度きり表示の内部状態まで持ち越さないため。
+        FlashNotificationRelay::relayTo($request->session());
 
         return redirect()->route('settings');
     }
diff --git a/app/Http/Middleware/HandleInertiaRequests.php b/app/Http/Middleware/HandleInertiaRequests.php
index f91727a..0c868a7 100644
--- a/app/Http/Middleware/HandleInertiaRequests.php
+++ b/app/Http/Middleware/HandleInertiaRequests.php
@@ -9,6 +9,7 @@
 use App\Services\Marketing\ContactUrl;
 use App\Services\Organization\OrganizationMembershipService;
 use App\Support\Auth\SessionEpoch;
+use App\Support\Http\FlashNotificationRelay;
 use App\Support\Seo\SeoManager;
 use Illuminate\Http\Request;
 use Illuminate\Support\Str;
@@ -35,6 +36,7 @@ public function version(Request $request): ?string
 
     /**
      * 全ページ共有 props。
+     * 通知 flash のキー集合の SoT は FlashNotificationRelay::NOTIFICATION_KEYS。
      * flash.visitKey は flash-to-toast の de-dup 用 (同一 flash の二重表示防止)。
      *
      * @return array<string, mixed>
@@ -80,11 +82,11 @@ public function share(Request $request): array
                 'pendingCount' => fn (): int => app(OrganizationMembershipService::class)
                     ->pendingInvitationCountFor($user),
             ],
+            // 通知キー集合の SoT は FlashNotificationRelay::NOTIFICATION_KEYS
+            // (FlashNotificationRelayDriftTest が一致を固定)。visitKey は通知ではなく
+            // 二重表示を抑える見分け用のため中継の対象外で別建て
             'flash' => [
-                'success' => $request->session()->get('success'),
-                'error' => $request->session()->get('error'),
-                'info' => $request->session()->get('info'),
-                'warning' => $request->session()->get('warning'),
+                ...$this->notificationFlashProps($request),
                 'visitKey' => Str::uuid()->toString(),
             ],
             // 問い合わせ CTA の宛先 (内部 /contact / 外部 URL / mailto を config 駆動で切替)。
@@ -112,6 +114,21 @@ public function share(Request $request): array
         ];
     }
 
+    /**
+     * 通知の一時メッセージ (キー集合は FlashNotificationRelay::NOTIFICATION_KEYS から導出)。
+     *
+     * @return array<string, mixed>
+     */
+    private function notificationFlashProps(Request $request): array
+    {
+        $flash = [];
+        foreach (FlashNotificationRelay::NOTIFICATION_KEYS as $key) {
+            $flash[$key] = $request->session()->get($key);
+        }
+
+        return $flash;
+    }
+
     /**
      * ユーザー所属組織の一覧 (組織切替 UI 用。Phase 2c で sidebar に配線する)。
      *
diff --git a/app/Http/Middleware/RequireActiveSubscription.php b/app/Http/Middleware/RequireActiveSubscription.php
index a1dac03..0eacacd 100644
--- a/app/Http/Middleware/RequireActiveSubscription.php
+++ b/app/Http/Middleware/RequireActiveSubscription.php
@@ -9,6 +9,7 @@
 use App\Models\User;
 use App\Services\Billing\BillingAccess;
 use App\Services\Onboarding\OnboardingReturnResolver;
+use App\Support\Http\FlashNotificationRelay;
 use Closure;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Gate;
@@ -99,9 +100,10 @@ public function handle(Request $request, Closure $next): Response
             $this->returnResolver->rememberForOrganization($organization, '/'.ltrim($request->path(), '/'));
         }
 
-        // 直前 hop で積まれた flash (例: 招待受諾の success) が、この gate-redirect の
-        // 1 hop で消費され失われないよう延命する。
-        $request->session()->reflash();
+        // 直前 hop で積まれた通知 (例: 招待受諾の success) が、この gate-redirect の
+        // 1 hop で消費され失われないよう延命する。**通知だけ**を延命する (reflash() にしない):
+        // API キー平文のような 1 度きり表示の内部状態まで持ち越さないため。
+        FlashNotificationRelay::relayTo($request->session());
 
         // 遮断理由は着地ページが持つ (middleware は error flash を積まない)。
         return redirect()->route(
diff --git a/app/Support/Http/FlashNotificationRelay.php b/app/Support/Http/FlashNotificationRelay.php
new file mode 100644
index 0000000..8367951
--- /dev/null
+++ b/app/Support/Http/FlashNotificationRelay.php
@@ -0,0 +1,125 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Http;
+
+use Illuminate\Contracts\Session\Session;
+use Illuminate\Support\MessageBag;
+use Illuminate\Support\ViewErrorBag;
+
+/**
+ * 中間 redirect (跳ね返り) を 1 hop だけ跨いでユーザー向け通知を届ける単一窓口。
+ *
+ * Laravel の一時メッセージ (flash) は「次の 1 要求で失効」する。操作 → 中間 GET →
+ * 別 redirect のように**中間 GET が別 redirect を返す**経路 (例: 課金ゲートの
+ * オンボーディングへの跳ね返り) では、通知が描画される前に失効し
+ * 「押しても何も起きない」画面になる。本クラスはその跳ね返り地点で
+ * 「ユーザーに見せる通知」だけを延命する。
+ *
+ * 設計境界 (延命しすぎない):
+ *  - `session()->reflash()` は使わない。1 度きり表示の内部状態 (`new_api_key` =
+ *    API キー平文の発行直後 1 度きり表示) まで延命し、状態の持ち越しを生むため。
+ *  - `errors` は ViewErrorBag ごと延命しない。**着地画面が実際に描画するキー**
+ *    (RELAYABLE_ERROR_KEYS) だけを抽出して置き直す。初期値は空 = error は一切中継しない
+ *    (fail-closed)。着地画面が描画する error キーが生まれた時点で opt-in 追加する
+ *    (無条件の中継は着地画面のフォーム error キーと衝突して無関係な赤字を生む)。
+ *  - default 以外の名前付き error bag は中継しない (アプリ内に使用箇所が無い。fail-closed)。
+ *
+ * **保証範囲を誇張しない**: 保証するのは「跳ね返りの直前に呼べば通知が 1 hop 延命される」
+ * ことだけである。呼び忘れは検出しない (呼び出し点の目録は持たない)。
+ */
+final class FlashNotificationRelay
+{
+    public const string SUCCESS = 'success';
+
+    public const string ERROR = 'error';
+
+    public const string INFO = 'info';
+
+    public const string WARNING = 'warning';
+
+    /** session に ViewErrorBag が入るキー (Laravel 規約)。 */
+    public const string ERRORS = 'errors';
+
+    /**
+     * Inertia がユーザーへ届ける通知 flash キーの SoT。
+     * `HandleInertiaRequests::share()` が読み出しに使う唯一の定義であり、一致は
+     * `tests/Architecture/FlashNotificationRelayDriftTest.php` (middleware / 書き手) と
+     * `tests/js/architecture/flash-keys-sync.test.ts` (画面側の読み手 = flash-to-toast) が固定する。
+     *
+     * @var list<string>
+     */
+    public const array NOTIFICATION_KEYS = [
+        self::SUCCESS,
+        self::ERROR,
+        self::INFO,
+        self::WARNING,
+    ];
+
+    /**
+     * 跳ね返りの着地画面が実際に描画する error キー (opt-in allowlist)。
+     * 初期状態は空 (fail-closed の no-op。ViewErrorBag 抽出は拡張点として残す)。
+     *
+     * @var list<string>
+     */
+    public const array RELAYABLE_ERROR_KEYS = [];
+
+    /** 跳ね返りの直前に呼ぶ。通知の一時メッセージを 1 hop 延命し、表示可能な error だけを置き直す。 */
+    public static function relayTo(Session $session): void
+    {
+        $session->keep(self::NOTIFICATION_KEYS);
+        self::relayDisplayableErrors($session);
+    }
+
+    /**
+     * 中継対象 error キーの契約型を宣言する accessor (拡張点)。
+     *
+     * 初期値は空だが、契約としては list<string> であり、RELAYABLE_ERROR_KEYS へ
+     * opt-in 追加した時点で抽出が生きる。定数を直接 foreach すると初期状態では
+     * 静的解析上の到達不能コードになるため、契約型で受け渡す
+     * (型を偽る注釈や無視指定ではなく、拡張点の宣言として書く)。
+     *
+     * @return list<string>
+     */
+    private static function relayableErrorKeys(): array
+    {
+        return self::RELAYABLE_ERROR_KEYS;
+    }
+
+    /**
+     * ViewErrorBag の default bag から allowlist のキーだけを抜き、新しい bag として置き直す。
+     * `keep(ERRORS)` は使わない (bag 全体が延命され allowlist が無効化されるため)。
+     * allowlist のキーが 1 つも無ければ何もしない (元の errors は次の保存で自然に失効する)。
+     */
+    private static function relayDisplayableErrors(Session $session): void
+    {
+        $errors = $session->get(self::ERRORS);
+        if (! $errors instanceof ViewErrorBag) {
+            return;
+        }
+
+        $bag = $errors->getBag('default');
+
+        /** @var array<string, list<string>> $relayed */
+        $relayed = [];
+        foreach (self::relayableErrorKeys() as $key) {
+            /** @var list<string> $messages */
+            $messages = [];
+            foreach ($bag->get($key) as $message) {
+                if (is_string($message) && $message !== '') {
+                    $messages[] = $message;
+                }
+            }
+            if ($messages !== []) {
+                $relayed[$key] = $messages;
+            }
+        }
+
+        if ($relayed === []) {
+            return;
+        }
+
+        $session->flash(self::ERRORS, (new ViewErrorBag)->put('default', new MessageBag($relayed)));
+    }
+}
diff --git a/resources/js/lib/stores/flash-to-toast.ts b/resources/js/lib/stores/flash-to-toast.ts
index b3465c4..a22320a 100644
--- a/resources/js/lib/stores/flash-to-toast.ts
+++ b/resources/js/lib/stores/flash-to-toast.ts
@@ -19,8 +19,12 @@ export interface FlashPayload {
 /** 最後に消費した visitKey (モジュール変数で保持し、同一 visit の再評価を抑止する) */
 let lastVisitKey: string | null = null;
 
-/** flash の各キーと toast type の対応 (キーが入っていれば対応する type で addToast する) */
-const FLASH_KEYS = ["success", "error", "info", "warning"] as const;
+/**
+ * flash の各キーと toast type の対応 (キーが入っていれば対応する type で addToast する)。
+ * キー集合の SoT は PHP 側 FlashNotificationRelay::NOTIFICATION_KEYS。一致は
+ * tests/js/architecture/flash-keys-sync.test.ts が固定する (export はその参照用で挙動不変)。
+ */
+export const FLASH_KEYS = ["success", "error", "info", "warning"] as const;
 
 /**
  * flash payload を toast に変換して enqueue する。
diff --git a/tests/Architecture/FlashNotificationRelayDriftTest.php b/tests/Architecture/FlashNotificationRelayDriftTest.php
new file mode 100644
index 0000000..6f2dc92
--- /dev/null
+++ b/tests/Architecture/FlashNotificationRelayDriftTest.php
@@ -0,0 +1,408 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\HandleInertiaRequests;
+use App\Support\Http\FlashNotificationRelay;
+use Illuminate\Http\Request;
+use Webmozart\Assert\Assert;
+
+/*
+ * 「Inertia がユーザーへ届ける通知 flash キー」の単一出典 (SoT) ずれ検査。
+ *
+ * flash キー集合は「書き手 (controller/middleware の ->with()) / 中継 (FlashNotificationRelay) /
+ * 共有 (HandleInertiaRequests) / 画面側の読み手 (flash-to-toast.ts)」の 4 箇所に現れる。
+ * SoT (FlashNotificationRelay::NOTIFICATION_KEYS) と食い違うと、跳ね返りの中継から
+ * 漏れた通知が中間 redirect で失効する (「押しても何も起きない」の再発)、または
+ * 打ち間違いのキー ('succes' 等) の通知が誰にも読まれず消える。本検査は PHP 側 3 箇所を固定し、
+ * 画面側の読み手との一致は tests/js/architecture/flash-keys-sync.test.ts が固定する。
+ * 順序は契約にしない (集合として比較)。
+ *
+ * **母集団を広げて言わない**: 2 本目が見るのは「app/ の flash 書き手すべて」ではなく
+ * 「走査器が拾えるリテラル書き手」である。動的キー (定数・変数経由) と `[a-z_]+` 以外の
+ * キー (camelCase) は母集団に入らない。
+ */
+
+it('Inertia が共有する flash キー集合が FlashNotificationRelay の SoT と一致する', function (): void {
+    $request = Request::create('/');
+    $request->setLaravelSession(app('session.store'));
+
+    $shared = app(HandleInertiaRequests::class)->share($request);
+    $flash = $shared['flash'];
+    Assert::isArray($flash);
+
+    $actual = array_values(array_diff(array_keys($flash), ['visitKey']));
+    $expected = FlashNotificationRelay::NOTIFICATION_KEYS;
+    sort($actual);
+    sort($expected);
+
+    expect($actual)->toBe($expected);
+});
+
+/**
+ * flash 書き手走査の allowlist (通知 toast ではない flash キー)。
+ *
+ * **初期件数 = 1**。「ユーザー通知」をここへ足してはならない (NOTIFICATION_KEYS へ足す)。
+ * 増え始めたら内部状態の flash の SoT を別に立てるサインとして扱うこと。
+ *
+ * @return array<string, string> key => 除外理由
+ */
+function flashWriterScanAllowlist(): array
+{
+    return [
+        'new_api_key' => 'API キー平文の発行直後 1 度きり表示に使う内部状態の flash (OrganizationApiKeyController)。ユーザー通知の toast ではなく、中継で延命してもならない',
+    ];
+}
+
+/**
+ * PHP ソースを「意味のあるトークン列」に落とす (空白・コメントを除去)。
+ * 文字列リテラルは flash キーそのものなので残す。
+ *
+ * @return list<array{0: int, 1: string}> [token id (リテラルは -1), text]
+ */
+function flashWriterSignificantTokens(string $source): array
+{
+    $out = [];
+    foreach (token_get_all($source) as $token) {
+        if (is_string($token)) {
+            $out[] = [-1, $token];
+
+            continue;
+        }
+        if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG], true)) {
+            continue;
+        }
+        $out[] = [$token[0], $token[1]];
+    }
+
+    return $out;
+}
+
+/**
+ * `$start` の開き記号に対応する閉じ記号の index を返す (無ければ null)。
+ *
+ * @param  list<array{0: int, 1: string}>  $tokens
+ */
+function flashWriterMatchingIndex(array $tokens, int $start, string $open, string $close): ?int
+{
+    $depth = 0;
+    $count = count($tokens);
+    for ($i = $start; $i < $count; $i++) {
+        if ($tokens[$i][1] === $open) {
+            $depth++;
+
+            continue;
+        }
+        if ($tokens[$i][1] !== $close) {
+            continue;
+        }
+        $depth--;
+        if ($depth === 0) {
+            return $i;
+        }
+    }
+
+    return null;
+}
+
+/**
+ * flash キーとして扱う文字列リテラルなら中身を返す (`[a-z_]+` 限定。
+ * view composer の `seoHead` のような camelCase キーは対象外)。
+ *
+ * @param  array{0: int, 1: string}  $token
+ */
+function flashWriterLiteralKey(array $token): ?string
+{
+    if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
+        return null;
+    }
+    $key = trim($token[1], "'\"");
+
+    return preg_match('/^[a-z_]+$/', $key) === 1 ? $key : null;
+}
+
+/**
+ * `$withIndex` の呼び出しが属する文が `back()` / `redirect(` / `to_route(` を
+ * 起点とするチェーンかを判定する (文頭 = 直前の `;` `{` `}` まで遡る)。
+ *
+ * 素のヘルパ呼び出しのみを起点と認める: 同名のメソッド/静的呼び出し
+ * (`$driver->redirect()` / `Foo::redirect()`) は直前トークンが `->` / `?->` / `::` に
+ * なるため棄却する (Socialite の `$driver->redirect()` チェーン等での誤検知を防ぐ)。
+ *
+ * @param  list<array{0: int, 1: string}>  $tokens
+ */
+function flashWriterStatementHasRedirectRoot(array $tokens, int $withIndex): bool
+{
+    for ($i = $withIndex - 1; $i >= 0; $i--) {
+        $text = $tokens[$i][1];
+        if (in_array($text, [';', '{', '}'], true)) {
+            return false;
+        }
+        if ($tokens[$i][0] !== T_STRING
+            || ! in_array($text, ['back', 'redirect', 'to_route'], true)
+            || ($tokens[$i + 1][1] ?? '') !== '(') {
+            continue;
+        }
+        $prev = $tokens[$i - 1] ?? null;
+        if ($prev !== null && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
+            continue;
+        }
+
+        return true;
+    }
+
+    return false;
+}
+
+/**
+ * 配列リテラルの「深さ 1」(= 外側の配列直下) にある flash キーを取り出す。
+ * ネストした値配列の内側キー (`['meta' => ['reason' => …]]` の `reason`) は拾わない。
+ *
+ * @param  list<array{0: int, 1: string}>  $tokens
+ * @return list<string>
+ */
+function flashWriterDepth1ArrayKeys(array $tokens, int $from, int $to): array
+{
+    $keys = [];
+    $depth = 0;
+    for ($i = $from; $i <= $to; $i++) {
+        $text = $tokens[$i][1];
+        if ($text === '[') {
+            $depth++;
+
+            continue;
+        }
+        if ($text === ']') {
+            $depth = max(0, $depth - 1);
+
+            continue;
+        }
+        if ($depth !== 1) {
+            continue;
+        }
+        $next = $tokens[$i + 1] ?? null;
+        if ($next === null || $next[0] !== T_DOUBLE_ARROW) {
+            continue;
+        }
+        $key = flashWriterLiteralKey($tokens[$i]);
+        if ($key !== null) {
+            $keys[] = $key;
+        }
+    }
+
+    return $keys;
+}
+
+/**
+ * ソース 1 ファイル分から flash 書き手のキーをトークン走査で収集する (自己検証テストの対象)。
+ *
+ * 収集する形 (ユーザー通知 flash になり得る書き込み):
+ *  - `->with('key', $value)` — redirect チェーン。第 2 引数必須で拾い、単一引数の
+ *    Eloquent eager load (`->with('relation')`) は拾わない。第 2 引数が closure
+ *    (`fn` / `function` / `static`) で始まる場合は constrained eager load とみなし除外する
+ *  - `->with(['key' => $value, ...])` — 連想配列形の一括 flash。**同一文が `back()` /
+ *    `redirect(` / `to_route(` を起点とするチェーンのときだけ**、配列の深さ 1 のキーのみ拾う
+ *    (Socialite の `$driver->with(['prompt' => ...])` のような flash ではない fluent API と、
+ *    ネスト値配列の内側キーを誤収集しないため)
+ *  - `->flash('key', ...)` / `::flash('key', ...)` — session への直書き・facade 形
+ *    (receiver を問わない)
+ *
+ * コメント・空白はトークン化で除去済み。動的キー (変数・定数経由) と、変数に受けた
+ * RedirectResponse への後付け `with([...])` は検出できない (取りこぼす側に倒した網。
+ * scalar 形は receiver に依らず捕捉する)。
+ *
+ * @return list<string>
+ */
+function flashWriterKeysFromSource(string $source): array
+{
+    $tokens = flashWriterSignificantTokens($source);
+    $keys = [];
+    $count = count($tokens);
+
+    for ($i = 1; $i < $count; $i++) {
+        if ($tokens[$i][0] !== T_STRING) {
+            continue;
+        }
+        $name = $tokens[$i][1];
+        $prev = $tokens[$i - 1];
+        $open = $i + 1;
+        if (($tokens[$open][1] ?? '') !== '(') {
+            continue;
+        }
+
+        if ($name === 'with' && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
+            $first = $tokens[$open + 1] ?? null;
+            if ($first === null) {
+                continue;
+            }
+
+            // 連想配列形: back()/redirect(/to_route( 起点の文のみ、深さ 1 のキーを拾う
+            if ($first[1] === '[') {
+                if (! flashWriterStatementHasRedirectRoot($tokens, $i - 1)) {
+                    continue;
+                }
+                $close = flashWriterMatchingIndex($tokens, $open + 1, '[', ']');
+                if ($close === null) {
+                    continue;
+                }
+                $keys = [...$keys, ...flashWriterDepth1ArrayKeys($tokens, $open + 1, $close)];
+
+                continue;
+            }
+
+            // scalar 形: ->with('key', <closure 以外>)
+            $key = flashWriterLiteralKey($first);
+            if ($key === null || ($tokens[$open + 2][1] ?? '') !== ',') {
+                continue;
+            }
+            $secondArg = $tokens[$open + 3] ?? null;
+            if ($secondArg !== null && in_array($secondArg[0], [T_FN, T_STATIC, T_FUNCTION], true)) {
+                continue;
+            }
+            $keys[] = $key;
+
+            continue;
+        }
+
+        if ($name === 'flash' && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
+            $key = flashWriterLiteralKey($tokens[$open + 1] ?? [-1, '']);
+            if ($key !== null) {
+                $keys[] = $key;
+            }
+        }
+    }
+
+    return array_values(array_unique($keys));
+}
+
+it('flash 書き手のキーはすべて NOTIFICATION_KEYS か理由付き allowlist に属する', function (): void {
+    $root = dirname(__DIR__, 2);
+    $allowlist = flashWriterScanAllowlist();
+
+    /** @var array<string, list<string>> $violations キー => 由来ファイル */
+    $violations = [];
+    /** @var iterable<SplFileInfo> $it */
+    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));
+    foreach ($it as $file) {
+        if (! $file->isFile() || $file->getExtension() !== 'php') {
+            continue;
+        }
+        $source = file_get_contents($file->getPathname());
+        Assert::string($source);
+
+        foreach (flashWriterKeysFromSource($source) as $key) {
+            if (in_array($key, FlashNotificationRelay::NOTIFICATION_KEYS, true) || array_key_exists($key, $allowlist)) {
+                continue;
+            }
+            $violations[$key][] = ltrim(str_replace($root, '', $file->getPathname()), '/');
+        }
+    }
+
+    ksort($violations);
+    $lines = [];
+    foreach ($violations as $key => $files) {
+        $lines[] = sprintf("  '%s'  // %s", $key, implode(', ', array_unique($files)));
+    }
+
+    expect($lines)->toBe(
+        [],
+        "NOTIFICATION_KEYS にも writer allowlist にも無い flash キーの書き手:\n".implode("\n", $lines)
+        ."\nユーザー通知なら FlashNotificationRelay::NOTIFICATION_KEYS へ、内部状態の flash なら理由付きで allowlist へ登録すること"
+    );
+});
+
+it('writer 走査の allowlist は理由付きで初期 1 件から増えていない', function (): void {
+    $allowlist = flashWriterScanAllowlist();
+
+    expect($allowlist)->toHaveCount(1);
+    foreach ($allowlist as $key => $reason) {
+        expect($reason)->not->toBe('', "allowlist の {$key} に理由がない");
+    }
+});
+
+describe('writer 走査ロジックの自己検証', function (): void {
+    it('正例: ->with の scalar 形 / 連想配列形 / flash / facade 形をすべて拾う', function (): void {
+        $source = <<<'PHP'
+        <?php
+        return back()->with('success', 'saved')
+            ->with(['error' => 'x', 'info' => 'y']);
+        $request->session()->flash('warning', 'w');
+        $session->flash('custom_state', 'v');
+        Session::flash('facade_key', 'z');
+        PHP;
+
+        expect(flashWriterKeysFromSource($source))
+            ->toBe(['success', 'error', 'info', 'warning', 'custom_state', 'facade_key']);
+    });
+
+    it('正例: redirect()/to_route() 起点チェーンの連想配列形 with も拾う', function (): void {
+        $source = <<<'PHP'
+        <?php
+        return redirect()->route('dashboard')->with(['success' => 'x']);
+        return to_route('billing.index')->with(['redirect_array_key' => 'y']);
+        PHP;
+
+        expect(flashWriterKeysFromSource($source))->toBe(['success', 'redirect_array_key']);
+    });
+
+    it('連想配列形は深さ 1 のキーのみ拾う (ネスト値配列の内側キーは拾わない)', function (): void {
+        $source = <<<'PHP'
+        <?php
+        return back()->with(['status' => 'ok', 'meta' => ['reason' => 'x']]);
+        PHP;
+
+        expect(flashWriterKeysFromSource($source))->toBe(['status', 'meta']);
+    });
+
+    it('負例: 単一引数の eager load / closure 第 2 引数の constrained eager load は拾わない', function (): void {
+        $source = <<<'PHP'
+        <?php
+        $q->with('organization');
+        $q->with('user', fn ($query) => $query->active());
+        $q->with('items', static fn ($query) => $query->latest());
+        $q->with('project', function ($query) { return $query; });
+        PHP;
+
+        expect(flashWriterKeysFromSource($source))->toBe([]);
+    });
+
+    it('負例: コメント内の flash 書き込みは拾わない', function (): void {
+        $source = <<<'PHP'
+        <?php
+        // back()->with('commented_key', 'x') は既定の形
+        /* Session::flash('doc_key', 'y') */
+        /** `->with('docblock_key', $v)` の説明 */
+        return back()->with('success', 'real');
+        PHP;
+
+        expect(flashWriterKeysFromSource($source))->toBe(['success']);
+    });
+
+    it('負例: camelCase キー (view composer の seoHead 等) は対象外', function (): void {
+        $source = <<<'PHP'
+        <?php
+        $view->with('seoHead', $html);
+        PHP;
+
+        expect(flashWriterKeysFromSource($source))->toBe([]);
+    });
+
+    it('負例: back()/redirect(/to_route( 起点でない連想配列形 with (Socialite の OAuth 引数等) は拾わない', function (): void {
+        $source = <<<'PHP'
+        <?php
+        $driver->with(['prompt' => 'login']);
+        PHP;
+
+        expect(flashWriterKeysFromSource($source))->toBe([]);
+    });
+
+    it('負例: 同名メソッド呼び出し ($driver->redirect() 等) は起点と誤認しない', function (): void {
+        $source = <<<'PHP'
+        <?php
+        return $driver->redirect()->with(['prompt' => 'login']);
+        return Foo::redirect()->with(['static_key' => 'x']);
+        PHP;
+
+        expect(flashWriterKeysFromSource($source))->toBe([]);
+    });
+});
diff --git a/tests/Feature/Inertia/FlashNotificationRelayBounceTest.php b/tests/Feature/Inertia/FlashNotificationRelayBounceTest.php
new file mode 100644
index 0000000..43a5ef5
--- /dev/null
+++ b/tests/Feature/Inertia/FlashNotificationRelayBounceTest.php
@@ -0,0 +1,148 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Organization\OrganizationMembershipService;
+use App\Support\Http\FlashNotificationRelay;
+use Illuminate\Session\ArraySessionHandler;
+use Illuminate\Session\Store;
+use Illuminate\Support\Facades\Route;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * 跳ね返り (中間 redirect) を跨ぐ通知の中継 (FlashNotificationRelay::relayTo) の振る舞い固定。
+ *
+ * 対象は「中間 GET が別 redirect を返す」2 経路:
+ *   - RequireActiveSubscription (課金ゲート → onboarding へ跳ね返る)
+ *   - EnsureAccountNotPendingDeletion (退会予約中の凍結 → /settings へ跳ね返る)
+ *
+ * ★観測点は**跳ね返り応答の直後の session** である。着地画面の共有 prop に
+ *   `new_api_key` が無いことを見ても意味がない (着地画面はもともとその prop を公開しないので
+ *   `reflash()` のままでも緑になる = 偽陽性)。
+ * ★`withSession([...])` は値を置くだけで**一時メッセージの世代情報を作らない**ため、
+ *   `keep()` / `reflash()` / 要求終了時の失効を再現できない。よって本テストは
+ *   **必ず本物の要求境界を跨いで一時メッセージを作る** (テスト専用 route で
+ *   `redirect()->with(...)` / `session()->flash(...)` を実行する)。
+ *
+ * 通知キーの検査範囲: 3 番目の観測は代表値として `success` で行う。
+ * **4 キーすべてが延命されることは `NOTIFICATION_KEYS` を回す実装
+ * (`$session->keep(self::NOTIFICATION_KEYS)`) で一意に決まる**ため、
+ * 2 つの middleware × 4 キーの網羅は行わない。
+ *
+ * error の中継契約 (fail-closed): `RELAYABLE_ERROR_KEYS` は空 = error は一切中継しない。
+ * **将来ここへキーを足すときは、同じ変更で「許可キーだけ残る (正例) / それ以外と
+ * 名前付き bag は残らない (負例)」を本ファイルへ足すこと。**
+ */
+
+/**
+ * 中継テスト用の種まき route を登録する。
+ * 課金ゲートにも凍結にも掛からない middleware 構成 (`web` + `auth`) にして、
+ * 「跳ね返りの 1 hop 前」に相当する要求をそのまま再現する。
+ */
+function registerFlashRelaySeedRoute(Closure $handler): void
+{
+    Route::middleware(['web', 'auth'])->get('/__flash-relay/seed', $handler);
+}
+
+/** 通知 (success) と内部状態の flash (new_api_key) を同じ要求で積む種まき。 */
+function seedNotificationAndInternalFlash(): void
+{
+    registerFlashRelaySeedRoute(function () {
+        session()->flash('new_api_key', 'sk_live_平文キー');
+
+        return redirect('/projects')->with('success', '中継テストの通知');
+    });
+}
+
+// ── 課金ゲートの跳ね返り (RequireActiveSubscription) ──
+
+test('課金ゲートの跳ね返りは通知だけを 1 hop 延命し、内部状態の flash は持ち越さない', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    seedNotificationAndInternalFlash();
+
+    // 1. 実際の要求境界を跨いで一時メッセージを作る
+    $this->actingAs($owner)->get('/__flash-relay/seed')->assertRedirect('/projects');
+
+    // 2-3. 跳ね返りを起こし、**その応答直後の session** を見る
+    $this->actingAs($owner)->get('/projects')
+        ->assertRedirect(route('onboarding.checkout'))
+        ->assertSessionHas('success', '中継テストの通知')
+        ->assertSessionMissing('new_api_key');
+
+    // 4. 着地の GET で共有 prop に載る
+    $this->actingAs($owner)->get(route('onboarding.checkout'))
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('flash.success', '中継テストの通知'));
+
+    // 5. 着地の後は失効している (延命は 1 hop だけ)。ここで再び中継を通る route は使わない
+    $this->actingAs($owner)->get(route('onboarding.checkout'))
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('flash.success', null));
+});
+
+// ── 退会予約中の凍結の跳ね返り (EnsureAccountNotPendingDeletion) ──
+
+test('凍結の跳ね返りは通知だけを 1 hop 延命し、内部状態の flash は持ち越さない', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
+    $owner->refresh();
+    seedNotificationAndInternalFlash();
+
+    $this->actingAs($owner)->get('/__flash-relay/seed')->assertRedirect('/projects');
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertRedirect(route('settings'))
+        ->assertSessionHas('success', '中継テストの通知')
+        ->assertSessionMissing('new_api_key');
+
+    $this->actingAs($owner)->get(route('settings'))
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('flash.success', '中継テストの通知'));
+
+    $this->actingAs($owner)->get(route('settings'))
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('flash.success', null));
+});
+
+// ── error は中継しない (RELAYABLE_ERROR_KEYS が空 = fail-closed) ──
+
+test('検証エラー (default bag) は跳ね返りで中継されない', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    registerFlashRelaySeedRoute(fn () => redirect('/projects')->withErrors(['project_name' => '名前を入力してください']));
+
+    $this->actingAs($owner)->get('/__flash-relay/seed')->assertRedirect('/projects');
+
+    $this->actingAs($owner)->get('/projects')
+        ->assertRedirect(route('onboarding.checkout'))
+        ->assertSessionMissing('errors');
+});
+
+test('名前付き error bag も跳ね返りで中継されない', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    registerFlashRelaySeedRoute(fn () => redirect('/projects')->withErrors(['project_name' => '名前を入力してください'], 'projectForm'));
+
+    $this->actingAs($owner)->get('/__flash-relay/seed')->assertRedirect('/projects');
+
+    $this->actingAs($owner)->get('/projects')
+        ->assertRedirect(route('onboarding.checkout'))
+        ->assertSessionMissing('errors');
+});
+
+/*
+ * ★この 1 本だけ HTTP を跨がない: Laravel の Store::save() は保存前に errors を
+ *   ViewErrorBag として直列化する (prepareErrorBagForSerialization) ため、
+ *   ViewErrorBag でない値は**要求境界を跨げず 500 になる**。
+ *   よって「置き直しをしない」という中継側の契約は relayTo() を直に呼んで固定する。
+ */
+test('errors に ViewErrorBag でない値が入っていても置き直されない', function (): void {
+    $session = new Store('flash-relay-contract', new ArraySessionHandler(120));
+    $session->start();
+    $session->flash(FlashNotificationRelay::ERRORS, 'ViewErrorBag ではないただの文字列');
+    // 1 hop 進め、「直前 hop で積まれた flash」の状態にする
+    $session->ageFlashData();
+
+    FlashNotificationRelay::relayTo($session);
+
+    // 延命対象に載るのは通知キーだけで、errors は置き直されない
+    expect($session->get('_flash.new'))->toBe(FlashNotificationRelay::NOTIFICATION_KEYS);
+});
diff --git a/tests/js/architecture/flash-keys-sync.test.ts b/tests/js/architecture/flash-keys-sync.test.ts
new file mode 100644
index 0000000..8308e06
--- /dev/null
+++ b/tests/js/architecture/flash-keys-sync.test.ts
@@ -0,0 +1,69 @@
+import { describe, expect, it } from "vitest";
+import fs from "node:fs";
+import path from "node:path";
+import { FLASH_KEYS } from "@/lib/stores/flash-to-toast";
+
+/**
+ * 画面側の読み手 (flash-to-toast.ts の FLASH_KEYS) ⇔ PHP 側の SoT
+ * (FlashNotificationRelay::NOTIFICATION_KEYS) のキー集合一致検査。
+ *
+ * flash キー集合は「書き手 / 中継 / 共有 / 画面側の読み手」の 4 箇所に現れる。PHP 側 3 箇所は
+ * tests/Architecture/FlashNotificationRelayDriftTest.php が固定し、本テストが最後の鎖
+ * (実際に画面へ出す側) を閉じる。片方だけキーを足すと、通知が誰にも読まれず消える
+ * (または読み手のない共有キーが残る) ずれをここで落とす。
+ *
+ * PHP ソースからの抽出は正規表現で行う。定義形が変わって抽出できなくなった場合は
+ * 0 キー = fail に倒す (0 件一致の偽の緑を作らない)。
+ *
+ * **保証範囲を広げて言わない**: 見るのはキー集合の一致だけである。共有 prop の名前 (`flash`) や
+ * 見分け用キーの名前 (`visitKey`) のずれは見ない。
+ */
+
+const ROOT = path.resolve(__dirname, "../../..");
+const relaySource = fs.readFileSync(
+    path.join(ROOT, "app/Support/Http/FlashNotificationRelay.php"),
+    "utf-8",
+);
+
+/** NOTIFICATION_KEYS 定義ブロックの self::CONST 参照を、string 定数の実値へ解決する */
+function phpNotificationKeys(): string[] {
+    const block = relaySource.match(
+        /const array NOTIFICATION_KEYS = \[([\s\S]*?)\];/,
+    );
+    if (!block)
+        throw new Error(
+            "NOTIFICATION_KEYS definition not found in FlashNotificationRelay.php",
+        );
+
+    const constValues = new Map<string, string>();
+    for (const m of relaySource.matchAll(
+        /const string ([A-Z_]+) = '([a-z_]+)';/g,
+    )) {
+        constValues.set(m[1], m[2]);
+    }
+
+    const keys: string[] = [];
+    for (const ref of block[1].matchAll(/self::([A-Z_]+)/g)) {
+        const value = constValues.get(ref[1]);
+        if (value === undefined) {
+            throw new Error(
+                `NOTIFICATION_KEYS references unresolvable const: ${ref[1]}`,
+            );
+        }
+        keys.push(value);
+    }
+    return keys;
+}
+
+describe("flash keys sync (画面側の読み手 ⇔ PHP 側の SoT)", () => {
+    it("PHP ソースから NOTIFICATION_KEYS を 1 件以上抽出できる (0 件一致は定義形の変更 = fail)", () => {
+        expect(phpNotificationKeys().length).toBeGreaterThan(0);
+    });
+
+    it("flash-to-toast の FLASH_KEYS と NOTIFICATION_KEYS が集合一致する", () => {
+        const php = [...phpNotificationKeys()].sort();
+        const js = [...FLASH_KEYS].sort();
+
+        expect(js).toEqual(php);
+    });
+});
```

## テスト結果

```
## テストファースト (実装前の fail 確認)

### tests/Architecture/FlashNotificationRelayDriftTest.php (実装前)
tests=11 passed=9 errors=2
- 「Inertia が共有する flash キー集合が … 一致する」→ Class "App\Support\Http\FlashNotificationRelay" not found
- 「flash 書き手のキーはすべて …」→ 同上
- 走査器の自己検証 (正例 3 / 負例 5) と allowlist 件数の 9 本は実装前から緑 (走査器は SoT に依存しないため)

### tests/js/architecture/flash-keys-sync.test.ts (実装前)
FAIL — ENOENT: app/Support/Http/FlashNotificationRelay.php が無い (0 件一致の偽の緑にならず fail に倒れた)

### tests/Feature/Inertia/FlashNotificationRelayBounceTest.php (実装前 = reflash() のまま)
tests=5 passed=0 failed=5
- 課金ゲートの跳ね返り → `Session has unexpected key [new_api_key]` (= reflash() が API キー平文を延命していた)
- 凍結の跳ね返り → 同上
- 検証エラー (default bag) → `Session has unexpected key [errors]`
- 名前付き error bag → `Session has unexpected key [errors]`
- errors に ViewErrorBag でない値 → 当初は HTTP 経由で書いていたが、Laravel の Store::save() が
  保存前に errors を ViewErrorBag として直列化する (prepareErrorBagForSerialization) ため
  **要求境界を跨げず 500** になった。よってこの 1 本だけ relayTo() を直に呼ぶ形へ書き換えた

## 実装後の検証コマンド

| コマンド | 結果 |
|---|---|
| `composer phpstan` (level 10) | OK (989 ファイル・エラー 0) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` | OK |
| `pnpm typecheck` | OK |
| `pnpm build` | OK |
| `pnpm typecheck:packages` / `pnpm build:packages` | OK |
| `composer test` (全数) | 実行中 (ホスト全体のグローバルテストロック待ち) |
| `pnpm test` (全数) | 実行待ち |
```
