## 施策判定

- P1/P2/P3/P5/P6/P7/P8a/P9: **APPROVE**
- P4/P8b: **REQUEST_CHANGES**

## Findings

- **[Critical] P8bの`down()`契約がまだ自己矛盾しています。** 直前では「Starterのみfalse、Personalは絶対に触らない」としていますが、次行で「当該2行をfalseへ戻す」と記載されています。
  - **修正案:** 後者を「`code='starter'` の1行のみfalseへ戻す」に置換してください。

- **[Warning] 旧公開契約が数箇所残っています。**
  - P1 PlanSeeder: Personal/StarterをP8bで再公開
  - P4非スコープ: Personal/Starterの再公開はP8b
  - P4一覧: `personal/starter` はfalse
  - P3 Plan集合: P8bでPersonal/Starterを再公開
  - **修正案:** 全て「Personal=P3、Starter=P8b」へ統一してください。

- **[Warning] P8bテストの基準件数が旧状態です。** 再公開前を「1枚」としていますが、P3後はPersonal+Standardの**2枚**です。
  - **修正案:** `2 → 3`を固定し、P8b migrationの`down()`後もPersonal+Standardの2枚を維持するテストを追加してください。

## 総合判定

**CHANGES_REQUESTED**

設計ロジック自体は収束しています。残るのは、同じrollback事故を誘発し得る記述上の矛盾だけです。