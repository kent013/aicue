# 対応マトリクス: design-review Round 3

Critical 6 件 / Warning 6 件。**すべてに対応した**。

---

## 再承認条件 5 点への対応（総括）

| # | 条件 | 対応 |
|---|---|---|
| 1 | auth / account-deletion 側の裁定を施策 7・11 へ反映する | **対応**。外部裁定を待たずに確定させた — **当該 feature 自身が docblock に書いた fallback 規則**（「current org を持たない利用者には作らない。org 文脈を捏造しない」）を、AG-037 で入力が消えた後にそのまま適用する形にした。新しい方針を作っていないので、他 feature の仕様を勝手に変えたことにならない。lctl の auth 側セルへは**申し送る** |
| 2 | slug 書き込み gate に migration・境界テストの exact-fit 例外を設ける | **対応**。件数・パターン・理由の完全一致の例外目録を新設（ファイル全体は除外しない） |
| 3 | 改名の基準時刻を行ロック後に取得する | **対応**。`$now` を `lockForUpdate()` の**後**へ移した |
| 4 | 組織作成時の requested slug 競合を 422 へ変換する | **対応**。`OrganizationController::store` の変換点の表を新設 |
| 5 | 施策 10 の自己検出と実装順序を修正する | **対応**。自己検査目録を新設し、施策 10 を**単位 B の後**へ移した |

---

## 施策 1（APPROVE）
指摘なし。

---

## 施策 2

### [Critical] `OrganizationSlugWritePathTest` が migration と自分の境界テストを必ず違反にする
- 判断: **対応する**
- 根拠: 完全にそのとおり。正規化 migration の生 SQL UPDATE と、
  CHECK を撃つために**意図的に**値オブジェクトを迂回する境界テストは、
  どちらも「`AssignableOrganizationSlug` を受ける 1 本」に当てはまらない。
- 対応内容: `OrganizationSlugWriteExemptions` を新設し、
  **パス + 許可する書き込み形 + 件数 + 30 文字以上の理由**の exact-fit 登録にした。
  **ファイル全体を走査から外さない**（登録した形以外はその 2 ファイルの中でも検出する）。
  登録できるのは `database/migrations` と `tests` のみで `app/` には置けない。
  登録する 2 件を表で明示した。

### [Warning] config を正本としつつガイドにも同じ契約文を置くのは二重管理
- 判断: **対応する**
- 対応内容: **正本は config の docblock 1 か所**とし、
  `docs/app-integration-guide.md` には**契約文を複写せず参照だけ**を書く形にした。

---

## 施策 3

### [Critical] `$now` を行ロック取得**前**に確定している
- 判断: **対応する**
- 根拠: 指摘が正しい。ロック待ちが起きると、待つ前の時刻を基準に判定するため
  **既に窓外へ出た履歴を数えて誤って拒否**し得る。
- 対応内容: `$now = CarbonImmutable::now()` を **`lockForUpdate()` の後**へ移し、
  「以降 cutoff と `renamed_at` はこの 1 つの値を使う」と docblock に書いた。

### [Warning] FormRequest の同値検査後からロック取得までに値が変わり得る（Service 側例外の変換が要る）
- 判断: **対応する**
- 対応内容: 変換点の表に
  **「同一識別名への改名（ロック後に Service が検出）→ Controller で
  `InvalidOrganizationSlugException::unchanged()` を捕まえ 422 へ」**の行を追加した。

---

## 施策 4

### [Critical] Requested slug の一意衝突を組織作成 Controller で 422 へ変換する点が未設計
- 判断: **対応する**
- 根拠: 施策 3 の変換表は改名 Controller 専用だった。
- 対応内容: 「組織作成時の競合の変換点」表を新設した
  （構文違反・予約語 → FormRequest で 422 / Requested の一意衝突 → `store` で 422 /
  Derived の衝突 → Service 内で Fallback へ遷移 / Fallback の 3 回失敗 → 500 /
  それ以外の `QueryException` → 再送出）。
  Feature テスト `OrganizationCreateConflictTest` を追加した。

### [Warning] 「1 試行 = 1 savepoint」は外側 transaction が無い場合に不正確
- 判断: **対応する**
- 対応内容: 契約を **「1 試行 = 1 transaction 境界」**へ言い直し、
  「外側 transaction 内では savepoint、外側が無ければトップレベル transaction の rollback」
  と明記した。登録フローは外側 transaction の中から呼ぶことも書いた。

### [Warning] Laratrust の role cache は DB の rollback で戻らない
- 判断: **対応する**
- 対応内容: `createWith()` の**順序契約**を明記した —
  **`slug` を持つ `Organization` の `save()` が成功した後**に `attach()` / `addRole()` を行う。
  一意違反は `save()` で起きるので、この順序なら**失敗試行で role 付与が走らない**。
  テストに「**権限 cache にも残留が無い**」を追加した。

---

## 施策 5 / 6 / 9（APPROVE）
指摘なし。

---

## 施策 7

