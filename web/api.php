<?php
/**
 *  Copyright (C) 2016 SURFnet.
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
require_once sprintf('%s/vendor/autoload.php', dirname(__DIR__));

use SURFnet\VPN\Server\Api\Request;
use SURFnet\VPN\Server\Api\Response;
use SURFnet\VPN\Server\Api\Exception\HttpException;
use SURFnet\VPN\Server\Api\CommonNames;
use SURFnet\VPN\Server\Api\CommonNamesModule;
use SURFnet\VPN\Server\Api\GroupsModule;
use SURFnet\VPN\Server\Api\InfoModule;
use SURFnet\VPN\Server\Api\LogModule;
use SURFnet\VPN\Server\Api\OpenVpnModule;
use SURFnet\VPN\Server\Api\Service;
use SURFnet\VPN\Server\Api\Users;
use SURFnet\VPN\Server\Api\UsersModule;
use SURFnet\VPN\Server\Config;
use SURFnet\VPN\Server\InstanceConfig;
use SURFnet\VPN\Server\Logger;
use SURFnet\VPN\Server\OpenVpn\ManagementSocket;
use SURFnet\VPN\Server\OpenVpn\ServerManager;

$logger = new Logger('vpn-server-api');

try {
    // this is provided by Apache, using CanonicalName
    $request = new Request($_SERVER, $_GET, $_POST);
    $instanceId = $request->getServerName();

    $dataDir = sprintf('%s/data/%s', dirname(__DIR__), $instanceId);
    $configDir = sprintf('%s/config/%s', dirname(__DIR__), $instanceId);

    $instanceConfig = InstanceConfig::fromFile(
        sprintf('%s/config.yaml', $configDir)
    );

    $service = new Service();

    $service->addHook(
        'before',
        'auth',
        function (Request $request) use ($instanceConfig) {
            // check if we have valid authentication
            $apiConsumers = $instanceConfig->apiConsumers();
            $authUser = $request->getHeader('PHP_AUTH_USER', false);
            $authPass = $request->getHeader('PHP_AUTH_PW', false);
            if (is_null($authUser) || is_null($authPass)) {
                throw new HttpException('missing authentication information', 401);
            }

            if (array_key_exists($authUser, $apiConsumers)) {
                // time safe string compare, using polyfill on PHP < 5.6
                if (hash_equals($apiConsumers[$authUser], $authPass)) {
                    return $authUser;
                }
            }

            throw new HttpException('invalid authentication information', 401);
        }
    );

    $service->addModule(
        new LogModule($dataDir)
    );
    $service->addModule(
        new OpenVpnModule(
            new ServerManager($instanceConfig, new ManagementSocket(), $logger)
        )
    );
    $service->addModule(
        new CommonNamesModule(
            new CommonNames(sprintf('%s/common_names', $dataDir)),
            $logger
        )
    );
    $service->addModule(
        new UsersModule(
            new Users(sprintf('%s/users', $dataDir)),
            $logger
        )
    );
    $service->addModule(
        new GroupsModule(
            $instanceConfig,
            $logger
        )
    );
    $service->addModule(
        new InfoModule($instanceConfig)
    );

    $service->run($request)->send();
} catch (Exception $e) {
    $logger->error($e->getMessage());
    $response = new Response(500);
    $response->setBody($e->getMessage());
    $response->send();
}
