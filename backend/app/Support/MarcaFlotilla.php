<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\ConfiguracionTenant;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Logo y banner de la app de clientes de una flotilla (spec tenant/035, RN-60 a RN-62).
 *
 * Los archivos viven en el disco `local` del tenant (stancl lo separa por flotilla), bajo `marca/`,
 * y se sirven por `Cliente\MarcaArchivoController`: la ruta pública `/storage` no alcanza los
 * discos separados por tenant. Cada subida lleva una marca de versión en el nombre, así que la URL
 * cambia con cada imagen nueva y se puede cachear para siempre.
 *
 * Del logo se generan los íconos que usa el teléfono al instalar la app: 512 (el logo mismo), 192 y
 * uno "maskable" con margen, porque Android recorta ese ícono en círculo o en gota.
 *
 * Espera la tenencia ya inicializada.
 */
class MarcaFlotilla
{
    public const LOGO_LADO = 512;

    public const ICONO_CHICO = 192;

    /** Ancho máximo con el que se guarda el banner: la app lo pinta a lo ancho de un teléfono. */
    public const BANNER_ANCHO_MAX = 1600;

    /** Lo que el nombre de un archivo de marca puede ser; la ruta pública no sirve nada más. */
    public const PATRON_ARCHIVO = '(logo|icono-192|maskable)-[a-z0-9]{12}\.png|banner-[a-z0-9]{12}\.(png|jpg|webp)';

    private const CARPETA = 'marca';

    public static function guardarLogo(UploadedFile $archivo): void
    {
        $origen = self::abrir($archivo);
        $version = self::version();

        // Recorte al centro por si el "cuadrado" trae un píxel de más de un lado.
        $lado = min(imagesx($origen), imagesy($origen));
        $x = intdiv(imagesx($origen) - $lado, 2);
        $y = intdiv(imagesy($origen) - $lado, 2);

        $logo = self::lienzo(self::LOGO_LADO, self::LOGO_LADO);
        imagecopyresampled($logo, $origen, 0, 0, $x, $y, self::LOGO_LADO, self::LOGO_LADO, $lado, $lado);
        self::escribirPng("logo-{$version}.png", $logo);

        $chico = self::lienzo(self::ICONO_CHICO, self::ICONO_CHICO);
        imagecopyresampled($chico, $logo, 0, 0, 0, 0, self::ICONO_CHICO, self::ICONO_CHICO, self::LOGO_LADO, self::LOGO_LADO);
        self::escribirPng("icono-192-{$version}.png", $chico);

        // Maskable: fondo blanco y el logo al 80 % para que el recorte del sistema no lo corte.
        $maskable = imagecreatetruecolor(self::LOGO_LADO, self::LOGO_LADO);
        imagefill($maskable, 0, 0, (int) imagecolorallocate($maskable, 255, 255, 255));
        $interior = (int) round(self::LOGO_LADO * 0.8);
        $margen = intdiv(self::LOGO_LADO - $interior, 2);
        imagecopyresampled($maskable, $logo, $margen, $margen, 0, 0, $interior, $interior, self::LOGO_LADO, self::LOGO_LADO);
        self::escribirPng("maskable-{$version}.png", $maskable);

        self::quitarLogo();
        ConfiguracionTenant::establecer(ConfiguracionTenant::APP_LOGO, $version);
    }

