{{-- Embed the same Arabic typeface in every document. PDF rendering must not
     wait for Google Fonts or a second request to this application's web server. --}}
@foreach ([400 => 'Regular', 500 => 'Medium', 700 => 'Bold', 800 => 'ExtraBold'] as $weight => $style)
@font-face {
    font-family: 'Tajawal';
    font-style: normal;
    font-weight: {{ $weight }};
    src: url('data:font/ttf;base64,{{ base64_encode(file_get_contents(resource_path("fonts/tajawal/Tajawal-{$style}.ttf"))) }}') format('truetype');
}
@endforeach
