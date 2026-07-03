<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Windows Agent Installer (MinIO / S3)
    |--------------------------------------------------------------------------
    |
    | The Windows agent installer is stored in MinIO (S3-compatible) and
    | streamed to admins from Settings → Agent Enrollment.
    |
    */

    'installer' => [
        'disk' => env('AGENT_INSTALLER_DISK', 'minio'),
        'object_key' => env('AGENT_INSTALLER_OBJECT_KEY', 'agents/InfraControl.Agent.Setup.exe'),
        'filename' => env('AGENT_INSTALLER_FILENAME', 'InfraControl.Agent.Setup.exe'),
        'version' => env('AGENT_INSTALLER_VERSION', '1.0.0'),
        'public_url' => env('AGENT_INSTALLER_PUBLIC_URL'),
        'public_base_url' => env('MINIO_PUBLIC_URL'),
    ],

];
