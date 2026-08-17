# 正典照合の記録 (Step 0)

## 取得

| 項目 | 値 |
|---|---|
| 取得日時 | 2026-08-18 (JST) 設計セッション中 |
| 台帳 | lctl `get_feature("inertia-integration")` — feature_revision `12-8f92b7e8ecfe` |
| 正典 | laravel-claude-template — `get_source` の resolved_commit `050ddc5ee957855575aa88c77054b7d77b696028` |
| 読んだ原本 | `app/Support/Http/FlashNotificationRelay.php` / `tests/Architecture/FlashNotificationRelayDriftTest.php` / `tests/js/architecture/flash-keys-sync.test.ts` / `resources/js/lib/stores/flash-to-toast.ts` / `app/Http/Middleware/HandleInertiaRequests.php` |

**経緯**: 設計開始時は台帳サーバ (tailscale 上の host) へ到達できず (`get_feature` / `get_source` が
全て timeout、`curl` でも接続不可)、正典を読めないまま「HEAD の課題から起こした設計」を作って
Codex 詳細設計レビューを 5 ラウンド回した (その記録は `detailed-review-round-1..5.md` と
同ディレクトリの対応マトリクスに残してある)。その後セッション終盤で疎通が回復したため、
設計書に自分で書いた完了条件どおり **Step 0 を実施し、正典へ寄せて詳細設計を書き直した**。

## 台帳が言っていること

- 機能 `inertia-integration` の `canonical_version` は t1。aicue は **status: pending / version: pre-t0**
  (「中継クラスも両側の drift gate も無い前世代形」)。裁定 **AG-057** が aicue に標準形への追従を求めている
- gates として登録されているのは
  `tests/Architecture/FlashNotificationRelayDriftTest.php` /
  `tests/js/architecture/flash-keys-sync.test.ts` /
  `tests/Architecture/MutationRedirectFlashTest.php` (更新成功通知の全数申告。t1 の要素)
- t1 が t0 に足すのは 2 つ — **更新成功通知の gate** と **起動失敗時に白画面にしない配線**。
  本タスクの範囲は t0 の中核 (中継クラス + 両側 drift gate) であり、t1 の 2 要素は別タスク

## 3 点の照合結果 (照合前の自案 → 正典)

| 照合点 | 照合前の自案 | 正典 | 採った形 |
|---|---|---|---|
| クラスの置き場所と名前空間 | `app/Support/Inertia/FlashNotificationRelay.php` (`App\Support\Inertia`) | **`app/Support/Http/FlashNotificationRelay.php`** (`App\Support\Http`) | 正典 |
| 語彙の定数名 | `KINDS` | **`NOTIFICATION_KEYS`** (要素は `SUCCESS` / `ERROR` / `INFO` / `WARNING` の各定数参照) | 正典 |
| 中継クラスの役割 | 共有 prop の組み立て (`payload(Request)`) | **中間 redirect を 1 hop 跨いで通知を延命する窓口 (`relayTo(Session)`)** + 語彙の SoT。共有 prop の組み立ては **middleware 側の private メソッド** (`notificationFlashProps()`) | 正典 |
| PHP gate の検査対象 | middleware の字句 (委譲の形・prop 名・session/uuid の禁止) | **(a) `share()` の実出力キー集合 = `NOTIFICATION_KEYS`** (振る舞い) + **(b) `app/` 全走査で flash 書き手のキーが `NOTIFICATION_KEYS` か理由付き allowlist に属すること** + 走査器の自己検証 (正例 3・負例 5) | 正典 |
| JS gate の検査対象 | 語彙 + prop 名 + 見分けキー名の 3 点、消費経路の構文木走査 | **語彙の集合一致のみ** (PHP ソースから正規表現で `NOTIFICATION_KEYS` を解決) + 0 件抽出を fail に倒す | 正典 |
| 画面側の変更 | union 型・`readFlash` 正規化器・消費経路の目録 | **`FLASH_KEYS` を export して docblock を付けるだけ** (挙動不変) | 正典 |
| session 値の正規化 | 文字列以外を null に倒す | 正規化しない (`session()->get()` の値をそのまま載せる) | 正典 |

**差異が出たときの扱い**: 設計書の Step 0 に書いたとおり「名前だけ合わせて終わりにせず、
公開 API と契約のどちらを正本へ寄せるか評価する」を適用した。今回は **全項目で正典へ寄せた**。
自案の方が広い検査 (消費経路の構文木走査など) を持っていたが、

- 家系の 4 リポジトリが既に運用している形と検査対象が違うと、以後の還流・追従が差分で追えない
- 自案の広い検査は aicue だけの独自形になり、`docs/template-divergence.md` への登録が要る
- 守りたい無音故障 (キーの片側忘れ) は正典の 2 gate で落ちる

ため、**独自の上積みは持ち込まない**判断とした (逸脱の登録は行わない = 収束であって逸脱ではない)。

## aicue 固有の値 (形は正典・値は実測)

- **flash 書き手 allowlist は 1 件** (`new_api_key`)。正典は 2 件 (`status` / `new_api_key`) だが、
  aicue には `status` を書く経路が **app/ に 1 つも無い**
  (`EnumerationSafePasswordResetLinkResponse` は `back()->with('success', …)` を返す。
   Fortify 既定の `status` を `success` へ寄せる方針は `app/Http/Responses/Fortify/` の
   5 クラスで既に完了しており、`status` の語は**コメントにしか無い** = 走査はコメントを数えない)。
  これは形の逸脱ではなく**母集団の実測差**である
- 走査で拾われない既知の書き手が 1 つある: `->with(BillingFeedbackKind::FLASH_KEY, …)` は
  キーが定数なので**動的キーとして検出外**である (正典の走査器も同じ限界を明記している)。
  この値は「共有 flash の 4 キーと衝突しない名前」として意図的に語彙の外にある
- aicue の `share()` は正典より共有 prop が多い (`currentOrganization` / `notifications` /
  `invitationInbox` / `sessionEpoch`)。**flash の組み立て方だけ**を正典に合わせ、他は触らない
