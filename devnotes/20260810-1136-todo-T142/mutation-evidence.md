# T142 (PR-B: 猶予期間つき削除 / 凍結方式) mutation 実測記録

> 実装完了の条件は「テストが緑」ではなく「**壊すと赤くなることを実測した**」。
> 詳細設計 `devnotes/20260809-0908-account-deletion-grace/detailed-design.md` §共通/mutation の
> **PR-B 該当分**を 1 つずつ適用 → 対象テストが赤いことを実測 → 変異を戻した。
> 全変異は適用後に `git checkout --` で復元済み (最終 `git status --short` が空であることを確認)。
>
> **設計の予測と実測がずれたものは、辻褄を合わせずそのまま記録する。**

## 実測サマリ

| # | 変異 | 設計の予測 | 実測 | 判定 |
|---|------|-----------|------|------|
| M4 | `AccountDeletionFreezeAllowance` から `Settings` を削る | 到達性テスト (取消に到達できない) | **赤**。`AccountDeletionFreezeTest`「予約中でも /settings は 200」が 302 になり、gate の件数 pin (16→15) も赤 | 予測どおり (+ 件数 pin も点灯) |
| M5 | 同 enum に `dashboard` を足す | exact-fit 検査 3 | **赤 — ただし赤くなったのは件数 pin だけ**。検査 3 は「宣言 (enum) と実装 (middleware の分岐) の一致」を測るので、enum に足すと**両側が同時に動く**ため点灯しない | **予測とずれ (記録)** |
| M6 | 凍結 middleware を priority list でテナント境界より前へ | `TenantBoundaryOrderingTest` + 他組織 `{project}` が 302 | **赤**。検査 2 (binding と guard の間に短絡)・検査 5 (列の完全一致)・behavioral (404 期待が 302) の 3 本 | 予測どおり |
| M7 | 執行バッチの終了コードを常に SUCCESS | 「想定外例外で FAILURE」 | **赤** (2 本: 想定外例外 / 非正規行) | 予測どおり |
| M8 | `deleteAccount` の precondition をブロッカー判定の後へ | 「抽出後に取消 → 削除しない」 | **2 形を実測**。(a) `$blockers = …` の**直後** (throw より前) へ動かす形は**緑のまま** = 検出できない。(b) `throw` ブロックの**後**へ動かす形は**赤** (取消済みユーザーに `ValidationException` が出る) | **予測とずれ (記録)**。設計の「判定の後」は (b) の意味であり、(a) の窓は本テストでは検出できない |
| M9 | 通知 `via()` から予約生存の再確認を外す | 「予約 → 即取消 → メール 0 通」 | **赤** (2 本: 即取消 / 再予約時の古い job) | 予測どおり |
| M17 | 同 enum に `settings.account.destroy` を足す | 「予約中は即時削除できない」 | **赤**。gate 検査 8 (名指し pin) + 件数 pin + behavioral (即時削除が `/` へ 302 = 実際に消えた) | 予測どおり |
| M18 | `logout` を `auth`+`verified` group の中へ移す | 凍結 gate 検査 6 (`U` に含まれないこと) | **赤**。※Fortify 登録 route を物理的に動かす代わりに、group 内へ `->name('logout')` の route を足して同値の状況を作った | 予測どおり (再現手段のみ代替) |
| M19 | `requestAccountDeletion` の冪等 no-op を外す | 「予約 POST 2 回でメール 1 通」 | **赤** (2 本: purge_after が 3 日延びる / メールが 2 通) | 予測どおり |
| M20 | 執行バッチの抽出条件から `whereNotNull('deletion_requested_at')` を外す | 「片列だけの非正規行を due に数えない」 | **赤** (`due=0` 期待が満たされない) | 予測どおり |
| M21 | `config/account.php` の `deletion_grace_days` を 0 に | `AccountDeletionGraceConfigTest` の fail-fast | **赤** (検査 2 の値 pin + 検査 3/7/8/9 が `Assert::greaterThan` で例外) | 予測どおり |
| M22 | `purgeAfter()` を `addDaysNoOverflow` に戻す | 「2026-01-31 の 30 日後 = 2026-03-02」 | **赤 — ただし理由が違う**。本リポジトリの Carbon に `addDaysNoOverflow` は**存在せず** `Method addDaysNoOverflow does not exist.` で落ちる。設計が想定した「静かに 28 日へ丸められる」壊れ方は**起きない** | **予測とずれ (記録)**。所見はコードの docblock にも反映済み |
| M23 | 通知 `via()` を `fresh() ?? $notifiable` へ戻す | 「執行済み user へ送らない」 | **赤** | 予測どおり |
| M25 | `recent-auth.confirm` を allowlist から外す | 到達性 (d) 移譲画面へ到達できない | **赤** (step-up 確認画面が 302 + 件数 pin) | 予測どおり |
| M27 | 同 enum に `billing.auto-recharge.update` を足す | 「予約中に auto-recharge 更新が遮断される」 | **赤** (gate 検査 8 の名指し pin + 件数 pin) | 予測どおり |
| M28 | users の CHECK 制約を外し片列だけ UPDATE | migration の DB 制約テスト | **赤** (`QueryException` が飛ばない) | 予測どおり |
| M29 | `PortalConfigurationSpec` の `subscription_update` を `true` に | 凍結 gate の**前提検査 3 点** | **赤**。赤くなったのは `AccountDeletionFreezeRouteGateTest` 検査 7 (`subscription_update.enabled === false`) | 予測どおり。**`billing:ensure-portal-configuration --verify` は spec との一致しか見ないため、この前提 pin が無ければ気づけなかった** |

