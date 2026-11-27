/**
 * Almacenamiento en el teléfono por flotilla (spec tenant/035, RN-09). Todas las llaves llevan el
 * slug, así que dos flotillas instaladas desde motopanda.mx nunca leen ni borran lo de la otra.
 *
 * Cada acceso va protegido: en modo privado o con el sitio bloqueado el navegador lanza error, y la
 * app debe seguir funcionando, solo sin recordar.
 */

export type LlaveAlmacen =
  'token' | 'instalar-ofrecido' | 'continuar-navegador' | 'borrador' | 'avisos-pedidos'

interface AlmacenBasico {
  getItem(llave: string): string | null
  setItem(llave: string, valor: string): void
  removeItem(llave: string): void
  readonly length: number
  key(indice: number): string | null
}

const PREFIJO = 'pide'

export function llaveDe(slug: string, llave: LlaveAlmacen): string {
  return `${PREFIJO}:${slug}:${llave}`
}

export function crearAlmacen(slug: string, almacen: AlmacenBasico | null = navegador()) {
  return {
    leer(llave: LlaveAlmacen): string | null {
      try {
        return almacen?.getItem(llaveDe(slug, llave)) ?? null
      } catch {
        return null
      }
    },

    leerJson<T>(llave: LlaveAlmacen): T | null {
      const texto = this.leer(llave)
      if (texto === null) return null
      try {
        return JSON.parse(texto) as T
      } catch {
        return null
      }
    },

    guardar(llave: LlaveAlmacen, valor: string): void {
      try {
        almacen?.setItem(llaveDe(slug, llave), valor)
      } catch {
        // Sin almacenamiento: la app sigue, solo no recuerda.
      }
    },

    guardarJson(llave: LlaveAlmacen, valor: unknown): void {
      this.guardar(llave, JSON.stringify(valor))
    },

    borrar(llave: LlaveAlmacen): void {
      try {
        almacen?.removeItem(llaveDe(slug, llave))
      } catch {
        // Igual que arriba.
      }
    },

    /** Cerrar sesión: borra todo lo de esta flotilla y nada de otra. */
    borrarTodo(): void {
      if (!almacen) return
      try {
        const propias: string[] = []
        for (let i = 0; i < almacen.length; i++) {
          const llave = almacen.key(i)
          if (llave?.startsWith(`${PREFIJO}:${slug}:`)) propias.push(llave)
        }
        propias.forEach((llave) => almacen.removeItem(llave))
      } catch {
        // Igual que arriba.
      }
    },
  }
}

export type Almacen = ReturnType<typeof crearAlmacen>

function navegador(): AlmacenBasico | null {
  try {
    return typeof localStorage === 'undefined' ? null : localStorage
  } catch {
    return null
  }
}
