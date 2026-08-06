# 対応マトリクス: design-review Round 1

## [Critical] 施策 6: Filament MFA set-up-required の exemption が「実装時に確認」のまま

- 判断: **対応する** (実査で確定させた)
- 根拠: deny-by-default gate で「未確定のまま exemption を置く」のは最悪。指摘が正しい。
- 対応内容: vendor 実装を実査し、**GET の描画では TOTP 秘密もリカバリコードも生成されない**
  ことを確定した。生成は `SetUpAppAuthenticationAction::mountUsing()`
  (`vendor/filament/filament/src/Auth/MultiFactor/App/Actions/SetUpAppAuthenticationAction.php:45-54`)
  = **Livewire POST (`default-livewire.update`)** の中で起きる。
  `SetUpRequiredMultiFactorAuthentication::mount()` は enable 済み判定と redirect のみ。
  exemption 理由を「導線リンクの描画のみ / 生成は mountUsing 側」と実装に即した文言へ書き換え、
  「実装時に確認すること」から削除した。設計末尾に**実査で確定させた事項の表**を新設。

## [Critical] 施策 9-4: PHPStan level 10 で `$callback` の null が絞り込まれない

- 判断: **対応する**
- 根拠: `expect()->not->toBeNull()` は PHPStan の narrowing にならない。指摘のとおり。
- 対応内容: `Webmozart\Assert\Assert::isInstanceOf($callback, RoutingRoute::class)` で
  narrowing する形に書き換え (リポジトリ標準は Webmozart Assert。`assert()` ではなく
  既存コード (`SocialAuthController` 等) と同じ流儀に揃えた)。必要な import も明記。

## [Warning] 施策 4: 2FA GET へ `10,1` を貼ると既存 2FA 操作と bucket を共有するのでは

- 判断: **対応する (設計を変更した。本レビューで最大の収穫)**
- 根拠: 指摘を受けて vendor を実査した結果、**共有することが確定**した。
  `ThrottleRequests::handle()` の inline 分岐はキーを
  `$prefix.resolveRequestSignature($request)` で作り、`$prefix` の既定は `''`、
  `resolveRequestSignature()` は認証済みなら `formatIdentifier($user->getAuthIdentifier())`
  **だけ**を返す (route も limiter 名も入らない)。
  一方 named limiter は `md5($limiterName.$limit->key)` でレーンが分かれる。
  → ページ描画のたびに 2 発飛ぶ GET を inline に足すと、
  **2FA 設定画面を 3 回リロードしただけで共有カウンタが 6 に達し、
  `recent-auth.password` (max 6) が 429 = 再認証できなくなる**。
  「秘密 GET を有界化するために再認証を壊す」は明確な後退。
- 対応内容:
  - 施策 4 を **named limiter `two-factor-secret-read` (10/min、user | ip の 2 分岐)** に変更。
    閾値 10/min は姉妹の 2FA 管理操作と同値のまま (値は発明していない)
  - 変更の根拠 (vendor 実装の引用付き) を施策 4 の冒頭節として明記
  - AGENTS.md の規約との関係を明記: 文言 (「認証済みかつ actor 自身」) は満たすが、
    **根拠**(「既定キーがちょうど求める数える単位になる」) が成立しないため規約の根拠に従う
  - 施策 7 に `two-factor-secret-read` の scenario を追加 (limiter は 3 本になる)
  - 施策 10 に「inline throttle は route ごとの bucket ではない」を docs 追記項目として追加
  - 後続 TODO 候補に「既存 inline throttle 群の bucket 共有の見直し」を追加
    (既存レーンの分離は閾値と数える単位の再設計になるため本タスク外)

## [Warning] 施策 8-5 が middleware entry の存在確認に寄っている / bucket 共有を示せない

- 判断: **対応する**
- 根拠: 上記の発見により、この指摘は決定的に重要になった。
- 対応内容: テストを 6 本 → 8 本に増やし、
  **8-6「2FA 秘密 GET のレーンは独立している — 10 回踏んでも recent-auth / 2FA 管理 POST が
  429 にならない」を新設**した (inline へ戻したらここで落ちる恒久回帰)。
  8-7 で実際の 429 発生も固定する (存在確認だけにしない)。

