@php
    // GTM noscript。head 側と同じ二重ゲート (production かつ container_id 非空)。
    $gtmId = \App\Support\GoogleTagManager::containerId();
    $gtmEnabled = \App\Support\GoogleTagManager::isEnabled();
@endphp
@if ($gtmEnabled)
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ rawurlencode($gtmId) }}"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
@endif
