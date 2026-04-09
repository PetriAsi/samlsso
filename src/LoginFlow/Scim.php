<?php
declare(strict_types=1);
/**
 *  ------------------------------------------------------------------------
 *  samlSSO
 *
 *  samlSSO was inspired by the initial work of Derrick Smith's
 *  PhpSaml. This project's intend is to address some structural issues
 *  caused by the gradual development of GLPI and the broad amount of
 *  wishes expressed by the community.
 *
 *  Copyright (C) 2024 by Chris Gralike
 *  ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of samlSSO plugin for GLPI.
 *
 * samlSSO plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * samlSSO is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with samlSSO. If not, see <http://www.gnu.org/licenses/> or
 * https://choosealicense.com/licenses/gpl-3.0/
 *
 * ------------------------------------------------------------------------
 *
 *  @package    samlSSO
 *  @version    1.2.7
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.2.5
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso\LoginFlow;

use User as glpiUser;
use GlpiPlugin\Samlsso\Config\ConfigEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * This class handles SCIM (System for Cross-domain Identity Management) requests.
 * It allows external Identity Providers to provision users and groups in GLPI.
 */
class Scim
{
    /**
     * Initializer for SCIM requests.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function init(Request $request): JsonResponse
    {
        $idpId = (int) $request->get('idpId');
        $config = new ConfigEntity($idpId);

        if (!$config->isValid() || !$config->getField(ConfigEntity::SCIM_ACTIVE)) {
            return new JsonResponse(['error' => 'SCIM not enabled for this IdP'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->authenticate($request, $config)) {
            return new JsonResponse(['error' => 'Invalid SCIM token'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->handleRequest($request, $config);
    }

    /**
     * Validates the Bearer token.
     *
     * @param Request $request
     * @param ConfigEntity $config
     * @return bool
     */
    private function authenticate(Request $request, ConfigEntity $config): bool
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || strpos($authHeader, 'Bearer ') !== 0) {
            return false;
        }

        $token = substr($authHeader, 7);
        return $token === $config->getField(ConfigEntity::SCIM_TOKEN);
    }

    /**
     * Dispatches SCIM requests to appropriate handlers.
     *
     * @param Request $request
     * @param ConfigEntity $config
     * @return JsonResponse
     */
    private function handleRequest(Request $request, ConfigEntity $config): JsonResponse
    {
        $pathInfo = $request->getPathInfo();
        $method = $request->getMethod();

        // Basic routing for /Users
        if (preg_match('#/Users(?:/(.+))?#', $pathInfo, $matches)) {
            $userId = $matches[1] ?? null;

            switch ($method) {
                case 'GET':
                    return $userId ? $this->getUser($userId) : $this->getUsers($request);
                case 'POST':
                    return $this->createUser($request);
                case 'PUT':
                case 'PATCH':
                    return $this->updateUser($request, $userId);
                case 'DELETE':
                    return $this->deleteUser($userId);
            }
        }

        return new JsonResponse(['error' => 'Endpoint not implemented'], Response::HTTP_NOT_IMPLEMENTED);
    }

    private function getUsers(Request $request): JsonResponse
    {
        // Simple implementation: list all users created by SAML/SCIM
        $user = new glpiUser();
        // This is a placeholder for actual user search/listing logic
        return new JsonResponse([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => 0,
            'Resources' => []
        ]);
    }

    private function getUser(string $id): JsonResponse
    {
        $user = new glpiUser();
        if ($user->getFromDB($id)) {
            return new JsonResponse($this->mapUserToScim($user));
        }
        return new JsonResponse(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
    }

    private function createUser(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        // Map SCIM data to GLPI user fields
        $userFields = $this->mapScimToUser($data);
        
        $user = new glpiUser();
        // Check if user already exists by externalId/authid or userName/name
        $externalId = $data['externalId'] ?? null;
        $userName = $data['userName'] ?? null;
        
        /* if ($externalId && $user->getFromDBByCrit(['authid' => $externalId])) {
            return new JsonResponse(['error' => 'User already exists'], Response::HTTP_CONFLICT);
        } */
        if ($userName && $user->getFromDBbyName($userName)) {
            return new JsonResponse(['error' => 'User already exists'], Response::HTTP_CONFLICT);
        }

        if ($id = $user->add($userFields)) {
            $user->getFromDB($id);
            return new JsonResponse($this->mapUserToScim($user), Response::HTTP_CREATED);
        }

        return new JsonResponse(['error' => 'Failed to create user'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function updateUser(Request $request, string $id): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $user = new glpiUser();
        if (!$user->getFromDB($id)) {
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $userFields = $this->mapScimToUser($data);
        $userFields['id'] = $id;

        if ($user->update($userFields)) {
            $user->getFromDB($id);
            return new JsonResponse($this->mapUserToScim($user));
        }

        return new JsonResponse(['error' => 'Failed to update user'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function deleteUser(string $id): JsonResponse
    {
        $user = new glpiUser();
        if (!$user->getFromDB($id)) {
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        if ($user->delete(['id' => $id], true)) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        return new JsonResponse(['error' => 'Failed to delete user'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Maps SCIM user data to GLPI user fields.
     */
    private function mapScimToUser(array $data): array
    {
        return [
            'name'      => $data['userName'] ?? '',
            'realname'  => $data['name']['familyName'] ?? '',
            'firstname' => $data['name']['givenName'] ?? '',
            '_useremails' => isset($data['emails']) ? array_column($data['emails'], 'value') : [],
            'is_active' => $data['active'] ?? 1,
            // 'authid'    => $data['externalId'] ?? ($data['userName'] ?? ''),
            'authtype'  => 4, // External
        ];
    }

    /**
     * Maps GLPI user to SCIM structure.
     */
    private function mapUserToScim(glpiUser $user): array
    {
        return [
            'schemas'    => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'id'         => (string) $user->fields['id'],
            'userName'   => $user->fields['name'],
            // 'externalId' => $user->fields['authid'],
            'name'       => [
                'familyName' => $user->fields['realname'],
                'givenName'  => $user->fields['firstname'],
            ],
            'active'     => (bool) $user->fields['is_active'],
            'emails'     => [
                ['value' => $user->fields['name'], 'primary' => true] // Simplified
            ]
        ];
    }
}