## [Warning] 施策 8: `Http::preventStrayRequests()` は Socialite/Guzzle を保証しない

- 判断: **対応する**
- 根拠: Socialite は Guzzle を直接使うため Laravel HTTP client の fake では捕まらない。正しい。
- 対応内容: 8-3 を新設し、**Socialite ファサードの spy で `driver()` が呼ばれないこと**を
  直接 assert する形にした。`preftStrayRequests()` は追加の網としてのみ併用し、
  単独の根拠にしないことを実装注意に明記。

## [Warning] 施策 9-2 が `social.redirect` にも DB 書込 0 件を要求している (session driver と衝突)

- 判断: **対応する**
- 根拠: `social.redirect` は session に OAuth state を書く。`SESSION_DRIVER=database` では
  DB 書込として観測され、case の適用条件 (自セッション内の副作用は許容) と検査条件が衝突する。
- 対応内容: 9-1 / 9-2 の対象を `AuthViewRenderOnly` 代表 3 本に限定し、
  `social.redirect` は 9-4 (外向き HTTP なし / Socialite spy) + 9-5 (完了経路の throttle 実在)
  で検証する形に分離。**条件に無いものを検査しない**理由も明記した。

## [Warning] 施策 9: CTE (`with ... insert`) を先頭動詞判定では検出できない

- 判断: **対応する**
- 根拠: deny-by-default では見逃しが最悪。過検出は「exemption を諦めて throttle を貼る」方向に
  しか倒れないので安全、という指摘の論理が正しい。
- 対応内容: 判定を「先頭が insert/update/delete/truncate、**または** `with` で始まり
  これらの動詞を含む」に変更 (保守的に write 扱い)。9-3 の単体ケース表も更新。

## [Warning] 施策 6: タイトルが「検査 2 本追加」だが snippet は 3 本

- 判断: **対応する**
- 対応内容: 施策一覧・見出し・波及変更をすべて「検査 3 本追加」に統一。

## [Warning] 施策 6: case 別 cap のテストが「未使用 case」を検出しない (説明と不一致)

- 判断: **対応する** (説明を直すのではなく**検査を強くする**方を採った)
- 根拠: 説明どおり「新 case を足したら上限も同時に決めさせる」方が deny-by-default として強い。
  使用時に初めて要求する形だと、使い始めた瞬間に上限なしで通る窓が空く。
- 対応内容: `ThrottleCoverageExemption::cases()` を走査して**全 case に cap を要求**する形に変更。
  併せて cap 側に enum に無い case が残っていないか (rename/削除の stale) も検出するようにした。

## [Warning] 施策 10: 後半に `exemption 25 / cap 26` と読める記述があり exact fit と矛盾

- 判断: **対応する**
- 対応内容: 概念設計 §8-2 の検証表と §10 のリスク表を
  「全体 cap 25 (exact fit) + case 別上限」に統一。

## [Warning] 施策 2: 無効リクエストも正当ユーザーの枠を消費する点を明記すべき

- 判断: **対応する**
- 対応内容: limiter の docblock に「無効リクエストも同じ bucket を消費する = 一時 DoS が残る」
  ことと、その引き換えに得ているもの (外向き HTTP / token 照合の総量が有界になる) を明記。
  テスト名 (8-1 / 8-4) にも「無効 request でも枠を消費する」観点を入れた。

## [Warning] 施策 3: `social.redirect` 無制限との組み合わせで callback 枠を枯らす一時 DoS が残る

- 判断: **対応する** (許容リスクとして docs に残す)
- 対応内容: 施策 10 の docs 追記項目に「invalid callback 比率の監視」と
  「`social.redirect` を throttle しないため一時 DoS は残る」を明記。

## [Suggestion] 施策 1: fail 観測ログに実測母集団数も残す運用

- 判断: **対応する** (軽微)
- 対応内容: 再現用スクリプト
  `devnotes/20260806-1634-throttle-unauthenticated-get/measure-population.php` を設計成果物として
  同梱し、「実装時に確認すること」から参照するようにした。
