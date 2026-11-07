Hola, {{ $name }}:

@if ($url)
El equipo de EscudoSST te registró como administrador de cuenta de {{ $companyName }}.

Crea tu contraseña aquí: {{ $url }}

El enlace vence en {{ $hours }} horas y sirve una sola vez. Al ingresar por primera vez, configurarás la verificación en dos pasos.
@else
El equipo de EscudoSST te dio acceso como administrador de cuenta de {{ $companyName }}. Ingresa con tu correo y contraseña de siempre y elige la empresa.
@endif

Si no esperabas este correo, ignóralo.
— EscudoSST
