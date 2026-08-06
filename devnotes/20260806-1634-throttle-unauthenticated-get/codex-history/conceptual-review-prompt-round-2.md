# Round 2: Round 1 指摘への対応

Round 1 の全指摘に対する対応マトリクスと、修正後の概念設計 (該当節) を示します。
残 Critical / Warning があれば指摘してください。無ければ全体判定 APPROVED を出してください。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Critical] `AuthViewRenderOnly` のカテゴリ定義が緩すぎる (`social.redirect` を含めている)

- 判断: **対応する**
- 根拠: 指摘のとおり。`social.redirect` は OAuth state を session に生成して外部 IdP へ
  遷移させる**認証フローの開始**であり、「描画にすぎない」ではない。同じ箱に入れると
  「GET だが認証フローを開始する route」を将来まとめて免除する穴になる。
  enum の docblock 自身が「汎用に見えるものほど適用条件を狭く」と定めているので、
  この指摘は既存設計の思想と一致している。
- 対応内容: 新 case を 2 つに分割 (§4-3)。
  - `AuthViewRenderOnly` (13 本) — 描画のみ
  - `AuthFlowInitiationWithoutOutboundCall` (`social.redirect` 1 本のみ) —
    適用条件に「**対になる完了経路が throttle 済みであること**」を入れ、
    `social.callback` の throttle に構造的に依存させた。
    この依存は `ThrottleExemptionPremiseTest` で behavioral に固定する
    (callback の throttle を外すと exemption の前提が崩れて fail する)。

## [Warning] behavioral proof が「外部 HTTP / Mail なし」に偏っている (4 条件のうち 1 つしか固定していない)

- 判断: **対応する**
- 根拠: deny-by-default の最悪失敗は「前提が崩れたのに inventory は通り続ける」こと。
  4 条件のうち機械化できるものは機械化すべき。
- 対応内容: §4-5 を新設し、検査を 3 点追加。
  1. exemption inventory の key は **throttle を 1 本も持たない**こと
     (指摘の「throttle と exemption の二重登録」。現行は `count($entries)===1` で
      先に continue するため検出できない構造的な穴だった)
  2. 新 2 case を使う entry は **非変更系 (GET/HEAD のみ)** であること
  3. premise テストで **DB 書込 0 件** を追加 (read は許す。理由も明記)

## [Warning] `social.callback` の閾値根拠が「passkeys guest と同値だから」だけでは弱い / 監視・緊急緩和が無い

- 判断: **対応する** (ただし閾値そのものは変えない)
- 根拠: 閾値変更は `AG-096` が「プロダクト依存」と裁定しており、エージェント判断で
  動かさない。一方「巻き添えが起こりうる」ことを設計に書かずに済ませるのは
  前周回の Warning (落としたことを書かなかった) と同じ失敗になる。
- 対応内容: §10-1「未認証 IP レーン (10/min) の巻き添えリスクをどう扱うか」を新設。
  - 起こりうることを正直に認めた上で、詰みにならない根拠 (入口ページは throttle しない /
    1 分で解ける / 一回性操作) を明示
  - 監視項目 (429 発生率) を運用要件として明記
  - **初動は閾値を上げることではない**(まず `TRUSTED_PROXIES` / 実 client IP の解決を疑う)
    という順序を明記

## [Warning] `social.redirect` を exemption にする理由が楽観的 (カテゴリ名と実態のズレ)

- 判断: **対応する** (Critical と同一の対応)
- 根拠: 同上。
- 対応内容: `AuthFlowInitiationWithoutOutboundCall` へ分離。
  「throttle を貼らない」判断自体は維持する (§5 案 5 の根拠 = 外向き HTTP の総量は
  callback 側で有界化されるため redirect を絞っても減らない)。

## [Warning] `two-factor.*` GET への `10,1` が「秘密 GET の保護は済んだ」と誤読されうる

- 判断: **対応する**
- 根拠: 後続 TODO B2 (recent-auth 化) が静かに落ちる失敗モードは実在する。
- 対応内容: §4-2 に「誤読防止 (必須)」を追記。付与箇所の docblock と behavioral テスト名の
  両方に「回数上限であって認証強度ではない / 認証強度は B2」と明記することを設計要件にした。
  ※ Architecture テストや TODO 台帳への機械参照までは作らない
  (B2 は既に `aicue:T120` の後続として台帳にあり、二重管理になる = 思考原則 2)。

## [Warning] cap 14 → 26 が「25 + 1」で、cap だけでは形骸化を防げない

- 判断: **対応する**
- 根拠: 指摘のとおり全体 cap だけでは「どのカテゴリが膨らんだか」が見えない。
  新カテゴリ 13 件が将来 30 件になっても、全体 cap の一言でしか止まらない。
