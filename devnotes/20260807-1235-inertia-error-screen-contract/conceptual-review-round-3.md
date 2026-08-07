全体判定: **CHANGES_REQUESTED**

Round 2 の主要な設計課題は解消されています。特に version による配備境界は二段階配備より恒久契約として扱いやすく、方向性は妥当です。ただし、version が nullable である場合の deny-by-default 条件と、設計本文内の撤回漏れを修正する必要があります。

## 1. 使命との整合性

[Suggestion] North Star との整合性は十分です。

旧タブに対して無理に Error component を返さず、現状の Blade モーダルへフォールバックする判断も「今日より悪くしない」という成功条件に沿っています。

## 2. 禁止事項違反

[Suggestion] 明示的な禁止事項違反はありません。

非空 CTA、disabled 状態の禁止、DTO 経由の props、テストと Architecture gate の計画まで設計されています。

## 3. 実現可能性

[Warning] `X-Inertia-Version` と現在 version の nullable な比較条件が未定義です。

`HandleInertiaRequests::version()` は `?string` を返します。単純な厳密比較でも、双方が `null` なら一致と判定され得ます。しかし `null === null` は「同じ build を使っている」証明になりません。versioning が無効、manifest が利用不能、またはテスト環境で version が生成されない場合、配備境界が空洞化します。

修正提案:

- request version と current version の双方が、空でない文字列の場合だけ比較する
- 片方でも `null` または空文字なら差し替えない
- 比較条件を次の意味に固定する:

```php
is_string($requestVersion)
    && $requestVersion !== ''
    && is_string($currentVersion)
    && $currentVersion !== ''
    && $requestVersion === $currentVersion
```

- Feature テストへ次のケースを追加する:
  - request version 欠落
  - current version が `null`
  - request version が空文字
  - 一致
  - 不一致

[Warning] version 解決自体の失敗が fail-safe の外に出る可能性があります。

`app(HandleInertiaRequests::class)->version($request)` が manifest 参照などで例外を出した場合も、元の Blade response を返す必要があります。現在の型境界では `toResponse()` の try/catch は明記されていますが、適用判定全体を包むかが不明確です。

修正提案:

- version 取得、DTO 生成、route 解決、`toResponse()`、ヘッダ移植までを renderer の try/catch 内へ含める
- どの段階の `Throwable` でも `null` を返す契約にする
- version resolver が throw した場合に原 response が完全一致で残るテストを追加する

## 4. 期待効果の妥当性

[Warning] eager 化の gate は、ソース上の glob 存在だけでなく生成物の性質を検査すべきです。

eager glob と通常 glob の両方に `Error.svelte` が含まれます。Vite が静的 import として初期 bundle に統合する想定は妥当ですが、「コードに `{ eager: true }` がある」だけでは、Error が独立 chunk に戻っていないことまで保証できません。

修正提案:

- JS Architecture テストの成功条件を「eager glob の文字列がある」だけにしない
- `pnpm build` 後の manifest または bundle graph で、Error が独立した遅延 entry ではないことを検査する
- 少なくとも resolver の eager map が同期 component を返し、lazy loader を呼ばない単体テストを置く

## 5. リスク

[Warning] `Retry-After` の本文解釈とヘッダ移植の関係が曖昧です。

現在の記述では、不正形式や HTTP-date は本文・API details から除外する一方、原ヘッダは無条件に新 response へ移植すると読めます。これは必ずしも不正ではありませんが、「Retry-After の解釈を SoT に一本化」という説明とは一致しません。

修正提案:

次のどちらかを明示的に選んでください。

- 原 response のプロトコル情報として、値にかかわらずヘッダを保持する
- `RetryAfterSeconds::parse()` が成功した場合だけ、正規化した整数値をヘッダへ設定する

Laravel の正規発行経路だけを契約対象にするなら、後者の方が本文、API details、HTTP ヘッダの三者を同じ SoT にできます。

## 6. スコープの適切さ

[Warning] 撤回済み変更が実装方針表に残っています。

実装方針にまだ次の行があります。

> 変更 | `tests/Feature/Security/TenantBoundaryPrecedenceTest.php` | 応答比較を「入力 id を正規化してから」に拡張

後続節では「変更しない」「正規化は撤回」としており、設計が矛盾しています。

修正提案:

- 実装方針表から当該行を削除する
- 対応マトリクスと「既存テストへの波及」の記述だけを残す

同様に、以下の古い表現も修正してください。

- 待ち時間節の「寄せても (c) の実挙動は変わらない」
- スコープ外 (c) の「実挙動は不変」

いずれも「正規発行経路では不変、不正形式は意図的に厳格化」と統一する必要があります。

## 7. 型安全性

[Suggestion] 型境界は概念設計として十分な具体度になりました。

`non-empty-list`、array shape、非負整数、Symfony response への変換まで固定されています。実装時は `RetryAfterSeconds::parse(mixed): ?int` の戻り値が `int<0,max>|null` と推論されるよう、負数除外後の戻り経路をPHPStanに認識させる必要があります。

version の `null`/空文字を明示的に不一致とし、判定全体の fail-safe、eager の生成物検査、本文の撤回漏れを直せば APPROVED と判断できます。