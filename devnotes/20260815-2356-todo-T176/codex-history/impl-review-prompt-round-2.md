# Round 2: Round 1 指摘への対応

Round 1 の [Warning] 4 件 / [Suggestion] 1 件はすべて対応した (見送り・反論は無い)。
対応マトリクスと、変更後の該当箇所を渡す。再レビューして全体判定を返してほしい。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Warning] `check_catalog()` が `/` を含む token をすべて無視している (段 4 が空振りする)

- 判断: **対応する**
- 根拠: 指摘のとおり。`routes/api.php` を候補から外したかっただけなのに、`projects/store` の
  ような打ち間違いまで無視していた。段 4 の目的 (代表機構が実在すること) に穴が開く。
- 対応内容: `PATH_TOKEN_RE`(区切りを含み、かつ拡張子で終わるもの) に一致する token だけを
  パスと見なすよう変更した。負の対照 `test_パスに見えないスラッシュ入りの記載はドリフト` を追加。
  詳細設計 §施策 3「段 4 の契約」にも是正の経緯を書いた。

## [Warning] 未知のトップレベル項目が exit 2 になっている (設計は段 2 の drift = exit 3)

- 判断: **対応する**
- 根拠: 指摘のとおり終了コード規約と食い違っていた。fail-closed ではあるが、
  「注釈の書き間違い」は drift の側に属するので 3 が正しい。
- 対応内容: `load_annotations()` の戻りを `Annotations` (routes + unknown_top_level) にして
  持ち回り、`validate_annotations()` が drift 行として列挙するようにした。
  自己テストも exit 3 期待へ直し、詳細設計にも是正を書いた。

## [Suggestion] `notes-screens.md` にも「表を書かない」と書いてあるが検査は operations だけ

- 判断: **対応する** (弱める側ではなく、検査を両方へ広げる)
- 根拠: 連結先ごとに規則が変わる方が事故のもとになる。実装コストは 2 行で、
  「表そのものを置かない」という 1 つの規則で説明が閉じる。
- 対応内容: `check_notes()` を 2 ファイル分の写像で受け取る形にし、自己テストも
  2 ファイルの subTest で回すようにした。`notes-screens.md` の冒頭注記に
  「あちらは correlate.py が読むから / こちらは規則を揃えるため」と理由の違いを明記した。

## [Warning] 「webhook には沈黙する」は誇張 (`webhooks.ses` は目録に載っている)

- 判断: **対応する**
- 根拠: 指摘のとおり。沈黙するのは「`web` group を宣言していない面」であって
  「webhook 一般」ではない。実際 `webhooks.ses` は操作表に区分 `外` として載っている。
- 対応内容: `AGENTS.md` §bug-hunt / `docs/template-divergence.md` D20 /
  `.claude/skills/app-bug-hunt/SKILL.md` Phase 1 / 生成器の docstring の 4 か所を
  「`web` を宣言していない面には沈黙する。`web` を宣言していれば webhook でも目録に入る
  (実例: `webhooks.ses`)」へ直した。


## 変更後のコード (scripts/bug-hunt-inventory.py の該当箇所)

