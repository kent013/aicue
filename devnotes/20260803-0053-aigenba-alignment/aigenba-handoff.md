# aigenba への引き継ぎ: AI-CUE 側が優位な差分 (決済ドメイン以外)

> **目的**: 「aigenba に合わせる」は**双方向**である。AI-CUE が aigenba へ整列する過程
> (`devnotes/20260803-0053-aigenba-alignment/`、監査台帳 `../20260802-1548-aigenba-alignment-audit/audit.md`)
> で見つかった、**AI-CUE 側が優れている / 安全な差分**を aigenba へ返す。
>
> **分類**: 既存の `devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md` の
> カテゴリ運用に従い、本文書は全件 **カテゴリ B (AI-CUE 側が優れている / 安全 = 返す)** である。
> A (対象が存在しない) / C (既存契約への適合) / D (ドメイン要件の差) / E (一時的措置) は返さないため
> 本文書には載せない。
>
> **確度の書き方**: aigenba 側の実コード (`/tmp/aigenba` の working copy、2026-08-02 時点) を読んで
> 確認した事実と、未検証の推測を分けて書く。**実行による再現はしていない**。

## 採否結果 (受け手 = aigenba 側が記入する)

| # | 差分 | 種別 | 提案の要旨 | 採否 (adopt / reject / defer) | 判断日 | 備考 |
|---|---|---|---|---|---|---|
| F-1 | bug-hunt シェルの 3 段 DB guard + xtrace 秘匿 | 安全性 | 破壊操作の直前に DB 名 regex / role を hard-deny。`set -x` 下で API key を trace に出さない | | | |
| F-2 | `correlate.py` のヘッダ列 index 動的決定 + backtick 剥がし | 正確性 | 列構成の違う節を**誤 join せず skip** する。backtick 付き route 名が分母から静かに落ちるのを防ぐ | | | |
| F-3 | ~~`audit-gate` のテスト~~ | — | **取り下げ (前提誤り)**。下記 F-3 節参照。**aigenba 側の対応は不要** | — | 2026-08-02 | 提案側で撤回 |
| F-4 | bfcache の秘匿・再検証 (Safari を含む) | 安全性 | aigenba は Safari bfcache を「別施策」としているが、**installable PWA を持つ以上同じ穴がある** | | | |
| F-5 | `validate_findings.py` の `import io` 位置 / `open()` の context manager 化 | 可読性・資源解放 | AI-CUE は**整列優先で意図的に見送った**。aigenba が直したら AI-CUE も追随する | | | |

> 記入方法: 採否欄に `adopt` / `reject` / `defer` と判断日を書き、理由は備考か aigenba 側 devnotes へ。
> **reject でよい**。目的は往復管理であって採用の強制ではない (前例: 同ディレクトリの
> `../20260717-0035-aigenba-billing-parity/aigenba-handoff.md` は先方検証で「指摘不成立」として CLOSED)。

---

## F-1. bug-hunt シェルの 3 段 DB guard と xtrace 秘匿

| | |
|---|---|
| **aigenba** | `scripts/bug-hunt-shard.sh` は `require_orchestrator`(親専用 gate、default-deny) を持つが、**DB 名の hard-deny guard が無い**。`shard_db()` が返した名前をそのまま `createdb "${db}"` / `dropdb --if-exists "$(shard_db "${shard}")"` / `migrate:fresh --seed --force` に渡す。API キーは環境変数から読み `real_llm_env+=("ANTHROPIC_API_KEY=${ANTHROPIC_API_KEY}")` の形で配列へ入れる |
| **AI-CUE** | 破壊的操作の**同一プロセス・直前**に用途別 3 段 guard を通す: `guard_shard_db_name`(DB 名が `^<prefix>(_[1-8])?$` に一致しなければ abort) / `guard_bughunt_runtime`(+ `APP_ENV=bughunt.local` + DB user が `bughunt`) / `guard_admin_provision`(+ admin role の明示必須)。秘密取扱区間は `secret_xtrace_off` / `secret_xtrace_restore` で囲む |
| **理由 (安全性)** | (a) **DB 名 guard**: 変数の取り違え・env leak・引数ミスが起きても、dev DB 名は regex に一致しないため `createdb` / `dropdb` / `migrate:fresh` に到達しない。「呼び出し側が正しい名前を渡す」前提を**構造で置き換える**。(b) **xtrace 秘匿**: `bash -x scripts/bug-hunt-shard.sh ...` でデバッグすると、**キーを含む代入行がそのまま trace に出て CI ログ・端末履歴に残る**。`set -x` は障害調査時にこそ使われるため、露出は「めったに起きない」ではなく「困っている時に起きる」 |
| **aigenba への提案** | 1) `createdb` / `dropdb` / `migrate:fresh` を呼ぶ関数の先頭で DB 名 regex を hard-deny する (dev DB 名の大小・前後空白バリアントも regex 不一致で落ちる)。2) キーを扱う区間を `set +x` / 復元で囲む (AI-CUE の 2 行実装がそのまま流用できる)。3) guard 自体の回帰テストを `self-test` に足す (AI-CUE は `[c] [d] [e]` で dev DB 名 abort / user≠bughunt abort / admin 未設定 abort を検証している) |
| **確度** | **高** (aigenba 側の実コードで guard 関数の不在と `createdb` / `dropdb` の直呼びを確認済み)。ただし aigenba の運用で実際に事故が起きたかは未確認。**影響は「起きたときの被害」側**にある |
| **検出元** | 全面監査 2026-08-02 (`../20260802-1548-aigenba-alignment-audit/audit.md`)。AI-CUE 側は実装済み (`scripts/bug-hunt-shard.sh` + `scripts/bug-hunt-shard.sh self-test`) |

