# T125 gate 赤化確認 (mutation) の記録

実施日: 2026-08-07 / ブランチ `todo/T125` (base = main 3f38e06)。

手順: 1 mutation ごとに **primary の gate をファイル単位で実行** し
(`composer test -- <file>`)、赤になること・理由メッセージが期待どおりであることを確認して
**全ファイルをバックアップから復元**した。ドライバは
`/tmp/.../scratchpad/mutate.py` (一時スクリプト。恒久化しない)。
最終状態に mutation が残っていないことは `git status --short` と
残留文字列 grep (`t125-mutation-probe` / `ProbeMutationCase` / `fake.route.probe` /
`password-sett` / `is_scalar`) で確認済み。

> **「そのテストだけが赤になる」とは書かない**。route の指定を 1 か所変えると
> 複数の gate が同時に反応するのが正常であり、primary (この mutation で検証したい gate) と
> collateral (同時に赤くなるのが正しい gate) を分けて記録する。

## 結果一覧

| # | mutation | primary | 実測 | primary のメッセージ |
|---|---|---|---|---|
| M1 | `recent-auth.password` を `throttle:6,1` に戻す | `InlineThrottleInventoryTest`「inline throttle を持つ route は目録に登録されている」 | **赤** (7 中 1 失敗) | `recent-auth.password` が未登録として列挙 |
| M2 | 目録から `livewire.upload-file` を消す | 同上 | **赤** (7 中 2 失敗) | `livewire.upload-file` が未登録として列挙。collateral =「case 別件数」(mixed 0 件 / 宣言 1 件) |
| M2' | 目録に架空 route を 1 件足す | 「目録の key は現存する inline throttle route」 | **赤** (7 中 2 失敗) | `fake.route.probe` が stale として列挙。collateral =「case 別件数」(stateless 3 件 / 宣言 2 件) |
| M2'' | enum に case を足す (件数宣言はしない) | 「case 別件数が宣言値とちょうど一致」 | **赤** (7 中 1 失敗) | `probe_mutation_case: inlineThrottleRationaleExactCountByCase() に件数がありません` |
| M2''' | 目録から `passport.device.code` を消す | 「case 別件数が宣言値とちょうど一致」(**減少方向**) | **赤** (7 中 2 失敗) | `vendor_stateless_ip_bucket: 1 件 (宣言 2 件)`。collateral =「未登録」検査 |
| M2'''' | 分類 case を実効 middleware 列と食い違わせる (passport=mixed / livewire=stateless) | 「分類 case の適用条件が実効 middleware 列と一致する」 | **赤** (7 中 1 失敗) | 両 route が「適用条件 (session / auth の有無) を満たしていません」 |
| M3 | `settings.password.store` を `throttle:password-verify` に変える | `ThrottleLaneAssignmentTest`「割当が目録と完全一致する」 | **赤** (4 中 2 失敗) | `password-verify` に 4 本 / `password-set` が消える差分。collateral =「レーンはすべて 1 本以上」 |
| M4 | 同 route を `throttle:password-sett` (typo) に変える | `ThrottleLaneAssignmentTest`「route に貼られた named limiter はすべて実在する」 | **赤** (4 中 3 失敗) | `settings.password.store → password-sett`。collateral = 割当一致 / レーン空振り |
| M5 | `password-set` limiter のキーを `password-verify` にする | `RateLimiterKeyConventionTest`「共有グループ外の limiter は互いにキーを共有しない」 | **赤** (10 中 3 失敗) | `password-verify と password-set が同じキーを produce`。collateral = prefix 一致 / full key |
| M5' | `RateLimiterKeys` の種別を `:user:` → `:actor:` | 同「actor/IP レーンの full key が宣言と完全一致する」 | **赤** (10 中 2 失敗) | 8 レーンすべてで `期待 [...:user:4242] 実際 [...:actor:4242]`。collateral = prefix 一致 |
| M6 | 共有グループ宣言から `api-write` を外す | 同「共有グループ外の limiter は互いにキーを共有しない」 | **赤** (10 中 1 失敗) | `api-read と api-write` / `api-write と api-status` の衝突 |
| M6' | `api-write` だけ別キーにする | 同「宣言した共有グループは実際にキーを共有している」 | **赤** (10 中 2 失敗) | `api-actor/api-write: 他のメンバーとキーを共有していません (宣言が古い)`。collateral = prefix 一致 |
| M7 | `RateLimiterKeys` の user 分岐を `is_scalar()` に戻す | `RateLimiterKeysTest`「bool / float のとき user 分岐へ落ちない」 | **赤** (8 中 4 失敗) | `true` → `:user:1` / `false` → `:user:` / `1.5` → `:user:1.5` / 空文字 → `:user:` |
| M8 | `two-factor-manage` を `throttle:10,1` に戻す | (設計の primary) `AuthThrottleCoverageTest`「2FA 管理レーンを使い切っても…」 | **緑のまま** (下記 §M8 参照) | — |
| M8 (再判定) | 同上 | `InlineThrottleInventoryTest`「未登録」/ `ThrottleLaneAssignmentTest`「割当一致」「レーン空振り」 | **赤** (それぞれ 1 / 2 失敗) | 4 本が未登録として列挙 / `two-factor-manage` にレーンが 0 本 |
| M9-a | `recent-auth.password` から throttle を剥がし、**かつ**残数ヘッダ検査を外す | helper を使う 3 本 (Livewire / 2FA 管理 / メール検証) が**緑のままになる**こと | **3 本とも緑** | — (これが「ヘッダ検査が無いと false green になる」証明) |
| M9-b | M9-a から**ヘッダ検査だけを戻す** | 同じ 3 本が赤になること | **3 本とも赤** | `X-RateLimit-* が無い = throttle が走っていない。false green の疑い` |
| M10 | 自前 controller の web route に `throttle:9,1` を付け `VendorMixedUserOrIpBucket` で登録 (件数も 2 に上げる) | `InlineThrottleInventoryTest`「分類 case の適用条件が実効 middleware 列と一致する」 | **赤** (7 中 1 失敗) | `t125.mutation.probe: vendor_mixed_user_or_ip_bucket の適用条件を満たしていません` = **自前 route は vendor case へ登録できない**の証明 |

