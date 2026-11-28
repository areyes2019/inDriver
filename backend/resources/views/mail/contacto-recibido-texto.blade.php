Nuevo contacto desde motopanda.mx

Nombre: {{ $contacto->nombre }}
Flotilla o empresa: {{ $contacto->empresa ?: '—' }}
Teléfono: {{ $contacto->telefono }}
Correo: {{ $contacto->email }}
Motos: {{ $contacto->motos ?? '—' }}

Mensaje:
{{ $contacto->mensaje ?: '—' }}

Responde este correo para contestarle directamente.
