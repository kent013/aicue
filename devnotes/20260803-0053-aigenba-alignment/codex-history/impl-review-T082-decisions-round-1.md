# 対応マトリクス: impl-review T082 (トラック T-a / 施策 1〜8) Round 1

Codex 返答: [`../impl-review-T082-round-1.md`](../impl-review-T082-round-1.md)
プロンプト: [`impl-review-T082-prompt-round-1.md`](./impl-review-T082-prompt-round-1.md)
モデル: `gpt-5.3-codex` (`model_reasoning_effort=high`) / one-shot (`--ephemeral --sandbox read-only`)

Codex verdict: **CHANGES_REQUESTED**

---

## [Critical] 施策 8 の必須完了条件 (WebKit レーンで復元シナリオ 2/3/4 を恒久自動回帰化) が未達

`tests/Browser/AuthenticatedPageBfcacheTest.php` の 3 シナリオが両レーンで skip されている。

- **判断: 事実として認める (この worktree では解消不能)。ただし「原因の記述」は誤りだったので訂正する**
- **根拠**:
  - skip 自体は正しい設計 (`bfcacheRestoreIsReproducible()` でハーネスの再現能力を毎回実測し、
    再現できたら正のコントロールが厳格に効く)。**空振りを green と偽っていない**点は設計どおり。
  - しかし実装・文書が書いていた原因「Playwright は自動化インスペクタを接続した状態で
    ブラウザを起動するため」は **Chromium については誤り**であることを本レビューで実測確認した。
    真の原因は **Playwright が chromium の既定起動スイッチに `--disable-back-forward-cache` を
    固定で含めている**こと (`playwright-core` 1.61.1 の chromium switches を実際に grep して確認)。
    = `no-store` による evict 以前に、**bfcache 機構がブラウザ起動時点で無効**になっている。
  - この誤った原因を恒久文書に残すと、必須完了条件の解消方向を誤らせる (最も害が大きい)。
  - **Chromium 側は解消経路が具体化した**: `launch` に
    `ignoreDefaultArgs: ['--disable-back-forward-cache']` を渡せば有効化できる。
    ただし `pest-plugin-browser` の `Playwright/Client.php::connectTo()` が launch-options を
    ハードコードしており、**プラグイン側の対応か vendor patch が必要**。本 diff の範囲外。
    かつ Chromium は cookie 変更で CCNS ページを evict するため、有効化しても
    **シナリオ 4 は原理的に再現できない** (シナリオ 2・3 のみ)。
  - **WebKit 側 (= 正本レーン) の原因は未特定**。ここが本丸であり、調査が要る。
- **対応内容**:
  - `docs/supported-browsers.md` の「bfcache 復元が自動回帰でカバーできていない理由」を
    レーン別の原因表 (Chromium=特定済み / WebKit=未特定) に書き換え、
    「自動化インスペクタが原因」という誤った説明を明示的に否定した。
  - 「未対応事項」節に「Chromium は有効化してもシナリオ 4 は原理的に不可」を追記。
  - `Target` 表の目標記述をレーン別の具体的な解消条件へ更新。
  - `tests/Browser/AuthenticatedPageBfcacheTest.php` の冒頭コメントと skip メッセージ、
    `scripts/run-browser-test.sh` のコメントの原因記述も同様に訂正。
  - **完了条件そのものは満たせていない**。T-a は **CHANGES_REQUESTED のまま**とし、
    設計側で (a) 別ハーネスの採用 / (b) pest-plugin-browser への launch-options 経路追加 /
    (c) WebKit の page cache 無効化原因の特定 / (d) 完了条件の再定義 のいずれかを判断する必要がある。

## [Critical] `docs/supported-browsers.md` が未達を自己申告している状態で完了扱いは不可

- **判断: 対応する (= 完了扱いにしない)。ただし文書の記述自体は正しい**
- **根拠**: 施策 7 は「マージ後の実態を書く」と規定しており、
  未達を未達と書いている現状の文書は**規定どおり**。Codex も施策 7 との整合は認めている。
  問題は文書ではなく、**施策 8 の必須条件が未達であること**そのもの。
- **対応内容**: 上記 Critical と同じ。**verdict を CHANGES_REQUESTED として報告**し、
  T082 を green だから完了とは扱わない。

## [Warning] `MANUALLY_RESOLVED` の免除が param 単位で広すぎる (deny-by-default の穴)

- **判断: 対応する (実装を修正)**
- **根拠**: 設計書に無い新設定数であり、かつ param 名だけの免除は
  「将来 `{notification}` を使う別 route が丸ごと IV-9(a) を免除される」穴になる。
  Codex の指摘どおり **route identity + param 単位**へ縮小すべき。
  なお免除しても IV-3/IV-4 (pattern) と IV-9(b)(c) は効くため 22P02/22003 防御は落ちない
  (この点は元実装の主張どおり)。
- **対応内容**:
  - `RouteBindingTypes::MANUALLY_RESOLVED` を
    `array<string, array{routes: list<string>, reason: string}>` へ変更し、
    `notification` の免除を `notifications.open` / `notifications.read` の 2 route に限定。
  - `routeBindingResolutionViolations()` の (a) 免除判定を
    「param 名一致 **かつ** route identity 一致」へ変更。
  - IV-9 補テストを拡張: 免除 route identity が**実在すること** (陳腐化した免除を残さない)、
    `routes` が空でないこと、理由が非空であることを検証。
  - **負のコントロールを追加**: 免除済み param `{notification}` を**別の fixture route** で
    typehint 無しに使うと fail することを固定
    (`負のコントロール IV-9(a): MANUALLY_RESOLVED 未登録 route の同名 param は免除されない`)。
    param 単位の免除に戻すとこのテストが落ちる。
  - `docs/architecture.md` に `MANUALLY_RESOLVED` の規約を明記。

