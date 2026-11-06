Hola, {{ $name }}:

{{ $title }}

{{ $intro }}

@foreach ($rows as [$label, $value])
- {{ $label }}: {{ $value }}
@endforeach

@if ($note)
{{ $note }}

@endif
@if ($buttonUrl)
{{ $buttonText }}: {{ $buttonUrl }}

@endif
— EscudoSST
