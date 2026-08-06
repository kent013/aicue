# Round 3: Round 2 指摘への対応

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 2

## [Warning] §10-1 が `invitations.accept` について施策と矛盾している

- 判断: **対応する**
- 根拠: 指摘のとおり明白な矛盾。`invitations.accept` は入口ページそのものを絞るため
  「入口は開く」という緩和根拠は成立しない。誤った安心を設計に残すのは本タスクが
  是正しようとしている失敗そのもの (死んだ条件と同じ質の誤り)。
- 対応内容: §10-1 の項目 1 を 2 レーンに分けて書き直した。
  - `social-callback`: `login` / `register` の入口は throttle しないので画面は開く
  - `invitation-accept`: **11 人目は招待リンクを開いた時点で 429 になり画面も出ない**と明記
  - 「詰みにならない」根拠を **招待リンクが失効せず `Retry-After` 後に必ず開ける**ことに限定

## [Warning] `social.callback` の前提テストが第 1 段の付与を検査できていない可能性

- 判断: **一部反論 + 対応する**
- 反論の根拠 (事実確認): `RouteThrottleBinder::throttleEntries($router, $route)` は
  「第 3 段の付与台帳」を返す実装ではない。実体は
  `RouteThrottleBinder.php:171-174` の
  `self::filterThrottleEntries($router->gatherRouteMiddleware($route))` であり、
  **解決後の実効 middleware 列** (controller middleware 込み) を filter している。
  `ThrottleCoverageInventoryTest` が母集団全体の判定に使っているのも同じ関数であり、
  第 1 段 (`routes/web.php` 直書き) の付与も確実に見える。
  (台帳を持つのは `attachOnBooted()` に渡す配列の側で、判定関数とは別物)
- 対応する部分: 「解決後の middleware を見ていること」が設計から読み取れなかったのは
  記述不足なので、判定点の実体を明記した。加えて指摘の後半
  「limiter 名まで固定する」は防御として明確に強いので採用し、
  **entry の params 部が `social-callback` であること**まで assert する要件にした
  (throttle は付いているが別 limiter に差し替わっていた、を検出できる)。

## [Warning] `auth_view_render_only` の上限 14 は「proof なしで免除できる枠」を 1 つ残す

- 判断: **対応する** (提案 A: 上限を現在値 13 に固定)
- 根拠: 指摘が正しい。余裕枠は deny-by-default の趣旨と正面から矛盾する。
  提案 B (inventory を data provider にして 13 本すべてに HTTP/Mail/DB 検査を適用) も
  検討したが、`filament.admin.auth.*` は panel 権限を持つ user の用意が要り、
  `password.reset/{token}` / `two-factor.login` は分岐条件を満たさないと
  「描画されなかっただけ」の空振り green になる。**空振りする 13 本の網より、
  実効する 4 本の網 + exact fit の cap** の方が deny-by-default として強い。
- 対応内容:
  - `auth_view_render_only` の上限を **13 (exact fit)** に
  - **全 case の上限を現在値ちょうど**に (署名短絡の +1 余裕も撤回)
  - **全体 cap も 25 (exact)** に
  - exact fit にする理由 (14 本目が必ず「数値を変える差分」として現れ、
    個別理由・代表テスト追加要否・そもそも貼るべきでないかの再検討を強制する) を明記

## [Suggestion] `DB::listen()` の書込判定が先頭コメント / CTE で脆い

- 判断: **対応する** (限定的に)
- 根拠: 対象 4 route が発行するのは Eloquent / query builder 生成の SQL のみで
  先頭コメントは付かないため前方一致で足りるが、検出器が黙って壊れるのは
  deny-by-default の最悪失敗にあたる。
- 対応内容: 判定を `ltrim()` 後の `insert|update|delete|truncate` 前方一致として関数に切り出し、
  **判定関数自身の単体ケース** (先頭空白付き / `select` / `with ... insert`) を
  同ファイルに置く要件を追記した。SQL パーサは導入しない (思考原則 2)。

---

## 参考: RouteThrottleBinder の該当実装 (反論の根拠)

```php
/**
 * 実効 middleware 列 (controller middleware 込み) のうち throttle entry を返す。
 * 目録検査 (ThrottleCoverageInventoryTest) が使う**完全な**判定点。
 */
public static function throttleEntries(Router $router, Route $route): array
{
    return self::filterThrottleEntries($router->gatherRouteMiddleware($route));
}
```

---

## 修正後の §4-4 / §4-5 / §10-1 (全文)