## [Warning] Livewire の route identity 正規化が設計書に未記載

- **判断: 対応する (仕様化)。実装は維持**
- **根拠**: Livewire の endpoint prefix は `EndpointResolver::prefix()` が APP_KEY 由来の
  8 桁ハッシュを生成するため、**正規化しないと APP_KEY ごとに inventory が壊れる**
  (dev と testing で別ハッシュ = 環境依存の誤 fail)。実装判断は妥当。
  ただし設計書の「identity は name か `method:uri`」に対する未記載の拡張であり、
  仕様として残す必要がある。
- **対応内容**: `docs/architecture.md` の route identity 規約に正規化を明記した。
  正規化 regex は `^livewire-[0-9a-f]{8}/` と**狭く**限定しており
  (prefix セグメントのみ・path 構造と method は保持)、誤同一視のリスクは限定的と判断。
  設計書本体 (正本) への反映は設計者の責務なので**逸脱事項として報告**する。

## [Warning] 施策 5 の期待完全値が設計表 (`no-store`) と違い `no-store, private` になっている

- **判断: 反論する (実装が正しい)。ただし設計表は要訂正**
- **根拠**: ソース側は設計書どおり `Cache-Control: no-store` を設定している
  (`RequireRecentAuth.php:57` / `RequireTwoFactorForEnforcedOrganizations.php:97` /
  `FortifyServiceProvider.php:199`)。実際に送出されるヘッダが `no-store, private` になるのは
  **Symfony `ResponseHeaderBag::computeCacheControlValue()` が `public` / `private` / `s-maxage`
  のいずれも無い Cache-Control に `, private` を自動付与する**ため。
  施策 5 は「**送出される完全値**をピンする」テストなので、実測値をピンするのが正しい。
  P3-a baseline が書き換えた結果ではない (`no-store` を持つ応答は untouched)。
  設計書の「現行値」列がコードのリテラルを書いていたのが不正確だった。
- **対応内容**: 実装は変更しない。**設計書の表の訂正が必要**な点を逸脱事項として報告する。
  なお「untouched 契約の証明」という docblock の主張は、baseline が付与する値も
  `no-store, private` で**同値のため 4 経路中 3 経路では判別力が無い**
  (guest である経路 1 だけが真に untouched を証明している)。これは保証の後退ではないが
  docblock の表現がやや強い。**非ブロッキングとして報告**するに留める。

## [Suggestion] `NON_MODEL` 縮小 (7 → 3) の根拠が設計書と未同期

- **判断: 対応する (文書化)**
- **根拠**: 設計書の 7 件は route を実走査する前の暫定列挙であり、
  実 route に現れない param を残すと **IV-2 (逆方向検査) が陳腐化した登録として fail** する。
  縮小は gate の要求に従った結果であり、保証の弱化ではない。
- **対応内容**: `docs/architecture.md` に「`NON_MODEL` は実 route 走査の結果だけを登録する
  (残すと IV-2 が fail させる)」を明記した。

## [判定なし] `routes/api.php` の `whereNumber` 6 箇所削除

- Codex 判定: 「設計整合 (問題なし)」。設計書「後方互換の並走を残さない (思考原則 #3)」に
  明記された `whereUuid` 削除と同型であり、`Route::pattern` が同一以上の制約
  (`[0-9]{1,18}` は `whereNumber` の `[0-9]+` より狭い) を掛ける。**対応不要**。

---

## 再検証 (修正後)

| コマンド | 結果 |
|---|---|
| `vendor/bin/pint --test` | pass (`{"tool":"pint","result":"passed"}`) |
| `composer phpstan` | pass (685 files / level 10 / `[OK] No errors`。baseline 追加・widen なし) |
| `composer test -- --filter=RouteBindingTypeConstraintInventory` | pass (19 tests / 27 assertions) |
| `composer test` | pass (2082 tests / 2080 passed / 2 skipped / 8349 assertions)。修正前 2081 → **負のコントロール 1 本増**、skip は既存 2 件から増えていない |
| `composer test:browser` | pass (chromium 8 tests: 5 passed / 3 skipped、webkit 8 tests: 5 passed / 3 skipped)。**3 skip は未解消 = 施策 8 未達** |

## 設計書への逸脱報告 (設計者判断が必要)

1. **施策 8 の必須完了条件が未達**。上記 Critical。設計側の判断が要る。
2. **`MANUALLY_RESOLVED` は設計書に無い新設**。本レビューで route identity 単位へ縮小したが、
   inventory の分類体系に関わるため設計書へ反映が必要。
3. **Livewire route identity の prefix 正規化**は設計書の identity 規約への追加。
4. **施策 5 の「現行値」列 (`no-store`)** は Symfony の自動 `, private` 付与を考慮していない。
   実測値は `no-store, private`。設計書の表を訂正すべき。
5. **`NON_MODEL` の実走査による縮小** (7 → 3)。設計書の暫定列挙を実態へ更新すべき。
