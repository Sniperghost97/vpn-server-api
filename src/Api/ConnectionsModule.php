<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Server\Api;

use DateTime;
use LC\Common\Config;
use LC\Common\Http\ApiErrorResponse;
use LC\Common\Http\ApiResponse;
use LC\Common\Http\AuthUtils;
use LC\Common\Http\InputValidation;
use LC\Common\Http\Request;
use LC\Common\Http\Service;
use LC\Common\Http\ServiceModuleInterface;
use LC\Common\ProfileConfig;
use LC\Server\Storage;

class ConnectionsModule implements ServiceModuleInterface
{
    /** @var \LC\Common\Config */
    private $config;

    /** @var \LC\Server\Storage */
    private $storage;

    /** @var \DateTime */
    private $dateTime;

    public function __construct(Config $config, Storage $storage)
    {
        $this->config = $config;
        $this->storage = $storage;
        $this->dateTime = new DateTime();
    }

    /**
     * @return void
     */
    public function setDateTime(DateTime $dateTime)
    {
        $this->dateTime = $dateTime;
    }

    /**
     * @return void
     */
    public function init(Service $service)
    {
        $service->post(
            '/connect',
            /**
             * @return \LC\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-server-node']);

                return $this->connect($request);
            }
        );

        $service->post(
            '/disconnect',
            /**
             * @return \LC\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-server-node']);

                return $this->disconnect($request);
            }
        );
    }

    /**
     * @return \LC\Common\Http\Response
     */
    public function connect(Request $request)
    {
        $profileId = InputValidation::profileId($request->requirePostParameter('profile_id'));
        $commonName = InputValidation::commonName($request->requirePostParameter('common_name'));
        $ip4 = InputValidation::ip4($request->requirePostParameter('ip4'));
        $ip6 = InputValidation::ip6($request->requirePostParameter('ip6'));
        $connectedAt = InputValidation::connectedAt($request->requirePostParameter('connected_at'));

        if (null !== $response = $this->verifyConnection($profileId, $commonName)) {
            return $response;
        }

        $this->storage->clientConnect($profileId, $commonName, $ip4, $ip6, new DateTime(sprintf('@%d', $connectedAt)));

        return new ApiResponse('connect');
    }

    /**
     * @return \LC\Common\Http\Response
     */
    public function disconnect(Request $request)
    {
        $profileId = InputValidation::profileId($request->requirePostParameter('profile_id'));
        $commonName = InputValidation::commonName($request->requirePostParameter('common_name'));
        $ip4 = InputValidation::ip4($request->requirePostParameter('ip4'));
        $ip6 = InputValidation::ip6($request->requirePostParameter('ip6'));

        $connectedAt = InputValidation::connectedAt($request->requirePostParameter('connected_at'));
        $disconnectedAt = InputValidation::disconnectedAt($request->requirePostParameter('disconnected_at'));
        $bytesTransferred = InputValidation::bytesTransferred($request->requirePostParameter('bytes_transferred'));

        $this->storage->clientDisconnect($profileId, $commonName, $ip4, $ip6, new DateTime(sprintf('@%d', $connectedAt)), new DateTime(sprintf('@%d', $disconnectedAt)), $bytesTransferred);

        return new ApiResponse('disconnect');
    }

    /**
     * @param string $profileId
     * @param string $commonName
     *
     * @return \LC\Common\Http\ApiErrorResponse|null
     */
    private function verifyConnection($profileId, $commonName)
    {
        // verify status of certificate/user
        if (false === $result = $this->storage->getUserCertificateInfo($commonName)) {
            // if a certificate does no longer exist, we cannot figure out the user
            return new ApiErrorResponse('connect', sprintf('user or certificate does not exist [profile_id: %s, common_name: %s]', $profileId, $commonName));
        }

        $userId = $result['user_id'];

        // this is always string, but DB gives back scalar|null
        $sessionExpiresAt = new DateTime((string) $this->storage->getSessionExpiresAt($userId));
        if ($sessionExpiresAt->getTimestamp() < $this->dateTime->getTimestamp()) {
            $errMsg = sprintf('[VPN] the certificate is still valid, but the session expired at %s', $sessionExpiresAt->format(DateTime::ATOM));
            $this->storage->addUserMessage($userId, 'notification', $errMsg);

            return new ApiErrorResponse('connect', $errMsg);
        }

        if ($result['user_is_disabled']) {
            $msg = '[VPN] unable to connect, account is disabled';
            $this->storage->addUserMessage($userId, 'notification', $msg);

            return new ApiErrorResponse('connect', $msg);
        }

        return $this->verifyAcl($profileId, $userId);
    }

    /**
     * @param string $profileId
     * @param string $externalUserId
     *
     * @return \LC\Common\Http\ApiErrorResponse|null
     */
    private function verifyAcl($profileId, $externalUserId)
    {
        // verify ACL
        $profileConfig = new ProfileConfig($this->config->getSection('vpnProfiles')->getSection($profileId)->toArray());
        if ($profileConfig->getItem('enableAcl')) {
            // ACL enabled
            $userPermissionList = $this->storage->getPermissionList($externalUserId);
            $profilePermissionList = $profileConfig->getSection('aclPermissionList')->toArray();
            if (false === self::hasPermission($userPermissionList, $profilePermissionList)) {
                $msg = sprintf('[VPN] unable to connect, user permissions are [%s], but requires any of [%s]', implode(',', $userPermissionList), implode(',', $profilePermissionList));
                $this->storage->addUserMessage($externalUserId, 'notification', $msg);

                return new ApiErrorResponse('connect', $msg);
            }
        }

        return null;
    }

    /**
     * @return bool
     */
    private static function hasPermission(array $userPermissionList, array $aclPermissionList)
    {
        // one of the permissions must be listed in the profile ACL list
        foreach ($userPermissionList as $userPermission) {
            if (\in_array($userPermission, $aclPermissionList, true)) {
                return true;
            }
        }

        return false;
    }
}
