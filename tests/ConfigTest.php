<?php

declare(strict_types=1);

use PaymosCsCart\Config;

function test_cscart_config_builds_client_config_and_secret_map()
{
    paymos_cscart_reset_test_state();
    paymos_cscart_write_generated_config("array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array(
                'base_url' => 'https://api.paymos.test',
                'api_key' => 'pk_test_123',
                'api_secret' => 'sk_test_123',
                'project_id' => 'prj_123',
                'webhook_secret' => 'whsec_sandbox',
            ),
            'live' => array(
                'base_url' => 'https://api.paymos.live',
                'api_key' => 'pk_live_123',
                'api_secret' => 'sk_live_123',
                'project_id' => 'prj_live_123',
                'webhook_secret' => 'whsec_live',
            ),
        ),
    )");

    $config = Config::fromProcessorParams(cscart_processor_params());
    $clientConfig = $config->clientConfig();

    assertSameValue('sandbox', $config->environment(), 'CS-Cart config must use selected mode.');
    assertSameValue('prj_123', $config->projectId(), 'CS-Cart config must expose sandbox project id.');
    assertSameValue('pk_test_123', $clientConfig->apiKey(), 'CS-Cart config must build SDK client config.');
    assertSameValue('https://api.paymos.test', $clientConfig->baseUrl(), 'CS-Cart config must read base_url from the selected environment block, not a top-level key.');

    $liveConfig = $config->clientConfigForEnvironment('live');
    assertSameValue('https://api.paymos.live', $liveConfig->baseUrl(), 'CS-Cart config must read the live environment base_url independently.');

    assertSameValue(
        array('sandbox' => 'whsec_sandbox', 'live' => 'whsec_live'),
        $config->webhookSecrets(),
        'CS-Cart config must expose both webhook secrets for environment detection.'
    );
}

function test_cscart_config_rejects_mismatched_api_secret_environment()
{
    paymos_cscart_reset_test_state();
    paymos_cscart_write_generated_config("array(
        'mode' => 'sandbox',
        'environments' => array(
            'sandbox' => array(
                'api_key' => 'pk_test_123',
                'api_secret' => 'sk_live_wrong',
                'project_id' => 'prj_123',
                'webhook_secret' => 'whsec_sandbox',
            ),
        ),
    )");

    $threw = false;
    try {
        Config::fromProcessorParams(cscart_processor_params())->clientConfig();
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }

    assertTrueValue($threw, 'CS-Cart config must reject sandbox key with live secret.');
}
