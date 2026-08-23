<?php

declare(strict_types=1);

namespace Tests\Support\LegacyUrl;

/**
 * 旧 URL 検出の許可区分。
 *
 * ★区分は**限定列挙**であり、**それぞれが機械で確かめられる前提を持つ**
 *   (`LegacyUrlAllowance::preconditionViolation()` が区分ごとに検査する)。
 *   前提を持たない区分は「説明ラベル」にすぎず、走査器共通規約 (d)
 *   「集めた走査結果を判定に使わない形を作らない」に触れる。
 * ★新しい区分を足す操作そのものがレビューに見えることが目的なので、
 *   区分を増やすときは**前提の検査も同じ変更で書く**。
 */
enum LegacyUrlAllowanceKind: string
{
    /**
     * **正規の分岐入口** (`capture.entry`) としての出現。
     *
     * 前提: 一致した語が撮影 PWA の根そのものであり、かつ route 表の `capture.entry` の
     * URI がその語と一致すること (入口が動いたら登録ごと赤くなる)。
     */
    case CanonicalCaptureEntry = 'canonical_capture_entry';

    /**
     * URL ではなく**リポジトリ内のディレクトリのパス**である。
     *
     * 前提: 一致した語をリポジトリルートからの相対パスとして解決したとき、
     * **実在するディレクトリ**であること (`/app` → `app/`)。
     */
    case FilesystemPath = 'filesystem_path';

    /**
     * URL ではなく**オブジェクトストレージの鍵**である。
     *
     * 前提: 同じファイルに鍵の接頭辞 (`LegacyUrlAllowance::STORAGE_KEY_MARKERS`) が現れること。
     */
    case StorageObjectKey = 'storage_object_key';

    /**
     * 撤去したものが**もう無いこと自体を説明している**記述。
     *
     * 前提: 同じファイルに撤去の語 (`LegacyUrlAllowance::REMOVAL_MARKER`) が現れること。
     */
    case AbsenceAssertion = 'absence_assertion';

    /**
     * **組織相対パス**として宣言された値で、組織 prefix は利用側が付ける。
     *
     * 前提: 登録が名指しした**利用側のファイル**が実在し、そこに組織 URL 組み立ての入口
     * (`LegacyUrlScanner::ORGANIZATION_URL_MODULE` の関数) が現れること。
     * 利用側を書かない登録は作れない (「なんとなく直せない」を入れる口を塞ぐ)。
     */
    case OrganizationRelativePath = 'organization_relative_path';
}