## M8: 設計の primary が赤にならなかった件 (実態どおり記録する)

設計表は M8 (2FA 管理を inline へ戻す) の primary を
`AuthThrottleCoverageTest`「2FA 管理レーンを使い切っても再認証・パスワード設定・メール検証は
429 にならない」としていたが、**実測は緑のまま**だった。

原因は設計側の予測誤りである。本 TODO 実装後は `recent-auth.password` /
`settings.password.store` / `verification.send` が **named レーンへ移っている**ため、
2FA 管理だけを inline へ戻しても:

- 2FA 管理の inline bucket (max 10) は 10 回で使い切られ、11 回目は 429 → 上限の期待は満たされる
- 巻き添え先の 3 本は別 bucket (named) のまま → 429 にならない

つまり「inline へ 1 本だけ戻す」は、**他の全員が named にいる限り巻き添えを作らない**。
巻き添えが復活するのは 2 本以上を同時に inline へ戻したときであり、
behavioral proof の守備範囲ではない。

この mutation を捕まえるのは**目録 gate**である (再判定で確認済み):

- `InlineThrottleInventoryTest`「未登録」= 自前でない vendor route ではない 4 本が inline に現れる
- `ThrottleLaneAssignmentTest`「割当が目録と完全一致」「レーンはすべて 1 本以上」
  = `two-factor-manage` レーンが空になる

したがって **M8 に対する検出は失われていない**が、
「behavioral proof が単独で inline 回帰を全部捕まえる」という主張は誇張になるため
そう書かない。behavioral proof が固定しているのは
「**あるレーンを使い切ったとき別レーンが生きていること**」であり、
inline への差し戻しそのものの検出は目録 gate の担当である。

## M9 の対象外 (設計どおり)

- 「パスワード照合レーンを使い切っても…」は `recent-auth.password` を**消費元**としても
  使うため、throttle を剥がすと 7 回目の 429 期待で先に赤になる (M9-a / M9-b とも赤)。
- 「パスワード照合面 3 本は 1 つのレーンを共有する」も同 route の残数ヘッダを
  **直接**読むため M9-a / M9-b とも赤。
- 「認証は throttle より先に走る」は `ThrottleRequests` が実効列から消えるため
  M9-a / M9-b とも赤。
- `ActivatePersonalTest` のプラン有効化テストは helper を使わず直接ヘッダ検査を書くため
  M9 の観測対象外 (設計 Round 4 の裁定どおり)。
