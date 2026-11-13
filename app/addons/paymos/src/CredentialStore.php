<?php

declare(strict_types=1);

namespace PaymosCsCart;

use Paymos\Plugin\AesGcmEnvelope;
use Paymos\Plugin\CredentialSet;
use Tygh\Registry;

final class CredentialStore
{
    private const CREDENTIALS = 'credentials_v1';
    private const STATE = 'connect_state_v1';

    public static function loadCredentials()
    {
        $payload = self::load(self::CREDENTIALS, 'paymos-cscart-credentials-v1');
        if (count($payload) === 0) {
            return array();
        }
        if (!isset($payload['schema'], $payload['environments'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['environments'])) {
            throw new \RuntimeException('Stored Paymos credentials have an invalid schema.');
        }
        return CredentialSet::normalize($payload['environments']);
    }

    public static function saveCredentials(array $environments)
    {
        self::save(self::CREDENTIALS, 'paymos-cscart-credentials-v1', array(
            'schema' => 1,
            'environments' => CredentialSet::normalize($environments),
        ));
    }

    public static function saveState(array $state)
    {
        self::save(self::STATE, 'paymos-cscart-connect-state-v1', array(
            'schema' => 1,
            'expires_at' => time() + (int) $state['expires_in'],
            'state' => $state,
        ));
    }

    public static function loadState()
    {
        $payload = self::load(self::STATE, 'paymos-cscart-connect-state-v1');
        if (!isset($payload['schema'], $payload['expires_at'], $payload['state'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['state'])
            || time() >= (int) $payload['expires_at']) {
            self::clearState();
            return array();
        }
        return $payload['state'];
    }

    public static function clearState()
    {
        db_query('DELETE FROM ?:paymos_config WHERE name = ?s', self::STATE);
    }

    /**
     * Stores a new Connect attempt. It replaces any earlier attempt; a poll of
     * that earlier attempt then finds a different device code and applies
     * nothing (see whileCurrentAttempt()).
     */
    public static function startAttempt(array $state)
    {
        self::connectLocked(static function () use ($state) {
            self::saveState($state);
        });
    }

    /**
     * Runs $action (credential save, state clear) only while the stored Connect
     * attempt is still the one identified by $deviceCode (48-02). The poll's
     * HTTP request runs outside this lock; another administrator may have
     * started a newer attempt meanwhile, and a stale poll result must not clear
     * it or write the credentials of the older approval over it.
     *
     * @throws \RuntimeException when the attempt was replaced or expired.
     */
    public static function whileCurrentAttempt($deviceCode, callable $action)
    {
        return self::connectLocked(static function () use ($deviceCode, $action) {
            $state = self::loadState();
            if (!isset($state['device_code']) || !is_string($state['device_code'])
                || !hash_equals($state['device_code'], (string) $deviceCode)) {
                throw new \RuntimeException('This Paymos connection request was replaced by a newer one.');
            }

            return $action();
        });
    }

    private static function connectLocked(callable $action)
    {
        $name = hash('sha256', db_get_field('SELECT DATABASE()') . ':paymos_cscart:connect');
        if ((string) db_get_field('SELECT GET_LOCK(?s, 10)', $name) !== '1') {
            throw new \RuntimeException('Another Paymos connection change is in progress. Try again.');
        }
        try {
            return $action();
        } finally {
            db_get_field('SELECT RELEASE_LOCK(?s)', $name);
        }
    }

    private static function load($name, $aad)
    {
        $encoded = db_get_field('SELECT value FROM ?:paymos_config WHERE name = ?s', $name);
        return !is_string($encoded) || $encoded === ''
            ? array()
            : AesGcmEnvelope::open($encoded, self::keyMaterial(), $aad);
    }

    private static function save($name, $aad, array $payload)
    {
        $encoded = AesGcmEnvelope::seal($payload, self::keyMaterial(), $aad);
        db_query('REPLACE INTO ?:paymos_config ?e', array('name' => $name, 'value' => $encoded));
    }

    private static function keyMaterial()
    {
        $material = trim((string) Registry::get('config.crypt_key'));
        if ($material === '') {
            throw new \RuntimeException('CS-Cart crypt_key is not configured.');
        }
        return $material;
    }
}
