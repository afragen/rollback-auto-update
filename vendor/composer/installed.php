<?php return array(
    'root' => array(
        'name' => 'afragen/rollback_auto_update',
        'pretty_version' => 'dev-main',
        'version' => 'dev-main',
        'reference' => '1f48d071e6fc418917f156f4ab68ccd6d1242c2a',
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => false,
    ),
    'versions' => array(
        'afragen/rollback_auto_update' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => '1f48d071e6fc418917f156f4ab68ccd6d1242c2a',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'afragen/singleton' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => 'be8e3c3b3a53ba30db9f77f5b3bcf2d5e58ed9c0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../afragen/singleton',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
    ),
);