## Codex 実装レビュー Round 1 を受けて追加した mutation

| # | 変異 (実施後は必ず戻す) | 赤くなるべきテスト | 実測 |
|---|------|-----------|------|
| M30 | `isDue()` の前提を `isNormalized()` → `isPending()` に戻し、執行バッチの抽出条件から `whereColumn('deletion_purge_after', '>=', 'deletion_requested_at')` を外す | 「期限 < 予約時刻の非正規行は削除されず report + FAILURE」(Feature 2 本) | **赤** (バッチ側で `due=1` になり、Service 側でも `executeAccountDeletionRequest` が true を返して削除された) |
| M31 | `AccountDeletionFreezeAllowance` から `settings.security` を外す | 「2FA 未準拠ユーザーが設定画面へ到達できる (詰みではない)」 | **赤** (`/settings/security` が 302 に倒れ、取消は 2FA ゲート・2FA 設定は凍結という**相互ブロックの詰み**が再現する) |

M30 / M31 はいずれも **Codex レビューの指摘を追ったところ実在の欠陥が見つかった**もので、
指摘そのままではなく「本当に壊れる形」を作ってから修正している。

- **M30 (Critical)**: CHECK 制約が壊れて `deletion_purge_after < deletion_requested_at` の行ができた場合、
  `unexpected` として report はするのに**その行が due 抽出に残って物理削除されていた** (fail-open)。
  猶予が経過していないユーザーが早期に消える向きの欠陥。DTO 側 (`isNormalized()`) と
  クエリ側の**両方**を fail-closed にした。
- **M31 (Critical / 設計の allowlist 漏れ)**: 2FA 必須組織の**未準拠**ユーザーは、
  2FA 強制ゲート (凍結より前に走る) が取消 DELETE を `settings.security` へ倒す一方、
  その `settings.security` を凍結が `/settings` へ倒すため、**行き先のない詰み**になっていた。
  設計の allowlist には `settings.security` が入っていない。実測して発見し追加した
  (これは設計の見落としであり、実装での逸脱ではない)。

## Codex 実装レビュー Round 2 で判明した実測 (追加)

- **`report(new ValidationException)` は何も起きない**。Laravel の既定 dontReport が
  `ValidationException` を握り潰すため、設計どおりに書いた「保留を report する」は
  **実際には無効だった** (`Exceptions::fake()` + `assertReported` で実測して発覚)。
  件数を載せた `RuntimeException` へ集約する形に変更し、テストで固定した。
  → 「終了コード + report() を監視する」という運用契約が、**保留については嘘になっていた**。
- **`Exceptions::assertReported` を入れるまで、`report()` を消してもテストは緑だった**
  (終了コードしか見ていなかったため)。監視契約を主張するなら報告そのものを固定する必要がある、
  という Round 2 の指摘は正しい。

## 予測とのずれ (3 件) の詳細

### 1. M5 — 「exact-fit 検査 3」は allowlist の**増加**を捕まえない

検査 3 は `U` の全 route に対して middleware を実際に駆動し、「bypass した集合」と
「enum が宣言する集合」が一致することを見る。enum に case を足すと **middleware の挙動も同時に変わる**
ため、両辺が同じだけ動いて一致は保たれる。増加を捕まえるのは
**件数の exact-fit pin (`FREEZE_ALLOWANCE_COUNT`)** と **名指しの pin (検査 8)** の 2 つである。

検査 3 が本当に守るのは「宣言と実装がずれること」— たとえば middleware に prefix 一致や
wildcard を実装で持ち込む改変であり、そちらは検査 3 でしか落ちない。役割が違うので両方残す。

### 2. M8 — precondition の「判定の後」には 2 つの位置がある

- `$blockers = $this->organizationsBlockingDeletion(...)` の**直後 / throw の前**:
  ブロッカー例外は出ないので**テストは緑のまま**。実害は「取消済みユーザーに対して
  無駄なブロッカー評価クエリが走る」ことだけで、観測可能な契約は壊れない。
- `throw` ブロックの**後**: 取消済みユーザーが `ValidationException` を受け、バッチが
  「業務上の保留 (blocked)」と誤分類する。**テストは赤**。

実装は前者よりさらに前 (fresh 取得の直後) に置いてある。テストが固定しているのは
**「ブロッカー例外より前であること」**であり、「ブロッカー評価クエリより前であること」は
固定していない。誇張しないためここに明記する。

### 3. M22 — `addDaysNoOverflow` はこの Carbon に存在しない

設計は「`addDaysNoOverflow` は月末丸めで 30 日未満になるため禁止」と書いていたが、実測では
`Method addDaysNoOverflow does not exist.` で即座に落ちる (静かに壊れる経路ではない)。
したがって現実の危険は *NoOverflow ではなく **日加算を月単位の式へ書き換えること**の側にあり、
それは `AccountDeletionGraceConfigTest` の behavioral 検査
(2026-01-31 + 30 日 = 2026-03-02 / うるう年跨ぎ) が担う。
`CarbonOverflowArithmeticGateTest` の禁止語彙は月・年・四半期のみで日は母集団外である
(gate の定数を実読して確認済み)。

## この PR で実施していない mutation

M1 / M2 / M3 / M10〜M16 / M24 / M26 は PR-A・PR-C1・PR-C2・PR-C3 の担当 (本 PR の変更対象外)。
M1〜M3 の実測は `devnotes/20260810-1004-todo-T141/mutation-evidence.md` にある。