### 4-4. cap の更新 (全体 14 → 25 + **case 別上限**)

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
| `signature_required_before_effect` | 1 | 1 | `storage.local.upload` のみ |
| `auth_view_render_only` | 13 | 13 | 認証面の描画 GET。**ここが膨らむ = 貼るべきものを逃がした疑い** |
| `auth_flow_initiation_without_outbound_call` | 1 | 1 | `social.redirect` のみ。増えたら必ず再設計 |

**上限は現在値ちょうど (exact fit) にする**。余裕を 1 でも持たせると、
その 1 本は「個別の behavioral proof も再レビューも無しに免除できる枠」になる。
exact fit なら 14 本目を足す作業が必ず「上限の数値を変える差分」として現れ、
個別理由・代表テストへの追加要否・そもそも貼るべきでないかの再検討を強制できる。

同じ理由で**全体 cap も 25 (exact)** とする (`array_sum()` にはせず独立の定数)。
全体はセレクタ全体の広さを、case 別は分類の偏りを見る。役割が違うので両方を検査する。

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
| **DB 書込 0 件** | 同上 | `DB::listen` で `insert` / `update` / `delete` / `truncate` で始まる SQL が 0 件 (read は許す) |
| `social.callback` が throttle を**ちょうど 1 本**持ち、その limiter が `social-callback` である | `social.callback` | `AuthFlowInitiationWithoutOutboundCall` の適用条件 4 番目 |

**`social.callback` の検査に使う判定点**: `RouteThrottleBinder::throttleEntries($router, $route)`
を使う。これは `Router::gatherRouteMiddleware($route)` の**解決後**の実効 middleware 列を
filter する実装 (`RouteThrottleBinder.php:171-174`) であり、「第 3 段の付与台帳」ではない。
したがって第 1 段 (`routes/web.php` 直書き) で貼った throttle も確実に見える
(`ThrottleCoverageInventoryTest` が母集団全体の判定に使っているのと同じ関数)。
その上で **entry 文字列の params 部が `social-callback` であること**まで固定し、
「throttle は付いているが別 limiter に差し替わっていた」を検出できるようにする。

**SQL 書込判定の頑健性について**: 先頭コメント / CTE 付き SQL では前方一致が崩れうる。
対象 4 route が発行する SQL は Eloquent / query builder 生成のもの (先頭コメント無し) に
限られるため前方一致で足りるが、判定関数は `ltrim()` してから
`insert|update|delete|truncate` を前方一致する形に切り出し、
**その判定関数自身の単体ケース** (先頭空白付き / `select` / `with ... insert`) を
同ファイル内に置いて、検出器が黙って壊れないようにする。

**DB read を 0 件にしない理由**: `register` は session に自分で置いた
`invitation_token` から prefill を解決するため DB read が 1 件発生する
(`OrganizationMembershipService::resolveRegisterPrefillEmail()`。token 不在なら DB へ到達しない)。
条件は「DB **書込**を伴わない」に留め、read が許される理由を個別 exemption の理由文に書く。

### 4-6. ドキュメント

`docs/app-integration-guide.md` §7b に、**セレクタが「面」で取ること**と
**認証面 GET の分類方針**を追記する (S1 は変更系のまま / S3 は全メソッド、という非対称の理由)。

---

## 5. 代替案と却下理由

### 10-1. 未認証 IP レーン (10/min) の巻き添えリスクをどう扱うか

**正直な評価**: AI-CUE の想定現場 (朝礼後に作業者が一斉ログイン、導入時に管理者が一斉招待) では
「同一グローバル IP から 1 分内に 11 回の SSO callback / 招待リンク open」は**起こりうる**。
「起こらないから安全」とは言わない。

**それでも 10/min IP を採る理由**:

1. **詰みにならない**。429 は 1 分で解け、`Retry-After` ヘッダで再試行時刻が示される。
   - `social-callback`: **`login` / `register` の入口ページは throttle しない**ため
     「画面すら開けない」状態にはならない。止まるのは SSO の完了往復だけ。
   - `invitation-accept`: **こちらは入口ページそのもの** (`GET /invitations/accept`) を
     絞るため、11 人目は**招待リンクを開いた時点で 429 になり画面も出ない**。
     ここを「入口は開く」と書くのは誤りなので明記する。詰みにならない根拠は
     **招待リンクが失効せず、`Retry-After` 後の再試行で必ず開ける**ことに限定される
     (`OrganizationInvitation` の有効期限は分単位ではない)。
   いずれも再試行可能な一回性操作であり、恒久的に到達不能になる経路は生まれない。
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

残 Critical / Warning があれば指摘してください。無ければ全体判定 APPROVED を出してください。
