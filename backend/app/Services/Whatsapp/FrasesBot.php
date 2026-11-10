<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Support\HoraOperacion;
use Illuminate\Support\Arr;

/**
 * Todo lo que dice el bot de WhatsApp (spec tenant/031, D16 y D17).
 *
 * Cada frase tiene 2 o 3 versiones y se elige una al azar, para que un cliente frecuente no lea
 * siempre lo mismo. La IA no redacta: solo estas frases salen hacia el cliente. Las variables van
 * entre llaves, por ejemplo `{nombre}`.
 */
class FrasesBot
{
    /** @var array<string, list<string>> */
    private const FRASES = [
        'bienvenida' => [
            "Claro que sí, con gusto te mando una moto 🏍️\n\nPásame estos datos:\n• ¿Quién recibe? (nombre y teléfono)\n• ¿A dónde se lo llevamos?\n• ¿Dónde lo recogemos?\n• ¿Quién paga el envío, tú o quien recibe?",
            "¡Con mucho gusto te ayudo con tu envío! 🏍️\n\nNomás dime:\n• Nombre y teléfono de quien recibe\n• Dirección de entrega\n• Dirección donde recogemos\n• Quién paga el envío",
        ],
        'anotado' => ['Muy bien.', 'Perfecto.', '¡Listo!', 'Va.'],

        'destinatario_nombre' => [
            '¿Cómo se llama la persona que recibe el paquete?',
            '¿A nombre de quién va el paquete?',
            '¿Quién lo va a recibir?',
        ],
        'entrega' => [
            '¿En qué calle y número vive {nombre}, y en qué colonia?',
            '¿A qué dirección se lo llevamos a {nombre}? Calle, número y colonia, porfa.',
            '¿Y la dirección de {nombre}? Con calle, número y colonia.',
        ],
        'entrega_sin_nombre' => [
            '¿A qué dirección lo llevamos? Calle, número y colonia, porfa.',
            '¿En qué calle, número y colonia lo entregamos?',
        ],
        'destinatario_telefono' => [
            '¿A qué número le marcamos a {nombre} si hace falta?',
            '¿Me pasas el teléfono de {nombre}, por si el repartidor necesita llamarle?',
        ],
        'telefono_invalido' => [
            'Ese teléfono no me sale completo. ¿Me lo pasas a 10 dígitos?',
            'Creo que al teléfono le faltan números. ¿Me lo das a 10 dígitos, porfa?',
        ],
        'recogida' => [
            '¿Y dónde pasamos por el paquete? Calle, número y colonia.',
            '¿De dónde lo recogemos? Calle, número y colonia, porfa.',
        ],
        'recogida_ofrecida' => [
            '¿Lo recogemos en {direccion}, como la vez pasada?',
            '¿Pasamos por él a {direccion}, como la última vez?',
        ],
        'direccion_imprecisa' => [
            'No encontré bien «{texto}» en el mapa. ¿Me la pasas con calle, número y colonia?',
            'Mmm, «{texto}» no me sale exacta. ¿Me das calle, número y colonia, porfa?',
        ],
        'fuera_de_cobertura' => [
            'Uy, {direccion} queda fuera de la zona donde damos servicio. ¿Tienes otra dirección?',
            'Perdón, hasta {direccion} no llegamos todavía. ¿Hay otra dirección que nos sirva?',
        ],
        'quien_paga' => [
            '¿Quién paga el envío, tú o {nombre}?',
            '¿El envío lo pagas tú o lo paga {nombre} al recibir?',
        ],
        'cobro_producto' => [
            '¿Hay que cobrarle algo a {nombre} además del envío? Por ejemplo, el precio de un producto.',
            '¿El repartidor le cobra algo a {nombre}, aparte del envío? Por ejemplo, un producto.',
        ],
        'importe_producto' => [
            '¿Cuánto hay que cobrarle a {nombre} por el producto?',
            '¿De cuánto es el producto que le cobramos a {nombre}?',
        ],
        'remitente_con_cobro' => [
            'Cuando tú pagas el envío no podemos cobrarle un producto a quien recibe por aquí. ¿Mejor que {nombre} pague el envío junto con el producto?',
        ],
        'cliente_nombre' => [
            'Ya casi. ¿Cómo te llamas, para anotarte?',
            'Una última cosa: ¿a nombre de quién anoto el pedido?',
        ],
        'cliente_telefono' => [
            '¿Me pasas tu teléfono a 10 dígitos, para anotarte?',
        ],

        'resumen' => [
            'Así quedaría tu envío:',
            'Te dejo el resumen de tu envío:',
        ],
        'resumen_pregunta' => [
            '¿Lo confirmo?',
            '¿Todo bien? Si sí, lo confirmo.',
        ],
        'que_cambiamos' => [
            'Claro, ¿qué cambiamos?',
            'Va, dime qué cambio.',
        ],
        'pulsa_boton' => [
            'Para seguir, elige una opción en el resumen: Confirmar, Corregir o Cancelar.',
        ],
        'confirmado' => [
            "¡Listo! Tu envío quedó como {folio} ✅\nYa estamos buscando una moto.",
            "¡Confirmado! Tu folio es {folio} ✅\nEn un momento sale una moto.",
        ],
        'ya_confirmado' => [
            'Ese envío ya quedó confirmado como {folio} ✅',
        ],
        'cancelado' => [
            'Listo, lo cancelé. Si necesitas otro envío, aquí estoy 🙂',
            'Va, quedó cancelado. Cuando quieras mandar algo, escríbeme.',
        ],
        'sin_pendiente' => [
            'No tengo ningún envío pendiente tuyo. Si quieres mandar algo, cuéntame 🙂',
        ],
        'servicio_no_disponible' => [
            'Perdón, por ahora no estamos tomando envíos por aquí. {contacto}',
        ],
        'cliente_inactivo' => [
            'Por ahora no puedo tomarte envíos por aquí. {contacto}',
        ],
        'persona' => [
            'Claro, ya le aviso a alguien de la flotilla para que te conteste por aquí.',
            'Va, en un momento te escribe una persona de la flotilla por aquí.',
        ],
        'persona_urgente' => [
            ' Si es urgente, llama al {telefono}.',
        ],
        'sin_precio' => [
            'Ahorita no puedo calcular el precio de ese envío. {contacto}',
        ],
        'ia_fallo' => [
            'Perdón, no te entendí bien. ¿Me lo dices de otra forma?',
            'Uy, no alcancé a entenderte. ¿Me lo repites, porfa?',
        ],
        'solo_texto' => [
            'Por ahora solo leo mensajes de texto y ubicaciones. ¿Me lo escribes, porfa?',
        ],
        'resumen_avisos' => [
            'Te aviso por aquí cómo va tu envío. Si no quieres avisos, escribe BAJA.',
        ],
        'baja' => [
            'Listo, ya no te mandaremos avisos de tus envíos. Si los quieres de nuevo, escribe ALTA.',
        ],
        'alta' => [
            '¡Listo! Te volveremos a avisar por aquí cómo van tus envíos.',
        ],

        // Avisos de estado (RN-47).
        'aviso_asignado' => [
            '🏍️ ¡Ya tenemos moto! {repartidor} va por el paquete de tu envío {folio}.',
            '🏍️ {repartidor} ya va por el paquete de tu envío {folio}.',
        ],
        'aviso_en_camino' => [
            '📦 Tu envío {folio} ya va en camino con {destinatario}.',
            '📦 ¡Va en camino! Tu envío {folio} ya salió rumbo a {destinatario}.',
        ],
        'aviso_entregado' => [
            '✅ Tu envío {folio} ya fue entregado. ¡Gracias!',
            '✅ ¡Entregado! Tu envío {folio} ya llegó con {destinatario}.',
        ],
        'aviso_cancelado' => [
            'Tu envío {folio} se canceló. {contacto}',
        ],
        'aviso_incidencia' => [
            'Hubo un problema con tu envío {folio}. {contacto}',
        ],
        'aviso_en_regreso' => [
            '{destinatario} no pagó, así que el repartidor te regresa el paquete del envío {folio}. Al recibirlo le pagas {total}.',
        ],
        'aviso_devuelto' => [
            'El paquete de tu envío {folio} ya te fue devuelto.',
        ],

        'contacto_con_telefono' => [
            'Puedes hablar con una persona de la flotilla al {telefono}.',
        ],
        'contacto_sin_telefono' => [
            'Por ahora no hay una persona disponible por este medio; comunícate directamente con la flotilla.',
        ],
    ];

    /**
     * @param  array<string, string>  $variables
     */
    public function decir(string $clave, array $variables = []): string
    {
        $versiones = self::FRASES[$clave];
        $frase = config('services.whatsapp.frases_al_azar', true) ? Arr::random($versiones) : $versiones[0];

        return strtr($frase, array_combine(
            array_map(fn (string $nombre) => '{'.$nombre.'}', array_keys($variables)),
            array_values($variables),
        ) ?: []);
    }

    /** RN-36: el saludo según la hora de la flotilla. */
    public function saludo(): string
    {
        $hora = (int) now()->setTimezone(HoraOperacion::zona())->format('G');

        return match (true) {
            $hora >= 5 && $hora < 12 => '¡Hola! Buenos días.',
            $hora >= 12 && $hora < 19 => '¡Hola! Buenas tardes.',
            default => '¡Hola! Buenas noches.',
        };
    }

    public function contacto(?string $telefonoFlotilla): string
    {
        return $telefonoFlotilla !== null && $telefonoFlotilla !== ''
            ? $this->decir('contacto_con_telefono', ['telefono' => $telefonoFlotilla])
            : $this->decir('contacto_sin_telefono');
    }
}