## F-2. `coverage/correlate.py` のヘッダ列 index 動的決定と backtick 剥がし

| | |
|---|---|
| **aigenba** | `load_operations()` が `cols[0], cols[1], cols[2], cols[3]` の**固定 index** で `route name / 操作 / story / 区分` を読む。ヘッダ行は「col0 が `route name`」または「col3 が `区分`」で判定して skip する。`_parse_route_cell()` は **backtick を剥がさない** |
| **AI-CUE** | `_header_indices()` が**ヘッダ行から name / story / 区分 の列 index を動的に決め**、以降の行に適用する (節ごとに更新)。ヘッダを検出できない表は **skip する** (誤 join しない)。`_parse_route_cell()` は backtick を除去する |
| **理由 (正確性)** | aigenba の `operations.md` には**既に**列構成の違う節がある: `| route name (api.v1) | CLI コマンド | story | 区分 |` (S8) と `| 操作 | 内容 | story | 区分 |` (S10, Filament)。後者は **col0 が route 名ではない**ため、固定 index では `admin.login` / `admin.users.crud` 等の**操作ラベルが route 名として分母に混入**する (route:list と join できない幽霊エントリになる)。また 4 列でない行 (5 列 / 8 列) が実在し、`len(cols) < 4` を通過した行は**ずれた列**で解釈される。backtick については、name セルが `` `api.v1.x` `` 形式だと route 名らしさ判定 (`[A-Za-z0-9_.\-]+` の完全一致) を通らず、**分母から静かに脱落**する |
| **aigenba への提案** | `load_operations()` をヘッダ駆動にする (name/story/区分 のヘッダ語彙は既存表記をそのまま許容リストにする)。**ヘッダを認識できない表は分母に入れない**方針にすると、S10 のような「操作で数える節」を混入させずに済む (数えたいなら専用の列見出しを付ける)。あわせて `_parse_route_cell()` の先頭で backtick を除去する。AI-CUE 側の実装と単体テスト (`coverage/test_correlate.py`) がそのまま参考になる |
| **確度** | **中〜高**。列構成の違う節・非 4 列行・backtick 行が aigenba の `operations.md` に実在することは確認済み (grep)。ただし**実際に分母がいくつずれているかは未計測**なので、まず現行 `correlate.py` の出力で `unmapped` / 幽霊 route の有無を見てほしい |
| **検出元** | 全面監査 2026-08-02。AI-CUE 側は実装済み |

## F-3. `scripts/audit-gate.test.ts` — **取り下げ (提案側の前提誤り)**

詳細設計 (`detailed-design.md` 施策 14) は「supply-chain gate 自体は両者にあるが、**gate のテストは AI-CUE のみ**」を
根拠に F-3 を挙げていた。**この前提は誤りである**。2026-08-02 に確認したところ、aigenba にも
`tests/js/scripts/audit-gate.test.ts` (327 行) が実在し、テスト項目は AI-CUE 版とほぼ同一だった
(配置が `tests/js/scripts/` か `scripts/` 併置かの違いのみ)。

実際に残る差分は **AI-CUE が PyPI (pip-audit) にも対応している**点だけで、
aigenba は `EcosystemEnum` から `pypi` を意図的に除外し「Python ecosystem は aigenba に存在しない」と
コメントで明記している。**aigenba に Python 依存が入るまでは移植の価値が無い**ため、本項目は取り下げる。

> 記録の意図: 「調べたら前提が誤りだった」を消さずに残す (`aigenba-divergence-ledger.md` の
> 「乖離ではないと判定したもの」と同じ扱い)。**aigenba 側の対応は不要**。

## F-4. bfcache の秘匿・再検証 (Safari / PWA)

