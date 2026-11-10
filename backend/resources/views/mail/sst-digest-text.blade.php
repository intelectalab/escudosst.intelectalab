Hola, {{ $name }}:

Alertas SST de {{ $company }} que requieren tu atención:

@foreach($items as $it)
- {{ $it['title'] }}: {{ $it['body'] }}
  {{ $it['url'] }}
@endforeach

Ir al inicio: {{ $url }}
— EscudoSST
