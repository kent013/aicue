全体判定: **CHANGES_REQUESTED**

Round 1 の主要な懸念はほぼ解消しています。`two-factor.enable` を同一 TODO に含める判断も妥当です。ただし、実装方針に分類表と矛盾する箇所があり、現状のままでは inventory を正しく実装できません。

### 1. 使命との整合性

[Suggestion] 妥当です。今回の変更は直接的な教材生成機能ではありませんが、セッション奪取だけで第二要素を複製・破壊できる状態を解消するもので、組織資産を預かるサービスの前提条件に当たります。

### 2. 禁止事項違反

[Suggestion] 禁止事項への抵触は見当たりません。

`RequireRecentAuth` の既存 `RecentAuthRequiredResource` / DTO 契約を使い、新規 JSON レスポンスを作らない方針が明記されたため、Round 1 の懸念は解消しています。

### 3. 実現可能性

[Critical] exemption の件数と enum の case 数が矛盾しています。

分類表と gate は exemption を次の3本としています。

- `two-factor.confirm`
- `two-factor.login`
- `two-factor.login.store`

一方、backend 方針は `TwoFactorStepUpExemption.php` を「2 case」としています。さらに gate の exact-fit cap も3です。このままでは3 routeを型付き enum で表現できません。

修正提案: 「新設（3 case）」へ修正し、各 route を別 case として表現してください。もし login 2本を1 caseに集約する設計なら、route名から enumへの写像方法と stale-entry 検査方法を明記する必要がありますが、1 route 1 caseの方が目録として明快です。

[Warning] 素材取得時の409処理は、複数 fetch が同時に失敗した場合の集約位置を明確にしてください。

QRとsecret-keyを並列取得して各 fetch が個別に `guardWithRecentAuth()` を呼ぶと、モーダルの多重起動や `pendingAction` の上書きが起こり得ます。

修正提案: 各 fetch は `{ recentAuthRequired: boolean }` を返すだけにし、`loadEnrollmentAssets()` の集約地点で一度だけ `guardWithRecentAuth(() => void loadEnrollmentAssets())` を呼ぶ、と設計を固定してください。JSテストには「両方が409でもモーダル起動と pending action 登録は1回」を追加するのが安全です。

### 4. 期待効果の妥当性

[Suggestion] 妥当です。秘密GETだけでなく、同じseedを破壊できる`force=true`経路も閉じることで、「セッション単体では第二要素を褬製・破壊できない」という一貫した効果になります。

ただし、recent-auth済みセッション自体を奪取された場合までは防げないため、表現は現在の「奪取済み session だけでは成立しない」のままが適切です。

### 5. リスク

[Warning] 母集団の拡大は現在の11本については十分ですが、意味上の不変条件とセレクタはまだ完全には一致していません。

「route名に`two-factor`を含む」は、将来 `mfa.*` や `security.requirement.*` のような名前で第二要素を変更するrouteが追加された場合には検出しません。Round 1 の「Fortify 9本だけ」という問題は解消しましたが、意味的に全2FA状態変更routeを保証しているわけではありません。

修正提案: 不変条件の機械保証範囲を「route名に`two-factor`を含む全route」に限定して明記してください。たとえば次の表現なら保証と実装が一致します。

> route名に `two-factor` を含むrouteは、recent-auth必須または根拠付き exemption に分類する。別名で2FA状態へ触るrouteを追加する場合は、このinventoryの母集団設計も同時に見直す。

より広い意味保証を主張するなら、命名規約をArchitecture testで強制する別の仕組みが必要になり、本TODOには過大です。

### 6. スコープの適切さ

[Suggestion] `two-factor.enable` は本TODOに含めるべきです。

別TODOへ分離すると、秘密GETを保護した直後にも「同じ秘密をセッションだけで再生成して正規ユーザーをロックアウトできる」経路が残ります。また、既知の脆弱経路を exemption に置くのは、exemption inventoryを恒久的な先送り台帳に変えてしまいます。

`force`自体の封殺は別TODO、既存routeへのrecent-auth追加は今回、という境界も適切です。

### 7. 型安全性

[Critical] 上記の enum case 数不一致を解消する必要があります。

修正提案: `TwoFactorStepUpExemption` を3 caseとし、route名との対応をenum内で型付きに固定してください。PHPStan level 10上も、裸の文字列配列よりenumを正本にして `match` を網羅させる構造が適しています。

[Suggestion] 409の判定では、HTTP statusだけでなく既存DTO/Resourceの識別フィールドを型付きに検証してください。単なる `response.status === 409` だけだと、別理由の409までstep-upとして扱う可能性があります。

---

質問への回答は次のとおりです。

1. 11本への拡大で、現在存在するアプリ側2FA routeの取りこぼしは解消しました。ただし、保証名はセレクタの範囲に合わせて限定する必要があります。
2. `two-factor.enable` は今回に含めるのが妥当です。分離や exemption 化は推奨しません。
3. inline bucket検討の4点に重大な穴はありません。追加消費は通常フローでsatisfier POST 1回ですが、passkey等のsatisfierも含め「passwordに限らず選択されたsatisfier 1回」と書く方が正確です。また、素材fetchの二重409による再認証起動の重複は別途防止してください。