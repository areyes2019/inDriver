<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Contraseña que el sistema genera al dar de alta un usuario (spec 011 y spec tenant/001): la
 * única que el usuario recibe por correo en texto plano, así que tiene que sobrevivir intacta al
 * viaje hasta su bandeja de entrada.
 *
 * No se usa `Str::password()` tal cual porque su juego de símbolos incluye `\`, y las líneas de un
 * `MailMessage` se renderizan como Markdown: una diagonal invertida antes de un signo de
 * puntuación es un escape, y el correo entrega la contraseña con ese carácter de menos. El usuario
 * copia lo que ve, `Hash::check()` falla, y el alta parece un usuario que "no está registrado".
 *
 * Aquí la contraseña es letras, números y exactamente un símbolo de `SIMBOLOS`: sin diagonales
 * (`\` ni `/`), y sin ningún carácter que Markdown o el escapado HTML del correo transformen.
 */
final class PasswordGenerada
{
    public const LARGO = 16;

    /**
     * Símbolos que atraviesan sin cambios tanto el Markdown del correo como el escapado HTML.
     *
     * Quedan fuera `\` y `/` (diagonales), `*` `_` `~` `` ` `` `[` `]` (marcas de Markdown),
     * y `&` `<` `>` (se convierten en entidades HTML).
     *
     * @var array<int, string>
     */
    public const SIMBOLOS = ['!', '@', '#', '$', '%', '?', '+', '=', '(', ')'];

    public static function generar(int $largo = self::LARGO): string
    {
        // `symbols: false` deja solo letras y números, garantizando al menos uno de cada uno.
        $base = Str::password($largo - 1, letters: true, numbers: true, symbols: false, spaces: false);

        $simbolo = self::SIMBOLOS[random_int(0, count(self::SIMBOLOS) - 1)];
        $posicion = random_int(0, strlen($base));

        return substr($base, 0, $posicion).$simbolo.substr($base, $posicion);
    }
}
