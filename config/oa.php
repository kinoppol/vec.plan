<?php
// RVC-ONE (SSO gateway + User-sync API). The only place these values live; read through RvcOne::config().
// client_secret is never committed: enter it in the app (สถานศึกษา › การเชื่อมต่อ RVC-ONE, stored encrypted),
// or on the server set the environment variable OA_CLIENT_SECRET, or put
// ['client_secret' => '...'] in config/oa.local.php (git-ignored), which overrides any key below.
return [
    'authorize_url'   => 'http://workspace.rvc.ac.th/oa/index.php',
    'verify_url'      => 'http://workspace.rvc.ac.th/oa/api/verify_token.php',
    'users_url'       => 'http://workspace.rvc.ac.th/oa/api/users.php',
    'client_id'       => 'cl_9cfd5dfdba',
    'client_secret'   => getenv('OA_CLIENT_SECRET') ?: null,
    // Must match the registered redirect_uri character for character.
    'redirect_uri'    => 'https://plan.rvc.ac.th/web/auth/callback',
    // Institution that receives synced users and first-time SSO users.
    'institution_id'  => 1,
    // User types to sync: staff | student | all (null = everything the app is allowed).
    'sync_type'       => 'staff',
    'sync_page_size'  => 500,
    'timeout'         => 15,
];
