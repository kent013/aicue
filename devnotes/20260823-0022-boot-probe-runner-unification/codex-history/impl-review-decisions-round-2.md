# 対応マトリクス: impl-review Round 2

Codex 実装レビュー Round 2 (全体判定 CHANGES_REQUESTED) への対応。
Round 2 は Round 1 の [Critical] 1 / 2 / 3 と [Warning] 5 / [Suggestion] 6 を「修正済み」と判定した。
残る指摘は 3 件。

---

## [Suggestion] `externalFakeProbeAssertNormalizedPath()` の負例を恒久テストにする

- **判断: 対応する**
- **根拠**: 指摘のとおり。実データが常に正常なので、**この helper を空実装にしても
  P-11 / P-14 は緑のまま**である = 今回直した分岐そのものに退行検知が無い。
  AGENTS.md §静的検査の共通規約 **(c)「検出力は負例で裏取りする」** に正面から当たる。
- **対応内容**: 述語を純関数 `externalFakeProbeIsNormalizedAbsolutePath()` へ切り出し、
  **P-16 として恒久のデータ駆動テスト 14 例**を置いた。
  - 正例 3 (実データと同じ形。false になると P-11 / P-14 が偽レッドになる)
  - 負例 `..` 3 形 (`/tmp/x/../../workspace/...` / 末尾 `..` / 先頭 `/../`)
  - 負例 `.` 2 形
  - 負例 相対パス 3 形
  - **紛らわしいが正当な 3 形** (`..hidden` / `.hidden` / `a..b`) —
    素の部分文字列判定で書いていたら誤って弾いていた形を正例として固定した

## [Warning] `BootProbeResult` の PHPDoc の食い違い

- **判断: 見送る (Codex が上流申し送りとする扱いを受け入れた)**
- **対応内容**: 変更しない。Round 1 のマトリクスに記録済み。

## [Critical] 自己検査 S9 / S10 がリポジトリの `.env` を読む

- **判断: 指摘を全面的に受け入れる。ただし「除去」ではなく「封じ込め + 目録化」で応じる**
- **実測して事実を確定させた** (議論ではなくデータで詰めた):

  S9 / S10 と同じ形 (アプリを起こすだけ) の子を起こし、**秘密の値そのものは出さずに
  「非空かどうかと長さ」だけ**を報告させた結果:

  | 設定キー | 子での状態 |
  |---|---|
  | `app.env` | 非空 (5 文字 = `local`) |
  | `services.stripe.secret` | `null` |
  | `cashier.secret` | 空 |
  | `filesystems.disks.s3.secret` | 空 |
  | `services.google.client_secret` | 空 |
  | `mail.mailers.smtp.password` | `null` |
  | **`database.connections.pgsql.password`** | **非空 (8 文字)** |
  | **`ciphersweet.providers.string.key`** | **非空 (64 文字)** |
  | 読んだ環境ファイル | **`.env`** |

  → **Codex が正しい。** 子はリポジトリの `.env` を読み、本チェックアウトでは
  外部サービスの資格情報こそ空だったが、**DB のパスワードと実 `CIPHERSWEET_KEY` は載った**。
  **「空だった」のはこのチェックアウトの性質であって保証ではない。**

- **なぜ同一プロセスのテストでは問題にならないのかも確定させた**:
  `phpunit.xml` が `<server name="STRIPE_SECRET" value="" force="true"/>` のように
  秘密を**強制的に無害化**している。しかし `<server force>` は **PHPUnit プロセスにしか効かず、
  `proc_open` で起こした子には及ばない**。これが子と同一プロセスの非対称の正体である。

- **それでも T249 で除去できない理由** (Round 1 から変えない):
  当該検体は**バイト一致で取り込んだ共有ファイルの中**にあり、書き換えると意図的逸脱の登録
  (`LedgerPins::DIVERGENCE_ENTRY_COUNT` の更新) が要る。T249 の受入条件は
  「取り込み 3 本を編集しない」である。

- **対応内容 (Codex の求めた「別の構造的境界」)**: 除去できない以上、
  **この危険面が申告なしに増えないことを機械で固定する**。軸 B の申告へ
  `boots_repository_env` を足し、**G-8** を新設した:
  1. `true` の集合が `['tests/Unit/Support/Process/BootProbeRunnerTest.php']` と**完全一致**
     (増減のどちらでも赤)
  2. `true` を申告してよいのは `tests/Unit/Support/Process/` 配下 =
     **バイト一致で取り込んだ共有ファイルだけ**。
     **aicue が自分で書いたファイルには `true` を申告できない**
  3. `child_entry` 以外 (`in_process` / `inventory`) は必ず `false`
  G-8 の docblock に、実測値・`<server force>` が子に及ばない機序・
  `fake-wiring-probe.php` が専用環境ファイルで回避している対比・
  **上流 (正典 v1) で解消されたら本 pin の `true` は 0 件になる**ことを書いた。

## [Critical] 全体テストの「2 回連続 green」

- **判断: 対応する**
- **根拠**: 指摘のとおり。green / fail / green は「2 回連続」ではない。
  無関係な flaky という分析は機械的な受入条件を置き換えない。
- **対応内容**: 上記の修正をすべて当てた**最終コード**で `composer test` を回し直し、
  **2 回連続 green** を得た:

  | 走行 | 結果 |
  |---|---|
  | final run 1 | **6500 tests / 6498 passed / 0 failed / 2 skipped** (598.3 秒) |
  | final run 2 | **6500 tests / 6498 passed / 0 failed / 2 skipped** (602.6 秒) |

## 性能測定の結論 (Codex の助言どおりに書き換えた)

「最小値どうしの比較は事後的で偏りやすいので合格の根拠にしない」という助言を受け入れ、
結論を **「(c) = 12.4 秒は安定して測れている / 全体比較は環境の雑音により判定不能」**
までに留める。**閾値は動かしていない。**
