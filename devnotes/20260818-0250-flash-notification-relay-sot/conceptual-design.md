# 概念設計: flash 通知の単一出典 (FlashNotificationRelay) と両レーンのドリフト検査

対象機能 (家系機能台帳): `inertia-integration`
種別: 現行世代形への追従 (テンプレート正典への収束)

> **位置づけの但し書き (Round 1 レビュー反映)**: 本設計時、機能台帳へ到達できず正典の実ファイルを
> 読めていない (末尾に記録)。したがって本書は現時点では「**正典準拠**」を名乗らず、
> 「HEAD にある無音故障を解く設計 + **正典との後追い照合を完了条件に含む**」ものとして扱う。
> 照合で食い違ったら正典へ合わせる (合わせない判断をするなら `docs/template-divergence.md` へ登録)。
>
> **【追記: 照合を実施した】** セッション終盤で台帳への疎通が回復したため、上の完了条件どおり
> 照合を行った (記録: `codex-history/canon-reconciliation.md`)。**課題認識と改善の方向は
> そのまま裏が取れたが、実現方法は正典と食い違っていたため全項目を正典へ寄せた**。
> 下の「改善アイデア」節は照合後の形に書き直してある。照合前の版の詳細設計と、
> それに対する Codex レビュー 5 ラウンドの記録は `codex-history/` に残した。

## 背景・課題

Laravel の一時メッセージ (flash) を Inertia の共有 props で運び、画面側で toast に変換する経路は、
本リポジトリでは動いてはいるが**語彙の出所が分散している**。HEAD の実測:

| 役割 | 場所 | HEAD の状態 |
|---|------|------|
| 書き手 | `app/` の 83 箇所 | `->with('success'|'error'|'info'|'warning', …)`。ほかに `new_api_key` (API キー平文の 1 度きり表示) |
| 中継 | — | **無い**。中間 redirect を跨ぐ延命は `session()->reflash()` (`RequireActiveSubscription` / `EnsureAccountNotPendingDeletion` の 2 箇所) |
| 共有 | `app/Http/Middleware/HandleInertiaRequests.php` L83-89 | 4 つのキー名と `visitKey` を**直書き**して `session()->get()` |
| 画面側の読み手 | `resources/js/lib/stores/flash-to-toast.ts` L23 | `FLASH_KEYS` を**直書き** (export もされていない) |

この形の問題は**壊れ方が無音である**こと。

- 追加のとき: サーバ側 (1) にキーを 1 つ足して画面側 (2) を直し忘れると、
  そのメッセージは**送られているのに 1 度も表示されない**。PHP レーンも JS レーンも緑のまま通る。
- 打ち間違いのとき: 書き手が `succes` と書いても、誰にも読まれずに消えるだけで何も落ちない。
- 延命のとき: 中間 redirect を跨ぐ延命が `reflash()` なので、**通知でない一時メッセージまで
  延命される**。aicue には `new_api_key` (API キーの平文) を運ぶ経路があり、
  課金ゲートの跳ね返りが挟まると平文が 1 hop 余分に session に残る。

つまり現状は「同じ語彙を複数の実装が独立に持ち、一致は人手でのみ保たれている」状態である。
本リポジトリは同種の危険 (PHP enum ⇔ TS union) を既に**機械で固定**しており
(`AccountDeletionBlockerActionTsSyncInvariantTest` / `Tests\Support\TsUnionValues`)、
flash だけが取り残されている。

家系の正典 (laravel-claude-template) は、この語彙の単一出典として
`app/Support/Http/FlashNotificationRelay.php` を持ち、PHP レーンと TS レーンの**両方**に
drift gate (`FlashNotificationRelayDriftTest.php` / `flash-keys-sync.test.ts`) を置く形へ
移行済みである。台帳上 aicue だけが `pending` (pre-t0) の前世代形として残っており、
裁定 AG-057 が標準形への追従を求めている。

