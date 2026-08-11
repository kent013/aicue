# mutation evidence (T153 bug-hunt の SSO 外部遷移を塞ぐ)

詳細設計 §「mutation で赤化を確認する手順」の M1〜M13 を実施した記録。
**入れた mutation はすべて戻し済み**（末尾の復元確認を参照）。

- 実行環境: worktree `/workspace/.claude/worktrees/tasks/T153` (branch `todo/T153`)
- 実行コマンド: `composer test -- --filter=<対象>`
- 表記: `tests / passed / failed / errors`

---

## 実装前の赤（テストファースト）

`devnotes/20260811-2037-todo-T153/red-first.md` が正本。
`--filter=FakeSocialiteWiring` で **9 / 2 / 5 / 2**。
**負のコントロール #1 は実装前から緑**（`accounts.google.com` を実測）= 施策後の #2 の緑が
「もともと外に出ていなかった」ではないことの証拠。

---

## M1: `registerSocialAuthFake()` の `bind(...)` 行を削除

| 対象 | 予測 | 実測 |
|---|---|---|
| `FakeSocialiteWiring` | #2〜#6 が赤 | **9 / 4 / 5 / 0** — #2 #3 #4 #5 #6 が赤（予測どおり） |
| `ExternalFakeWiringInvariant` | `3-2 実証` の 2 ケース | **48 / 45 / 3 / 0** — `3-2 @ testing` / `3-2 @ bughunt.local` に加え **`3-8 網羅性` も赤** |

> **予測とのズレ（記録）**: 設計は M1 で `3-8` が赤くなることを書いていなかった。
> `3-8` は provider ソースの bind 組と inventory の**集合一致**なので、bind 行を消すと
> inventory 側に entry が残って差分が出る。**検出が予測より 1 本多い**方向のズレ。

## M2: `SSO_FAKE_ENVIRONMENTS` に `'local'` を追加

| 対象 | 予測 | 実測 |
|---|---|---|
| `FakeSocialiteWiring` | #8 のみ赤 | **9 / 8 / 1 / 0** — #8 `fake は local 環境では配線されない` のみ赤（予測どおり）。<br>`-'App\Services\Auth\SocialiteDriverResolver'` / `+'App\Services\Auth\Fakes\FakeSocialiteDriverResolver'` |

## M3: `SSO_FAKE_ENVIRONMENTS` を `['production']` に変更

| 対象 | 予測 | 実測 |
|---|---|---|
| `FakeSocialiteWiring` | #2〜#6 が赤 | **9 / 4 / 5 / 0**（予測どおり） |
| `ExternalFakeWiringInvariant` | `3-2 実証` + `3-3`（production） | **48 / 45 / 3 / 0** — `3-2 @ testing` / `3-2 @ bughunt.local` / `3-3 @ production`（予測どおり） |

## M4: `FakeSocialiteProvider::redirect()` を実 IdP URL へ変更

| 対象 | 予測 | 実測 |
|---|---|---|
| `FakeSocialiteWiring` | #2（host 一致）、#3〜#6（round-trip） | **9 / 4 / 5 / 0**（予測どおり）。#2 は `provider=google が自アプリ host に閉じていません`、#3〜#6 は Location 不一致 |

## M5: `FakeSocialiteProvider::user()` の `id` を `'g-1'` に変更

| 対象 | 予測 | 実測 |
|---|---|---|
| `FakeSocialiteWiring` | **#7 のみ**赤 | **9 / 4 / 3 / 2** — #7（契約 pin）に加え **#3 #5 が赤・#4 #6 がエラー** |

> **予測とのズレ（記録）**: 設計は「#7 のみ」と書いていたが、実測では round-trip 系も落ちた。
> 理由は identity の**決定論性そのもの**が #3〜#6 の前提だから（#4/#6 は
> `provider_user_id='fake-google-user'` で作った Factory 連携に戻れず、
> `assertAuthenticatedAs` / session 参照が `Call to a member function all() on array` で落ちる）。
> **検出が予測より広い**方向のズレで、契約の固定としては強い。

## M6: `SocialAuthController` の resolver 呼び出しを `Socialite::driver()` へ戻す

| 対象 | 予測 | 実測 |
|---|---|---|
| `FakeSocialiteWiring` | #2〜#6 が赤 | **9 / 4 / 5 / 0**（予測どおり） |
| `ExternalSeamInventory` | `外部到達: SocialLogin は funnel 1 クラスに固定される` | **15 / 13 / 2 / 0** — funnel 固定に加え **`走査 site と目録は (クラス, 種別) で双方向に一致する` も赤**（`SocialAuthController.php:67` / `:90` が未登録として列挙される） |

> **予測とのズレ（記録）**: 双方向照合が先に赤くなる。設計の予測より 1 本多い。

## M7: `ExternalFakeWiringInventory` の SSO entry を削除

| 対象 | 予測 | 実測 |
|---|---|---|
| `ExternalFakeWiringInvariant` | `3-8 網羅性` / `3-10` | **43 / 41 / 2 / 0** — `3-8` は赤（bind 組の集合差分に `SocialiteDriverResolver => FakeSocialiteDriverResolver` が現れる）。もう 1 本は `3-10 参照クラスの集合一致` |