```python
CAPABILITY_TABLE_HEADER = "| id | 機能 (actor→outcome) | 代表機構 (route name) |"
CAPABILITY_ID_RE = re.compile(r"^[A-Z]{2,5}-[0-9]{2}$")
BACKTICK_TOKEN_RE = re.compile(r"`([^`]+)`")
# ファイルパス (routes/api.php) は route 名候補にしない。**拡張子を持つものだけ**を
# パスと見なす (`/` を含むだけで捨てると `projects/store` のような打ち間違いが素通りする)。
PATH_TOKEN_RE = re.compile(r"^[A-Za-z0-9_.\-]+(?:/[A-Za-z0-9_.\-]+)+\.[A-Za-z0-9]+$")
```

```python
@dataclass(frozen=True)
class Annotations:
    """注釈ファイルの中身。未知のトップレベル項目も落とさずに持ち回る。"""

    routes: dict[str, dict[str, object]]
    unknown_top_level: tuple[str, ...]



def load_annotations(path: Path) -> Annotations:
    """注釈 TOML を読む (読み取り専用。生成器は注釈ファイルを書き換えない)。"""
    if not path.is_file():
        raise FatalError(f"[{STAGE2}] 注釈ファイルが無い: {path}")
    try:
        data = tomllib.loads(path.read_text(encoding="utf-8"))
    except tomllib.TOMLDecodeError as exc:
        raise FatalError(f"[{STAGE2}] 注釈ファイルが TOML として読めない: {exc}") from exc

    if data.get("schema_version") != SCHEMA_VERSION:
        raise FatalError(
            f"[{STAGE2}] 注釈の schema_version が {SCHEMA_VERSION} ではない: "
            f"{data.get('schema_version')!r}"
        )
    routes = data.get("routes", {})
    if not isinstance(routes, dict):
        raise FatalError(f"[{STAGE2}] 注釈の routes が表ではない")
    for name, entry in routes.items():
        if not isinstance(entry, dict):
            raise FatalError(f"[{STAGE2}] 注釈 {name} が表ではない")

    return Annotations(
        routes={str(name): dict(entry) for name, entry in routes.items()},
        # 書いたのに効かない項目を残さない (打ち間違いを黙って捨てない)。段 2 の drift。
        unknown_top_level=tuple(sorted(k for k in data if k not in ("schema_version", "routes"))),
    )
```

```python
def validate_annotations(facts: Facts, annotations: Annotations) -> list[str]:
    """注釈の定義域一致・語彙・形式・複合 method を検査し、違反行を全件返す。"""
    violations: list[str] = []

    for key in annotations.unknown_top_level:
        violations.append(f"[{STAGE2}] 未知のトップレベル項目: {key}")

    entries = annotations.routes
    screen_names = {f.name for f in facts.screens}
    surface_names = {f.name for f in facts.surface}

    for name in sorted(surface_names - set(entries)):
        violations.append(f"[{STAGE2}] 未注釈の route: {name}")
    for name in sorted(set(entries) - surface_names):
        violations.append(f"[{STAGE2}] 実装に無い route の注釈が残っている: {name}")
```

```python
def check_notes(notes: dict[str, str]) -> list[str]:
    """散文ノートが下流ローダを騙す表を持たないことを検査する。

    `coverage/correlate.py` は operations.md を頭から走査し、直近に見たヘッダの列割当で
    以降の `|` 始まりの行を操作行として読む。**ヘッダとして読めない表が来ても列割当は
    更新されない**ので、連結される散文ノートに表があると、生成表の列割当のまま
    注釈に無い行が操作として読まれてしまう。よって**表そのものを置かせない**。

    画面側のノートにも同じ規則を課す (連結先で規則が変わる方が事故のもとになる)。
    """
    violations = []
    for name, text in notes.items():
        for lineno, raw in enumerate(text.splitlines(), start=1):
            if raw.strip().startswith("|"):
                violations.append(
                    f"[{STAGE2}] {name} {lineno} 行目: 散文ノートに表を置かない "
                    "(correlate.py が操作行として読んでしまう)"
                )

    return violations
```

```python
def check_catalog(catalog_text: str, facts: Facts) -> list[str]:
    """capability-catalog.md の代表機構が実在し、id が重複しないことを検査する。

    対象はヘッダが CAPABILITY_TABLE_HEADER の表**だけ** (責務境界・割当規則の表は見ない)。
    網羅性 (すべての route が id を持つか) は見ない (overlay なので網羅を主張しない)。
    """
    violations: list[str] = []
    seen: list[str] = []
    inside = False

    for raw in catalog_text.splitlines():
        line = raw.strip()
        if line == CAPABILITY_TABLE_HEADER:
            inside = True
            continue
        if not inside:
            continue
        if not line.startswith("|"):
            inside = False
            continue
        cols = [c.strip() for c in line.strip("|").split("|")]
        if len(cols) < 3 or set("".join(cols)) <= set("- "):
            continue

        capability_id, mechanisms = cols[0], cols[2]
        if not CAPABILITY_ID_RE.match(capability_id):
            violations.append(f"[{STAGE4}] id の書式が契約外: {capability_id}")
        elif capability_id in seen:
            violations.append(f"[{STAGE4}] id が重複している: {capability_id}")
        seen.append(capability_id)

        for token in BACKTICK_TOKEN_RE.findall(mechanisms):
            token = token.strip()
            # ファイルパスだけを候補から外す。丸括弧の説明はそもそもバッククォートで
            # 囲まれていないので候補に入らない。
            if PATH_TOKEN_RE.match(token):
                continue
            if token.endswith("*"):
                if not any(n.startswith(token[:-1]) for n in facts.all_names):
                    violations.append(
                        f"[{STAGE4}] {capability_id}: 前方一致する route が 1 件も無い: {token}"
                    )
            elif token not in facts.all_names:
                violations.append(f"[{STAGE4}] {capability_id}: 実在しない route 名: {token}")
```

## 追加・変更した自己テスト (scripts/tests/test_bug_hunt_inventory.py)

```python
    def test_未知のトップレベル項目はドリフト(self):
        self.write(inv.ANNOTATIONS_PATH, 'version = 1\n' + BASE_ANNOTATIONS)
        self.assert_drift("未知のトップレベル項目: version")
    def test_散文ノートの表はドリフト(self):
        # 互換ヘッダでなくても列割当は据え置かれるので、表そのものを置かせない。
        for path, table in (
            (inv.NOTES_OPERATIONS_PATH, "| name | story | 区分 |\n|---|---|---|\n| fake.route | S1 | 通常 |\n"),
            (inv.NOTES_SCREENS_PATH, "| 何か | 別の | 表 | です | ね |\n|---|---|---|---|---|\n| a | b | c | d | e |\n"),
        ):
            with self.subTest(path=str(path)):
                self.setUp()
                self.generate_then()
                self.write(path, "## 散文\n\n" + table)
                code, output = self.run_check()
                self.assertEqual(inv.EXIT_DRIFT, code, output)
                self.assertIn("散文ノートに表を置かない", output)


# --------------------------------------------------------------------------- #
# 段 3: 生成物
# --------------------------------------------------------------------------- #
class GeneratedFilesTest(SandboxCase):    def test_パスに見えないスラッシュ入りの記載はドリフト(self):
        # `/` を含むだけで候補から外すと、URL の打ち間違いが素通りしてしまう。
        self.write(inv.CATALOG_PATH, BASE_CATALOG.replace("`projects.store`", "`projects/store`"))
        code, output = self.run_check()
        self.assertEqual(inv.EXIT_DRIFT, code, output)
        self.assertIn("実在しない route 名", output)

```

## 文書の差分 (AGENTS.md / docs/template-divergence.md / SKILL.md / notes-screens.md / 詳細設計)

```diff
diff --git a/.claude/skills/app-bug-hunt/SKILL.md b/.claude/skills/app-bug-hunt/SKILL.md
index 6fdf8d1..1d60d17 100644
--- a/.claude/skills/app-bug-hunt/SKILL.md
+++ b/.claude/skills/app-bug-hunt/SKILL.md
@@ -15,8 +15,9 @@ # 探索的バグハント (bug-hunt)
 両方を消化するように設計されている。**発見と報告まで**が守備範囲。修正は app-design / app-implement の管轄。
 
 > **テンプレート注記**: 本スキルは spirux/aigenba の bug-hunt 基盤を汎用化したもの。アプリ名・ポート・DB 名は
-> プレースホルダ化してある。`screens.md` / `operations.md` / `stories/` は**スケルトン**で、初回に
-> `php artisan route:list` から生成する (下記 Phase 1)。オプトインで、使わなければアプリ実行には一切影響しない
+> プレースホルダ化してある。`screens.md` / `operations.md` は**生成物**で、注釈 (`inventory/annotations.toml`)
+> と散文 (`inventory/notes-*.md`) から作る (下記 Phase 1)。`stories/` はスケルトンのままである。
+> オプトインで、使わなければアプリ実行には一切影響しない
 > (config/bughunt.php + BughuntCoverageMiddleware は env + function_exists の二重 guard で完全 no-op)。
 
 ## 使命
@@ -122,7 +123,8 @@ ### 手順 (親 = このセッション。worktree 内から実行)
      **shard agent は consult しない** (子は素の `proposed` finding のみ)。
 6. **teardown**: `BUGHUNT_ORCHESTRATOR=1 scripts/bug-hunt-shard.sh teardown --run-id {ts} [--drop-db]`。
    その後、手順2 の `--hold-lock` 常駐プロセスを終了して lock 解放。
-7. **インベントリ修正の反映**: 統合 report に記録した採用分のみを screens.md / operations.md / stories に反映する。
+7. **目録修正の反映**: 統合 report に記録した採用分のみを `inventory/annotations.toml` (割当・区分・理由) /
+   `inventory/notes-*.md` (散文) / stories に反映し、`python3 scripts/bug-hunt-inventory.py generate` を走らせる。
 8. **adjudication 追記の規律 (人手判断時のみ)**: finding を誤検知 / 意図的仕様 / won't-fix と確定したら、
    cross-session の再 triage を避けるため `ledger/adjudications.jsonl` に 1 行 append (既存行は編集しない)。
    詳細スキーマは `ledger/README.md`。
@@ -198,36 +200,37 @@ ### 環境の前提知識
 | テストアカウント | ManualTestSeeder が投入 (`{role}-{plan}@example.com` / `multi-org@example.com` / `unverified@example.com`、全員 `password123`)。管理画面 admin は `admin@example.com` / `password12345` (AdminUserSeeder) |
 | 管理画面 MFA | `.env.bughunt.local` の `ADMIN_MFA_REQUIRED=false` で無効化 (email+password でログイン可) |
 
-## Phase 1: インベントリ鮮度確認 (初回はスケルトンから生成)
+## Phase 1: 目録の鮮度確認 (生成物なので手で書かない)
 
-screens.md (画面) と operations.md (操作) が現実と乖離していないかを確認する。**テンプレート初期状態では
-両ファイルは空スケルトン**なので、初回は下記で `route:list` から生成して埋める:
+screens.md (画面) と operations.md (操作) は**生成物**である。実装の機械事実
+(`php artisan bughunt:inventory-scan`) と、人が書く注釈・散文を合成して作る。
+まずドリフトが無いことを確認する:
 
 ```bash
-# 画面 (GET × inertia)
-php artisan route:list --json | python3 -c "
-import json,sys
-for r in json.load(sys.stdin):
-    if 'GET' not in r['method']: continue
-    uri=r['uri']; mw=str(r.get('middleware',[]))
-    if uri.startswith(('api/','admin','_','.well-known','storage','sanctum','livewire','oauth','mcp')) or 'debug' in uri: continue
-    if 'web' not in mw: continue
-    print(uri, r.get('name') or '-')" | sort
-
-# 操作 (非GET × web セッション面)
-php artisan route:list --json | python3 -c "
-import json,sys
-for r in json.load(sys.stdin):
-    m=r['method'].split('|')[0]
-    if m in ('GET','HEAD','OPTIONS'): continue
-    mw=str(r.get('middleware',[])); name=r.get('name') or '-'
-    if 'web' not in mw: continue
-    if name.startswith(('cashier','passport','livewire')) or 'webhook' in name: continue
-    print(m, r['uri'], name)" | sort -k2
+scripts/bug-hunt-inventory-check.sh   # exit 0=一致 / 2=致命 / 3=ドリフト
 ```
 
-- インベントリに無い新ルートは追記し、どのストーリーに割り当てるか決める。消えたルートは落とす。
-- ドリフト検知は `scripts/bug-hunt-inventory-check.sh` でも実行できる (exit 0=差分なし / 3=差分あり)。
+- **exit 3 (ドリフト)** の出力は 3 種類に分かれる。
+  - `[注釈] 未注釈の route: …` — 実装に route が増えた。
+    `.claude/skills/app-bug-hunt/inventory/annotations.toml` に 1 行足す
+    (画面なら `kind` = 画面 / JSON、割当なら `story` = S1..S7 と `kubun` = 通常 / 逸、
+    探索の分母に載せないなら `kubun` = 外 と 30 文字以上の `reason`)。
+  - `[注釈] 実装に無い route の注釈が残っている: …` — route が消えた。注釈も消す。
+  - `[生成物] 生成物が再生成の結果と一致しない: …` — 再生成し忘れか手編集。下記を走らせる。
+- 注釈を直したら再生成する (**表の行は手で書かない**):
+
+```bash
+python3 scripts/bug-hunt-inventory.py generate
+```
+
+- **exit 2 (致命)** は抽出そのものが成立していない (抽出条件を満たさない環境 / 母集合 0 件 /
+  壊れた注釈)。目録には触れずに原因を直す。
+- 散文 (画面の既知の仕様・認可契約など) は `inventory/notes-screens.md` /
+  `inventory/notes-operations.md` に書く。**ノートに表を書かない**
+  (連結先を読む `coverage/correlate.py` が操作行として拾ってしまうため、段 2 が拒否する)。
+- 見るのは `web` group を宣言した面だけである。`web` を宣言していない面 (機械向け API /
+  管理画面 / MCP / 現在の webhook の大半) には沈黙する。`web` を宣言していれば webhook でも
+  目録に入る (`webhooks.ses` は操作表に区分 `外` で載っている)。
 - このフェーズは数分以内に留める。
 
 ## Phase 2: ストーリー実走 (本体)
@@ -456,7 +459,9 @@ ### Phase 4b: worktree のクローズ (既定の worktree 走行時)
 
 ## メンテナンス規約
 
-- 新画面・新フローを実装したら screens.md / operations.md と該当ストーリーを更新する。
-  新しい書き込みルートは必ずいずれかのストーリーに割り当てる (ドリフト検知は inventory-check.sh)。
+- 新画面・新フローを実装したら `inventory/annotations.toml` に注釈を 1 行足して再生成し
+  (`python3 scripts/bug-hunt-inventory.py generate`)、該当ストーリーを更新する。
+  新しい書き込みルートは必ずいずれかのストーリーに割り当てる (未注釈は inventory-check.sh が exit 3)。
+  **screens.md / operations.md を直接編集しない** (生成物であり、byte 比較で赤くなる)。
 - ストーリーカードの「期待」は設計の正 (devnotes/docs) への参照を持つこと。カード自体が仕様の正本になってはならない。
 - 同じ finding が 2 回連続で「要確認」のまま放置されたら、仕様を確定させる TODO を提案する。
diff --git a/.claude/skills/app-bug-hunt/inventory/notes-screens.md b/.claude/skills/app-bug-hunt/inventory/notes-screens.md
new file mode 100644
index 0000000..4e7879a
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/inventory/notes-screens.md
@@ -0,0 +1,72 @@
+<!--
+  screens.md の末尾へそのまま連結される散文。人が書く (生成器は中身を読まない)。
+  **表を書かないこと** — notes-operations.md と同じ規則である。あちらは
+  coverage/correlate.py が操作行として拾ってしまうのが直接の理由で、こちらは
+  連結先ごとに規則が変わる方が事故のもとになるため同じ規則を課している。
+  段 2 が表の混入を drift として拒否する。
+-->
+
+## 画面に関する既知の仕様 (散文)
+
+**非 Inertia の GET (画面ではないが分母に載せているもの)**:
+`capture.csrf-cookie` (撮影 PWA の CSRF cookie 発行) と `session.status`
+(bfcache guard `resources/js/lib/bfcache-guard.ts` が pageshow 直後に叩く
+セッション有効性プローブ。auth グループの**外**にあり guest でも 200 +
+`authenticated: false`) は Inertia ページを返さないが、ブラウザ挙動の契約に
+直結するためインベントリに残す (S3 / S6 で観測する)。
+パスキーの `passkey.*-options` 3 本も同じ扱い (次節)。
+
+## パスキー options endpoint の扱い (要検出)
+
+`passkey.*-options` の 3 本は**画面ではなく WebAuthn の challenge を返す JSON GET**
+(`capture.csrf-cookie` / `session.status` と同じ扱いで表に載せている)。
+bug-hunt はこれらを**単独で開くのではなく**、S1/S6 のパスキー操作を UI から実走した
+副作用として通過させる。加えて逸脱アイデアとして直叩きを行う:
+
+- `passkey.registration-options` / `passkey.confirm-options` は `RequireRecentAuth` /
+  auth の配下。**未ログイン・再認証切れで直叩きしたときに 401/302 で止まり、
+  challenge が漏れない**こと。
+- `passkey.login-options` は guest 配下。**メールアドレスを列挙できる応答差
+  (存在するユーザーと存在しないユーザーで応答が変わる)** が出ないこと (存在オラクル)。
+- 3 本とも `throttle:passkeys` 配下。連打時の 429 が**画面上で説明される**こと
+  (無反応で詰まないこと。H4)。
+
+## 課金ゲート着地 (P4 ゲート反転) の画面遷移
+
+> 未契約組織は業務 route group に入れない (`require-active-subscription`)。遮断時の着地は
+> **`manageBilling` 保持者 → `onboarding.checkout` / 非保持者 → `onboarding.billing-required`**
+> (正本: `docs/billing-gate-inversion-runbook.md`、運用契約: `docs/architecture.md`
+> §サブスク契約 Checkout とオンボーディング着地)。
+
+- `onboarding.checkout` は**離脱ガード付き**: 契約済み (有効 sub / free personal) は
+  `billing.index` へ、`manageBilling` 非保持者は `onboarding.billing-required` へ逃がす。
+- `onboarding.billing-required` も同様に、利用可なら `dashboard`、`manageBilling` 保持者なら
+  `onboarding.checkout` へ逃がす。**どちらの画面も「行き先のない詰み」を作らないこと**が契約で、
+  ここでループ・403・空画面が出たら finding (H4/H10)。
+- `?plan=` は org スコープ session へ積んで canonical URL へ 303 する (query が残らない)。
+  リロードしても選択が消えない (peek) こと。
+
+## ナビゲーション/レイアウト規約 (T069 左サイドバー、参照アプリ aigenba 準拠)
+
+> ログイン後の全画面は `templates/AppLayout.svelte` の**左サイドバー型シェル**を共有する
+> (設計正本: `devnotes/20260716-1757-login-sidebar-nav/`)。bug-hunt はこの構造規約への
+> 準拠を横断ヒューリスティクス H11/H13 とあわせて全認証画面で検査する。
+
+**左サイドバー nav 項目 (desktop 固定 / mobile ドロワー) — ここに出てよいもの:**
+- ダッシュボード `/dashboard`(常時)、プロジェクト `/projects`(組織あり)、
+  メンバー `/manage/users`(`canManageMembers`)、API キー `/organizations/{slug}/api-keys`(`canManageApiKeys`)、
+  請求 `/billing`(組織あり)
+
+**下部ユーザー/組織ポップアップ (SidebarUserMenu) — ここに出るべきもの (左 nav に出してはいけない):**
+- **個人設定 `/settings`**、組織設定 `/organizations/{slug}/settings`、CLI/MCP セットアップ、
+  法務(利用規約/プライバシー/特商法)、ログアウト、組織切替
+- **規約 (要検出)**: 「個人設定 `/settings`」は**下部ポップアップ専用**。左サイドバー nav 項目としては
+  出さない(T069 で設定はポップアップへ移動した)。左 nav に「設定」が重複掲載されていれば finding
+  (H10 相当: 直前設計との矛盾 / 二重掲載)。
+- 通知はベル(`notification-bell` / mobile `notification-bell-mobile`)単一導線。左 nav 項目にしない。
+
+**ページ幅/レイアウト準拠 (要検出、H11/H13):**
+- 各ページ本文はサイドバーのオフセット(desktop 256/64px、mobile 0)配下の `<main>` コンテナ内に収まり、
+  **横スクロール・要素はみ出し・レイアウト幅非準拠が無い**こと。旧レイアウトの `max-w-6xl` 中央寄せを外したため、
+  独自に幅を仮定していたページ(テーブル/ワイド要素)が新シェル幅に非準拠になっていないかを desktop/mobile で確認する。
+- desktop(≥1024)/tablet(768)/mobile(375) で本文が破綻せず、サイドバー折りたたみ(64px)時も本文幅が追従すること。
diff --git a/AGENTS.md b/AGENTS.md
index b1f2b6d..eed466e 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -414,8 +414,22 @@ ## bug-hunt (LLM 探索的バグハント、オプトイン)
   **保証しないもの**は `docs/architecture.md` §パイプライン通し確認 が正本。
 - **worktree 既定**: bug-hunt は worktree から走る (`scripts/bughunt-worktree-hook.sh` の PreToolUse ガードが
   main 直叩きを早期に止める。配線は `.claude/settings.json` に常設済み。§常設 hook 配線)。
-- **スケルトン**: `screens.md` / `operations.md` / `stories/` はテンプレートでは空スケルトン。初回に
-  `php artisan route:list` から生成する (SKILL.md Phase 1)。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
+- **目録は生成物 (T176)**: `screens.md` / `operations.md` は手で書かない。実装の機械事実
+  (`php artisan bughunt:inventory-scan`) と、人が書く注釈 (`inventory/annotations.toml`) ・
+  散文 (`inventory/notes-*.md`) から `python3 scripts/bug-hunt-inventory.py generate` で作る。
+  route を足したら**注釈を 1 行足して再生成する** (表の行は手で書かない)。
+  ドリフト検査は `scripts/bug-hunt-inventory-check.sh` (判定は生成器側。exit 0=一致 /
+  2=致命 / 3=ドリフト) で、守るのは 4 つ — 再生成の忘れ・生成物の手編集 (段 3 の byte 比較) /
+  意味の欠落 = 新しい route に割当も対象外理由も無い (段 2) / 抽出の故障 = 環境違い・母集合 0 件
+  (段 1) / 機能カタログの代表機構が実在しないこと (段 4)。
+  **見るのは `web` group を宣言した面だけ**である。`web` を宣言していない面 (機械向け API /
+  Filament 管理画面 / MCP / **現在の** webhook の大半) には沈黙する。面として除くのは
+  先頭セグメントの `oauth` と `livewire-{hash}` の 2 つだけで、それ以外で `web` を宣言した
+  route は webhook であっても必ず目録に入り注釈を要求される (実例: `webhooks.ses` は
+  操作表に載り区分 `外`)。web 面のうち探索の分母に載せないものは注釈の区分 `外` として
+  **目録に見える形で**理由付きで宣言する。
+  テンプレート正典との差 (機能カタログを生成しない / 注釈は TOML / 中間 JSON を持たない) は
+  `docs/template-divergence.md` **D20**。`stories/` はテンプレートでは空スケルトンのままである。
 - **capability 語彙**: finding の `capability_tag` の正本は
   `.claude/skills/app-bug-hunt/capability-catalog.md`(SOP→シナリオ→撮影→レンダの責務境界を
   先に定義し、その上に capability_id を割り当てる。未割当は `unmapped`・tag 不能は `unknown`)。
diff --git a/devnotes/20260815-2100-bughunt-inventory-generator/detailed-design.md b/devnotes/20260815-2100-bughunt-inventory-generator/detailed-design.md
index e95df99..0cf5c19 100644
--- a/devnotes/20260815-2100-bughunt-inventory-generator/detailed-design.md
+++ b/devnotes/20260815-2100-bughunt-inventory-generator/detailed-design.md
@@ -284,7 +284,9 @@ # web 面だが探索の分母に載せないもの
 
 - **許可キーはこの 4 つだけ**。トップレベルは `schema_version` と `[routes.…]` のみ。
   未知のキー (`memo` / `stroy` のような打ち間違い) は段 2 の drift にする
-  (書いたのに効いていない注釈を残さない)
+  (書いたのに効いていない注釈を残さない)。**トップレベルの未知項目も同じく drift (exit 3)**
+  であり、致命 (exit 2) にはしない (実装時の是正: 当初の実装は読み込み時に例外にしていたが、
+  それでは終了コード規約と食い違うので、注釈の集合として持ち回って段 2 で列挙する形にした)
 - 区分 `外` / `終` で `story` を禁じるのは、表では `-` に潰れて見えなくなる古い割当を残さないため
 - セルへ出る値 (`kind` / `story` / `kubun`) と、生成器が実装から取る値 (uri / route 名 / 題名) に
   `|` / CR / LF が含まれていたら段 2 の drift にする
@@ -429,9 +431,14 @@ ### 生成物の書式 (byte 一致で比較するので厳密に決める)
 - `story` は区分が `外` / `終` のとき `-`
 - ヘッダ名 (`name` / `story` / `区分`) は `correlate.py` が列位置を決めるキーなので変えない
 - **散文ノートに下流ローダを騙す表を置かせない**: `correlate.py` は `operations.md` を頭から走査し、
-  ヘッダらしい行を見つけるたびに列位置を更新する。連結される `notes-operations.md` に
-  `name` / `story` / `区分` を含む表ヘッダがあると、注釈に無い行が operation として読まれてしまう。
-  よって**ノート内にこの 3 語を含む表ヘッダがあれば段 2 の drift** にする
+  ヘッダらしい行を見つけるたびに列位置を更新する。**ヘッダとして読めない表が来ても列位置は
+  更新されない**ため、連結される `notes-operations.md` にどんな表があっても、直前に読んだ
+  生成表の列割当で行が読まれてしまう (実装確認: `_header_indices()` が `None` を返すと
+  `idx` は据え置かれる)。よって**ノート内に表の行 (行頭が `|`) が 1 行でもあれば段 2 の drift**
+  にする。実装時の是正: 設計当初は「`name` / `story` / `区分` を含む表ヘッダ」だけを禁じていたが、
+  それでは別のヘッダを持つ表が素通りして同じ事故になるため、規則を「表そのものを置かない」へ広げた
+  (規則が単純になり、抜けも塞がる)。**同じ規則を `notes-screens.md` にも課す** —
+  画面側に直接の危険は無いが、連結先ごとに規則が変わる方が事故のもとになる
 
 ### 段 4 (機能カタログの参照整合) の契約
 
@@ -440,7 +447,10 @@ ### 段 4 (機能カタログの参照整合) の契約
 - id は `^[A-Z]{2,5}-[0-9]{2}$`。**重複したら drift**
 - 代表機構セルは**バッククォートで囲まれた token だけ**を route 名候補とする。
   `/` 区切りの複数記載を許す。丸括弧の説明 (`(機構横断)` / `(admin panel)` /
-  `(クライアント状態。route なし)`) とパス (`routes/api.php`) は候補にしない
+  `(クライアント状態。route なし)`) とパス (`routes/api.php`) は候補にしない。
+  **パスの判定は「区切りを含み、かつ拡張子で終わる」ことで行う** (実装時の是正:
+  当初は「`/` を含めば候補から外す」としていたが、それでは `projects/store` のような
+  打ち間違いが素通りして段 4 が空振りする)
 - `*` で終わる token (`projects.categories.*` / `legal.*`) は**前方一致で 1 件以上**当たれば良い
 - 実在判定の母集合は**抽出した全 route 名** (web 面に限らない。カタログは admin / api 面も指す)
 - 当たらない token が 1 つでもあれば drift。**網羅性 (すべての route が id を持つか) は見ない**
@@ -584,7 +594,7 @@ ### 5-a. `scripts/tests/test_bug_hunt_inventory.py` (Python 自己テスト・st
   4. 各 route の `story` / `kubun` / `operation` (URL 列) が期待どおり。
      併せて**同じ route 名が生成 md 内に 1 度しか現れない**ことも見る
      (`load_operations()` は重複を「最初の定義を優先」で畳むので、重複が隠れないようにする)
-  5. 散文ノートに互換ヘッダの表を混ぜた fixture では、段 2 が drift を返して
+  5. 散文ノートに表を混ぜた fixture では、段 2 が drift を返して
      そもそもレンダリングまで進まない (上の禁止規則の負の対照)
 
 ### 5-b. `tests/Architecture/BughuntInventoryToolSelfTest.php` (新規)
@@ -642,7 +652,7 @@ ### 変更箇所と内容
 
 | ファイル | 変更 |
 |---|---|
-| `docs/template-divergence.md` | **D19** を追加 (テンプレート正典との 3 点の差: 機能カタログを生成せず 3 列を維持 / 注釈は TOML / 中間 JSON を持たない)。「揃えている不変条件」に段 2・段 4 を書く |
+| `docs/template-divergence.md` | **D20** を追加 (テンプレート正典との 3 点の差: 機能カタログを生成せず 3 列を維持 / 注釈は TOML / 中間 JSON を持たない)。「揃えている不変条件」に段 2・段 4 を書く |
 | `AGENTS.md` §bug-hunt | 「スケルトン」の記述を「目録は生成物」へ改め、再生成コマンドとドリフト検査の役割 (何を守るのか) を 3 行で書く |
 | `scripts/README.md` | `bug-hunt-inventory.py` を台帳へ追加、`bug-hunt-inventory-check.sh` の説明を薄い呼び出しへ更新、`scripts/tests/test_bug_hunt_inventory.py` を追加 |
 | `.claude/skills/app-bug-hunt/SKILL.md` | Phase 1 の「`route:list` から手で生成する」手順を `generate` / `check` へ差し替え。メンテナンス規約の「新画面を実装したら 2 ファイルを更新する」を「注釈を 1 行足して再生成する」へ |
@@ -655,7 +665,7 @@ ### 変更箇所と内容
 「面として除くのは `oauth` と `livewire-{hash}` の 2 つ」/「web 面の中で分母に載せないものは
 注釈の区分 `外` として**目録に見える形で**宣言する」。
 
-### D19 に書く内容 (骨子)
+### D20 に書く内容 (骨子)
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 5661a87..99f47a0 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -886,3 +886,74 @@ ### 関連
   `tests/Architecture/PostBootRouteMutationInventoryTest.php`
 - 設計: `devnotes/20260815-2100-route-cache-middleware-attach/`
 - 契約の正本: `docs/app-integration-guide.md` §7c
+
+---
+
+## D20 ✅ bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 列・中間 JSON 無しで実装する
+
+家系の正典 (機能台帳 `bughunt-inventory-generation` の t1) は、bug-hunt の分母 (画面一覧 /
+操作一覧 / 機能カタログ) を実装から生成し、人が書く注釈ファイルと段階的なドリフト検査で守る形である。
+本アプリはこの**方式そのものは採る**が、次の 3 点で正典と形が違うので登録する。
+
+| 観点 | 家系の正典 / テンプレート | 本アプリ |
+|---|---|---|
+| 機能カタログ (`capability-catalog.md`) | 生成物。3 列は 機能 / 対応する画面 / 対応する操作 | **生成しない**。3 列は `id` / `機能 (actor→outcome)` / `代表機構 (route name)` を維持し、参照整合だけを検査する |
+| 注釈ファイル | `inventory/annotations.yaml` | **`inventory/annotations.toml`** |
+| 中間成果物 | `inventory/inventory.json` をコミットする | **持たない** (生成・検査の実行中にだけ存在する) |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **id 列は所見記録の語彙正本である**。`.claude/skills/app-bug-hunt/ledger/findings.schema.json` の
+   必須項目 `capability_tag` は機能カタログの id を値に取る。正典の 3 列には id 列が無く、
+   寄せると語彙の供給元が消えて `unknown` / `unmapped` の判定基準ごと壊れる。
+   また「機能 ↔ 画面 / 操作」の対応は注釈側が route ごとに持つので、カタログにも書くと
+   同じ対応関係が 2 か所に載る (家系が AG-044 でやめた形)。カタログ本体は
+   「機構を利用者価値で束ねた overlay であり MECE ではない」と自ら宣言しており、
+   実装から導けない = 生成対象にしても保守量は減らない。
+2. **注釈が TOML なのは Python の依存規約の帰結である**。`AGENTS.md` §bug-hunt が
+   Python ツールを標準ライブラリのみと定めており、本環境に PyYAML は無い (実測)。
+   `tomllib` は標準ライブラリにあるので、YAML を採ると依存追加か自前パーサのどちらかが要る
+   (どちらも「自前機構の前に公式作法を確認する」に反する)。
+3. **中間 JSON に読み手がいない**。下流の照合器 (`coverage/correlate.py`) が読むのは
+   `operations.md` の name 列であって中間 JSON ではない。コミットするとドリフト面が 1 つ増えるだけで、
+   守るものが無い。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「目録は実装と注釈から再生成でき、ずれていたら CI が落ちる」
+
+| 不変条件 | 担い手 |
+|---|---|
+| 抽出が成功し、宣言した抽出条件で走り、母集合が 0 件でないこと (段 1) | `scripts/bug-hunt-inventory.py` (exit 2) / `scripts/tests/test_bug_hunt_inventory.py` |
+| 注釈の集合が面の集合と一致し、語彙・必須・理由の長さを満たすこと (段 2) | 同上 (exit 3)。未注釈も残置注釈も許さない |
+| 生成物が再生成の結果と byte 一致すること (段 3) | 同上 (exit 3)。手編集と再生成の忘れをまとめて捕まえる |
+| 機能カタログの代表機構が実在し、id が重複しないこと (段 4) | 同上 (exit 3) |
+| 検査シェルが判定を持たず、終了コード 0 / 2 / 3 を実際に返すこと | `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` (sandbox 実走) |
+| 生成器の自己テストが `composer test` の下で実走すること | `tests/Architecture/BughuntInventoryToolSelfTest.php` |
+| 抽出コマンドが事実だけを書き出すこと (面の判定を持たない) | `tests/Feature/Bughunt/InventoryScanCommandTest.php` |
+
+**保証範囲を誇張しない**: 見るのは `web` group を宣言した面だけである。
+沈黙するのは「`web` group を**宣言していない**面」であり、実測では機械向け API (`api/`) /
+Filament の管理画面 (`/admin`) / MCP / Stripe の webhook がこれに当たる。
+「webhook 一般に沈黙する」わけではない — `web` を宣言している `webhooks.ses` は
+操作表に載り、区分 `外` として理由付きで見える。面として除くのは先頭セグメントの
+`oauth` と `livewire-{hash}` の 2 つだけで、それ以外で `web` を宣言した route は
+必ず目録に入り注釈を要求される。
+注釈の**内容**の妥当性 (割当が適切か) は見ない。画面題名の欠落も検出しない。
+機能カタログの網羅性も見ない (代表機構の実在と id の一意性まで)。
+目録の母集合は T164 の記録器が観測しうる route の**部分集合**であり、両者は一致しない。
+
+### 再検討の条件 (解消条件)
+
+- 家系の正典が id 列を持つ形へ変わったとき (機能カタログの生成を採り直す)
+- 本リポジトリの Python に依存を足す裁定が出たとき (注釈を YAML へ寄せる)
+- 中間 JSON を読む道具が家系に現れたとき
+
+### 関連
+
+- 実装: `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` /
+  `.claude/skills/app-bug-hunt/inventory/`
+- gate: `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` /
+  `tests/Architecture/BughuntInventoryToolSelfTest.php` /
+  `tests/Feature/Bughunt/InventoryScanCommandTest.php`
+- 設計: `devnotes/20260815-2100-bughunt-inventory-generator/`

```

## 再実測

- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `scripts/tests` で `python3 -m unittest test_bug_hunt_inventory`: **51 tests OK** (Round 1 時点は 50)
- 関連 Pest (BugHuntInventoryCheckInvariantTest / BughuntInventoryToolSelfTest /
  tests/Feature/Bughunt / ScriptsReadmeInventoryTest): 33 passed
- `bash scripts/bug-hunt-inventory-check.sh`: exit 0 (画面 68 件 / 操作 79 件、目録の内容に変化なし)

`composer test` の全数は本ラウンドの修正後にもう一度回す (Round 1 時点では 5097 tests 全 green)。

## 質問

残る [Critical] / [Warning] があれば指摘してほしい。無ければ全体判定を APPROVED で返してほしい。
