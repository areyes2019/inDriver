<?php

use App\Support\PasswordGenerada;
use Illuminate\Mail\Markdown;

it('generates a password of the requested length', function () {
    expect(strlen(PasswordGenerada::generar()))->toBe(PasswordGenerada::LARGO);
    expect(strlen(PasswordGenerada::generar(24)))->toBe(24);
});

it('generates passwords with letters, numbers and exactly one symbol, never a slash', function () {
    foreach (range(1, 200) as $i) {
        $password = PasswordGenerada::generar();

        expect($password)->toMatch('/[a-z]/i')
            ->and($password)->toMatch('/[0-9]/')
            ->and($password)->not->toContain('/')
            ->and($password)->not->toContain(chr(92));

        $simbolos = preg_replace('/[a-z0-9]/i', '', $password);

        expect(strlen($simbolos))->toBe(1)
            ->and(PasswordGenerada::SIMBOLOS)->toContain($simbolos);
    }
});

// El correo de credenciales renderiza sus líneas como Markdown. Si la contraseña generada trae un
// carácter que el parser interpreta, el usuario recibe una contraseña distinta de la guardada y el
// login falla con "credenciales no registradas" sobre un usuario que sí existe.
it('survives the markdown rendering of the credentials email intact', function () {
    foreach (range(1, 200) as $i) {
        $password = PasswordGenerada::generar();

        expect(Markdown::parse("Contraseña: `{$password}`")->toHtml())
            ->toContain("<code>{$password}</code>");
    }
});