- 対応内容: §4-4 に **case 別上限マップ** (`throttleCoverageExemptionCapByCase()`) を追加。
  既存 6 case は現状値でほぼ固定し (署名短絡だけ +1)、
  `auth_view_render_only` = 14 / `auth_flow_initiation_without_outbound_call` = 1 とした。
  全体 cap は `array_sum()` にせず独立の 26 として両方検査する
  (全体 = セレクタの広さ、case 別 = 分類の偏り。役割が違う)。

## [Suggestion] limiter closure の戻り値型・nullability を明示する

- 判断: **対応する**
- 対応内容: §4-2 に「limiter closure の型」を追記
  (`fn (Request $request): Limit` + `$request->ip() ?? 'unknown'`。既存 limiter と同じ書き方)。

## [Suggestion] 使命への位置づけは妥当

- 判断: **見送る** (変更不要)

---

## 修正後の該当節 (全文)

### 4-2. throttle を新たに貼る 5 本

| route | 貼り方 | limiter | 閾値の根拠 (既存値を発明しない) |
|-------|--------|---------|------------------------------|
| `social.callback` | 第 1 段 (`routes/web.php`) | 新 named `social-callback` | 未認証で到達する認証面・IP 単位の既存本番値 = `passkeys` limiter の guest 分岐 **10/min** と同値 |
| `invitations.accept` | 第 1 段 (`routes/web.php`) | 新 named `invitation-accept` | 姉妹操作 `invitations.accept.store` の **`10,1`** と同値 |
| `two-factor.qr-code` | 第 3 段 (`RouteThrottleBinder`) | inline `10,1` | 姉妹 `two-factor.enable` / `.confirm` / `.disable` / `.regenerate-recovery-codes` と同値 |
| `two-factor.secret-key` | 第 3 段 | inline `10,1` | 同上 |
| `two-factor.recovery-codes` | 第 3 段 | inline `10,1` | 同上 |

- **未認証面に inline を使わない**: `social.callback` / `invitations.accept` は未認証で到達するため
  named limiter を新設し、キーを `{レーン}:ip:{値}` で明示する
  (フレームワーク既定キーへの暗黙依存を作らない = AGENTS.md §7b)。
- **`two-factor.*` GET 3 本に inline `10,1` を使える理由**: いずれも `auth` middleware 配下で
  **actor 自身の 2FA 秘密**しか返さない = 「認証済みかつ actor 自身に閉じる操作」に該当し、
  フレームワーク既定キー (user id) がちょうど求める数える単位になる。
- **誤読防止 (必須)**: `two-factor.qr-code` / `.secret-key` / `.recovery-codes` への `10,1` は
  **連続取得の回数上限**であって、秘密の漏えい防止でも step-up の代替でもない。
  「throttle を貼ったから秘密 GET の保護は済んだ」と次に触る人が誤読すると、後続 TODO **B2**
  (recent-auth 化) が静かに落ちる。付与箇所の docblock と behavioral テスト名の両方に
  「回数上限であって認証強度ではない / 認証強度は B2」と明記する。
- **limiter closure の型**: 新 limiter は `fn (Request $request): Limit` で戻り値型を明示し、
  `$request->ip()` の `?string` を `?? 'unknown'` で潰す (既存 limiter と同じ書き方)。
  PHPStan level 10 を型の widen なしで通す。
- **キーに route parameter / query token を入れない**: `social.callback` の `{provider}`、
  `invitations.accept` の `?token=` を key に混ぜると bucket が分かれ、
  「429 になるまでの回数」が実在オラクルになる (`NamedRateLimiterKeyTest` の思想)。

### 4-3. 残り 14 本を exemption として分類する (新 enum case は 2 つ)

**`social.redirect` を「描画にすぎない route」に混ぜない**。これは OAuth state を生成して
外部 IdP へ遷移させる**認証フローの開始**であり、描画系と同じ箱に入れると
「GET だが認証フローを開始する route」を将来まとめて免除する穴になる。
よって case を 2 つに分ける (enum docblock「汎用に見えるものほど適用条件を狭く」に従う)。