## 改善アイデア (照合後 = 正典の形)

1. **語彙の単一出典クラスを 1 つ作る**: `App\Support\Http\FlashNotificationRelay`
   (`app/Support/Http/FlashNotificationRelay.php`)。
   - 通知種別の語彙を `NOTIFICATION_KEYS` として持つ (これが SoT)
   - **中間 redirect (跳ね返り) を 1 hop 跨いで通知だけを延命する窓口** `relayTo(Session)` を持つ。
     `reflash()` と違い、1 度きり表示の内部状態 (API キー平文など) は延命しない
2. **共有 prop を SoT から導出する**。`HandleInertiaRequests` に private の
   `notificationFlashProps()` を置き、`NOTIFICATION_KEYS` を回して session から読む。
   **振る舞いは同値** (読み出し元・キーの並び・`visitKey` の発行時点を変えない)。
3. **画面側は `FLASH_KEYS` を export するだけ**。aicue の `flash-to-toast.ts` は
   正典とこの 1 行以外が既に同一である (挙動は変えない)。
4. **両レーンに drift gate を置く**。
   - PHP レーン `tests/Architecture/FlashNotificationRelayDriftTest.php` —
     (a) `share()` の**実出力**キー集合 = `NOTIFICATION_KEYS` /
     (b) `app/` 全走査で **flash 書き手のキー**がすべて SoT か理由付き allowlist に属する /
     (c) allowlist が理由付きで件数から増えていない / (d) 走査器の自己検証 (正例・負例)
   - TS レーン `tests/js/architecture/flash-keys-sync.test.ts` —
     PHP ソースから `NOTIFICATION_KEYS` を解決して `FLASH_KEYS` と集合一致を見る。
     0 件抽出は fail に倒す
5. **跳ね返りの延命を `reflash()` から中継へ移す**。HEAD の 2 箇所
   (`RequireActiveSubscription` / `EnsureAccountNotPendingDeletion`) は
   コメントで「通知を着地先まで保つ」と書きながら全 flash を延命している。
   意図どおり通知だけにする (思考原則 3: 旧実装を同じ変更で消す)。

> 照合前の版では、画面側に union 型と読み出しの正規化器を足し、共有 prop 名と
> 見分けキー名も両レーンで突き合わせる形を設計していた。正典はそこまで見ておらず、
> 独自の上積みは家系の追従・還流を差分で追えなくするため**持ち込まない**判断とした
> (経緯は `codex-history/canon-reconciliation.md`)。

## 期待効果

- **使命への貢献**: 撮影ナビと SOP 解析は「操作の結果が言葉で返ること」に依存する
  (押した → 受け付けた / 失敗した が出ない画面は現場作業者を詰ませる)。
  通知経路が無音で壊れる余地を消すことは、思考ゼロで使える現場 UI の前提を守る。
- **落ちるようになるもの**: 語彙の片側忘れ (どちらのレーンでも赤) / 語彙外のキーで
  通知を書く形 (打ち間違いを含む。走査 gate が赤) / 共有 prop のキー集合が SoT からずれること。
- **無くなるもの**: 跳ね返りでの延命しすぎ (API キー平文の 1 hop 余分な滞留)。
- **落ちないもの (誇張しない)**: 共有 prop 名 (`flash`) と見分けキー名 (`visitKey`) の
  片側改名は、正典の 2 gate では**見ない** (集合の一致だけを見るため)。
  動的キー (定数・変数経由) の書き手も走査の対象外である。
- 家系との形の一致により、以後の正典追従 (flash 関連の変更) が差分で追える。

## 実装方針 (概要)

- 新規 1 クラス: `app/Support/Http/FlashNotificationRelay.php` (正典の移植。
  `declare(strict_types=1)` + 日本語コメント)
- 変更 1 箇所: `HandleInertiaRequests::share()` の `flash` 部を `NOTIFICATION_KEYS` 導出へ
  (private メソッド `notificationFlashProps()` を 1 つ足す)
