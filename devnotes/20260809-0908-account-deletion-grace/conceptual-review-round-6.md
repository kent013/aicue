全体判定: **CHANGES_REQUESTED**

## 使命との整合性

[Warning] 猶予つき削除そのものは North Star に合っています。誤操作で現場資産への到達手段を即時喪失しない、という効果は本質的です。

ただし凍結中 allowlist の `billing.*` / `billing.tickets.*` / `billing.auto-recharge.*` / `onboarding.*` が広すぎます。ブロッカー解消ではなく、購入・契約・自動チャージ設定など「新しい課金状態や消える資産を増やす操作」まで通る可能性があります。

修正提案: allowlist は namespace 単位ではなく route 名単位で、「解約・支払責務の解消・請求確認・移譲」だけに絞る。凍結中に checkout / ticket purchase / auto-recharge enable ができないことを Feature テストで固定してください。

## 禁止事項違反

[Critical] Round 5 の Critical は**完全には解消されていません**。本文にまだ `failClosed` を除外する記述が残っています。

該当箇所:
- §3 観測できる成功条件: `fail-closed 分類を除く`
- §6 公開順序 手順 4: `期限超過件数 0 (failClosed 分類を除く)`

一方で §4-3c と台帳報告条件では「`failClosed` を含む」と書かれており、公開条件が文書内で矛盾しています。このままだと実装者が古い条件を採用し、`failClosed > 0` のまま C3 を公開する失敗シナリオが残ります。

修正提案: 全箇所を **「分類を問わず期限超過件数 0」「failClosed を含む」** に統一してください。特に §3 と §6 の括弧書きを削除または修正する必要があります。

## 実現可能性

[Warning] Laravel 12 + Svelte 5 + Inertia.js で実現可能です。ただし凍結 middleware を `auth + verified` group 全体に付ける設計は、route 一覧との突合が実装難度の中心になります。設計は gate で全件検査するとしており方向性は妥当ですが、allowlist が広いままだと freeze の意味が薄くなります。

修正提案: `AccountDeletionFreezeAllowance` は wildcard を持たず、route name の exact case のみにする方が設計意図と一致します。

## 期待効果の妥当性

[Warning] 「既定導線における誤操作を回復可能にする」という効果は合理的です。ただし即時削除 route を副導線として残す以上、「UI 主導線が本当に予約へ移る」ことを設計上の完了条件に含めるべきです。

修正提案: Svelte 側で即時削除が主ボタンにならないこと、予約導線が primary であることを Browser/Component いずれかのテスト対象に入れてください。禁止事項 8 に従い、条件未充足時は disabled ではなく押下時エラーにする点も明記するとよいです。

## リスク

[Warning] `deleteAccount()` をバッチから再利用する方針は良いですが、既存メソッドが `ValidationException` を UI 契約として使っている場合、Command 側の結果分類が曖昧になるリスクがあります。

修正提案: 詳細設計で `ValidationException` を「業務 blocker」として扱うのか、「想定外失敗」として扱うのかを明示し、Command の exit code テストに入れてください。

[Suggestion] メール通知は at-most-once と明記されており過剰保証は避けられています。送信直前の予約生存確認も妥当です。詳細設計では再予約時の古い job が送られない fixture を必ず入れるとよいです。

## スコープの適切さ

[Warning] 5 PR 分割は妥当ですが、C2 の「schedule はデプロイ時点から有効」と「初回 apply の確認後に C3」の間に、人手運用の失敗余地があります。設計は「デプロイ基盤を作らない」としており、それ自体は AGENTS.md に沿っていますが、C3 PR の事前条件を機械的に見落とさない工夫が弱いです。

修正提案: C3 の PR テンプレートまたは runbook に、初回 apply 結果と horizon 0 の証跡を必須チェック項目として置く、という運用ゲートを明記してください。新しいデプロイ機構は不要です。

## 型安全性

[Suggestion] DTO/enum/interface に寄せる方針は良いです。`BillingRetentionPurgeResultDto` に任意メタデータを持たせない判断も PHPStan level 10 と相性が良いです。

[Suggestion] `isPublicationReady()` は `failClosed == 0 && unexpectedFailures == 0 && expiredRemaining == 0` のように、公開判定に必要な全条件を DTO 内で表現できる形にしてください。現状の 5 フィールドだけだと「期限超過総数」をどこで持つかが詳細設計に残っています。

## 結論

Round 5 の主旨である「`failClosed` を公開条件から除外しない」は設計の中心では正しく修正されていますが、§3 と §6 に逆の文言が残っているため、この最終版はまだ承認できません。

その矛盾を直し、凍結中の課金系 allowlist を route 単位へ絞れば、概念設計としては APPROVED に近い状態です。