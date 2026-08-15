# T176 目録の生成方式への移行記録 (一度きりの実測)

`devnotes/20260815-2100-bughunt-inventory-generator/bootstrap-annotations.py` を
main 起点の worktree (`todo/T176`) で 1 度だけ実行した結果。

## 1. 母集合 (`php artisan bughunt:inventory-scan` の実測、APP_ENV=local)

| 集合 | 件数 |
|---|---|
| 全 route オブジェクト | 211 |
| `web` group を宣言している route | 158 |
| 面から除いた route (先頭セグメント `oauth` / `livewire-{hash}`) | 11 |
| **web 面** | **147** |
| ├ 画面表 (GET / HEAD のみ) | 68 |
| └ 操作表 (非 GET を含む) | 79 |
| 名前を持たない web 面 route | 0 |
| 名前が重複する route | 0 |
| 面の外にある名前を持たない route (許容) | 11 |

画面表 ⊎ 操作表 = web 面 (68 + 79 = 147) が成り立つ。概念設計の実測と一致する。

## 2. 旧表との差分 (移行スクリプトの標準出力)

```
書き出し: .claude/skills/app-bug-hunt/inventory/annotations.toml (147 route)
旧表にしか無い route (消えた行): []
新しく見える route (14 件): ['debug.bfcache-trial', 'debug.bfcache-trial.away', 'debug.login',
 'password.confirmation', 'seo.ai', 'seo.llms', 'seo.robots', 'seo.sitemap', 'social.callback',
 'social.redirect', 'two-factor.qr-code', 'two-factor.recovery-codes', 'two-factor.secret-key',
 'webhooks.ses']
```

- **旧表の行は 1 件も落ちていない** (旧表にしか無い route = 0 件)。
- 新しく見えるのは 14 件で、内訳は詳細設計の想定どおり
  (seo 4 / social 2 / 第二要素の秘密開示 3 / `password.confirmation` / debug 3 / `webhooks.ses` 1)。
  debug 3 のうち 2 件 (`debug.bfcache-trial` / `debug.bfcache-trial.away`) は
  設計後に main へ入った T161 の検証ページである。
- 14 件はすべて区分 `外` として、30 文字以上の理由を人が書いた。理由は生成物の
  「対象外の理由」節に出る (正規表現の中に沈んでいた状態から、目録に見える状態へ移った)。

## 3. 移行後の実測

```
$ python3 scripts/bug-hunt-inventory.py generate
生成完了: 画面 68 件 / 操作 79 件 (抽出条件 local-or-unit-tests)

$ bash scripts/bug-hunt-inventory-check.sh; echo $?
一致: 画面 68 件 / 操作 79 件 (抽出条件 local-or-unit-tests)
0
```

下流の照合器との結合も実測した (`coverage/correlate.py` の `load_operations()` で
生成後の `operations.md` を読む):

```
79 件 (区分 外 = 1 件 = webhooks.ses)
```

操作表の件数と完全に一致し、余分な行 (散文からの誤読) は無い。

## 4. 散文の移設

- `screens.md` の 4 節 (非 Inertia の GET / パスキー options endpoint の扱い /
  課金ゲート着地の画面遷移 / ナビゲーション・レイアウト規約) を
  `inventory/notes-screens.md` へ**文言を変えずに**移した。
- `operations.md` の 2 節 (課金ゲート allowlist と認可 / パスキー・ログイン手段の認可契約) を
  `inventory/notes-operations.md` へ同様に移した。
- 移設先の冒頭にだけ、生成物へ連結される旨と「表を書かない」規則の注記を足した。
