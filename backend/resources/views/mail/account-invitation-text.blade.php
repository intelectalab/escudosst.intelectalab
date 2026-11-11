Hola, {!! $name !!}:

@if ($url)
Te registraron como {!! $roleName ?: 'administrador de cuenta' !!} en {!! $companyName !!}.

Crea tu contraseña aquí: {!! $url !!}

El enlace vence en {!! $hours !!} horas y sirve una sola vez.@if (! $roleName) Al ingresar por primera vez, configurarás la verificación en dos pasos.@endif

@else
Te dieron acceso como {!! $roleName ?: 'administrador de cuenta' !!} en {!! $companyName !!}. Ingresa con tu correo y contraseña de siempre y elige la empresa.
@endif

Si no esperabas este correo, ignóralo.
— EscudoSST
