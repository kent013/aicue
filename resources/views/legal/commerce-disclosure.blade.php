@extends('legal.layout')

{{-- プレースホルダの特定商取引法に基づく表記スタブ。アプリ初期化時に事業者情報へ差し替えること。 --}}
@section('title', '特定商取引法に基づく表記')

@section('main')
    <div data-testid="legal-commerce-disclosure">
        <h1>特定商取引法に基づく表記</h1>
        <p class="updated">本ページはプレースホルダです。有償サービスを提供する場合は事業者情報を記入してください。</p>

        <dl>
            <div class="row"><dt>販売事業者</dt><dd>（事業者名を記入）</dd></div>
            <div class="row"><dt>運営統括責任者</dt><dd>（責任者名を記入）</dd></div>
            <div class="row"><dt>所在地</dt><dd>請求があったら遅滞なく開示します。</dd></div>
            <div class="row"><dt>連絡先</dt><dd><a href="{{ route('contact') }}">お問い合わせフォーム</a>よりご連絡ください。</dd></div>
            <div class="row"><dt>販売価格</dt><dd>各プランの詳細は<a href="{{ route('pricing') }}">料金ページ</a>をご参照ください。</dd></div>
            <div class="row"><dt>商品代金以外の必要料金</dt><dd>なし（消費税は表示価格に含みます）。</dd></div>
            <div class="row"><dt>支払方法</dt><dd>クレジットカード（Stripe による決済）。</dd></div>
            <div class="row"><dt>支払時期</dt><dd>お申し込み時、および以降は更新日に課金されます。</dd></div>
            <div class="row"><dt>役務の提供時期</dt><dd>決済完了後、即時にご利用いただけます。</dd></div>
            <div class="row"><dt>返品・解約について</dt><dd>サブスクリプションは請求管理画面からいつでも解約できます。役務の性質上、提供済み期間の返金は行いません。</dd></div>
        </dl>
    </div>
@endsection