```php
/**
 * 認証面の非変更系 (GET/HEAD) で、応答が画面 / ステータスの描画にすぎない route。
 *
 * 適用条件 (すべて満たすこと):
 *  - HTTP メソッドが GET/HEAD のみ (変更系には適用しない)
 *  - 外部呼び出し・メール送信・重い計算・**DB 書込**を伴わない (DB read は可)
 *  - 推測可能な秘密を開示しない (自セッションが既に保持する情報の再表示は可)
 *  - 副作用が自セッション (CSRF token / flash 等) の中に閉じる
 */
case AuthViewRenderOnly = 'auth_view_render_only';

/**
 * 認証フローを開始するが、その場では外向き通信を一切行わない非変更系 route。
 *
 * 適用条件 (すべて満たすこと):
 *  - HTTP メソッドが GET/HEAD のみ
 *  - **その場で外向き HTTP を発行しない** (発行するのは対になる完了経路)
 *  - 生成する状態が自セッション内に閉じ、他セッションから消費できない
 *  - **対になる完了経路が throttle 済みである** (増幅はそちらで有界化されている)
 */
case AuthFlowInitiationWithoutOutboundCall = 'auth_flow_initiation_without_outbound_call';
```

| case | 対象 | 件数 |
|------|------|------|
| `AuthViewRenderOnly` | `login` / `register` / `password.request` / `password.reset` / `two-factor.login` / `password.confirm` / `password.confirmation` / `recent-auth.confirm` / `recent-auth.status` / `verification.notice` / `filament.admin.auth.login` / `filament.admin.auth.profile` / `filament.admin.auth.multi-factor-authentication.set-up-required` | 13 |
| `AuthFlowInitiationWithoutOutboundCall` | `social.redirect` | 1 |

`AuthFlowInitiationWithoutOutboundCall` の適用条件 4 番目が
「`social.callback` に throttle を貼る」という本設計の施策に**構造的に依存**している点が重要で、
将来 callback の throttle を外すと exemption の前提が崩れる。
これは §4-5 の前提テストで behavioral に固定する。

### 4-4. cap の更新 (全体 14 → 26 + **case 別上限**)

母集団 47 → 70 (+49%) に対し exemption は 11 → 25。
比率は 23.4% → 35.7% に上がるが、これは「増分 23 本のうち 19 本が
**認証済み or 未認証の画面描画**」という母集団の質の変化そのものであり、
セレクタが広すぎることの証拠ではない。

ただし**全体 cap だけでは「どのカテゴリが膨らんだか」が見えない**。
新カテゴリ 13 件が将来 20 件・30 件へ増えても全体 cap の一言でしか止まらず、
レビュー時に「増えたのは描画系か、それとも本来貼るべきものが逃げたのか」を区別できない。
そこで **case 別の上限マップ**を併設する:

```php
/** @return array<string, int> ThrottleCoverageExemption::value => 上限 */
function throttleCoverageExemptionCapByCase(): array
```

| case | 現在 | 上限 | 上限の意味 |
|------|------|------|-----------|
| `static_metadata_response` | 4 | 4 | vendor が登録する OAuth メタデータ 4 本で固定 |
| `vendor_method_not_allowed_stub` | 2 | 2 | `GET|DELETE /api/v1/mcp` の 2 本で固定 |
| `session_teardown_only` | 2 | 2 | web / filament の logout 2 本で固定 |
| `local_only_debug_route` | 1 | 1 | `debug.login-as` のみ |
| `component_level_limiter` | 1 | 1 | `default-livewire.update` のみ |
| `signature_required_before_effect` | 1 | 2 | 署名短絡は今後も出うるため +1 |
| `auth_view_render_only` | 13 | 14 | 認証面の描画 GET。**ここが膨らむ = 貼るべきものを逃がした疑い** |
| `auth_flow_initiation_without_outbound_call` | 1 | 1 | `social.redirect` のみ。増えたら必ず再設計 |

全体 cap は `array_sum()` ではなく独立の 26 とし、両方を検査する
(全体はセレクタ全体の広さ、case 別は分類の偏りを見る。役割が違う)。

### 4-5. 検査の強化 (exemption の穴を 3 つ塞ぐ)

母集団を広げるだけでなく、**exemption 側の検査を 3 点足す**。
いずれも既存テストが構造的に見落としている点である。

1. **`ThrottleCoverageInventoryTest`**: exemption inventory の key は
   **throttle を 1 本も持たない**こと。
   現行は「throttle 1 本 → continue」で先に抜けるため、
   *throttle 済みなのに exemption にも登録されている* 状態を検出できない
   (stale 検出も「母集団に存在するか」しか見ない)。放置すると台帳に死んだ行が溜まる。
2. **`ThrottleCoverageInventoryTest`**: 新 2 case を使う entry は
   **非変更系 (GET/HEAD のみ) の route** であること。
   両 case の適用条件 1 番目を機械化する (`logout` 等の変更系がこの箱に落ちない)。
3. **`ThrottleExemptionPremiseTest`**: 新 2 case の前提を behavioral に固定する。

前提テストの内容 (14 本すべてに個別テストは書かない。**壊れやすい条件**だけを固定する):