    public static function guardarBanner(UploadedFile $archivo): void
    {
        $origen = self::abrir($archivo);
        $version = self::version();
        $extension = match ($archivo->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $ancho = imagesx($origen);
        $alto = imagesy($origen);

        if ($ancho > self::BANNER_ANCHO_MAX) {
            $alto = (int) round($alto * self::BANNER_ANCHO_MAX / $ancho);
            $ancho = self::BANNER_ANCHO_MAX;
        }

        // Se vuelve a codificar siempre, aunque no cambie de tamaño: así se guarda una imagen limpia,
        // sin metadatos (ubicación de la foto incluida) ni nada que no sea la imagen.
        $banner = self::lienzo($ancho, $alto);
        imagecopyresampled($banner, $origen, 0, 0, 0, 0, $ancho, $alto, imagesx($origen), imagesy($origen));

        $nombre = "banner-{$version}.{$extension}";
        $contenido = self::codificar($banner, $extension);
        Storage::disk('local')->put(self::CARPETA.'/'.$nombre, $contenido);

        self::quitarBanner();
        ConfiguracionTenant::establecer(ConfiguracionTenant::APP_BANNER, "{$version}.{$extension}");
    }

    public static function quitarLogo(): void
    {
        $version = ConfiguracionTenant::obtener(ConfiguracionTenant::APP_LOGO);

        if ($version !== null) {
            Storage::disk('local')->delete([
                self::CARPETA."/logo-{$version}.png",
                self::CARPETA."/icono-192-{$version}.png",
                self::CARPETA."/maskable-{$version}.png",
            ]);
        }

        ConfiguracionTenant::establecer(ConfiguracionTenant::APP_LOGO, null);
    }

    public static function quitarBanner(): void
    {
        $valor = ConfiguracionTenant::obtener(ConfiguracionTenant::APP_BANNER);

        if ($valor !== null) {
            Storage::disk('local')->delete(self::CARPETA."/banner-{$valor}");
        }

        ConfiguracionTenant::establecer(ConfiguracionTenant::APP_BANNER, null);
    }

    public static function logoUrl(): ?string
    {
        $version = ConfiguracionTenant::obtener(ConfiguracionTenant::APP_LOGO);

        return $version === null ? null : self::url("logo-{$version}.png");
    }

    public static function bannerUrl(): ?string
    {
        $valor = ConfiguracionTenant::obtener(ConfiguracionTenant::APP_BANNER);

        return $valor === null ? null : self::url("banner-{$valor}");
    }

    /**
     * Los íconos del manifiesto con el logo de la flotilla, o `null` si no ha subido uno.
     *
     * @return array{chico: string, grande: string, maskable: string}|null
     */
    public static function iconos(): ?array
    {
        $version = ConfiguracionTenant::obtener(ConfiguracionTenant::APP_LOGO);

        if ($version === null) {
            return null;
        }

        return [
            'chico' => self::url("icono-192-{$version}.png"),
            'grande' => self::url("logo-{$version}.png"),
            'maskable' => self::url("maskable-{$version}.png"),
        ];
    }

    /** Ruta en el disco del tenant de un archivo de marca, o `null` si el nombre no es válido. */
    public static function ruta(string $nombre): ?string
    {
        return preg_match('/^(?:'.self::PATRON_ARCHIVO.')$/', $nombre) === 1 ? self::CARPETA.'/'.$nombre : null;
    }

    private static function url(string $nombre): string
    {
        return route('cliente.marca.archivo', ['slug' => tenant('slug'), 'archivo' => $nombre]);
    }

    private static function version(): string
    {
        return Str::lower(Str::random(12));
    }

    private static function abrir(UploadedFile $archivo): GdImage
    {
        $imagen = @imagecreatefromstring((string) file_get_contents($archivo->getRealPath()));

        if (! $imagen instanceof GdImage) {
            throw new RuntimeException('No se pudo leer la imagen.');
        }

        return $imagen;
    }

    /** Lienzo con transparencia, para que un PNG sin fondo no salga con fondo negro. */
    private static function lienzo(int $ancho, int $alto): GdImage
    {
        $lienzo = imagecreatetruecolor($ancho, $alto);
        imagealphablending($lienzo, false);
        imagesavealpha($lienzo, true);
        imagefill($lienzo, 0, 0, (int) imagecolorallocatealpha($lienzo, 0, 0, 0, 127));

        return $lienzo;
    }

    private static function escribirPng(string $nombre, GdImage $imagen): void
    {
        Storage::disk('local')->put(self::CARPETA.'/'.$nombre, self::codificar($imagen, 'png'));
    }

    private static function codificar(GdImage $imagen, string $extension): string
    {
        ob_start();

        match ($extension) {
            'png' => imagepng($imagen, null, 6),
            'webp' => imagewebp($imagen, null, 85),
            default => imagejpeg($imagen, null, 85),
        };

        return (string) ob_get_clean();
    }
}
