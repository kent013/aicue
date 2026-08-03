# S5: 課金・チケット(残高/チャージ/消費)

- 前提状態: 代表ユーザー(組織オーナー/管理者)でログイン済み。Stripe fake。
- 目的: プラン選択 → checkout → サブスク状態確認、およびチケット残高の確認・チャージ・消費が二重課金/無反応/残高不整合なく進むか。料金表(pricing)の表示が実際の課金と矛盾しないか。

## 手順
1. `pricing`(料金表)を開く → 三層(個人バナー / 法人グリッド / 大規模利用バナー)とチケット価格表が表示され、CTA(申込/チャージ)導線が見える。未ログインでも閲覧でき、申込はログインへ誘導。**月次付与は廃止済み(D28)なので「月 N 枚のチケット付与」表記が復活していないか**も見る。
2. `billing.index` → 現在のプラン・per-bucket チケット残高(今すぐ使える/プラン付与残/購入済み残)・現行 quota 上限・「プラン比較」導線が表示(※容量Quota使用率は Dashboard 側。billing.index には容量ウィジェットは無い)。プラン一覧は `billing.plans` へ移設済み。
3. `billing.plans`(プラン比較) → 変更できないプランでも CTA は押せ、押下時に理由が出る(disabled で詰まない)。
4. `billing.checkout`(プラン申込/チケットチャージ) → Stripe fake の checkout へ → 戻ると残高/プランが更新され、二重送信しても二重課金にならない(冪等)。
5. `billing.portal` → Stripe カスタマーポータルへ遷移(無料パーソナル / 未契約では error flash で戻る = 500 にならない)。
6. チケットスポット購入 `billing.tickets.show`(`/purchase-tickets`) → 枚数入力 → `billing.tickets.checkout`(Stripe fake)。**枚数に範囲外(>上限)を入れてエラー表示後、有効値に修正するとエラー/invalid が即座に消える**か(stale invalid 解消, T041)。合計金額が枚数に応じ再計算されるか。
7. チケット消費との整合(S3 と連動): analyze で 1、render で N 消費され、残高が減る。preview は非消費。ジョブ失敗時は予約が解放され残高が戻る(reserve→commit/release の 2 フェーズ)。

## このストーリーで消化する screens / operations
- screens: pricing, billing.index, billing.plans, billing.tickets.show
- operations: billing.checkout, billing.portal, billing.tickets.checkout

## 逸脱アイデア (--deviate 時)
- checkout を二重送信/戻る→再送 → 二重課金・二重チャージにならないか(冪等マシン/webhook)。
- 残高不足のまま analyze/render を強行 → 押下時エラーで詰まないか、残高がマイナスにならないか。
- チャージ直後にジョブ失敗 → 予約解放で残高が正しく戻るか。TTL 切れ予約の付け替えで二重消費しないか。
- 料金表の価格と実 checkout 金額・付与チケット数が一致するか(表示と課金の乖離)。
- 他組織の billing/checkout に自分のセッションでアクセス → 認可されるか。