| | |
|---|---|
| **aigenba** | `NoStoreCacheHeadersForAuthenticatedPages` で認証済み応答に `no-store, private` を baseline 付与済み。ただし `docs/architecture.md` は **「Chrome・Firefox 対象、Safari は既知の残余リスクで別施策」**と明記しており、**クライアント側の対策は無い**。一方で `public/site.webmanifest` は `"display": "standalone"` を宣言し `app.blade.php` から link されている = **installable PWA である** |
| **AI-CUE** | 同じ baseline middleware (施策 4) に加えて、**クライアント側の bfcache 秘匿・再検証** (施策 6) を設計している: `pagehide` で `documentElement` に秘匿属性を付け**その DOM 状態ごと bfcache snapshot に入れる** → `pageshow` で秘匿属性があれば**秘匿したまま**軽量プローブでセッション有効性を確認 → 有効なら秘匿解除のみ (フォーム状態・履歴を壊さない) / 無効なら login へ hard navigation / プローブ失敗時は秘匿維持 + 再試行ボタン |
| **理由 (安全性)** | **Safari は `no-store` でも bfcache に格納しうる**。したがってサーバヘッダだけでは「ログアウト後に戻るで認証済み画面 (メンバー一覧等の PII) が復元される」を防げない。**PWA として standalone 起動される環境では iOS Safari (WebKit) が主要な実行系**になるため、「Safari は別施策」の残余リスクは**アプリの主要導線にそのまま残る**。また「復元後に非同期検証して遷移する」実装だと**検証完了までの間 PII が実際に表示される**ため、「秘匿してから検証」でなければ穴が塞がらない (AI-CUE の設計レビューでこの点が Critical 指摘になった) |
| **aigenba への提案** | (1) まず **実機確認**: iOS Safari (できれば PWA としてホーム画面から起動した状態) で「認証済み画面 → ログアウト → 戻る」を実行し、PII が再表示されるかを見る。(2) 再現するなら「pagehide で同期秘匿 → pageshow で秘匿のまま検証 → 有効なら解除のみ」の形を検討してほしい。**hard reload を常用しない**のが要点で、未送信フォームや復元済み状態を巻き添えにしないため。(3) 検証用の軽量プローブは既存の step-up 系 endpoint を流用せず**セッション有効性専用**を用意する (意味が違うものを兼用すると鮮度と有効性が混ざる) |
| **確度** | **中**。aigenba の webmanifest が standalone であること・architecture.md が Safari を対象外としていることは確認済みだが、**iOS Safari での実再現は未実施**。AI-CUE 側も本 handoff 執筆時点では**別トラック (T082) で実装中**であり、本ブランチ (`todo/T084`) にはコードが無い。**実装後に具体的な差分を添えて再度渡すのが望ましい** |
| **検出元** | 監査 2026-08-02 の P3-b。設計は `detailed-design.md` 施策 6 / 施策 8 (WebKit レーンの Browser E2E) |

## F-5. `ledger/validate_findings.py` の `import io` 位置と `open()` の context manager 化

| | |
|---|---|
| **aigenba** | `analyze()` の**関数内**に `import io as _io` を置く。`load_jsonl()` は `fh = open(path, encoding="utf-8")` を裸で開き `try/finally` で閉じる |
| **AI-CUE** | **同じ形へ揃える予定** (施策 9 で aigenba の 2-pass 対応 `text=` 引数ごと verbatim 移植する)。**AI-CUE 側では改善しない** |
| **理由** | `import` はモジュール先頭に置くのが標準 (PEP 8)、`open()` は context manager (`with`) の方が例外経路でも確実に閉じる。ただし**ここで AI-CUE だけ直すと新しい乖離を作る**ため、整列を優先して意図的に見送った (AI-CUE の詳細設計レビュー R1 Suggestion への回答として記録済み) |
| **aigenba への提案** | `import io` をモジュール先頭へ移し、`load_jsonl()` の分岐を `with` で書けるよう整える (`text` 指定時は `io.StringIO(text)` を `with` に通せば分岐が 1 本化できる)。**優先度は低い** (現行実装にバグは無い) |
| **確度** | **高** (差分は明確)。ただし**挙動の欠陥ではなくスタイル・資源解放の堅牢性**の話であり、reject でも実害は無い |
| **検出元** | AI-CUE 詳細設計レビュー R1 Suggestion。**aigenba が直したら AI-CUE も追随する** (先回りで独自修正しない) |

---

## 往復の運用

1. aigenba 側は上の採否表に `adopt` / `reject` / `defer` と判断日を記入する (理由は備考欄か aigenba 側 devnotes)。
2. **adopt された項目**は、aigenba 側で実装され次第 AI-CUE が追随する (F-5 は特に「aigenba が先、AI-CUE が後」)。
3. **reject された項目**は理由ごと本文書に残す。AI-CUE 側は現行実装を維持し、
   `../20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md` に
   「返したが reject された乖離」として記録する。
4. **defer** は再検討の契機 (条件・時期) を備考に書く。
