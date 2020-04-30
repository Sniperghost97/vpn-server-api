<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Server\Api;

use LC\Common\Http\ApiResponse;
use LC\Common\Http\AuthUtils;
use LC\Common\Http\InputValidation;
use LC\Common\Http\Request;
use LC\Common\Http\Service;
use LC\Common\Http\ServiceModuleInterface;
use LC\Server\Storage;

class SystemMessagesModule implements ServiceModuleInterface
{
    /** @var \LC\Server\Storage */
    private $storage;

    public function __construct(Storage $storage)
    {
        $this->storage = $storage;
    }

    /**
     * @return void
     */
    public function init(Service $service)
    {
        $service->get(
            '/system_messages',
            /**
             * @return \LC\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $type = InputValidation::messageType($request->requireQueryParameter('message_type'));

                return new ApiResponse('system_messages', $this->storage->systemMessages($type));
            }
        );

        $service->post(
            '/add_system_message',
            /**
             * @return \LC\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $type = InputValidation::messageType($request->requirePostParameter('message_type'));

                // we do NOT sanitize or verify message as *everything* is
                // allowed! It will never be used as-is for showing in the
                // browser, as the user portal will escape it before showing
                // and the apps MUST interprete it as "text/plain".
                $message = $request->requirePostParameter('message_body');

                $this->storage->addSystemMessage($type, $message);

                return new ApiResponse('add_system_message', true);
            }
        );

        $service->post(
            '/delete_system_message',
            /**
             * @return \LC\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $messageId = InputValidation::messageId($request->requirePostParameter('message_id'));

                $this->storage->deleteSystemMessage($messageId);

                return new ApiResponse('delete_system_message', true);
            }
        );
    }
}