| 検証 | 対象 | 方法 |
|------|------|------|
| 外向き HTTP 0 件 / メール送信 0 件 | `login` / `register` / `password.request` / `social.redirect` | `Http::preventStrayRequests()` + `Mail::fake()` の下で GET |
| **DB 書込 0 件** | 同上 | `DB::listen` で `insert` / `update` / `delete` 文が 0 件 (read は許す) |
| `social.callback` が throttle を持つ | `social.callback` | `AuthFlowInitiationWithoutOutboundCall` の適用条件 4 番目。`RouteThrottleBinder::throttleEntries()` が 1 本返すことを assert する |

**DB read を 0 件にしない理由**: `register` は session に自分で置いた
`invitation_token` から prefill を解決するため DB read が 1 件発生する
(`OrganizationMembershipService::resolveRegisterPrefillEmail()`。token 不在なら DB へ到達しない)。
条件は「DB **書込**を伴わない」に留め、read が許される理由を個別 exemption の理由文に書く。

### 4-6. ドキュメント

`docs/app-integration-guide.md` §7b に、**セレクタが「面」で取ること**と
**認証面 GET の分類方針**を追記する (S1 は変更系のまま / S3 は全メソッド、という非対称の理由)。

---

## 5. 代替案と却下理由

### (§10 リスク表と §10-1 全文)
## 10. リスク

| リスク | 影響 | 緩和 |
|--------|------|------|
| `social-callback` 10/min IP が同一 NAT 配下の一斉 SSO ログインを巻き添えにする | 現場 Wi-Fi・オフィス NAT で、同じ 1 分内に 11 人目以降が SSO ログインを完了できない | §10-1 |
| `invitation-accept` 10/min IP が一斉招待の同時クリックを巻き添えにする | 同一オフィスから 11 人目が招待リンクを開けない | §10-1 |
| `two-factor.*` GET への `10,1` が 2FA 設定画面のリロード連打を止める | 自分の設定画面が一時的に開けない | actor 自身のバケットであり他者への影響ゼロ。姉妹 POST と同値 |
| exemption 25 件で台帳が形骸化する | gate がハンコになる | cap を 26 で締める + 新カテゴリの前提を `ThrottleExemptionPremiseTest` で behavioral に固定する |
| S3 拡張により将来の認証面 GET 追加が必ず fail する | 開発時の摩擦 | それが deny-by-default の目的であり、意図した摩擦 |

### 10-1. 未認証 IP レーン (10/min) の巻き添えリスクをどう扱うか

**正直な評価**: AI-CUE の想定現場 (朝礼後に作業者が一斉ログイン、導入時に管理者が一斉招待) では
「同一グローバル IP から 1 分内に 11 回の SSO callback / 招待リンク open」は**起こりうる**。
「起こらないから安全」とは言わない。

**それでも 10/min IP を採る理由**:

1. **詰みにならない**。429 は 1 分で解ける。かつ `login` / `register` / `invitations.accept` の
   **入口ページ自体は throttle していない**ため、「画面すら開けない」状態にはならない。
   止まるのは SSO の完了往復と招待リンクの token 照合だけで、いずれも再試行可能な一回性操作。
2. **閾値を発明しない** (`AG-096` / AGENTS.md §7b)。未認証の認証面 IP レーンで本番稼働中の
   最大値が `passkeys` guest 分岐の 10/min であり、これ以上の値は本リポジトリに前例がない。
   前例のない値を「巻き添えが怖いから」で発明すると、gate の閾値規律が崩れる。
3. **キーの単位を変える代替が成立しない**。session id でキーすると
   `social.redirect` を叩くだけで新しい bucket を無限に取れるため、攻撃者に対し実質無制限になる
   (§5 案 6)。provider / token をキーに混ぜると存在オラクルになる (`NamedRateLimiterKeyTest`)。

**運用要件 (実装者への申し送り)**:

- `social-callback` / `invitation-accept` の **429 発生率を監視項目に入れる**
  (既存の webhook レーンと同じ扱い。`docs/app-integration-guide.md` §7b の
  「429 発生率を監視する」運用に 2 レーンを追加する)。
- **実測で巻き添えが出たときの初動は「閾値を上げる」ではない**。まず
  `TRUSTED_PROXIES` / 実 client IP の解決が正しいか (`docs/trusted-proxies-runbook.md`) を疑う。
  IP が実質 1 個に潰れていれば、閾値をいくら上げても同じことが起きる。
  それが健全な上で不足するなら、**プロダクト判断として閾値変更の TODO を起票する**
  (`AG-096` は閾値をプロダクト依存と裁定しており、エージェント判断で動かさない)。
