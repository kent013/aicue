<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 組織識別名の予約語 (家系裁定 AG-039 / 不変条件 I9・I10・I11)
|--------------------------------------------------------------------------
|
| **この docblock が「予約語を増やすときの運用契約」の唯一の正本である。**
| docs/app-integration-guide.md には契約文を複写せず参照だけを書く
| (2 か所に書くと必ず食い違う)。
|
| ## 運用契約 (初版だけの義務ではない)
|
| > 予約語一覧を追加・変更する変更は、**既存組織の識別名との衝突を検査する
| > migration (または同等のデプロイ前検査) を同じ変更に含め、衝突があれば
| > fail-closed で止める。**
|
| 固定 route を足して予約語を増やす変更が、既存組織の URL を黙って壊す経路になるのを防ぐ。
|
| ## 保証範囲を誇張しない
|
| **この運用契約は機械では強制しない** (config に語を足すだけで検査が走る仕組みは持たない)。
| 人がレビュー時に適用する運用契約である。機械が見るのは次の 2 つだけ:
|
| - `OrganizationSlugReservedWordsInvariantTest` — 識別名と**同じ位置**
|   (`/organizations/` 直下の第 2 セグメント) に現れる静的セグメントが
|   すべて `route_conflict` として登録されていること
| - `OrganizationSlugReservedWords::load()` — 分類の無い語・未知の分類・
|   構文違反の語で読み込みが落ちること (fail-closed)
|
| `authority_impersonation` / `syntax_conflict` の語は route 表から導けないので、
| **本ファイルが唯一の正本**である (機械検査は「登録漏れ」ではなく分類の妥当性だけを見る)。
|
| ## 理由の 3 分類 (App\Enums\Organization\SlugReservationReason)
|
| - `route_conflict`           … 識別名と同じ位置の静的セグメントと同名になる
| - `authority_impersonation`  … 運営・管理・支援を騙れる語
| - `syntax_conflict`          … URL・DNS・予約識別子として解釈がぶれる語
|
*/

return [
    /*
     * キーが予約語 (構文型を通るので小文字英数字とハイフンのみ)、値が理由の分類。
     * 分類の無い語・未知の分類は読み込み時に落ちる (deny-by-default)。
     */
    'words' => [
        // ルート衝突: /organizations/create (organizations.create) と同じ位置
        'create' => 'route_conflict',

        // 権威の詐称 (初版は最小集合にとどめる。厚くしすぎると正当な組織名が取れない)
        'admin' => 'authority_impersonation',
        'administrator' => 'authority_impersonation',
        'root' => 'authority_impersonation',
        'staff' => 'authority_impersonation',
        'support' => 'authority_impersonation',
        'system' => 'authority_impersonation',
        'official' => 'authority_impersonation',

        // 構文衝突: URL / DNS / 予約識別子として解釈がぶれる語
        'www' => 'syntax_conflict',
        'api' => 'syntax_conflict',
        'null' => 'syntax_conflict',
        'undefined' => 'syntax_conflict',
    ],
];
