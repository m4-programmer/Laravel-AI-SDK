<?php

return [

    /*
    | Shared secret (same as AI service `SERVICE_AUTH_SECRET` / `settings.service_auth_secret`).
    | Token = HMAC-SHA256(UTC date "Y-m-d" + secret) using key = secret.
    */
    'service_auth_secret' => env('SERVICE_AUTH_SECRET', ''),

];
