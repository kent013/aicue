# 対応マトリクス: conceptual-review Round 3

Codex 判定: **CHANGES_REQUESTED** ([Critical] 2 / [Warning] 3 / [Suggestion] 3)。
`UnknownApiErrorException` の 5xx 分類・`unknown` 禁止 gate・AGENTS.md 追記は
いずれも承認された。残る 2 Critical は「決めた判断を型と gate で矛盾なく表現する」修正であり、
全件そのまま採用した (反論なし)。

## [Critical] `map()` の型では条件付き規則を表現できない

- 判断: **対応する (Codex の修正案をそのまま採る)**
- 根拠: 指摘は正しい。`array<class-string<Throwable>, GatewayFailureClass>` の 1 本に
  `UnknownApiErrorException` を入れると、値がダミー (実際の分類規則を表さない) になる。
  外せば母集団との集合一致が壊れる。**「正本」を名乗る構造が嘘をつく**状態だった。
- 対応内容: API を 2 本に分割した。
  ```php
  /** 直接写像 (class → case) @return array<class-string<Throwable>, GatewayFailureClass> */
  public static function directMap(): array;

  /** 条件付き規則を持つクラス @return list<class-string<Throwable>> */
  public static function conditionalClasses(): array;
  ```
  gate が固定する集合契約 (Codex の提示どおり + 母集団との厳密一致を追加):
  - `keys(directMap) ∩ conditionalClasses = ∅`
  - `vendor 具象クラス集合 ⊆ keys(directMap) ∪ conditionalClasses`
  - `(keys(directMap) ∪ conditionalClasses) \ vendor 具象クラス集合 = framework 明示宣言集合`
    (この 2 本で**全体の集合一致**になる。vendor に無いものを勝手に足せない)
  - `conditionalClasses === [UnknownApiErrorException::class]` (**件数ではなくクラス同一性**で固定)
  - `directMap` の値に `GatewayFailureClass::Unknown` が現れない

## [Critical] 「全 case に fixture」と「unknown は写像不在専用」が両立していない

- 判断: **対応する (Codex の修正案をそのまま採る)**
- 根拠: 指摘は正しく、しかも自己矛盾だった。実ライブラリ例外を `unknown` の fixture に選ぶと、
  その例外は「意図的に未分類のまま置かれた vendor 例外」になり、
  vendor 全件分類の gate と真正面から衝突する。
  そもそも `unknown` は「本物と同じ例外を再現する分類」ではなく、
  **分類器の全域性を守る fallback** であり、fake/real 一致契約の対象にする意味が無い。
- 対応内容:
  - fake/real 一致 (parity) の対象を**業務分類 4 case** に限定した
    (`provider_unavailable` / `provider_rejected` / `invariant_violation` / `local_failure`)。
  - gate は「fixture の case 集合 == `GatewayFailureClass` の全 case − `Unknown`」を
    exact fit で固定する (case を足しても減らしても赤くなる)。
  - 「fixture が実ライブラリ名前空間のクラスを返す」条件も 4 case に限定。
  - spy の失敗注入からも `unknown` を外す (spy が未分類例外を投げる必要が無い)。
  - `unknown` の固定は**分類器の Unit テスト**が担当し、専用のテスト例外
    `Tests\Support\Billing\UnmappedGatewayFailureForTest` を使う
    (vendor クラスを未分類のまま使わない = gate と衝突しない)。

## [Warning] 「5xx 以外は再送で収束しない」は強すぎる / `0` や null の扱い

- 判断: **対応する**
- 根拠: 妥当。HTTP status だけから「再送で収束しない」を断定はできない。
  また `Stripe\Exception\ApiErrorException::getHttpStatus()` は
  vendor の PHPDoc が `@return null|int` (戻り型宣言なし) であり、**null がありうる**。
- 対応内容:
  - 表から `0` を削除し、条件を `>= 500` / **それ以外 (null を含む)** に整理。
  - 文言を「**運用上の保守的分類**であり、再送可能性の完全な意味判定ではない」と明記。
  - **null は `provider_rejected`** に倒すことを設計で確定した。根拠:
    分類は制御フローを変えないので「安全側」とは「運用を誤誘導しない側」であり、
    status 不明のときに `provider_unavailable` (= 待てば直る) と言うのは
    **無行動を示唆する誤誘導**になる。`provider_rejected` は「調べる」を促すので害が小さい。
    実際には `_specificV1APIError()` が必ず `$rcode` を渡すため null は防御的分岐である。
  - 境界テスト (499 / 500 / null) を Unit テスト計画へ追加した。

## [Warning] 特別規則の exact-fit はクラス同一性まで固定せよ

- 判断: **対応する** (上記 Critical 1 の集合契約に
  `conditionalClasses === [UnknownApiErrorException::class]` として吸収済み)

## [Warning] 条件付き規則を型構造に反映 / `getHttpStatus()` の null 明示処理

- 判断: **対応する**
- 対応内容: Critical 1 の API 分割で型構造に反映。null 処理は Codex 提示の形
  (`$status !== null && $status >= 500` の早期 return) を概念設計に擬似コードとして記載した。

## [Suggestion] 使命 / 禁止事項 / スコープ

- 判断: 変更不要 (すべて肯定的評価)。
