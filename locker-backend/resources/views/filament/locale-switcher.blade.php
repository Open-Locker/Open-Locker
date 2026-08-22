<div style="display:flex;align-items:center;gap:4px;font-size:0.875rem;justify-content:{{ ($center ?? false) ? 'center' : 'flex-start' }};padding:{{ ($center ?? false) ? '20px 0 0' : '0 12px' }};">
    @foreach(config('app.supported_locales', ['en']) as $i => $loc)
        @if($i > 0)
            <span style="color:#9ca3af;">|</span>
        @endif
        @if(app()->getLocale() === $loc)
            <span style="font-weight:700;color:#111827;">{{ strtoupper($loc) }}</span>
        @else
            <a href="{{ route('locale.switch', ['locale' => $loc]) }}" style="font-weight:400;color:#2563eb;text-decoration:none;">{{ strtoupper($loc) }}</a>
        @endif
    @endforeach
</div>
