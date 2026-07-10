@php
    // GTM head snippet。production かつ container_id 非空の二重ゲートでのみ描画する
    // (判定は CSP 緩和と共有する App\Support\GoogleTagManager に一元化)。
    $gtmId = \App\Support\GoogleTagManager::containerId();
    $gtmEnabled = \App\Support\GoogleTagManager::isEnabled();
@endphp
@if ($gtmEnabled)
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer',@json($gtmId));</script>
@endif
