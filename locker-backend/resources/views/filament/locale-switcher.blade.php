<div style="display:flex;align-items:center;gap:4px;padding:0 12px;font-size:0.875rem;">
    @foreach(config('app.supported_locales', ['en']) as $i => $loc)
        @if($i > 0)
            <span style="color:#9ca3af;">|</span>
        @endif
        @if($locale === $loc)
            <span style="font-weight:700;color:#111827;">{{ strtoupper($loc) }}</span>
        @else
            @php
                $url = '/' . preg_replace('#^(en|de)(/|$)#', $loc . '$2', request()->path());
            @endphp
            <a href="{{ $url }}" style="font-weight:400;color:#2563eb;text-decoration:none;">{{ strtoupper($loc) }}</a>
        @endif
    @endforeach
</div>
