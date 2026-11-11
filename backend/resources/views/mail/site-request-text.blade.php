{!! in_array(mb_strtolower(trim($name)), ['', 'hola'], true) ? 'Hola:' : 'Hola, '.$name.':' !!}

{!! $title !!}

{!! $intro !!}

@foreach ($rows as [$label, $value])
- {!! $label !!}: {!! $value !!}
@endforeach

@if ($note)
{!! $note !!}

@endif
@if ($buttonUrl)
{!! $buttonText !!}: {!! $buttonUrl !!}

@endif
— EscudoSST
