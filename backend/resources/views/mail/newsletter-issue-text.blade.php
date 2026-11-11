{!! $subjectLine !!}

@foreach ($blocks as $b)
@if ($b['t'] === 'h')
{!! mb_strtoupper($b['text']) !!}

@elseif ($b['t'] === 'ul')
@foreach ($b['items'] as $it)
- {!! $it !!}
@endforeach

@else
{!! $b['text'] !!}

@endif
@endforeach
@if ($legalNote)
Información referencial; no reemplaza la lectura de la norma ni la asesoría legal.
@endif

Ver en el navegador: {!! $webUrl !!}
Darme de baja: {!! $unsubscribeUrl !!}