## M8: `SocialiteDriverResolver::driver()` に `->stateless()` を追加

| 対象 | 予測 | 実測 |
|---|---|---|
| `ThrottleExemptionPremise` | 施策 6 のテストが赤 | **24 / 21 / 1 / 0** — `SSO の driver 解決経路は stateless() を使わない` が赤（予測どおり） |

## M9: 施策 6 の `$paths` から resolver を外す

| 対象 | 予測 | 実測 |
|---|---|---|
| M9 単独 | 直接は赤くならない（設計上の限界） | **24 / 24 / 0 / 0 = 緑**（予測どおり。限界を実測で確認） |
| M9 + M8（`$paths` から外したうえで resolver に `stateless()` を追加） | 設計は「M8 との組み合わせでのみ検出」と記載 | **24 / 22 / 0 / 2** — ソース走査テストは**緑のまま**だが、同ファイルの**実挙動テスト 2 本**が赤（`別セッションで発行した state では callback が外向き HTTP へ進まない` / `negative control: 自セッションの state なら…` がともに `Expected the key "state" to exist.`） |

> **予測とのズレ（記録・重要）**: 設計は「M9 は検出できない」と限界を書いたが、実測では
> **走査が盲目になっても state 照合の実挙動テストが `stateless()` 化を捕まえる**。
> つまり「走査対象の更新漏れ」の実害は設計が想定したより小さい。
> ただし **`$paths` 更新漏れ自体は無音のまま**であり、設計の
> 「保証しないもの 6（走査対象の自動追随はしない）」は**そのまま有効**。

## M10: `.env.bughunt.local` の `TESTING_FAKE_EXTERNALS=false` で `provision`

**CI では回らない**（bughunt DB / git 管理外 dotenv / 実 serve が要る）ため、施策 8 で追加した
期待値ブロックを**同一ロジックのまま切り出して単体評価**した。

```
入力 effective: {..., "fake_externals": false, ...}
出力: error: 隔離前提の実効 env が不一致 (実効値, 期待値): {'fake_externals': (False, True)}
rc=1
```

設計が予告した `('fake_externals', (False, True))` の形と**一致**。
`scripts/bug-hunt-shard.sh self-test` は施策 8 適用後に **all passed**（`[z5]` 実効 env 期待値導出も ok）。

## M11: 空振り防止（母集団 0 件）の存在確認

| 段階 | 実測 |
|---|---|
| `config/template.php` の `social_providers` を `[]` にする（assert は残す） | **9 / 2 / 7 / 0**。#2 は `Expecting [] not to be empty .` で赤 = **空母集団で緑にならない** |
| さらに `expect($providers)->not->toBeEmpty();` を削除 | `--filter=宣言済み全` が **1 / 1 = 緑**（= assert が無ければ空振りで緑になる） |

→ 空振り防止 assert が実際に効いていることを実測で確認。

## M12: docblock に `stateless()` という**語だけ**を書く（呼び出しは足さない）

| 対象 | 予測 | 実測 |
|---|---|---|
| `ThrottleExemptionPremise` | 赤く**ならない**こと | **24 / 24 / 0 / 0 = 緑**（予測どおり。regex 化で語の言及による偽陽性が消えた） |

## M13: `->  stateless ()`（空白入り）を追加

| 対象 | 予測 | 実測 |
|---|---|---|
| `ThrottleExemptionPremise` | 赤くなること | **24 / 21 / 1 / 0** — 施策 6 のテストが赤（予測どおり。`toContain('stateless(')` ならすり抜けていた形） |

## M14 (設計外・実装レビュー Round 1 の [Warning] 対応で追加)

Codex 実装レビュー Round 1 が「#9 は『Socialite に触れず』を実証していない
(driver を呼んでから login へ戻る実装に壊れても緑になる)」と指摘したため、
#9 に **呼ばれたら例外を投げる resolver を後勝ちで bind** する形へ強化した。
その強化が効いていることを次の mutation で確認した。

| mutation | 予測 | 実測 |
|---|---|---|
| `SocialAuthController::callback()` が **intent 判定より前**に `$this->socialiteDriver->driver($provider)` を呼ぶよう改変 | #9 が赤 | **9 / 8 / 1 / 0** — #9 のみ赤。`RuntimeException: intent 不在の callback が Socialite driver を解決しました: google` が stack trace 付きで出る |

> 強化前の #9 (login redirect + guest のみ) では**この mutation を検出できなかった** —
> 指摘は妥当であり、対応済み。

---

## 復元確認

全 mutation 復元後に再実行:

```
composer test -- --filter="FakeSocialiteWiring|ExternalFakeWiringInvariant|ExternalSeamInventory|ThrottleExemptionPremise"
→ tests=96 passed=96 failed=0
```

`git status --short` で意図した変更ファイル以外の差分が無いことを確認済み
（`config/template.php` は M11 で一時変更したが復元され、status に現れない）。