### [Critical] auth 側の裁定が未確定なので施策 7 の変更・契約・テスト・乖離扱いが確定していない
- 判断: **対応する**（外部裁定を待たずに確定させた。方法が変わった）
- 根拠: 「設計側では選ばない」を貫くと設計が永久に確定しない。
  しかし**新しい方針を選ばずに確定させる道**があった —
  現行 `NotificationCenterService::notifyAccountDeletionRequested()` の docblock は、
  この feature 自身の規則として
  **「current org を持たないユーザーには作らない（メールだけが届く。org 文脈を捏造しない）」**
  と明記している（実読）。AG-037 の下では**どの利用者も current org を持たない**ので、
  この規則をそのまま適用した結果が「アプリ内通知を作らない」である。
- 対応内容:
  - **当該 feature 自身が書いた fallback 規則の適用**として確定させ、その根拠（docblock の引用）を本文に載せた。
  - 他案（1 つ選ぶ / fan-out / `AppNotification` を nullable 化）を**採らない理由**を明記した
    （順に: AG-037 の裏口かつ「捏造しない」に反する / **新しい方針であり設計側が決めてよいものではない** /
    通知 feature の中核契約の変更でスコープ外）。
  - **メールは従来どおり届く**ことを明記した。
  - **申し送り（必須）**: 実装の登録時に lctl の auth / account-deletion 側のセルへ
    言及として申し送る。別の形を採ると裁定されたら**別 TODO で追従**する。
  - 乖離台帳への登録が**不要**であることを実測で確定した
    （`NotificationCenterService.php` / `NotificationController.php` は
    `docs/template-fingerprints.json` の `entries` に**存在しない** = 共有ファイルでない）。
  - スコープ外表・実装モード表（申し送り）・リスク表（R9）・施策 11 も同じ結論へ揃えた。
  - Feature テスト `AccountDeletionNotificationContextTest`
    （アプリ内通知が作られない / メールは届く / 未読件数が増えない）を追加した。

---

## 施策 8

### [Warning] `Assert::keyExists(..., (string) $routeName)` ではキャスト式しか検査されない
- 判断: **対応する**
- 対応内容: **`Assert::string($routeName)` を先に置き**、続いて
  `Assert::keyExists(self::TARGET_BY_ROUTE, $routeName)` とする形へ変えた。

### [Warning] service worker の登録 scope は script の配置だけでは決まらない
- 判断: **対応する**
- 根拠: そのとおり。`register()` の第 2 引数 `scope` option が優先される。
- 対応内容: 実測を追加した — `resources/js/pages/Capture/Show.svelte:439` の
  `navigator.serviceWorker.register("/capture-sw.js")` は**第 2 引数を渡していない**。
  そのうえで固定する対象を 3 つに増やした:
  (1) manifest に `scope` キーが無い / (2) **`register()` に `scope` option を渡していない**
  （渡すようになったら赤）/ (3) **Browser テストで実効的な `registration.scope` が `/`** であること。

---

## 施策 10

### [Critical] gate 自身と負例 fixture に検出語が必ず現れ、自分自身を検出する
- 判断: **対応する**
- 対応内容: `LegacyUrlSelfDetectionExemptions` を新設し、
  **パス + 対象パターン + 件数 + 30 文字以上の理由**の exact-fit 登録にした。
  **ファイル全体を走査から外さない**。登録できるのは gate 本体と fixture のみ。

### [Critical] 施策 10 は単位 B 完了後の「旧 URL ゼロ」を検査する gate なので先行できない
- 判断: **対応する**
- 根拠: そのとおり。単位 B 前は旧 URL が実在するので green にならない。
- 対応内容: 「実装順序」節を新設し、
  **抽出器だけ先に書いてもよいが main へ merge する単位は単位 B の後**と明記した。
  実装モードの進行順序も
  **単位 A → 施策 9 → 単位 B → 施策 3 → 施策 10 → 施策 11** へ直した。

### [Warning] Markdown のプレーン URL 終端集合にタブ・`}`・コロン等が無い
- 判断: **対応する**
- 対応内容: 終端を **空白文字全般**（半角空白・タブ・改行・全角空白）+
  列挙した閉じ記号・句読点（`)` `]` `}` `>` `"` `'` `` ` `` `,` `;` `:` `。` `、` `）` `｜` `|`）へ広げ、
  **「この列挙が保証範囲であり、ここに無い終端は保証しない」**ことを docblock に明記した
  （網羅を主張しない）。

---

## 施策 11

### [Critical] D41 を作らない理由が、取り下げた旧方針（fan-out）のままで本文と矛盾
- 判断: **対応する**
- 対応内容: 理由を**実測に基づくもの**へ差し替えた —
  `NotificationCenterService.php` / `NotificationController.php` は
  `docs/template-fingerprints.json` の `entries` に**存在しない**（＝テンプレートと共有しない）ので
  **逸脱の登録対象にならない**。
  「登録するか迷ったら登録する」の原則は**テンプレートに無い領域への上積み**にかかるもので、
  本件はアプリ固有ファイル内の既存規則の適用であって上積みではない、とも書いた。

### [Warning] `DIVERGENCE_ENTRY_COUNT = 37` は暫定値
- 判断: **対応する**
- 対応内容: 施策 7 が共有ファイルに触れないことが確定したので **37 で確定**とし、
  その理由を併記した。なお「数値は実装時に実ファイルの実数から再確認する」という
  既存の注記はそのまま残している。
