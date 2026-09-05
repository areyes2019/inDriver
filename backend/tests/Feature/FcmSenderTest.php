<?php

use App\Services\FcmSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // El access token se cachea 55 min (FcmSender) — se limpia entre pruebas para que no se
    // filtre de una prueba a otra dentro del mismo proceso de Pest.
    Cache::flush();
});

/**
 * Llave RSA de prueba, generada una sola vez con `openssl genrsa` — no es una credencial real, solo
 * sirve para firmar el JWT de prueba de FcmSender. `openssl_pkey_new()` no se usa aquí porque
 * requiere un `openssl.cnf` que no siempre está disponible en el entorno donde corren las pruebas
 * (p. ej. PHP para Windows sin ese archivo configurado).
 */
const FCM_LLAVE_PRIVADA_PRUEBA = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDN9zV0e+0pB/qt
XoB0WdoEIzD9aysvnoWiHtD9BEyJRR9YWleUvsyGfe1Dw3keohsVz82gfm8K1iSO
PXfT0+P+8Ih2mq8Og12oPrpL3g3JaC33o8V+2VR9wH2jxbQ3qLtynjmRufl0FbPE
46FOrHD4eKWxFcgZYO2jw4ImLZbMaj5t9aQ7nYe4/x5hNbpCFxgk1GuXR0l0RKmG
BkoYiZiBDouX7xe13FD8ddp92yjP8uIP/BoZSIoXCsj133wDJTNN7C0fQUUzOc6/
10Pny1l+VOPeuxFiwD28XaO1NsAtXotLNYGRAj8D76kSlQhrox7aViyMn1+AYq8G
4KCeWXi1AgMBAAECggEAM92+ZNR2TwBW5ISpNWORDryr8A0mRWoWfdJjz2tfOKwi
7hVl+6umhnG8p3VYkVnCF1aKkhF0thZiAz3AaKPxxLferXtbfPygv6b4M/W5pA/r
j3J63+wrpjUsjmrRbLi9Z2on1iYuhsiWSg0GiHDNTAzZsMPq7VUm0rf/lMyjLltZ
Md9vCX3pidj61slemGwaPatGvFDcsfj1goTFI1Q/fw6T+oFk61qjiI0hAB0+zNw0
w/AQfGaDJhuKphLbY6krwfGLpUf8yaIF3xvmGL51tK83tMcICmKosd9u1jxd+On4
0O5OHveOC96BqixyyXpsrHr0XDlPQ1qZWgWiGb6eywKBgQDmXoBsD18Fv1czJG7k
HSZzGVLLB2rGKJHy74GBjEDRvpVpPsJU171GWNRSVS5bHjSy6KrOC6SB2z65H4hP
iABTIxZpNLcBEh6abvppYDJH6rWXh2d0Rq61oIxD5szrL2CPccs1ATkRJ8S8QqF1
dmz7s1IZyUv14JKWOl4iNrG6ewKBgQDk4aJEa6xG5O6KoBqKU1c24CCOMcnGPVzc
9PdQEtX/xM562Zd94e+vgwXEyn2uBnP9MAmyDVSeURWNlxNHZs6bknUSGzzOfEm1
dzA2EB3vsFIFEmtTj7rFyIhfRrpdyXJRtXzloOSIus0EBeRncMKaRPubNdbqIzNS
s+h9d+SKjwKBgBB2EkEmfAjCGm4KHW5pctToq1Tcq9GLFprAaIWkSwFx1+VUWbiM
TfcX49waQBy8tNFP9NySUmgBDaNW0Hu2YSePq0tLPAR0kgFBCt26xP0ElYNFZqwV
XOiXl05G0L/Be+nkHLwl4TkLmXBGZpkpJDJ8JtK24pmoOXFIrG9PbzW/AoGBANbq
T5XzjMbc/GhKweEVNJWgirE6av6sa+BGXVtg9HS/9ipA2xEm8Atb+jS49p5MDOm3
C8OW5Nfrx1M2grHPBT3rneYskUJKTmQI0MpTA+knJT0B+Kl0EqrZC8R7A1BBcgjr
Y6WzGCSTUyLt7XR72x9EmwU43t7nwq9ro2j9BSpdAoGBAJzWdml6sO2FnVrHtMGM
77+kH0s4sDDSHB7ES87trWUfG2bbC8e7ktkuwpCbVhwJrpn4XvVvVjdJ+TuMl5e0
G8lvF1oTA8vmjhB5E3Bflaa5nsSMeuepLFhrczw1HDyK15+9JkzF0HQzDT00ZSbx
cWcLyhQTH/1lsPBkvfhAQTHt
-----END PRIVATE KEY-----
PEM;

function fcmCredencialesFalsas(): string
{
    $ruta = sys_get_temp_dir().'/fcm-credenciales-'.uniqid().'.json';
    File::put($ruta, json_encode([
        'client_email' => 'panda-express@demo-project.iam.gserviceaccount.com',
        'private_key' => FCM_LLAVE_PRIVADA_PRUEBA,
    ]));

    return $ruta;
}

it('does nothing when FCM is not configured', function () {
    Http::fake();

    (new FcmSender)->enviar('token-dispositivo', 'Título', 'Cuerpo');

    Http::assertNothingSent();
});

it('sends a push notification through the FCM v1 API', function () {
    $ruta = fcmCredencialesFalsas();
    config(['services.fcm.project_id' => 'demo-project', 'services.fcm.credentials_path' => $ruta]);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fake-token']),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/demo-project/messages/1']),
    ]);

    (new FcmSender)->enviar('token-dispositivo', 'Nuevo pedido disponible', 'Pedido PED-1 listo para tomar', ['event_id' => 'abc-123']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token')
        && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com/v1/projects/demo-project/messages:send')
        && $request->hasHeader('Authorization', 'Bearer ya29.fake-token')
        && $request['message']['token'] === 'token-dispositivo'
        && $request['message']['notification']['title'] === 'Nuevo pedido disponible'
        && $request['message']['data']['event_id'] === 'abc-123');

    File::delete($ruta);
});

it('does nothing when the credentials file is missing', function () {
    config(['services.fcm.project_id' => 'demo-project', 'services.fcm.credentials_path' => sys_get_temp_dir().'/no-existe.json']);

    Http::fake();

    (new FcmSender)->enviar('token-dispositivo', 'Título', 'Cuerpo');

    Http::assertNothingSent();
});
