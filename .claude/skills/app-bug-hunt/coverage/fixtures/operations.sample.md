# 操作インベントリ サンプル (correlate.py fixture) — App 実列で pin

凡例: ◎=毎回 / ○=実行 / 逸=逸脱のみ / 終=終端 / 外=対象外。

実 operations.md と同じ **markdown leading-pipe 5 列** (`method | route(URL) | name | story | 区分`)。
join キーは **name 列 (= index 2)** であり route(URL) 列 (index 1) ではない (fix-gate #3)。

## 認証 / アカウント

| method | route | name | story | 区分 |
|---|---|---|---|---|
| POST | register | register.store | S1 | ◎ |
| POST | login | login.store | S1 | ◎ |
| POST | logout | logout | S1 | ◎ |

## 組織 / メンバー

| method | route | name | story | 区分 |
|---|---|---|---|---|
| POST | organizations | organizations.store | S1 | ◎ |
| POST | organizations/{organization}/transfer | organizations.transfer | S4 | 逸 |
| PUT | organizations/{organization}/members/{user}/api-keys-permission | organizations.members.api-keys-permission.update | S4 | ○ |

## 課金 (対象外含む)

| method | route | name | story | 区分 |
|---|---|---|---|---|
| POST | billing/change-plan | billing.changePlan | S5 | 外 (UI 未参照) |

## API / CLI 面 (S8、6 列)

| method | route | api route name | CLI コマンド | story | 区分 |
|---|---|---|---|---|---|
| POST | /projects | `api.v1.projects.store` | `project:create` | S8 | ◎ |
| DELETE | /me/session | `api.v1.me.session.revoke` | `logout` | S8 | ○ |
