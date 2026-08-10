@extends('legal.layout')

{{-- プレースホルダのプライバシーポリシースタブ。アプリ初期化時に正式な文面へ差し替えること。 --}}
@section('title', 'プライバシーポリシー')

@section('main')
    <div data-testid="legal-privacy">
        <h1>プライバシーポリシー</h1>
        <p class="updated">最終改定日: （アプリ公開時に記入）</p>

        <p>
            本ページは <strong>{{ config('app.name') }}</strong>（以下「本サービス」）における
            個人情報の取り扱いに関するプレースホルダです。アプリ公開前に、実際の取得項目・利用目的・
            第三者提供・保管期間等に合わせて正式な文面へ差し替えてください。
        </p>

        <h2>1. 取得する情報</h2>
        <p>アカウント登録情報（メールアドレス・氏名等）、サービス利用に伴い自動的に生成されるログ情報等。</p>

        <h2>2. 利用目的</h2>
        <p>本サービスの提供・運用・改善、本人確認、不正利用の防止、およびお問い合わせへの対応のために利用します。</p>

        <h2>3. 第三者提供</h2>
        <p>法令に基づく場合を除き、本人の同意なく個人情報を第三者に提供しません。</p>

        {{--
            保有期間の節。**この文面は法務レビュー前の草案である。**
            家系の先例 (spirux の /privacy「取引関係書類等につき最長 N 年」) に揃えたもので、
            独自の法的主張を書き起こしていない。「実装が宣言する年数」と「法務が確定する年数」が
            一致することの確認は**人間の仕事**であり、確定時は config/legal.php の
            billing_retention_years と本文面を同じ PR で更新すること
            (config/legal.php の consent_version は本追記では draft-1 から動かさない。
             版の確定はリリース時のオーナー判断)。

            年数は literal で書かず App\Support\Legal\BillingRetention::years() から描画する
            (config / SSOT / 文面の三者一致。BillingRetentionConfigSingleSourceTest 検査 7 が固定)。
            data-legal-retention 属性は機械照合のマーカーで、見出し番号ではなくこの属性と
            固定文言「取引関係書類等」で照合する (節の並べ替え・番号の繰り下げに耐えるため。
            PrivacyRetentionDeclarationTest)。
        --}}
        <h2 id="retention">4. 保有期間</h2>
        <p data-legal-retention="billing-records">
            当社は、取得した個人情報を利用目的の達成に必要な期間に限り保有し、
            当該期間の経過後は遅滞なく消去または匿名化します。ただし、
            <strong>ご契約およびお支払いに関する取引関係書類等については、
            法令に定める保存期間に従い、取引の終了時から最長{{ \App\Support\Legal\BillingRetention::years() }}年間</strong>
            保有します。
        </p>
        <p>
            保有期間の起算点は取引の終了時（ご契約の終了日、お支払いの確定日等）です。
            継続中のご契約に関する記録は、当該契約が終了するまで保有します。
        </p>

        <h2>5. 開示・訂正・削除</h2>
        <p>利用者は自己の個人情報の開示・訂正・削除を請求できます。手続きはお問い合わせフォームよりご連絡ください。</p>

        <p style="margin-top:24px;">
            ご不明点は<a href="{{ route('contact') }}">お問い合わせフォーム</a>よりご連絡ください。
        </p>
    </div>
@endsection