- 変更 1 行: `resources/js/lib/stores/flash-to-toast.ts` の `FLASH_KEYS` を export (挙動不変)
- 新規 2 テスト: PHP レーンの drift gate (走査器と自己検証を含む) / TS レーンの drift gate
- 変更 2 箇所 + 新規 1 テスト: 跳ね返り 2 箇所の `reflash()` を `relayTo()` へ置き換え、
  「通知は延命される・1 度きり表示の内部状態は延命されない」を Feature テストで固定
- 既存テストの更新: 不要の見込み (`tests/js/lib/flash-to-toast.test.ts` も
  既存の課金ゲート / 退会猶予のテストも挙動を変えない)。実装時に全レーンで確認する

## 制約・前提

- **語彙は閉じた集合である**。session の一時メッセージに載る値がすべて通知種別なのではない。
  実例として `BillingFeedbackKind::FLASH_KEY = 'billing_feedback_kind'` は
  「共有 flash の 4 キーと衝突しない名前」であることを明記した上で意図的に語彙の**外**にあり、
  `new_api_key` (API キー平文の 1 度きり表示) も通知ではない。
  検査はこの外側のキーを巻き込まず、**理由付きの allowlist** で明示的に外す。
- **画面側の toast 種別 (`ToastType`) との関係は型検査が既に担っている**。
  `consumeFlash` は `addToast(flashKey, message)` を呼ぶため、語彙が `ToastType` の
  部分集合であることは `pnpm typecheck` が落とす。ここに追加の検査は置かない (思考原則 2)。
- **正典の形を削らず、足さない**。既存の共有ヘルパ (`TsUnionValues`) は抽出対象が違うので使わず、
  正典の gate が持つ抽出をそのまま移植する。独自の上積み (union 型・正規化器・消費経路の走査) は
  持ち込まない (家系の追従・還流を差分で追えなくするため)。
- テストファースト: 2 つの gate を先に書き、赤 (中継クラス未存在 / `FLASH_KEYS` 未 export) を
  確認してから実装する。跳ね返りの置き換えも Feature テストを先に赤にしてから直す。

## スコープ外

- **83 箇所の書き手 (`->with('success', …)`) を中継クラスの補助関数へ書き換えること**。
  正典もそうしていない。書き手の打ち間違いは**走査 gate**が受け持つ。
- 通知種別を増やすこと・toast の見た目や自動消去時間の変更。
- Fortify 既定の `status` の扱い (専用 Response クラス群で `success` へ寄せ済み)。
- 課金着地フィードバック (`billing_feedback_kind`) の設計変更。
- **t1 の残り 2 要素** — 更新成功通知の全数申告 gate (`MutationRedirectFlashTest`) と、
  起動失敗時に白画面にしない配線 (`boot-failure.ts`)。台帳上は同じ機能に属するが、
  本タスクは t0 の中核 (SoT + 両側 gate) に限る。別タスクとして起票する。

## 台帳照会の経緯 (記録)

設計開始時、機能台帳 (lctl) の MCP サーバ (tailscale 上の host) へ到達できず
(`get_feature` / `get_source` が全て timeout、`curl` でも接続不可。一般のインターネット疎通は正常)、
**正典の実ファイルを読めない**状態だった。そのため一旦「HEAD の実測 + 追従タスクに書かれた
クラス名・検査名」から設計を組み立て、後追い照合を完了条件に置いた。

セッション終盤で疎通が回復し、**照合を実施した** (記録: `codex-history/canon-reconciliation.md`)。
台帳 revision・正典の commit・3 点の比較結果・差異をどちらへ寄せたかをそこに残してある。
結果は**全項目で正典へ寄せた**。実装タスクでは照合をやり直す必要はないが、着手時に
`get_feature("inertia-integration")` を 1 度引いて feature_revision が動いていないかだけ確認する。
